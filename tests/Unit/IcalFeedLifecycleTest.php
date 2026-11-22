<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/src/Db.php';
require_once dirname(__DIR__, 2) . '/src/CalendarSync.php';
require_once dirname(__DIR__, 2) . '/src/OtaVerificationService.php';
require_once dirname(__DIR__, 2) . '/src/HouseRepository.php';
require_once dirname(__DIR__, 2) . '/src/helpers.php';

final class IcalFeedLifecycleTest extends TestCase
{
    private const T0 = 1795176000; // 2026-11-20 12:00:00 UTC

    private PDO $pdo;
    private int $propertyId;
    private int $userId;
    private int $now;

    protected function setUp(): void
    {
        putenv('APP_ENV=testing');
        Db::loadEnv();
        $this->pdo = db();
        $this->pdo->beginTransaction();
        $this->now = self::T0;
        OtaVerificationService::$clock = fn (): int => $this->now;
        OtaVerificationService::$httpTransport = fn (): string => $this->emptyIcs();

        $prop = $this->pdo->query("SELECT id, user_id FROM properties WHERE status = 'active' ORDER BY id LIMIT 1")
            ->fetch(PDO::FETCH_ASSOC);
        $this->assertNotEmpty($prop);
        $this->propertyId = (int) $prop['id'];
        $this->userId = (int) $prop['user_id'];
        $this->pdo->prepare('DELETE FROM calendar_days WHERE property_id = ?')->execute([$this->propertyId]);
        $this->pdo->prepare('DELETE FROM calendar_feeds WHERE property_id = ?')->execute([$this->propertyId]);
        $this->pdo->prepare('DELETE FROM ota_credit_ledger WHERE property_id = ?')->execute([$this->propertyId]);
        $this->pdo->prepare('DELETE FROM ota_challenge_attempts WHERE property_id = ?')->execute([$this->propertyId]);
    }

    protected function tearDown(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    private function emptyIcs(): string
    {
        return "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//CasaLivre//Test//EN\r\nEND:VCALENDAR\r\n";
    }

    private function icsWithBlocked(string $ymd): string
    {
        $end = (new DateTimeImmutable($ymd, new DateTimeZone('UTC')))->modify('+1 day')->format('Ymd');
        return "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//CasaLivre//Test//EN\r\n"
            . "BEGIN:VEVENT\r\nUID:blocked\r\nDTSTART;VALUE=DATE:" . str_replace('-', '', $ymd)
            . "\r\nDTEND;VALUE=DATE:" . $end . "\r\nSUMMARY:Blocked\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
    }

    private function feedBySlot(int $slot): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM calendar_feeds WHERE property_id = ? AND slot = ?');
        $stmt->execute([$this->propertyId, $slot]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    }

    private function addFeed(string $url = 'https://ical.booking.com/v1/export?t=lifecycle'): array
    {
        // Updated: 2026-11 - the Add Feed UI now requires explicit OTA verification mode.
        $result = HouseRepository::addFeed(
            $this->propertyId,
            $this->userId,
            $url,
            'Booking Test',
            'ota',
            'booking',
            'https://www.booking.com/hotel/br/casa-teste.html',
            true
        );
        $this->assertIsArray($result, is_string($result) ? $result : '');
        return $result;
    }

    public function testAddFeedCreatesPendingChallengeAndImportsImmediately(): void
    {
        $blocked = gmdate('Y-m-d', $this->now + 40 * 86400);
        OtaVerificationService::$httpTransport = fn (): string => $this->icsWithBlocked($blocked);

        $created = $this->addFeed();
        $feed = $this->feedBySlot(1);
        $this->assertSame('pending_verification', $feed['status']);
        $this->assertNotNull($feed['challenge_date']);
        $this->assertNull($feed['verified_at']);
        $this->assertNotNull($feed['last_synced_at'], 'the feed syncs immediately while pending');
        $this->assertSame('OK', $feed['last_status']);

        $night = $this->pdo->prepare('SELECT count(*) FROM calendar_days WHERE property_id = ? AND day = ? AND feed_id = ?');
        $night->execute([$this->propertyId, $blocked, (int) $feed['id']]);
        $this->assertSame(1, (int) $night->fetchColumn(), 'events are imported before the challenge is completed');

        $this->assertSame('pending_verification', $created['feed']['status'] ?? '');
        $this->assertSame('pending_verification', HouseRepository::feeds($this->propertyId)[0]['status']);
    }

    public function testAddFeedRejectsTheSameNormalizedUrlGlobally(): void
    {
        $other = $this->pdo->query(
            "SELECT id, user_id FROM properties WHERE status = 'active' AND id <> " . $this->propertyId . " ORDER BY id LIMIT 1"
        )->fetch(PDO::FETCH_ASSOC);
        $this->assertNotEmpty($other);

        $url = 'https://ical.booking.com/v1/export?t=GLOBAL';
        $this->addFeed($url);
        $result = HouseRepository::addFeed((int) $other['id'], (int) $other['user_id'], $url, 'Other');
        $this->assertSame('duplicate', $result);
    }

    public function testWorkerClaimsPendingAndActiveButSkipsErrorAndImportDoesNotVerify(): void
    {
        OtaVerificationService::$httpTransport = fn (): string => $this->icsWithBlocked(gmdate('Y-m-d', $this->now + 40 * 86400));
        $this->addFeed('https://ical.booking.com/v1/export?t=pending');
        $this->addFeed('https://ical.booking.com/v1/export?t=active');
        $this->addFeed('https://ical.booking.com/v1/export?t=error');
        $pending = $this->feedBySlot(1);
        $active = $this->feedBySlot(2);
        $error = $this->feedBySlot(3);

        $this->pdo->prepare("UPDATE calendar_feeds SET status='active', verified_at=to_timestamp(?), next_sync_at=now()-interval '1 minute' WHERE id=?")
            ->execute([$this->now, (int) $active['id']]);
        $this->pdo->prepare("UPDATE calendar_feeds SET status='error', next_sync_at='infinity' WHERE id=?")
            ->execute([(int) $error['id']]);
        $this->pdo->prepare("UPDATE calendar_feeds SET next_sync_at=now()-interval '1 minute' WHERE id=?")
            ->execute([(int) $pending['id']]);
        // Isolate the claim from feeds owned by other fixtures.
        $this->pdo->prepare("UPDATE calendar_feeds SET next_sync_at='infinity' WHERE property_id <> ?")
            ->execute([$this->propertyId]);

        $claimed = [];
        for ($i = 0; $i < 2; $i++) {
            $feed = HouseRepository::claimDueFeed();
            $this->assertNotNull($feed);
            $claimed[] = (int) $feed['id'];
        }
        sort($claimed);
        $expected = [(int) $pending['id'], (int) $active['id']];
        sort($expected);
        $this->assertSame($expected, $claimed, 'pending and active are claimed; error is skipped');

        $night = gmdate('Y-m-d', $this->now + 41 * 86400);
        HouseRepository::importFeed($this->propertyId, (int) $pending['id'], [$night], ['sync'], 0);
        $fresh = $this->pdo->query('SELECT status, verified_at FROM calendar_feeds WHERE id = ' . (int) $pending['id'])->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('pending_verification', $fresh['status']);
        $this->assertNull($fresh['verified_at']);
    }

    public function testVerifyFailureKeepsPendingAndSuccessPromotesAndAwardsCredits(): void
    {
        $created = $this->addFeed();
        $feed = $this->feedBySlot(1);
        $date = (string) $feed['challenge_date'];
        $dt = new DateTimeImmutable($date, new DateTimeZone('UTC'));
        $creditsBefore = (int) $this->pdo->query('SELECT quote_credits FROM users WHERE id = ' . $this->userId)->fetchColumn();

        OtaVerificationService::$httpTransport = fn (): string => $this->emptyIcs();
        $notYet = HouseRepository::verifyFeed($this->propertyId, (int) $feed['id']);
        $this->assertSame('not_yet_reflected', $notYet['status']);
        $still = $this->pdo->query('SELECT status, attempts FROM calendar_feeds WHERE id = ' . (int) $feed['id'])->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('pending_verification', $still['status']);
        $this->assertSame(1, (int) $still['attempts']);

        // The second attempt must respect the cooldown, so move the clock past it.
        $this->now += OtaVerificationService::CHECK_COOLDOWN_SECONDS + 1;
        OtaVerificationService::$httpTransport = fn (): string => $this->icsWithBlocked($date);
        $done = HouseRepository::verifyFeed($this->propertyId, (int) $feed['id']);
        // Updated: 2026-11 - Booking/Vrbo hold the badge and credits until admin spot-check.
        $this->assertSame('pending_review', $done['status']);
        $this->assertFalse($done['verified']);
        $this->assertFalse($done['credits_awarded']);

        $stored = $this->pdo->query('SELECT status, verified_at, credits_awarded, review_status FROM calendar_feeds WHERE id = ' . (int) $feed['id'])->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('active', $stored['status']);
        $this->assertNotNull($stored['verified_at']);
        $this->assertSame('pending_review', $stored['review_status']);
        $this->assertFalse((bool) $stored['credits_awarded']);
        $this->assertFalse(OtaVerificationService::hasVerifiedPlatform($this->propertyId));
        $this->assertSame(
            $creditsBefore,
            (int) $this->pdo->query('SELECT quote_credits FROM users WHERE id = ' . $this->userId)->fetchColumn()
        );

        $approved = OtaVerificationService::approveFeed((int) $feed['id'], (int) $this->userId);
        $this->assertSame('approved', $approved['status']);
        $this->assertTrue($approved['credits_awarded']);
        $this->assertTrue(OtaVerificationService::hasVerifiedPlatform($this->propertyId));
        $this->assertSame(
            $creditsBefore + OtaVerificationService::ONBOARDING_CREDIT_REWARD,
            (int) $this->pdo->query('SELECT quote_credits FROM users WHERE id = ' . $this->userId)->fetchColumn()
        );
        $ledger = $this->pdo->prepare('SELECT count(*) FROM ota_credit_ledger WHERE property_id = ?');
        $ledger->execute([$this->propertyId]);
        $this->assertSame(1, (int) $ledger->fetchColumn());

        // Verification never bypasses the challenge: a retry/unverified feed stays pending.
        $second = $this->addFeed('https://ical.booking.com/v1/export?t=unverified-guard');
        $secondFeed = $this->feedBySlot(2);
        HouseRepository::markFeedPending($this->propertyId, (int) $secondFeed['id']);
        $guard = $this->pdo->query('SELECT status FROM calendar_feeds WHERE id = ' . (int) $secondFeed['id'])->fetchColumn();
        $this->assertSame('pending_verification', $guard);
        HouseRepository::markFeedPending($this->propertyId, (int) $feed['id']);
        $this->assertSame('active', $this->pdo->query('SELECT status FROM calendar_feeds WHERE id = ' . (int) $feed['id'])->fetchColumn());
    }

    public function testPermanentFailureBecomesErrorAndRetryKeepsTheVerificationState(): void
    {
        $created = $this->addFeed();
        $feed = $this->feedBySlot(1);

        $failure = HouseRepository::recordFeedFailure((int) $feed['id'], 'http_4xx', 0);
        $this->assertTrue($failure['stopped']);
        $row = $this->pdo->query('SELECT status, next_sync_at FROM calendar_feeds WHERE id = ' . (int) $feed['id'])->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('error', $row['status']);
        $this->assertSame('infinity', (string) $row['next_sync_at']);

        HouseRepository::markFeedPending($this->propertyId, (int) $feed['id']);
        $row = $this->pdo->query('SELECT status, fail_count FROM calendar_feeds WHERE id = ' . (int) $feed['id'])->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('pending_verification', $row['status']);
        $this->assertSame(0, (int) $row['fail_count']);

        $this->pdo->prepare("UPDATE calendar_feeds SET status='active', verified_at=to_timestamp(?) WHERE id=?")
            ->execute([$this->now, (int) $feed['id']]);
        HouseRepository::recordFeedFailure((int) $feed['id'], 'http_4xx', 0);
        HouseRepository::markFeedPending($this->propertyId, (int) $feed['id']);
        $this->assertSame('active', $this->pdo->query('SELECT status FROM calendar_feeds WHERE id = ' . (int) $feed['id'])->fetchColumn());
    }

    public function testActiveStatusWithoutVerifiedAtDoesNotProveOwnership(): void
    {
        $this->addFeed('https://ical.booking.com/v1/export?t=legacy-active');
        $feed = $this->feedBySlot(1);
        // A pre-39 sync feed can be active while still unverified.
        $this->pdo->prepare("UPDATE calendar_feeds SET status = 'active', verified_at = NULL WHERE id = ?")
            ->execute([(int) $feed['id']]);

        OtaVerificationService::$httpTransport = fn (): string => $this->emptyIcs();
        $result = HouseRepository::verifyFeed($this->propertyId, (int) $feed['id']);
        $this->assertSame('not_yet_reflected', $result['status']);
        $this->assertFalse(OtaVerificationService::hasVerifiedPlatform($this->propertyId));
        $this->assertSame(
            'active',
            $this->pdo->query('SELECT status FROM calendar_feeds WHERE id = ' . (int) $feed['id'])->fetchColumn()
        );
    }
}
