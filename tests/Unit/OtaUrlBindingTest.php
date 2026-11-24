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
require_once dirname(__DIR__, 2) . '/src/Acoes/ICalFeedRemove.php';
require_once dirname(__DIR__, 2) . '/src/PublicListing.php';
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
        $dateBr = date('d/m/Y', strtotime($date));
        $this->assertSame($dateBr, $verified['challenge_date_br']);
        $this->assertSame(
            t('cal.review_pending_detail', ['platform' => 'Booking.com', 'date' => $dateBr]),
            $verified['message']
        );
        $this->assertFalse(OtaVerificationService::hasVerifiedPlatform($this->propertyId));

        $pending = OtaVerificationService::listPendingReviews();
        $pendingRow = array_values(array_filter($pending, fn (array $r): bool => (int) $r['id'] === (int) $feed['id']))[0] ?? [];
        $this->assertSame('Booking.com', $pendingRow['platform_label'] ?? null);
        $this->assertSame($dateBr, $pendingRow['challenge_date_br'] ?? null);
        $this->assertArrayHasKey('property_city', $pendingRow);
        $this->assertArrayHasKey('ics_metadata', $pendingRow);

        $beforeEdit = $this->feed($this->propertyId, (int) $feed['id']);
        $locked = HouseRepository::updateFeed(
            $this->propertyId, $this->userId, (int) $feed['id'], 'ota', 'booking',
            'https://www.booking.com/hotel/br/other-review.html',
            'https://ical.booking.com/v1/export?t=other-review',
            'Booking', true, true
        );
        $this->assertSame('pending_review_locked', $locked['status']);
        $afterEdit = $this->feed($this->propertyId, (int) $feed['id']);
        $this->assertSame($beforeEdit['property_url'], $afterEdit['property_url']);
        $this->assertSame($beforeEdit['url'], $afterEdit['url']);

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

        $noReason = OtaVerificationService::rejectFeed((int) $feed['id'], $this->userId, '   ');
        $this->assertSame('reason_required', $noReason['status']);

        $rejected = OtaVerificationService::rejectFeed((int) $feed['id'], $this->userId, 'Spot-check failed');
        $this->assertSame('rejected', $rejected['status']);
        $fresh = $this->feed($property, (int) $feed['id']);
        $this->assertSame('rejected', $fresh['review_status']);
        $this->assertNull($fresh['verified_at']);
        $this->assertSame('error', $fresh['status']);
        $this->assertFalse(OtaVerificationService::hasVerifiedPlatform($property));

        $again = OtaVerificationService::rejectFeed((int) $feed['id'], $this->userId, 'Again');
        $this->assertSame('invalid_state', $again['status']);
        $invalidApprove = OtaVerificationService::approveFeed((int) $feed['id'], $this->userId);
        $this->assertSame('invalid_state', $invalidApprove['status']);
        $ledger = $this->pdo->prepare('SELECT count(*) FROM ota_credit_ledger WHERE property_id = ?');
        $ledger->execute([$property]);
        $this->assertSame(0, (int) $ledger->fetchColumn(), 'invalid-state approve must not award credits');

        $unlocked = HouseRepository::updateFeed(
            $property, $this->userId, (int) $feed['id'], 'ota', 'vrbo',
            'https://www.vrbo.com/424243', 'https://www.vrbo.com/icalendar/424243.ics',
            'Vrbo', true, true
        );
        $this->assertSame('updated', $unlocked['status'], 'a rejected feed can be fixed and restarted');
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

        // Updated: 2026-11 - Requirement changed from confirmed reset/delete to strict read-only lock on verified OTA feeds.
        $before = $this->feed($this->propertyId, (int) $feed['id']);
        foreach ([false, true] as $confirm) {
            $locked = HouseRepository::updateFeed(
                $this->propertyId, $this->userId, (int) $feed['id'], 'ota', 'airbnb',
                'https://www.airbnb.com/rooms/828282', 'https://www.airbnb.com/calendar/ical/828282.ics',
                'Airbnb', true, $confirm
            );
            $this->assertSame('locked', $locked['status'], 'verified feeds stay locked even with confirm');
        }
        $gone = HouseRepository::removeFeed($this->propertyId, $this->userId, (int) $feed['id']);
        $this->assertSame('locked', $gone['status']);
        $this->assertNull($gone['feed']);
        $this->assertSame([], $gone['nights']);
        $this->assertSame([], $gone['link']);
        $after = $this->feed($this->propertyId, (int) $feed['id']);
        $this->assertSame($before['url'], $after['url']);
        $this->assertSame($before['property_url'], $after['property_url']);
        $this->assertSame('active', $after['status']);
        $this->assertTrue(OtaVerificationService::hasVerifiedPlatform($this->propertyId));

        $ledger = $this->pdo->prepare('SELECT count(*) FROM ota_credit_ledger WHERE property_id = ?');
        $ledger->execute([$this->propertyId]);
        $this->assertSame(1, (int) $ledger->fetchColumn());

        // Support intervention: direct DB delete, then the host re-verifies the same feed for zero extra credits.
        $this->pdo->prepare('DELETE FROM calendar_feeds WHERE id = ?')->execute([(int) $feed['id']]);
        $this->assertFalse(OtaVerificationService::hasVerifiedPlatform($this->propertyId));
        $ledger->execute([$this->propertyId]);
        $this->assertSame(1, (int) $ledger->fetchColumn(), 'the credit ledger survives the support deletion');

        $this->now += 86400;
        $readded = HouseRepository::addFeed(
            $this->propertyId, $this->userId, $ical, 'Airbnb', 'ota', 'airbnb', $public, true
        );
        $this->assertIsArray($readded);
        $newFeed = $this->feed($this->propertyId, (int) $readded['feed']['id']);
        $reVerified = $this->verifyFeedWithBlock($this->propertyId, $newFeed);
        $this->assertSame('verified', $reVerified['status']);
        $this->assertFalse($reVerified['credits_awarded'], 're-verification after support deletion pays zero');
        $ledger->execute([$this->propertyId]);
        $this->assertSame(1, (int) $ledger->fetchColumn());
    }

    private function credits(): int
    {
        return (int) $this->pdo->query('SELECT quote_credits FROM users WHERE id = ' . $this->userId)->fetchColumn();
    }

    private function verifyFeedWithBlock(int $propertyId, array $feed): array
    {
        $this->now += OtaVerificationService::CHECK_COOLDOWN_SECONDS + 1;
        OtaVerificationService::$httpTransport = fn (): string => $this->icsWithBlocked((string) $feed['challenge_date']);
        return HouseRepository::verifyFeed($propertyId, (int) $feed['id']);
    }

    public function testOncePerPlatformCreditsAndPermanentAntiFarmingLedger(): void
    {
        $start = $this->credits();

        // Property A: one reward for each platform.
        [$airIcal, $airPublic] = $this->airbnbUrl(111111);
        $air = HouseRepository::addFeed($this->propertyId, $this->userId, $airIcal, 'Airbnb', 'ota', 'airbnb', $airPublic, true);
        $this->assertIsArray($air);
        $airFeed = $this->feed($this->propertyId, (int) $air['feed']['id']);
        $airVerified = $this->verifyFeedWithBlock($this->propertyId, $airFeed);
        $this->assertTrue($airVerified['credits_awarded']);

        [$bookIcal, $bookPublic] = $this->bookingUrl('credit-book', 'casa-credit');
        $book = HouseRepository::addFeed($this->propertyId, $this->userId, $bookIcal, 'Booking', 'ota', 'booking', $bookPublic, true);
        $this->assertIsArray($book);
        $bookFeed = $this->feed($this->propertyId, (int) $book['feed']['id']);
        $bookVerified = $this->verifyFeedWithBlock($this->propertyId, $bookFeed);
        $this->assertSame('pending_review', $bookVerified['status']);
        $bookApproved = OtaVerificationService::approveFeed((int) $bookFeed['id'], $this->userId);
        $this->assertSame('approved', $bookApproved['status']);
        $this->assertTrue($bookApproved['credits_awarded']);

        $vrboIcal = 'https://www.vrbo.com/icalendar/444111.ics';
        $vrboPublic = 'https://www.vrbo.com/444111';
        $vrbo = HouseRepository::addFeed($this->propertyId, $this->userId, $vrboIcal, 'Vrbo', 'ota', 'vrbo', $vrboPublic, true);
        $this->assertIsArray($vrbo);
        $vrboFeed = $this->feed($this->propertyId, (int) $vrbo['feed']['id']);
        $this->verifyFeedWithBlock($this->propertyId, $vrboFeed);
        $vrboApproved = OtaVerificationService::approveFeed((int) $vrboFeed['id'], $this->userId);
        $this->assertSame('approved', $vrboApproved['status']);
        $this->assertTrue($vrboApproved['credits_awarded']);

        $this->assertSame($start + 15, $this->credits(), 'Airbnb + Booking + Vrbo award once each');
        $ledger = $this->pdo->prepare('SELECT platform FROM ota_credit_ledger WHERE property_id = ? ORDER BY platform');
        $ledger->execute([$this->propertyId]);
        $this->assertSame(['airbnb', 'booking', 'vrbo'], $ledger->fetchAll(PDO::FETCH_COLUMN));

        // Updated: 2026-11 - Requirement changed from confirmed reset/delete to strict read-only lock on verified OTA feeds.
        $lockedEdit = HouseRepository::updateFeed(
            $this->propertyId, $this->userId, (int) $airFeed['id'], 'ota', 'airbnb',
            'https://www.airbnb.com/rooms/111112', 'https://www.airbnb.com/calendar/ical/111112.ics',
            'Airbnb', true, true
        );
        $this->assertSame('locked', $lockedEdit['status'], 'verified feeds cannot be edited by the host');
        $this->pdo->prepare('DELETE FROM calendar_feeds WHERE id = ?')->execute([(int) $airFeed['id']]);
        $this->now += 86400;
        [$airIcal2, $airPublic2] = $this->airbnbUrl(111112);
        $air2 = HouseRepository::addFeed(
            $this->propertyId, $this->userId, $airIcal2, 'Airbnb', 'ota', 'airbnb', $airPublic2, true
        );
        $this->assertIsArray($air2);
        $airFeed2 = $this->feed($this->propertyId, (int) $air2['feed']['id']);
        $reVerified = $this->verifyFeedWithBlock($this->propertyId, $airFeed2);
        $this->assertSame('verified', $reVerified['status']);
        $this->assertFalse($reVerified['credits_awarded']);
        $this->assertSame($start + 15, $this->credits(), 're-verification after support reset does not pay twice');

        // Same Booking canonical on another property of the same host: approval succeeds, no credits.
        $propertyB = $this->makeProperty($this->userId, 'Booking B');
        $book2 = HouseRepository::addFeed(
            $propertyB, $this->userId, 'https://ical.booking.com/v1/export?t=credit-book-2',
            'Booking B', 'ota', 'booking', $bookPublic, true
        );
        $this->assertIsArray($book2);
        $book2Feed = $this->feed($propertyB, (int) $book2['feed']['id']);
        $this->verifyFeedWithBlock($propertyB, $book2Feed);
        $book2Approved = OtaVerificationService::approveFeed((int) $book2Feed['id'], $this->userId);
        $this->assertSame('approved', $book2Approved['status']);
        $this->assertFalse($book2Approved['credits_awarded'], 'multi-unit Booking canonical is paid only once');
        $this->assertSame($start + 15, $this->credits());

        // Platform switch on a fresh property: Vrbo pays once, switching back to Airbnb pays zero.
        $propertyD = $this->makeProperty($this->userId, 'Switch');
        [$switchIcal, $switchPublic] = $this->airbnbUrl(555001);
        $switch = HouseRepository::addFeed($propertyD, $this->userId, $switchIcal, 'Switch', 'ota', 'airbnb', $switchPublic, true);
        $this->assertIsArray($switch);
        $switchFeed = $this->feed($propertyD, (int) $switch['feed']['id']);
        $this->verifyFeedWithBlock($propertyD, $switchFeed);
        $this->assertSame($start + 20, $this->credits());

        // Updated: 2026-11 - Requirement changed from confirmed reset/delete to strict read-only lock on verified OTA feeds.
        $lockedSwitch = HouseRepository::updateFeed(
            $propertyD, $this->userId, (int) $switchFeed['id'], 'ota', 'vrbo',
            'https://www.vrbo.com/555001', 'https://www.vrbo.com/icalendar/555001.ics',
            'Switch', true, true
        );
        $this->assertSame('locked', $lockedSwitch['status']);
        $this->pdo->prepare('DELETE FROM calendar_feeds WHERE id = ?')->execute([(int) $switchFeed['id']]);
        $this->now += 86400;
        $vrboAdd = HouseRepository::addFeed(
            $propertyD, $this->userId, 'https://www.vrbo.com/icalendar/555001.ics',
            'Switch', 'ota', 'vrbo', 'https://www.vrbo.com/555001', true
        );
        $this->assertIsArray($vrboAdd);
        $vrboSwitchFeed = $this->feed($propertyD, (int) $vrboAdd['feed']['id']);
        $this->verifyFeedWithBlock($propertyD, $vrboSwitchFeed);
        $vrboSwitch = OtaVerificationService::approveFeed((int) $vrboSwitchFeed['id'], $this->userId);
        $this->assertTrue($vrboSwitch['credits_awarded'], 'first Vrbo on this property pays');
        $this->assertSame($start + 25, $this->credits());

        $lockedBack = HouseRepository::updateFeed(
            $propertyD, $this->userId, (int) $vrboSwitchFeed['id'], 'ota', 'airbnb',
            'https://www.airbnb.com/rooms/555003', 'https://www.airbnb.com/calendar/ical/555003.ics',
            'Switch', true, true
        );
        $this->assertSame('locked', $lockedBack['status']);
        $this->pdo->prepare('DELETE FROM calendar_feeds WHERE id = ?')->execute([(int) $vrboSwitchFeed['id']]);
        $this->now += 86400;
        [$backIcal, $backPublic] = $this->airbnbUrl(555003);
        $backAdd = HouseRepository::addFeed(
            $propertyD, $this->userId, $backIcal, 'Switch', 'ota', 'airbnb', $backPublic, true
        );
        $this->assertIsArray($backAdd);
        $backFeed = $this->feed($propertyD, (int) $backAdd['feed']['id']);
        $backVerified = $this->verifyFeedWithBlock($propertyD, $backFeed);
        $this->assertSame('verified', $backVerified['status']);
        $this->assertFalse($backVerified['credits_awarded'], 'returning to an already-paid platform pays zero');
        $this->assertSame($start + 25, $this->credits());

        // Delete Property A: the three ledger rows survive with property_id NULL.
        $this->pdo->prepare('DELETE FROM properties WHERE id = ?')->execute([$this->propertyId]);
        $nullRows = $this->pdo->prepare('SELECT platform, canonical_listing_id FROM ota_credit_ledger WHERE property_id IS NULL ORDER BY platform');
        $nullRows->execute();
        $orphans = $nullRows->fetchAll(PDO::FETCH_ASSOC);
        $this->assertCount(3, $orphans);
        $this->assertSame(['airbnb', 'booking', 'vrbo'], array_column($orphans, 'platform'));
        $this->assertNotEmpty($orphans[0]['canonical_listing_id']);

        // A recreated property verifying the same canonical gets the badge but zero credits.
        $propertyC = $this->makeProperty($this->userId, 'Recreated');
        [$reIcal, $rePublic] = $this->airbnbUrl(111111);
        $recreated = HouseRepository::addFeed($propertyC, $this->userId, $reIcal, 'Recreated', 'ota', 'airbnb', $rePublic, true);
        $this->assertIsArray($recreated);
        $recreatedFeed = $this->feed($propertyC, (int) $recreated['feed']['id']);
        $recreatedVerified = $this->verifyFeedWithBlock($propertyC, $recreatedFeed);
        $this->assertSame('verified', $recreatedVerified['status']);
        $this->assertFalse($recreatedVerified['credits_awarded'], 'the permanent ledger blocks recreated listings');
        $this->assertSame($start + 25, $this->credits());
    }

    public function testStrictLockRejectsVerifiedAndPendingReviewFeedsForHosts(): void
    {
        // Verified Airbnb feed (approved automatically).
        [$ical, $public] = $this->airbnbUrl(707070);
        $created = HouseRepository::addFeed($this->propertyId, $this->userId, $ical, 'Airbnb', 'ota', 'airbnb', $public, true);
        $this->assertIsArray($created);
        $verifiedFeed = $this->feed($this->propertyId, (int) $created['feed']['id']);
        $verifiedResult = $this->verifyFeedWithBlock($this->propertyId, $verifiedFeed);
        $this->assertSame('verified', $verifiedResult['status']);
        $verifiedFeed = $this->feed($this->propertyId, (int) $created['feed']['id']);
        $this->assertNotNull($verifiedFeed['verified_at'] ?? null);

        $night = '2099-03-10';
        $this->pdo->prepare(
            "INSERT INTO calendar_days (property_id, day, blocked, origin, feed_id)
             VALUES (?, ?, 1, 'sync', ?)
             ON CONFLICT (property_id, day) DO UPDATE SET blocked = 1, origin = 'sync', feed_id = EXCLUDED.feed_id"
        )->execute([$this->propertyId, $night, (int) $verifiedFeed['id']]);

        $before = $this->feed($this->propertyId, (int) $verifiedFeed['id']);
        $removed = ICalFeedRemove::remover($this->propertyId, $this->userId, (int) $verifiedFeed['id']);
        $this->assertSame('bloqueado', $removed->tipo);
        $this->assertNotSame('', (string) $removed->mensagem);
        $this->assertSame([], $removed->eventos, 'a blocked removal must not write CALENDAR_FEED_REMOVE');

        $after = $this->feed($this->propertyId, (int) $verifiedFeed['id']);
        $this->assertSame($before['url'], $after['url']);
        $this->assertSame($before['property_url'], $after['property_url']);
        $this->assertSame('active', $after['status']);
        $nightRow = $this->pdo->prepare('SELECT blocked, origin, feed_id FROM calendar_days WHERE property_id = ? AND day = ?');
        $nightRow->execute([$this->propertyId, $night]);
        $nightData = $nightRow->fetch(PDO::FETCH_ASSOC) ?: [];
        $this->assertSame(1, (int) $nightData['blocked']);
        $this->assertSame('sync', (string) $nightData['origin']);
        $this->assertSame((int) $verifiedFeed['id'], (int) $nightData['feed_id']);

        foreach ([false, true] as $confirm) {
            $locked = HouseRepository::updateFeed(
                $this->propertyId, $this->userId, (int) $verifiedFeed['id'], 'ota', 'airbnb',
                'https://www.airbnb.com/rooms/707071', 'https://www.airbnb.com/calendar/ical/707071.ics',
                'Airbnb', true, $confirm
            );
            $this->assertSame('locked', $locked['status'], 'confirm never unlocks a verified feed');
        }

        // Pending review Booking feed (verified_at set, review_status pending_review).
        [$bIcal, $bPublic] = $this->bookingUrl('strict-lock', 'casa-strict-lock');
        $bCreated = HouseRepository::addFeed($this->propertyId, $this->userId, $bIcal, 'Booking', 'ota', 'booking', $bPublic, true);
        $this->assertIsArray($bCreated);
        $bFeed = $this->feed($this->propertyId, (int) $bCreated['feed']['id']);
        $bVerified = $this->verifyFeedWithBlock($this->propertyId, $bFeed);
        $this->assertSame('pending_review', $bVerified['status']);

        $bRemoved = ICalFeedRemove::remover($this->propertyId, $this->userId, (int) $bFeed['id']);
        $this->assertSame('bloqueado', $bRemoved->tipo);
        $this->assertSame([], $bRemoved->eventos);
        $bLocked = HouseRepository::updateFeed(
            $this->propertyId, $this->userId, (int) $bFeed['id'], 'ota', 'booking',
            'https://www.booking.com/hotel/br/other-strict-lock.html',
            'https://ical.booking.com/v1/export?t=other-strict-lock',
            'Booking', true, true
        );
        $this->assertSame('pending_review_locked', $bLocked['status']);
        $this->assertNotSame([], $this->feed($this->propertyId, (int) $bFeed['id']));
    }

    public function testUnverifiedAndRejectedOtaFeedsRemainEditableAndRemovable(): void
    {
        // Pending verification: editable and removable.
        [$pIcal, $pPublic] = $this->airbnbUrl(606060);
        $pendingCreated = HouseRepository::addFeed($this->propertyId, $this->userId, $pIcal, 'Pendente', 'ota', 'airbnb', $pPublic, true);
        $this->assertIsArray($pendingCreated);
        $pendingId = (int) $pendingCreated['feed']['id'];
        $edited = HouseRepository::updateFeed(
            $this->propertyId, $this->userId, $pendingId, 'ota', 'airbnb',
            'https://www.airbnb.com/rooms/606061', 'https://www.airbnb.com/calendar/ical/606061.ics',
            'Pendente', true, false
        );
        $this->assertSame('updated', $edited['status']);
        $gone = ICalFeedRemove::remover($this->propertyId, $this->userId, $pendingId);
        $this->assertSame('ok', $gone->tipo);
        $this->assertSame([], $this->feed($this->propertyId, $pendingId));

        // Rejected VRBO stays removable.
        [$rIcal, $rPublic] = ['https://www.vrbo.com/icalendar/505050.ics', 'https://www.vrbo.com/505050'];
        $rejectedCreated = HouseRepository::addFeed($this->propertyId, $this->userId, $rIcal, 'Rejeitado', 'ota', 'vrbo', $rPublic, true);
        $this->assertIsArray($rejectedCreated);
        $rejectedId = (int) $rejectedCreated['feed']['id'];
        $rejectedFeed = $this->feed($this->propertyId, $rejectedId);
        $this->assertSame('pending_review', $this->verifyFeedWithBlock($this->propertyId, $rejectedFeed)['status']);
        $this->assertSame('rejected', OtaVerificationService::rejectFeed($rejectedId, $this->userId, 'Documento não confere')['status']);
        $rejectedGone = ICalFeedRemove::remover($this->propertyId, $this->userId, $rejectedId);
        $this->assertSame('ok', $rejectedGone->tipo);
        $this->assertSame([], $this->feed($this->propertyId, $rejectedId));

        // Rejected VRBO stays editable (support-facing reset through the normal OTA flow).
        $this->now += 86400;
        [$r2Ical, $r2Public] = ['https://www.vrbo.com/icalendar/505052.ics', 'https://www.vrbo.com/505052'];
        $rejected2Created = HouseRepository::addFeed($this->propertyId, $this->userId, $r2Ical, 'Rejeitado 2', 'ota', 'vrbo', $r2Public, true);
        $this->assertIsArray($rejected2Created);
        $rejected2Id = (int) $rejected2Created['feed']['id'];
        $rejected2Feed = $this->feed($this->propertyId, $rejected2Id);
        $this->assertSame('pending_review', $this->verifyFeedWithBlock($this->propertyId, $rejected2Feed)['status']);
        $this->assertSame('rejected', OtaVerificationService::rejectFeed($rejected2Id, $this->userId, 'Documento não confere')['status']);
        $rejectedEdit = HouseRepository::updateFeed(
            $this->propertyId, $this->userId, $rejected2Id, 'ota', 'vrbo',
            'https://www.vrbo.com/505053', 'https://www.vrbo.com/icalendar/505053.ics',
            'Rejeitado 2', true, true
        );
        $this->assertSame('updated', $rejectedEdit['status']);
    }

    private function property(int $propertyId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM properties WHERE id = ?');
        $stmt->execute([$propertyId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    }

    /** Three consecutive 401/403/404/410 responses run the passive downgrade for a verified feed. */
    private function passiveDowngrade(int $feedId): array
    {
        OtaVerificationService::$httpTransport = static function (): string {
            throw new RuntimeException('HTTP 401', 401);
        };
        $result = [];
        for ($i = 0; $i < OtaVerificationService::PASSIVE_DOWNGRADE_FAIL_DAYS; $i++) {
            $this->now += 86401;
            $result = HouseRepository::passiveVerifyFeed($feedId);
        }
        return $result;
    }

    public function testModelBPropertyLevelVerificationFollowsVerifiedMajorFeeds(): void
    {
        // 1. Verifying the first major OTA (Airbnb) marks the property as verified and awards credits.
        [$airIcal, $airPublic] = $this->airbnbUrl(303030);
        $airCreated = HouseRepository::addFeed(
            $this->propertyId, $this->userId, $airIcal, 'Airbnb', 'ota', 'airbnb', $airPublic, true
        );
        $this->assertIsArray($airCreated);
        $airFeed = $this->feed($this->propertyId, (int) $airCreated['feed']['id']);
        $airVerified = $this->verifyFeedWithBlock($this->propertyId, $airFeed);
        $this->assertSame('verified', $airVerified['status']);
        $this->assertTrue($airVerified['credits_awarded']);
        $property = $this->property($this->propertyId);
        $this->assertSame(1, (int) $property['is_verified'], 'Airbnb verification sets properties.is_verified');
        $this->assertNotNull($property['verified_at'], 'properties.verified_at is stamped');
        $airLedger = $this->pdo->prepare('SELECT count(*) FROM ota_credit_ledger WHERE property_id = ? AND platform = ?');
        $airLedger->execute([$this->propertyId, 'airbnb']);
        $this->assertSame(1, (int) $airLedger->fetchColumn(), 'Airbnb credits are awarded');

        // 2. A second major OTA (Booking.com) stays pending_review, then approval keeps the property verified.
        [$bookIcal, $bookPublic] = $this->bookingUrl('model-b-' . bin2hex(random_bytes(3)), 'casa-model-b');
        $bookCreated = HouseRepository::addFeed(
            $this->propertyId, $this->userId, $bookIcal, 'Booking', 'ota', 'booking', $bookPublic, true
        );
        $this->assertIsArray($bookCreated);
        $bookFeed = $this->feed($this->propertyId, (int) $bookCreated['feed']['id']);
        $bookVerified = $this->verifyFeedWithBlock($this->propertyId, $bookFeed);
        $this->assertSame('pending_review', $bookVerified['status']);
        $this->assertSame(1, (int) $this->property($this->propertyId)['is_verified'], 'the property stays verified while Booking awaits review');
        $bookApproved = OtaVerificationService::approveFeed((int) $bookFeed['id'], $this->userId);
        $this->assertSame('approved', $bookApproved['status']);
        $this->assertTrue($bookApproved['credits_awarded'], 'Booking.com credits are awarded on approval');
        $this->assertSame(1, (int) $this->property($this->propertyId)['is_verified']);
        $bookLedger = $this->pdo->prepare('SELECT count(*) FROM ota_credit_ledger WHERE property_id = ? AND platform = ?');
        $bookLedger->execute([$this->propertyId, 'booking']);
        $this->assertSame(1, (int) $bookLedger->fetchColumn());

        $links = OtaVerificationService::listVerifiedForProperty($this->propertyId);
        $this->assertSame(['booking', 'airbnb'], array_column($links, 'platform'));
        $this->assertSame([$bookPublic, $airPublic], array_column($links, 'property_url'), 'only validated public listing URLs are exposed');
        foreach ($links as $link) {
            $this->assertArrayNotHasKey('url', $link);
            $this->assertArrayNotHasKey('url_hash', $link);
            $this->assertArrayNotHasKey('ical_url', $link);
        }
        $listing = public_listing($this->property($this->propertyId));
        $this->assertTrue($listing['is_verified'], 'public listing exposes the property-level badge');
        $this->assertSame(['booking', 'airbnb'], array_column($listing['ota_verifications'], 'platform'));
        $this->assertSame([$bookPublic, $airPublic], array_column($listing['ota_verifications'], 'property_url'));
        $this->assertStringNotContainsString('url_hash', (string) json_encode($listing['ota_verifications']));

        // 3. Airbnb expires (3 consecutive 401s): Booking keeps the property verified, but Airbnb is no longer public.
        $downgraded = $this->passiveDowngrade((int) $airFeed['id']);
        $this->assertSame('downgraded', $downgraded['status']);
        $this->assertSame(1, (int) $this->property($this->propertyId)['is_verified'], 'another active major OTA keeps the badge');
        $this->assertSame(['booking'], array_column(OtaVerificationService::listVerifiedForProperty($this->propertyId), 'platform'));
        $this->assertTrue(public_listing($this->property($this->propertyId))['is_verified']);

        // 4. Booking expires too: no active approved major OTA remains, so the property loses the badge.
        $downgradedBook = $this->passiveDowngrade((int) $bookFeed['id']);
        $this->assertSame('downgraded', $downgradedBook['status']);
        $property = $this->property($this->propertyId);
        $this->assertSame(0, (int) $property['is_verified'], 'the badge drops when no major OTA remains verified');
        $this->assertSame([], OtaVerificationService::listVerifiedForProperty($this->propertyId));
        $this->assertFalse(public_listing($this->property($this->propertyId))['is_verified']);
    }
}
