<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/src/Db.php';
require_once dirname(__DIR__, 2) . '/src/ActivityLog.php';
require_once dirname(__DIR__, 2) . '/src/CalendarSync.php';
require_once dirname(__DIR__, 2) . '/src/OtaPlatformRegistry.php';
require_once dirname(__DIR__, 2) . '/src/OtaFeedGate.php';
require_once dirname(__DIR__, 2) . '/src/OtaVerificationService.php';
require_once dirname(__DIR__, 2) . '/src/HouseRepository.php';
require_once dirname(__DIR__, 2) . '/src/helpers.php';

final class OtaUrlBindingTest extends TestCase
{
    private const T0 = 1795176000; // 2026-11-20 12:00:00 UTC

    private PDO $pdo;
    private int $now;
    private int $userId;
    private int $propertyId;

    protected function setUp(): void
    {
        putenv('APP_ENV=testing');
        Db::loadEnv();
        $this->pdo = db();
        $this->pdo->beginTransaction();
        $this->now = self::T0;
        OtaVerificationService::$clock = fn (): int => $this->now;
        OtaVerificationService::$httpTransport = fn (): string => $this->emptyIcs();
        OtaFeedGate::$shareTransport = null;
        $this->userId = $this->makeUser();
        $this->propertyId = $this->makeProperty($this->userId, 'Principal');
    }

    protected function tearDown(): void
    {
        OtaVerificationService::$httpTransport = null;
        OtaVerificationService::$clock = null;
        OtaFeedGate::$shareTransport = null;
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    private function makeUser(): int
    {
        $email = 'ota+' . bin2hex(random_bytes(5)) . '@test.local';
        $stmt = $this->pdo->prepare(
            "INSERT INTO users (name, cpf, address, phone, email, password_hash, status)
             VALUES ('Anfitrião OTA', '52998224725', 'Rua A, 1', '48999999999', ?, 'x', 'active')
             RETURNING id"
        );
        $stmt->execute([$email]);
        return (int) $stmt->fetchColumn();
    }

    private function makeProperty(int $userId, string $title): int
    {
        $city = (int) $this->pdo->query("SELECT id FROM places WHERE type = 'city' ORDER BY id LIMIT 1")->fetchColumn();
        $this->assertGreaterThan(0, $city);
        $stmt = $this->pdo->prepare(
            "INSERT INTO properties (user_id, title, description, summary, city_place_id, address,
                lat, lng, bedrooms, beds, max_guests, nightly_price, status)
             VALUES (?, ?, 'Descrição', 'Resumo', ?, 'Rua A, 1', -27.6, -48.5, 2, 2, 4, 250, 'active')
             RETURNING id"
        );
        $stmt->execute([$userId, $title, $city]);
        return (int) $stmt->fetchColumn();
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

    private function bookingUrl(string $token, string $slug): array
    {
        return [
            'https://ical.booking.com/v1/export?t=' . $token,
            'https://www.booking.com/hotel/br/' . $slug . '.html',
        ];
    }

    private function airbnbUrl(int $room): array
    {
        return [
            'https://www.airbnb.com/calendar/ical/' . $room . '.ics',
            'https://www.airbnb.com/rooms/' . $room,
        ];
    }

    private function feed(int $propertyId, int $feedId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM calendar_feeds WHERE id = ? AND property_id = ?');
        $stmt->execute([$feedId, $propertyId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    }

    private function expectGate(string $field, callable $fn): void
    {
        try {
            $fn();
            $this->fail('gate should have rejected');
        } catch (OtaFeedGateException $e) {
            $this->assertSame($field, $e->field, $e->getMessage());
        }
    }

    public function testGateRejectsMissingPlatformCrossPlatformSpoofedHostAndPms(): void
    {
        [$ical, $public] = $this->airbnbUrl(1001);

        $this->expectGate('platform', fn () => HouseRepository::addFeed(
            $this->propertyId, $this->userId, $ical, 'Airbnb', 'ota', '', $public, true
        ));
        $this->expectGate('property_url', fn () => HouseRepository::addFeed(
            $this->propertyId, $this->userId, $ical, 'Airbnb', 'ota', 'airbnb',
            'https://www.booking.com/hotel/br/casa.html', true
        ));
        $this->expectGate('property_url', fn () => HouseRepository::addFeed(
            $this->propertyId, $this->userId, $ical, 'Airbnb', 'ota', 'airbnb',
            'https://www.airbnb.com.evil.test/rooms/1001', true
        ));
        $this->expectGate('property_url', fn () => HouseRepository::addFeed(
            $this->propertyId, $this->userId, $ical, 'Airbnb', 'ota', 'airbnb',
            'https://www.airbnb.com:8443/rooms/1001', true
        ));
        $this->expectGate('property_url', fn () => HouseRepository::addFeed(
            $this->propertyId, $this->userId, $ical, 'Airbnb', 'ota', 'airbnb',
            'https://user@www.airbnb.com/rooms/1001', true
        ));
        $this->expectGate('property_url', fn () => HouseRepository::addFeed(
            $this->propertyId, $this->userId, $ical, 'Airbnb', 'ota', 'airbnb',
            'https://www.airbnb.com/users/1001', true
        ));
        $this->expectGate('external_ical_url', fn () => HouseRepository::addFeed(
            $this->propertyId, $this->userId, 'https://stays.net/ical/x.ics', 'PMS', 'ota',
            'airbnb', 'https://www.airbnb.com/rooms/1001', true
        ));
        $this->assertSame(0, (int) $this->pdo->query(
            'SELECT count(*) FROM calendar_feeds WHERE property_id = ' . $this->propertyId
        )->fetchColumn(), 'nothing is persisted before the gate passes');
    }

    public function testSyncOnlyAcceptsPmsButNeverGetsChallengeOrBadge(): void
    {
        $result = HouseRepository::addFeed(
            $this->propertyId,
            $this->userId,
            'https://stays.net/ical/' . bin2hex(random_bytes(4)) . '.ics',
            'Stays'
        );
        $this->assertIsArray($result);
        $feed = $this->feed($this->propertyId, (int) $result['feed']['id']);
        $this->assertSame('sync_only', $feed['verification_mode']);
        $this->assertSame('active', $feed['status']);
        $this->assertNull($feed['challenge_date']);
        $this->assertNull($feed['verified_at']);
        $this->assertFalse(OtaVerificationService::hasVerifiedPlatform($this->propertyId));

        $verify = HouseRepository::verifyFeed($this->propertyId, (int) $feed['id']);
        $this->assertSame('not_ota', $verify['status']);
    }

    public function testAirbnbCrossMatchCanonicalizationAndSharedLinks(): void
    {
        $this->assertSame(
            'airbnb:123',
            OtaFeedGate::canonicalize('airbnb', '/rooms/123', 'https://www.airbnb.com/calendar/ical/123.ics')
        );
        $this->expectGate('property_url', fn () => OtaFeedGate::canonicalize(
            'airbnb',
            '/rooms/123',
            'https://www.airbnb.com/calendar/ical/999.ics'
        ));

        OtaFeedGate::$shareTransport = fn (): array => [
            'status' => 302,
            'location' => 'https://www.airbnb.com/rooms/555?adults=2',
        ];
        $this->assertSame(
            'https://www.airbnb.com/rooms/555?adults=2',
            OtaFeedGate::resolveMobileShareUrl('airbnb', 'https://abnb.me/abc')
        );

        OtaFeedGate::$shareTransport = fn (): array => [
            'status' => 302,
            'location' => 'https://evil.test/rooms/555',
        ];
        $this->expectGate('property_url', fn () => OtaFeedGate::resolveMobileShareUrl('airbnb', 'https://abnb.me/abc'));

        OtaFeedGate::$shareTransport = fn (): array => [
            'status' => 302,
            'location' => 'https://www.booking.com/hotel/br/casa-share.html',
        ];
        $this->assertSame(
            'https://www.booking.com/hotel/br/casa-share.html',
            OtaFeedGate::resolveMobileShareUrl('booking', 'https://www.booking.com/Share-abc')
        );

        OtaFeedGate::$shareTransport = fn (): array => [
            'status' => 302,
            'location' => 'https://www.airbnb.com/search?q=1',
        ];
        try {
            OtaFeedGate::resolveMobileShareUrl('airbnb', 'https://abnb.me/search');
            $this->fail('a non-listing share target must be rejected');
        } catch (OtaFeedGateException $e) {
            $this->assertSame('property_url', $e->field);
            $this->assertSame(t('ota.err_path'), $e->getMessage());
        }
    }

    public function testShareLinkFeedStoresCanonicalPublicUrl(): void
    {
        OtaFeedGate::$shareTransport = fn (): array => [
            'status' => 302,
            'location' => 'https://www.airbnb.com/rooms/777?adults=2',
        ];
        $result = HouseRepository::addFeed(
            $this->propertyId,
            $this->userId,
            'https://www.airbnb.com/calendar/ical/777.ics',
            'Airbnb',
            'ota',
            'airbnb',
            'https://abnb.me/abc',
            true
        );
        $this->assertIsArray($result);
        $feed = $this->feed($this->propertyId, (int) $result['feed']['id']);
        $this->assertSame('airbnb:777', $feed['canonical_listing_id']);
        $this->assertSame('https://www.airbnb.com/rooms/777', $feed['property_url']);
    }

    public function testBookingReviewHoldsBadgeAndCreditsUntilApproval(): void
    {
        [$ical, $public] = $this->bookingUrl('review-' . bin2hex(random_bytes(3)), 'casa-review');
        $created = HouseRepository::addFeed(
            $this->propertyId, $this->userId, $ical, 'Booking', 'ota', 'booking', $public, true
        );
        $this->assertIsArray($created);
        $feed = $this->feed($this->propertyId, (int) $created['feed']['id']);
        $this->assertSame('pending_review', $feed['review_status']);
        $this->assertSame('booking:br/casa-review', $feed['canonical_listing_id']);

        $date = (string) $feed['challenge_date'];
        $this->now += OtaVerificationService::CHECK_COOLDOWN_SECONDS + 1;
        OtaVerificationService::$httpTransport = fn (): string => $this->icsWithBlocked($date);
        $verified = HouseRepository::verifyFeed($this->propertyId, (int) $feed['id']);
        $this->assertSame('pending_review', $verified['status']);
        $this->assertFalse($verified['verified']);
        $this->assertFalse($verified['credits_awarded']);
        $this->assertFalse(OtaVerificationService::hasVerifiedPlatform($this->propertyId));

        $pending = OtaVerificationService::listPendingReviews();
        $this->assertNotEmpty(array_filter($pending, fn (array $r): bool => (int) $r['id'] === (int) $feed['id']));

        $approved = OtaVerificationService::approveFeed((int) $feed['id'], $this->userId);
        $this->assertSame('approved', $approved['status']);
        $this->assertTrue($approved['credits_awarded']);
        $this->assertTrue(OtaVerificationService::hasVerifiedPlatform($this->propertyId));
        $ledger = $this->pdo->prepare('SELECT canonical_listing_id FROM ota_credit_ledger WHERE property_id = ?');
        $ledger->execute([$this->propertyId]);
        $this->assertSame('booking:br/casa-review', $ledger->fetchColumn());
    }

    public function testVrboRejectionRemovesBadge(): void
    {
        $property = $this->makeProperty($this->userId, 'Vrbo');
        $ical = 'https://www.vrbo.com/icalendar/424242.ics';
        $public = 'https://www.vrbo.com/424242';
        $created = HouseRepository::addFeed($property, $this->userId, $ical, 'Vrbo', 'ota', 'vrbo', $public, true);
        $this->assertIsArray($created);
        $feed = $this->feed($property, (int) $created['feed']['id']);
        $this->assertSame('vrbo:424242', $feed['canonical_listing_id']);
        $date = (string) $feed['challenge_date'];
        $this->now += OtaVerificationService::CHECK_COOLDOWN_SECONDS + 1;
        OtaVerificationService::$httpTransport = fn (): string => $this->icsWithBlocked($date);
        $verified = HouseRepository::verifyFeed($property, (int) $feed['id']);
        $this->assertSame('pending_review', $verified['status']);

        $rejected = OtaVerificationService::rejectFeed((int) $feed['id'], $this->userId, 'Spot-check failed');
        $this->assertSame('rejected', $rejected['status']);
        $fresh = $this->feed($property, (int) $feed['id']);
        $this->assertSame('rejected', $fresh['review_status']);
        $this->assertNull($fresh['verified_at']);
        $this->assertSame('error', $fresh['status']);
        $this->assertFalse(OtaVerificationService::hasVerifiedPlatform($property));
    }

    public function testBookingMultiUnitAllowedForSameHostAndCreditCappedPerCanonical(): void
    {
        $property2 = $this->makeProperty($this->userId, 'Unidade 2');
        $otherUser = $this->makeUser();
        $property3 = $this->makeProperty($otherUser, 'Outro anfitrião');
        $ical1 = 'https://ical.booking.com/v1/export?t=multi-' . bin2hex(random_bytes(3));
        $ical2 = 'https://ical.booking.com/v1/export?t=multi-' . bin2hex(random_bytes(3));
        $ical3 = 'https://ical.booking.com/v1/export?t=multi-' . bin2hex(random_bytes(3));
        $public = 'https://www.booking.com/hotel/br/pousada-unica.html';

        $a = HouseRepository::addFeed($this->propertyId, $this->userId, $ical1, 'U1', 'ota', 'booking', $public, true);
        $b = HouseRepository::addFeed($property2, $this->userId, $ical2, 'U2', 'ota', 'booking', $public, true);
        $this->assertIsArray($a);
        $this->assertIsArray($b);
        $this->expectGate('property_url', fn () => HouseRepository::addFeed(
            $property3, $otherUser, $ical3, 'U3', 'ota', 'booking', $public, true
        ));

        $this->assertSame(
            2,
            (int) $this->pdo->query("SELECT count(*) FROM calendar_feeds WHERE canonical_listing_id = 'booking:br/pousada-unica'")->fetchColumn(),
            'same host keeps two units'
        );
    }

    public function testAirbnbCanonicalCannotBeReusedByAnotherProperty(): void
    {
        $property2 = $this->makeProperty($this->userId, 'Airbnb 2');
        [$ical, $public] = $this->airbnbUrl(919191);
        $a = HouseRepository::addFeed($this->propertyId, $this->userId, $ical, 'A1', 'ota', 'airbnb', $public, true);
        $this->assertIsArray($a);
        $this->expectGate('property_url', fn () => HouseRepository::addFeed(
            $this->propertyId, $this->userId, 'https://www.airbnb.com/calendar/ical/919191.ics?t=same-property', 'A1b', 'ota', 'airbnb', $public, true
        ));
        $this->expectGate('property_url', fn () => HouseRepository::addFeed(
            $property2, $this->userId, 'https://www.airbnb.com/calendar/ical/919191.ics?t=x', 'A2', 'ota', 'airbnb', $public, true
        ));
    }

    public function testPostVerificationLockEditResetsWithoutExtraCreditsAndDeleteRevokesBadge(): void
    {
        [$ical, $public] = $this->airbnbUrl(818181);
        $created = HouseRepository::addFeed($this->propertyId, $this->userId, $ical, 'Airbnb', 'ota', 'airbnb', $public, true);
        $this->assertIsArray($created);
        $feed = $this->feed($this->propertyId, (int) $created['feed']['id']);
        $date = (string) $feed['challenge_date'];
        $this->now += OtaVerificationService::CHECK_COOLDOWN_SECONDS + 1;
        OtaVerificationService::$httpTransport = fn (): string => $this->icsWithBlocked($date);
        $verified = HouseRepository::verifyFeed($this->propertyId, (int) $feed['id']);
        $this->assertSame('verified', $verified['status']);
        $this->assertTrue(OtaVerificationService::hasVerifiedPlatform($this->propertyId));

        $locked = HouseRepository::updateFeed(
            $this->propertyId, $this->userId, (int) $feed['id'], 'ota', 'airbnb',
            'https://www.airbnb.com/rooms/818181', 'https://www.airbnb.com/calendar/ical/818181.ics?t=new',
            'Airbnb', true, false
        );
        $this->assertSame('locked', $locked['status']);

        $same = HouseRepository::updateFeed(
            $this->propertyId, $this->userId, (int) $feed['id'], 'ota', 'airbnb',
            'https://www.airbnb.com/rooms/818181', 'https://www.airbnb.com/calendar/ical/818181.ics?t=same',
            'Airbnb', true, true
        );
        $this->assertSame('updated', $same['status'], 'updating a feed with its own canonical is allowed');
        $this->assertSame(
            'airbnb:818181',
            (string) $this->feed($this->propertyId, (int) $feed['id'])['canonical_listing_id']
        );

        $edited = HouseRepository::updateFeed(
            $this->propertyId, $this->userId, (int) $feed['id'], 'ota', 'airbnb',
            'https://www.airbnb.com/rooms/828282', 'https://www.airbnb.com/calendar/ical/828282.ics',
            'Airbnb', true, true
        );
        $this->assertSame('updated', $edited['status']);
        $fresh = $this->feed($this->propertyId, (int) $feed['id']);
        $this->assertSame('airbnb:828282', $fresh['canonical_listing_id']);
        $this->assertSame('pending_verification', $fresh['status']);
        $this->assertNull($fresh['verified_at']);
        $this->assertNotNull($fresh['challenge_date']);
        $this->assertTrue((bool) $fresh['credits_awarded'], 'credits remain awarded across reset');

        $ledger = $this->pdo->prepare('SELECT count(*) FROM ota_credit_ledger WHERE property_id = ?');
        $ledger->execute([$this->propertyId]);
        $this->assertSame(1, (int) $ledger->fetchColumn());

        HouseRepository::removeFeed($this->propertyId, $this->userId, (int) $feed['id']);
        $this->assertFalse(OtaVerificationService::hasVerifiedPlatform($this->propertyId));
        $ledger->execute([$this->propertyId]);
        $this->assertSame(1, (int) $ledger->fetchColumn(), 'the credit ledger survives feed removal');
    }
}
