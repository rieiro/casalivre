<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/src/Db.php';
require_once dirname(__DIR__, 2) . '/src/ActivityLog.php';
require_once dirname(__DIR__, 2) . '/src/CalendarSync.php';
require_once dirname(__DIR__, 2) . '/src/OtaVerificationService.php';

final class OtaVerificationServiceTest extends TestCase
{
    private const T0 = 1795176000; // 2026-11-20 12:00:00 UTC

    private PDO $pdo;
    private ?Closure $savedTransport = null;
    private ?Closure $savedClock = null;
    private ?Closure $savedRandom = null;
    private ?Closure $savedCredit = null;

    protected function setUp(): void
    {
        putenv('APP_ENV=testing');
        Db::loadEnv();
        $this->pdo = db();
        $this->pdo->beginTransaction();
        ActivityLog::seedActions($this->pdo);
        $this->savedTransport = OtaVerificationService::$httpTransport;
        $this->savedClock = OtaVerificationService::$clock;
        $this->savedRandom = OtaVerificationService::$randomInt;
        $this->savedCredit = OtaVerificationService::$creditHook;
    }

    protected function tearDown(): void
    {
        OtaVerificationService::$httpTransport = $this->savedTransport;
        OtaVerificationService::$clock = $this->savedClock;
        OtaVerificationService::$randomInt = $this->savedRandom;
        OtaVerificationService::$creditHook = $this->savedCredit;
        try {
            $this->pdo->exec('RESET ROLE');
        } catch (Throwable) {
        }
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    /** @return array{id: int, user_id: int} */
    private function activeProperty(int $offset = 0): array
    {
        $rows = $this->pdo->query(
            "SELECT id, user_id FROM properties WHERE status = 'active' ORDER BY id LIMIT 2"
        )->fetchAll(PDO::FETCH_ASSOC);
        $this->assertArrayHasKey($offset, $rows, 'seed database needs at least two active properties');
        return ['id' => (int) $rows[$offset]['id'], 'user_id' => (int) $rows[$offset]['user_id']];
    }

    private function airbnbIcs(): string
    {
        return implode("\r\n", [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Airbnb Inc//Guest Calendar//EN',
            'BEGIN:VEVENT',
            'UID:airbnb-event-1',
            'DTSTART;VALUE=DATE:20270410',
            'DTEND;VALUE=DATE:20270413',
            'SUMMARY:Reserved',
            'END:VEVENT',
            'BEGIN:VEVENT',
            'UID:booking-utc',
            'DTSTART:20270501T150000Z',
            'DTEND:20270502T110000Z',
            'SUMMARY:Reserved (UTC)',
            'END:VEVENT',
            'BEGIN:VEVENT',
            'UID:cancelled',
            'STATUS:CANCELLED',
            'DTSTART;VALUE=DATE:20270601',
            'DTEND;VALUE=DATE:20270605',
            'SUMMARY:Cancelled',
            'END:VEVENT',
            'BEGIN:VEVENT',
            'UID:transparent',
            'TRANSP:TRANSPARENT',
            'DTSTART;VALUE=DATE:20270610',
            'DTEND;VALUE=DATE:20270612',
            'SUMMARY:Free',
            'END:VEVENT',
            'BEGIN:VEVENT',
            'UID:single-day',
            'DTSTART;VALUE=DATE:20270701',
            'DTEND;VALUE=DATE:20270701',
            'SUMMARY:Single day',
            'END:VEVENT',
            'BEGIN:VEVENT',
            'UID:folded',
            'DTSTA',
            ' RT;VALUE=DATE:20270801',
            'DTEND;VALUE=DATE:20270803',
            'SUMMARY:Folded line',
            'END:VEVENT',
            'BEGIN:VEVENT',
            'UID:blocked-window',
            'DTSTART;VALUE=DATE:20280120',
            'DTEND;VALUE=DATE:20280121',
            'SUMMARY:First pick is blocked',
            'END:VEVENT',
            'END:VCALENDAR',
            '',
        ]);
    }

    private function bookingIcs(bool $blockDate = false, string $date = '2028-03-20'): string
    {
        $compact = str_replace('-', '', $date);
        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Booking.com//Calendar//EN',
            'BEGIN:VEVENT',
            'UID:booking-open',
            'DTSTART;VALUE=DATE:20270101',
            'DTEND;VALUE=DATE:20270105',
            'SUMMARY:Existing reservation',
            'END:VEVENT',
            'BEGIN:VEVENT',
            'UID:booking-cancelled',
            'STATUS:CANCELLED',
            'DTSTART;VALUE=DATE:' . $compact,
            'DTEND;VALUE=DATE:' . $compact,
            'SUMMARY:Cancelled hold',
            'END:VEVENT',
        ];
        if ($blockDate) {
            $next = (new DateTimeImmutable($date))->modify('+1 day')->format('Ymd');
            $lines = array_merge($lines, [
                'BEGIN:VEVENT',
                'UID:booking-challenge',
                'DTSTART;VALUE=DATE:' . $compact,
                'DTEND;VALUE=DATE:' . $next,
                'SUMMARY:Host blocked the challenge day',
                'END:VEVENT',
            ]);
        }
        $lines[] = 'END:VCALENDAR';
        $lines[] = '';
        return implode("\r\n", $lines);
    }

    /** @param list<int> $offsets */
    private function fixedRandom(array $offsets): void
    {
        $queue = $offsets;
        $last = $offsets === [] ? 0 : (int) $offsets[0];
        OtaVerificationService::$randomInt = static function (int $min, int $max) use (&$queue, &$last): int {
            if ($queue !== []) {
                $last = (int) array_shift($queue);
            }
            return max($min, min($max, $last));
        };
    }

    private function fullyBlockedIcs(): string
    {
        return implode("\r\n", [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'BEGIN:VEVENT',
            'UID:all-dates-blocked',
            'DTSTART;VALUE=DATE:20261201',
            'DTEND;VALUE=DATE:20280501',
            'SUMMARY:No open dates in the challenge window',
            'END:VEVENT',
            'END:VCALENDAR',
            '',
        ]);
    }

    /**
     * Reads audit rows through the retention role. Returns null when the test login cannot SET ROLE
     * (the shared dev database login is not a member; ops/dev-env/test-pg.sh does grant it).
     *
     * @param callable(PDO): mixed $fn
     */
    private function withAuditRole(callable $fn): mixed
    {
        try {
            $this->pdo->exec('SET ROLE casalivre_retention');
        } catch (PDOException) {
            return null;
        }
        try {
            return $fn($this->pdo);
        } finally {
            try {
                $this->pdo->exec('RESET ROLE');
            } catch (Throwable) {
            }
        }
    }


    /** @param list<array{0: string, 1: string, 2?: string}> $events */
    private function icsWithEvents(array $events, string $prodid = '-//CasaLivre//Test//EN', string $name = 'Casa Teste'): string
    {
        $lines = ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:' . $prodid, 'X-WR-CALNAME:' . $name];
        foreach ($events as $i => $event) {
            $lines[] = 'BEGIN:VEVENT';
            $lines[] = 'UID:test-event-' . $i;
            $lines[] = 'DTSTART;VALUE=DATE:' . $event[0];
            $lines[] = 'DTEND;VALUE=DATE:' . $event[1];
            if (isset($event[2])) {
                $lines[] = 'SUMMARY:' . $event[2];
            }
            $lines[] = 'END:VEVENT';
        }
        $lines[] = 'END:VCALENDAR';
        $lines[] = '';
        return implode("\r\n", $lines);
    }

    /** @return array{0: array{id:int,user_id:int}, 1: array{id:int,user_id:int}} */
    private function twoPropertiesSameOwner(): array
    {
        $owner = $this->pdo->query(
            "SELECT user_id FROM properties WHERE status = 'active'
              GROUP BY user_id HAVING count(*) >= 2 ORDER BY user_id LIMIT 1"
        )->fetchColumn();
        $this->assertNotFalse($owner, 'seed database needs two active properties with the same owner');
        $stmt = $this->pdo->prepare(
            "SELECT id, user_id FROM properties WHERE status = 'active' AND user_id = ? ORDER BY id LIMIT 2"
        );
        $stmt->execute([(int) $owner]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $this->assertCount(2, $rows);
        return [
            ['id' => (int) $rows[0]['id'], 'user_id' => (int) $rows[0]['user_id']],
            ['id' => (int) $rows[1]['id'], 'user_id' => (int) $rows[1]['user_id']],
        ];
    }

    /** @return array{id:int,user_id:int} */
    private function propertyOfAnotherOwner(int $userId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT id, user_id FROM properties WHERE status = 'active' AND user_id <> ? ORDER BY id LIMIT 1"
        );
        $stmt->execute([$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $this->assertNotEmpty($row, 'seed database needs a property owned by another host');
        return ['id' => (int) $row['id'], 'user_id' => (int) $row['user_id']];
    }

    public function testIsDateBlockedInIcsHandlesAllDayAndUtcTimestampFormats(): void
    {
        $ics = $this->airbnbIcs();

        $this->assertTrue(OtaVerificationService::isDateBlockedInIcs($ics, '2027-04-10'));
        $this->assertTrue(OtaVerificationService::isDateBlockedInIcs($ics, '2027-04-12'));
        $this->assertFalse(OtaVerificationService::isDateBlockedInIcs($ics, '2027-04-13'), 'DTEND is exclusive');
        $this->assertTrue(OtaVerificationService::isDateBlockedInIcs($ics, '2027-05-01'), 'UTC timestamp start');
        $this->assertFalse(OtaVerificationService::isDateBlockedInIcs($ics, '2027-05-02'));
        $this->assertFalse(OtaVerificationService::isDateBlockedInIcs($ics, '2027-06-02'), 'STATUS:CANCELLED is ignored');
        $this->assertFalse(OtaVerificationService::isDateBlockedInIcs($ics, '2027-06-11'), 'TRANSP:TRANSPARENT is ignored');
        $this->assertTrue(OtaVerificationService::isDateBlockedInIcs($ics, '2027-07-01'), 'same-day DTEND blocks one night');
        $this->assertTrue(OtaVerificationService::isDateBlockedInIcs($ics, '2027-08-02'), 'folded lines are unfolded');
        $this->assertFalse(OtaVerificationService::isDateBlockedInIcs($ics, '2027-08-03'));
        $this->assertFalse(OtaVerificationService::isDateBlockedInIcs($ics, '2027-04-09'));
    }

    public function testValidateUrlsEnforcesHttpsDomainAllowlistAndAirbnbIdMatch(): void
    {
        OtaVerificationService::validateUrls(
            'airbnb',
            'https://www.airbnb.com/rooms/12345678',
            'https://www.airbnb.com/calendar/ical/12345678.ics?t=secret-token'
        );
        OtaVerificationService::validateUrls(
            'airbnb',
            'https://www.airbnb.com.br/h/12345678',
            'https://www.airbnb.com.br/calendar/ical/12345678.ics'
        );
        OtaVerificationService::validateUrls(
            'booking',
            'https://www.booking.com/hotel/br/casa-livre.html',
            'https://ical.booking.com/v1/export?t=secret-token'
        );
        OtaVerificationService::validateUrls(
            'vrbo',
            'https://www.vrbo.com/1234567',
            'https://www.vrbo.com/icalendar/1234567.ics'
        );

        $invalid = [
            ['airbnb', 'http://www.airbnb.com/rooms/12345678', 'https://www.airbnb.com/calendar/ical/12345678.ics'],
            ['airbnb', 'https://evil.example/rooms/12345678', 'https://www.airbnb.com/calendar/ical/12345678.ics'],
            ['airbnb', 'https://www.airbnb.com/rooms/11111111', 'https://www.airbnb.com/calendar/ical/22222222.ics'],
            ['booking', 'https://evil.example/hotel.html', 'https://ical.booking.com/v1/export?t=x'],
            ['booking', 'https://www.booking.com/hotel.html', 'https://evil.example/export.ics'],
            ['expedia', 'https://www.expedia.com/1', 'https://www.expedia.com/1.ics'],
        ];
        foreach ($invalid as [$platform, $publicUrl, $icalUrl]) {
            try {
                OtaVerificationService::validateUrls($platform, $publicUrl, $icalUrl);
                $this->fail('accepted invalid tuple: ' . $platform . ' ' . $publicUrl . ' ' . $icalUrl);
            } catch (InvalidArgumentException $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }
    }

    public function testStartChallengePicksOpenDateOneToFourMonthsAhead(): void
    {
        // Updated: 2026-11 - Requirement changed from a 1-4 month window to a 14-16 month
        // primary window with 3-6 and 1-4 fallbacks; this test covers the primary window.
        OtaVerificationService::$clock = static fn (): int => self::T0;
        OtaVerificationService::$httpTransport = fn (): string => $this->airbnbIcs();
        $this->fixedRandom([0, 90]); // first pick (2028-01-20) is blocked; retry clamps to 2028-03-20

        $property = $this->activeProperty();
        $result = OtaVerificationService::startChallenge(
            $property['id'],
            'airbnb',
            'https://www.airbnb.com/rooms/12345678',
            'https://www.airbnb.com/calendar/ical/12345678.ics?t=secret-token'
        );

        $this->assertSame('2028-03-20', $result['challenge_date']);
        $this->assertSame('20/03/2028', $result['challenge_date_br']);
        $this->assertSame('pending_challenge', $result['status']);
        $this->assertGreaterThan(0, (int) $result['verification_id']);

        $row = $this->pdo->prepare('SELECT * FROM property_external_verifications WHERE id = ?');
        $row->execute([(int) $result['verification_id']]);
        $stored = $row->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('pending_challenge', $stored['status']);
        $this->assertSame('2028-03-20', (string) $stored['challenge_date']);
        $this->assertSame(0, (int) $stored['attempts']);
        $this->assertSame(0, (int) $stored['credits_awarded']);

        $payload = $this->withAuditRole(function (PDO $pdo) use ($property): ?array {
            $audit = $pdo->prepare(
                "SELECT payload::text FROM audit.audit_log WHERE code = 53 AND payload->>'imovel_id' = ? ORDER BY created_at DESC LIMIT 1"
            );
            $audit->execute([(string) $property['id']]);
            $decoded = json_decode((string) $audit->fetchColumn(), true);
            return is_array($decoded) ? $decoded : null;
        });
        if ($payload === null) {
            $this->addToAssertionCount(1); // shared dev DB login cannot SET ROLE; test-pg.sh covers it
        } else {
            $this->assertSame('airbnb', $payload['plataforma']);
            $this->assertSame('2028-03-20', $payload['challenge_date']);
            $this->assertStringContainsString('www.airbnb.com#', (string) $payload['url']);
            $this->assertStringNotContainsString('secret-token', (string) $payload['url'], 'iCal token must never be logged');
        }
    }

    public function testCheckChallengeEnforces60SecondCooldownAndReturnsNotYetReflected(): void
    {
        $now = self::T0;
        OtaVerificationService::$clock = static function () use (&$now): int {
            return $now;
        };
        OtaVerificationService::$httpTransport = fn (): string => $this->airbnbIcs();
        $this->fixedRandom([60]);

        $property = $this->activeProperty();
        $start = OtaVerificationService::startChallenge(
            $property['id'],
            'airbnb',
            'https://www.airbnb.com/rooms/12345678',
            'https://www.airbnb.com/calendar/ical/12345678.ics'
        );

        $first = OtaVerificationService::checkChallenge((int) $start['verification_id']);
        $this->assertSame('not_yet_reflected', $first['status']);
        $this->assertSame(1, $first['attempts']);
        $this->assertSame(60, $first['retry_after']);

        $now += 10;
        $second = OtaVerificationService::checkChallenge((int) $start['verification_id']);
        $this->assertSame('cooldown', $second['status']);
        $this->assertSame(50, $second['retry_after']);

        $now += 60;
        $third = OtaVerificationService::checkChallenge((int) $start['verification_id']);
        $this->assertSame('not_yet_reflected', $third['status']);
        $this->assertSame(2, $third['attempts']);

        $row = $this->pdo->prepare('SELECT attempts, status FROM property_external_verifications WHERE id = ?');
        $row->execute([(int) $start['verification_id']]);
        $stored = $row->fetch(PDO::FETCH_ASSOC);
        $this->assertSame(2, (int) $stored['attempts']);
        $this->assertSame('pending_challenge', $stored['status']);
    }

    public function testCheckChallengeCompletesVerificationUnlocksBadgeAndAwardsCreditsIdempotently(): void
    {
        $now = self::T0;
        OtaVerificationService::$clock = static function () use (&$now): int {
            return $now;
        };
        OtaVerificationService::$httpTransport = fn (): string => $this->bookingIcs(false);
        $this->fixedRandom([60, 60, 60]);

        $property = $this->activeProperty();
        $creditsBefore = (int) $this->pdo->query(
            'SELECT quote_credits FROM users WHERE id = ' . $property['user_id']
        )->fetchColumn();

        $start = OtaVerificationService::startChallenge(
            $property['id'],
            'booking',
            'https://www.booking.com/hotel/br/casa-livre.html',
            'https://ical.booking.com/v1/export?t=secret-token'
        );
        $verificationId = (int) $start['verification_id'];
        $challengeDate = (string) $start['challenge_date'];

        OtaVerificationService::$httpTransport = fn (): string => $this->bookingIcs(true, $challengeDate);
        $hookCalls = 0;
        OtaVerificationService::$creditHook = static function () use (&$hookCalls): void {
            $hookCalls++;
        };

        $done = OtaVerificationService::checkChallenge($verificationId);
        $this->assertSame('verified', $done['status']);
        $this->assertTrue($done['credits_awarded']);
        $this->assertSame($challengeDate, $done['challenge_date']);
        $this->assertSame(1, $hookCalls);

        $creditsAfter = (int) $this->pdo->query(
            'SELECT quote_credits FROM users WHERE id = ' . $property['user_id']
        )->fetchColumn();
        $this->assertSame($creditsBefore + OtaVerificationService::ONBOARDING_CREDIT_REWARD, $creditsAfter);

        $again = OtaVerificationService::checkChallenge($verificationId);
        $this->assertSame('verified', $again['status']);
        $this->assertSame(1, (int) $again['credits_awarded']);
        $this->assertSame(1, $hookCalls, 'a verified challenge cannot award credits twice');
        $this->assertSame(
            $creditsAfter,
            (int) $this->pdo->query('SELECT quote_credits FROM users WHERE id = ' . $property['user_id'])->fetchColumn()
        );

        // Updated: 2026-11 - Verified rows are now locked by the fraud gate; a new challenge is rejected.
        OtaVerificationService::$httpTransport = fn (): string => $this->bookingIcs(false);
        try {
            OtaVerificationService::startChallenge(
                $property['id'],
                'booking',
                'https://www.booking.com/hotel/br/casa-livre.html',
                'https://ical.booking.com/v1/export?t=secret-token'
            );
            $this->fail('a verified platform must be locked for changes');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('já está verificado e bloqueado', $e->getMessage());
        }
        $this->assertSame(1, (int) $this->pdo->query(
            'SELECT credits_awarded FROM property_external_verifications WHERE id = ' . $verificationId
        )->fetchColumn());
        $this->assertSame(1, $hookCalls);
        $this->assertSame(
            $creditsAfter,
            (int) $this->pdo->query('SELECT quote_credits FROM users WHERE id = ' . $property['user_id'])->fetchColumn()
        );

        // Updated: 2026-11 - Verified status is derived from calendar_feeds (unified challenge).
        $this->pdo->prepare('DELETE FROM calendar_feeds WHERE property_id = ?')->execute([$property['id']]);
        $feedIcal = 'https://ical.booking.com/v1/export?t=legacy-ota';
        $this->pdo->prepare(
            "INSERT INTO calendar_feeds
                (property_id, slot, url, url_hash, title, source_platform, status, verified_at, next_sync_at, last_status)
             VALUES (?, 1, ?, ?, 'Booking.com', 'booking', 'active', to_timestamp(?), now(), 'PENDENTE')"
        )->execute([$property['id'], $feedIcal, hash('sha256', $feedIcal), self::T0]);
        $verified = OtaVerificationService::listVerifiedForProperty($property['id']);
        $this->assertNotEmpty($verified);
        $this->assertSame('booking', $verified[0]['platform']);
        $this->assertArrayNotHasKey('ical_url', $verified[0]);
        $this->assertTrue(OtaVerificationService::hasVerifiedPlatform($property['id']));

        $auditCount = $this->withAuditRole(function (PDO $pdo) use ($property): ?int {
            $audit = $pdo->prepare(
                "SELECT count(*) FROM audit.audit_log WHERE code = 54 AND payload->>'imovel_id' = ?"
            );
            $audit->execute([(string) $property['id']]);
            return (int) $audit->fetchColumn();
        });
        if ($auditCount === null) {
            $this->addToAssertionCount(1);
        } else {
            $this->assertGreaterThan(0, $auditCount);
        }
    }

    public function testPreventsDuplicateUrlAcrossMultipleProperties(): void
    {
        OtaVerificationService::$clock = static fn (): int => self::T0;
        OtaVerificationService::$httpTransport = fn (): string => $this->airbnbIcs();
        $this->fixedRandom([60]);

        $a = $this->activeProperty(0);
        $b = $this->activeProperty(1);

        $start = OtaVerificationService::startChallenge(
            $a['id'],
            'airbnb',
            'https://www.airbnb.com/rooms/12345678',
            'https://www.airbnb.com/calendar/ical/12345678.ics'
        );
        OtaVerificationService::$httpTransport = fn (): string => $this->bookingIcs(true, (string) $start['challenge_date']);
        $done = OtaVerificationService::checkChallenge((int) $start['verification_id']);
        $this->assertSame('verified', $done['status']);

        try {
            OtaVerificationService::startChallenge(
                $b['id'],
                'airbnb',
                'https://www.airbnb.com/rooms/12345678',
                'https://www.airbnb.com/calendar/ical/12345678.ics'
            );
            $this->fail('the same verified Airbnb link must not verify a second property');
        } catch (InvalidArgumentException $e) {
            // Updated: 2026-11 - The global iCal-hash gate reports the calendar already registered.
            $this->assertStringContainsString('já está cadastrado ou verificado', $e->getMessage());
        }

        // A different public URL on the same iCal export is still a duplicate.
        OtaVerificationService::$httpTransport = fn (): string => $this->bookingIcs(false);
        $startB = OtaVerificationService::startChallenge(
            $a['id'],
            'booking',
            'https://www.booking.com/hotel/br/a.html',
            'https://ical.booking.com/v1/export?t=token-a'
        );
        OtaVerificationService::$httpTransport = fn (): string => $this->bookingIcs(true, (string) $startB['challenge_date']);
        OtaVerificationService::checkChallenge((int) $startB['verification_id']);

        try {
            OtaVerificationService::startChallenge(
                $b['id'],
                'booking',
                'https://www.booking.com/hotel/br/b.html',
                'https://ical.booking.com/v1/export?t=token-a'
            );
            $this->fail('the same verified iCal URL must not verify a second property');
        } catch (InvalidArgumentException $e) {
            // Updated: 2026-11 - Same iCal export is rejected by the global hash gate.
            $this->assertStringContainsString('já está cadastrado ou verificado', $e->getMessage());
        }
    }

    public function testAddGenerateAndRemoveChallengeFlow(): void
    {
        $this->assertSame(55, ActivityLog::OTA_VERIFY_ADD);
        $this->assertSame(56, ActivityLog::OTA_VERIFY_REMOVE);

        OtaVerificationService::$clock = static fn (): int => self::T0;
        OtaVerificationService::$httpTransport = fn (): string => $this->airbnbIcs();
        $this->fixedRandom([90]);

        $property = $this->activeProperty();
        $publicUrl = 'https://www.airbnb.com/rooms/12345678';
        $icalUrl = 'https://www.airbnb.com/calendar/ical/12345678.ics';

        $added = OtaVerificationService::addChallenge($property['id'], 'airbnb', $publicUrl, $icalUrl);
        $verificationId = (int) $added['id'];
        $this->assertGreaterThan(0, $verificationId);
        // Updated: 2026-11 - addChallenge now runs the full fraud gate and creates pending_challenge.
        $this->assertSame('pending_challenge', $added['status']);
        $this->assertSame('2028-03-20', $added['challenge_date']);

        $row = $this->pdo->prepare('SELECT * FROM property_external_verifications WHERE id = ?');
        $row->execute([$verificationId]);
        $stored = $row->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('pending_challenge', $stored['status']);
        $this->assertSame('2028-03-20', (string) $stored['challenge_date']);

        $generated = OtaVerificationService::generateChallenge($verificationId);
        $this->assertSame('pending_challenge', $generated['status']);
        $this->assertSame($verificationId, (int) $generated['verification_id']);
        $this->assertSame('2028-03-20', $generated['challenge_date']);

        $rows = OtaVerificationService::listForProperty($property['id']);
        $this->assertCount(1, $rows);
        $this->assertSame('pending_challenge', $rows[0]['status']);
        $this->assertSame('20/03/2028', $rows[0]['challenge_date_br']);

        $other = $this->activeProperty(1);
        try {
            OtaVerificationService::addChallenge($other['id'], 'airbnb', $publicUrl, $icalUrl);
            $this->fail('a pending challenge must block the same link on another property');
        } catch (InvalidArgumentException $e) {
            // Updated: 2026-11 - The global hash gate message.
            $this->assertStringContainsString('já está cadastrado ou verificado', $e->getMessage());
        }

        $this->assertTrue(OtaVerificationService::removeChallenge($verificationId, $property['id']));
        $this->assertFalse(OtaVerificationService::removeChallenge($verificationId, $property['id']));
        $this->assertSame([], OtaVerificationService::listForProperty($property['id']));
    }

    public function testAddChallengeRejectsUrlsOfAnotherPlatform(): void
    {
        $property = $this->activeProperty();
        try {
            OtaVerificationService::addChallenge(
                $property['id'],
                'airbnb',
                'https://www.booking.com/hotel/br/casa.html',
                'https://ical.booking.com/v1/export?t=x'
            );
            $this->fail('booking URLs must not be accepted for the airbnb platform');
        } catch (InvalidArgumentException $e) {
            $this->assertNotSame('', $e->getMessage());
        }
        $this->assertSame([], OtaVerificationService::listForProperty($property['id']));
    }

    public function testAddChallengeDoesNotInsertWhenNoOpenDateExists(): void
    {
        OtaVerificationService::$clock = static fn (): int => self::T0;
        OtaVerificationService::$httpTransport = fn (): string => $this->fullyBlockedIcs();
        $this->fixedRandom([0]);

        $property = $this->activeProperty();

        try {
            OtaVerificationService::addChallenge(
                $property['id'],
                'airbnb',
                'https://www.airbnb.com/rooms/12345678',
                'https://www.airbnb.com/calendar/ical/12345678.ics'
            );
            $this->fail('a fully blocked calendar must not add the challenge');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('datas livres no horizonte', $e->getMessage());
        }

        $this->assertSame([], OtaVerificationService::listForProperty($property['id']));
        $count = $this->pdo->prepare('SELECT count(*) FROM property_external_verifications WHERE property_id = ?');
        $count->execute([$property['id']]);
        $this->assertSame(0, (int) $count->fetchColumn(), 'no row may exist when no date is available');
    }

    public function testStage1RejectsMissingConsentPmsDomainsProfileUrlsAndCustomPorts(): void
    {
        $property = $this->activeProperty();
        $public = 'https://www.airbnb.com/rooms/12345678';
        $ical = 'https://www.airbnb.com/calendar/ical/12345678.ics';

        try {
            OtaVerificationService::validateAndCreateChallenge(
                $property['id'],
                $property['user_id'],
                'airbnb',
                $public,
                $ical,
                false
            );
            $this->fail('missing consent must be rejected');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('autoriza', $e->getMessage());
        }

        $invalid = [
            ['airbnb', 'https://stays.net/rooms/12345678', $ical],
            ['airbnb', 'https://www.airbnb.com/users/show/12345678', $ical],
            ['airbnb', 'https://www.airbnb.com:8443/rooms/12345678', $ical],
            ['airbnb', $public, 'https://stays.net/calendar/ical/12345678.ics'],
            ['booking', 'https://www.booking.com/searchresults.html', 'https://ical.booking.com/v1/export?t=x'],
            ['vrbo', 'https://www.vrbo.com/', 'https://www.vrbo.com/icalendar/123.ics'],
        ];
        foreach ($invalid as [$platform, $publicUrl, $icalUrl]) {
            try {
                OtaVerificationService::validateAndCreateChallenge(
                    $property['id'],
                    $property['user_id'],
                    $platform,
                    $publicUrl,
                    $icalUrl,
                    true
                );
                $this->fail('Stage 1 must reject: ' . $platform . ' ' . $publicUrl);
            } catch (InvalidArgumentException $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }

        $count = $this->pdo->prepare('SELECT count(*) FROM property_external_verifications WHERE property_id = ?');
        $count->execute([$property['id']]);
        $this->assertSame(0, (int) $count->fetchColumn());
    }

    public function testStage2EnforcesDailyRateLimitGlobalIcalHashAndBookingMultiUnitPousadaRule(): void
    {
        OtaVerificationService::$clock = static fn (): int => self::T0;
        OtaVerificationService::$httpTransport = fn (): string => $this->icsWithEvents([]);
        $this->fixedRandom([60]);

        [$a, $b] = $this->twoPropertiesSameOwner();
        $c = $this->propertyOfAnotherOwner($a['user_id']);
        $userId = $a['user_id'];

        // Daily cap: rows survive challenge deletion, so pre-seed five attempts.
        $attempt = $this->pdo->prepare(
            'INSERT INTO ota_challenge_attempts (user_id, property_id, platform, created_at)
             VALUES (?, ?, ?, to_timestamp(?))'
        );
        for ($i = 0; $i < OtaVerificationService::MAX_DAILY_CHALLENGES_PER_USER; $i++) {
            $attempt->execute([$userId, $a['id'], 'airbnb', self::T0]);
        }
        try {
            OtaVerificationService::validateAndCreateChallenge(
                $a['id'],
                $userId,
                'airbnb',
                'https://www.airbnb.com/rooms/900001',
                'https://www.airbnb.com/calendar/ical/900001.ics'
            );
            $this->fail('the sixth challenge in 24h must be rejected');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('Limite diário', $e->getMessage());
        }
        $this->pdo->prepare('DELETE FROM ota_challenge_attempts WHERE user_id = ?')->execute([$userId]);

        // Global iCal hash: the same feed cannot be registered anywhere else.
        $vrboIcal = 'https://www.vrbo.com/icalendar/777.ics';
        $this->pdo->prepare(
            "INSERT INTO property_external_verifications
                (property_id, platform, public_url, ical_url, status, canonical_listing_id,
                 ical_url_hash, credits_awarded, created_at, updated_at)
             VALUES (?, 'vrbo', 'https://www.vrbo.com/777', ?, 'verified', 'vrbo:777', ?, 1, now(), now())"
        )->execute([$a['id'], $vrboIcal, hash('sha256', $vrboIcal)]);

        try {
            OtaVerificationService::validateAndCreateChallenge(
                $b['id'],
                $userId,
                'vrbo',
                'https://www.vrbo.com/888',
                $vrboIcal
            );
            $this->fail('the same iCal feed must not be registered twice');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('já está cadastrado ou verificado', $e->getMessage());
        }

        // Booking multi-unit pousada: same host may share the canonical hotel id with distinct feeds.
        $pousadaPublic = 'https://www.booking.com/hotel/br/pousada.html';
        $unitA = 'https://ical.booking.com/v1/export?t=unit-a';
        $this->pdo->prepare(
            "INSERT INTO property_external_verifications
                (property_id, platform, public_url, ical_url, status, canonical_listing_id,
                 ical_url_hash, credits_awarded, created_at, updated_at)
             VALUES (?, 'booking', ?, ?, 'verified', 'booking:br/pousada', ?, 1, now(), now())"
        )->execute([$a['id'], $pousadaPublic, $unitA, hash('sha256', $unitA)]);

        $allowed = OtaVerificationService::validateAndCreateChallenge(
            $b['id'],
            $userId,
            'booking',
            $pousadaPublic,
            'https://ical.booking.com/v1/export?t=unit-b'
        );
        $this->assertSame('pending_challenge', $allowed['status']);
        $this->assertGreaterThan(0, (int) $allowed['verification_id']);

        // Defect 1 regression: the second unit verifies without uq_pev_verified_public_url failing.
        $allowedDate = (string) $allowed['challenge_date'];
        $allowedDt = new DateTimeImmutable($allowedDate . ' 00:00:00', new DateTimeZone('UTC'));
        OtaVerificationService::$httpTransport = fn (): string => $this->icsWithEvents([
            [$allowedDt->format('Ymd'), $allowedDt->modify('+1 day')->format('Ymd'), 'Host block'],
        ]);
        $verifiedUnitB = OtaVerificationService::checkChallenge((int) $allowed['verification_id'], true, $b['id']);
        $this->assertSame('verified', $verifiedUnitB['status']);

        // A different host still cannot claim the same canonical listing.
        try {
            OtaVerificationService::validateAndCreateChallenge(
                $c['id'],
                $c['user_id'],
                'booking',
                $pousadaPublic,
                'https://ical.booking.com/v1/export?t=unit-c'
            );
            $this->fail('another host must not claim the same canonical listing');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('outro anfitrião', $e->getMessage());
        }
    }

    public function testStage3RequiresDMinus1DAndDPlus7OpenAndExtractsIcsMetadata(): void
    {
        $property = $this->activeProperty();
        OtaVerificationService::$clock = static fn (): int => self::T0;
        // 2028-01-20 is the primary candidate; its D+7 (2028-01-27) is blocked, so the retry picks the window max.
        OtaVerificationService::$httpTransport = fn (): string => $this->icsWithEvents(
            [['20280127', '20280128', 'Reserved']],
            '-//Booking.com//Test//EN',
            'Casa Teste'
        );
        $this->fixedRandom([0, 60]);

        $result = OtaVerificationService::validateAndCreateChallenge(
            $property['id'],
            $property['user_id'],
            'booking',
            'https://www.booking.com/hotel/br/test.html',
            'https://ical.booking.com/v1/export?t=metadata'
        );
        $this->assertSame('2028-03-20', $result['challenge_date']);

        $row = $this->pdo->prepare('SELECT * FROM property_external_verifications WHERE id = ?');
        $row->execute([(int) $result['verification_id']]);
        $stored = $row->fetch(PDO::FETCH_ASSOC);
        $this->assertStringContainsString('PRODID:-//Booking.com//Test//EN', (string) $stored['ics_metadata']);
        $this->assertStringContainsString('X-WR-CALNAME:Casa Teste', (string) $stored['ics_metadata']);
        $this->assertLessThanOrEqual(200, strlen((string) $stored['ics_metadata']));
        $this->assertStringStartsWith('enc:v1:', (string) $stored['ical_url']);
        $plain = 'https://ical.booking.com/v1/export?t=metadata';
        $this->assertSame($plain, OtaVerificationService::decryptIcalUrl((string) $stored['ical_url']));
        $this->assertSame(hash('sha256', $plain), (string) $stored['ical_url_hash']);
    }

    public function testCheckChallengeRequiresIsolatedManualBlockAndRejectsGuestReservedSummaryOrMaxAttempts(): void
    {
        $property = $this->activeProperty();
        OtaVerificationService::$clock = static fn (): int => self::T0;
        OtaVerificationService::$httpTransport = fn (): string => $this->icsWithEvents([]);
        $this->fixedRandom([60]);

        $start = OtaVerificationService::startChallenge(
            $property['id'],
            'booking',
            'https://www.booking.com/hotel/br/isolated.html',
            'https://ical.booking.com/v1/export?t=isolated'
        );
        $verificationId = (int) $start['verification_id'];
        $date = (string) $start['challenge_date'];
        $dt = new DateTimeImmutable($date . ' 00:00:00', new DateTimeZone('UTC'));

        // D-1 also blocked -> not an isolated manual block.
        OtaVerificationService::$httpTransport = fn (): string => $this->icsWithEvents([
            [$dt->modify('-1 day')->format('Ymd'), $dt->modify('+1 day')->format('Ymd'), 'Host block'],
        ]);
        $notIsolated = OtaVerificationService::checkChallenge($verificationId, true, $property['id']);
        $this->assertSame('not_isolated_block', $notIsolated['status']);

        // D only, but the event is a guest reservation.
        OtaVerificationService::$httpTransport = fn (): string => $this->icsWithEvents([
            [$dt->format('Ymd'), $dt->modify('+1 day')->format('Ymd'), 'Reserved (guest)'],
        ]);
        $guest = OtaVerificationService::checkChallenge($verificationId, true, $property['id']);
        $this->assertSame('not_isolated_block', $guest['status']);

        // D only, manual host block -> verified.
        OtaVerificationService::$httpTransport = fn (): string => $this->icsWithEvents([
            [$dt->format('Ymd'), $dt->modify('+1 day')->format('Ymd'), 'Host block'],
        ]);
        $done = OtaVerificationService::checkChallenge($verificationId, true, $property['id']);
        $this->assertSame('verified', $done['status']);

        // Max attempts: a row at 15 attempts expires without another fetch.
        $ical = 'https://www.vrbo.com/icalendar/999.ics';
        $stmt = $this->pdo->prepare(
            "INSERT INTO property_external_verifications
                (property_id, platform, public_url, ical_url, status, challenge_date,
                 challenge_expires_at, attempts, canonical_listing_id, ical_url_hash, created_at, updated_at)
             VALUES (?, 'vrbo', 'https://www.vrbo.com/999', ?, 'pending_challenge', ?,
                     to_timestamp(?), ?, 'vrbo:999', ?, now(), now())
             RETURNING id"
        );
        $stmt->execute([
            $property['id'],
            $ical,
            $date,
            self::T0 + 3600,
            OtaVerificationService::MAX_CHECK_ATTEMPTS,
            hash('sha256', $ical),
        ]);
        $maxId = (int) $stmt->fetchColumn();
        $expired = OtaVerificationService::checkChallenge($maxId, true, $property['id']);
        $this->assertSame('expired', $expired['status']);
        $this->assertStringContainsString('limite de 15', $expired['message']);
    }

    public function testPermanentCreditLedgerPreventsCreditFarmingAfterRowDeletion(): void
    {
        $property = $this->activeProperty();
        OtaVerificationService::$clock = static fn (): int => self::T0;
        OtaVerificationService::$httpTransport = fn (): string => $this->icsWithEvents([]);
        $this->fixedRandom([60]);
        $hookCalls = 0;
        OtaVerificationService::$creditHook = static function () use (&$hookCalls): void {
            $hookCalls++;
        };
        $creditsBefore = (int) $this->pdo->query(
            'SELECT quote_credits FROM users WHERE id = ' . $property['user_id']
        )->fetchColumn();

        $start = OtaVerificationService::startChallenge(
            $property['id'],
            'booking',
            'https://www.booking.com/hotel/br/ledger.html',
            'https://ical.booking.com/v1/export?t=ledger'
        );
        $date = (string) $start['challenge_date'];
        $dt = new DateTimeImmutable($date . ' 00:00:00', new DateTimeZone('UTC'));
        OtaVerificationService::$httpTransport = fn (): string => $this->icsWithEvents([
            [$dt->format('Ymd'), $dt->modify('+1 day')->format('Ymd'), 'Host block'],
        ]);
        $verified = OtaVerificationService::checkChallenge((int) $start['verification_id']);
        $this->assertSame('verified', $verified['status']);
        $this->assertTrue($verified['credits_awarded']);
        $creditsAfter = (int) $this->pdo->query(
            'SELECT quote_credits FROM users WHERE id = ' . $property['user_id']
        )->fetchColumn();
        $this->assertSame($creditsBefore + OtaVerificationService::ONBOARDING_CREDIT_REWARD, $creditsAfter);
        $this->assertSame(1, $hookCalls);

        // Simulate a rogue/legacy deletion of the verified row: the ledger must survive.
        $this->pdo->prepare('DELETE FROM property_external_verifications WHERE id = ?')
            ->execute([(int) $start['verification_id']]);
        $ledger = $this->pdo->prepare('SELECT credits FROM ota_credit_ledger WHERE property_id = ?');
        $ledger->execute([$property['id']]);
        $this->assertSame(OtaVerificationService::ONBOARDING_CREDIT_REWARD, (int) $ledger->fetchColumn());

        // A brand new verification on the same property must not pay again.
        OtaVerificationService::$httpTransport = fn (): string => $this->icsWithEvents([]);
        $second = OtaVerificationService::startChallenge(
            $property['id'],
            'booking',
            'https://www.booking.com/hotel/br/ledger.html',
            'https://ical.booking.com/v1/export?t=ledger'
        );
        $secondDate = (string) $second['challenge_date'];
        $dt2 = new DateTimeImmutable($secondDate . ' 00:00:00', new DateTimeZone('UTC'));
        OtaVerificationService::$httpTransport = fn (): string => $this->icsWithEvents([
            [$dt2->format('Ymd'), $dt2->modify('+1 day')->format('Ymd'), 'Host block'],
        ]);
        $reverified = OtaVerificationService::checkChallenge((int) $second['verification_id']);
        $this->assertSame('verified', $reverified['status']);
        $this->assertFalse($reverified['credits_awarded']);
        $this->assertSame(1, $hookCalls);
        $this->assertSame(
            $creditsAfter,
            (int) $this->pdo->query('SELECT quote_credits FROM users WHERE id = ' . $property['user_id'])->fetchColumn()
        );
    }

    public function testPassiveSyncDowngradesVerifiedListingAfter3ConsecutiveDaysOfHttp404or410(): void
    {
        $property = $this->activeProperty();
        $now = self::T0;
        OtaVerificationService::$clock = static function () use (&$now): int {
            return $now;
        };

        $ical = 'https://ical.booking.com/v1/export?t=passive';
        $stmt = $this->pdo->prepare(
            "INSERT INTO property_external_verifications
                (property_id, platform, public_url, ical_url, status, challenge_date,
                 challenge_expires_at, verified_at, credits_awarded, canonical_listing_id,
                 ical_url_hash, fail_count, last_passive_check_at, created_at, updated_at)
             VALUES (?, 'booking', 'https://www.booking.com/hotel/br/passive.html', ?, 'verified',
                     '2028-03-20', to_timestamp(?), to_timestamp(?), 1, 'booking:br/passive', ?, 0, NULL,
                     now(), now())
             RETURNING id"
        );
        $stmt->execute([
            $property['id'],
            $ical,
            self::T0 + 3600,
            self::T0,
            hash('sha256', $ical),
        ]);
        $verificationId = (int) $stmt->fetchColumn();

        OtaVerificationService::$httpTransport = static function (): string {
            throw new RuntimeException('not found', 404);
        };
        $day1 = OtaVerificationService::passiveVerifyRow($verificationId);
        $this->assertSame('failed', $day1['status']);
        $this->assertSame(1, $day1['fail_count']);

        // A valid 200 resets the consecutive failure counter.
        $now += 86400;
        OtaVerificationService::$httpTransport = fn (): string => $this->icsWithEvents([]);
        $reset = OtaVerificationService::passiveVerifyRow($verificationId);
        $this->assertSame('ok', $reset['status']);

        $now += 86400;
        OtaVerificationService::$httpTransport = static function (): string {
            throw new RuntimeException('not found', 404);
        };
        $day3 = OtaVerificationService::passiveVerifyRow($verificationId);
        $this->assertSame(1, $day3['fail_count']);

        $now += 86400;
        OtaVerificationService::$httpTransport = static function (): string {
            throw new RuntimeException('not found', 404);
        };
        $day4 = OtaVerificationService::passiveVerifyRow($verificationId);
        $this->assertSame(2, $day4['fail_count']);

        $now += 86400;
        OtaVerificationService::$httpTransport = static function (): string {
            throw new RuntimeException('gone', 410);
        };
        $day5 = OtaVerificationService::passiveVerifyRow($verificationId);
        $this->assertSame('downgraded', $day5['status']);

        $row = $this->pdo->prepare('SELECT status, verified_at, fail_count FROM property_external_verifications WHERE id = ?');
        $row->execute([$verificationId]);
        $stored = $row->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('unverified', $stored['status']);
        $this->assertNull($stored['verified_at']);
        $this->assertSame(3, (int) $stored['fail_count']);
    }

    public function testPickOpenDateScansTheWindowWhenRandomAttemptsAreBlocked(): void
    {
        $property = $this->activeProperty();
        OtaVerificationService::$clock = static fn (): int => self::T0;
        OtaVerificationService::$httpTransport = fn (): string => $this->icsWithEvents([
            ['20280101', '20280210'], // blocks through 2028-02-09
            ['20280219', '20280501'], // blocks from 2028-02-19
        ]);
        $this->fixedRandom([0]); // every random attempt hits the blocked 2028-01-20

        $result = OtaVerificationService::validateAndCreateChallenge(
            $property['id'],
            $property['user_id'],
            'booking',
            'https://www.booking.com/hotel/br/scan.html',
            'https://ical.booking.com/v1/export?t=scan'
        );
        // The deterministic scan finds the first date whose D-1, D and D+7 are open.
        $this->assertSame('2028-02-11', $result['challenge_date']);
        $this->assertSame('pending_challenge', $result['status']);
    }

    public function testPublicUrlIsStoredWithoutQueryFragmentOrTrailingSlash(): void
    {
        $property = $this->activeProperty();
        OtaVerificationService::$clock = static fn (): int => self::T0;
        OtaVerificationService::$httpTransport = fn (): string => $this->icsWithEvents([]);
        $this->fixedRandom([60]);

        $result = OtaVerificationService::validateAndCreateChallenge(
            $property['id'],
            $property['user_id'],
            'airbnb',
            'https://www.airbnb.com/rooms/12345678/?affiliate=aff&check_in=2027#frag',
            'https://www.airbnb.com/calendar/ical/12345678.ics?t=secret'
        );
        $row = $this->pdo->prepare('SELECT public_url, ical_url FROM property_external_verifications WHERE id = ?');
        $row->execute([(int) $result['verification_id']]);
        $stored = $row->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('https://www.airbnb.com/rooms/12345678', (string) $stored['public_url']);
        $this->assertSame(
            'https://www.airbnb.com/calendar/ical/12345678.ics?t=secret',
            OtaVerificationService::decryptIcalUrl((string) $stored['ical_url'])
        );
    }

    public function testStage4RechecksDailyLimitAfterTheNetworkCall(): void
    {
        $property = $this->activeProperty();
        $userId = $property['user_id'];
        OtaVerificationService::$clock = static fn (): int => self::T0;
        $this->fixedRandom([60]);

        // Simulate a concurrent request that consumes the daily cap during the 8s fetch.
        OtaVerificationService::$httpTransport = function () use ($property, $userId): string {
            $stmt = $this->pdo->prepare(
                'INSERT INTO ota_challenge_attempts (user_id, property_id, platform, created_at)
                 VALUES (?, ?, ?, to_timestamp(?))'
            );
            for ($i = 0; $i < OtaVerificationService::MAX_DAILY_CHALLENGES_PER_USER; $i++) {
                $stmt->execute([$userId, $property['id'], 'booking', self::T0]);
            }
            return $this->icsWithEvents([]);
        };

        try {
            OtaVerificationService::validateAndCreateChallenge(
                $property['id'],
                $userId,
                'booking',
                'https://www.booking.com/hotel/br/race-daily.html',
                'https://ical.booking.com/v1/export?t=race-daily'
            );
            $this->fail('the transaction must re-check the daily limit after the fetch');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('Limite diário', $e->getMessage());
        }

        $count = $this->pdo->prepare(
            "SELECT count(*) FROM property_external_verifications WHERE property_id = ? AND platform = 'booking'"
        );
        $count->execute([$property['id']]);
        $this->assertSame(0, (int) $count->fetchColumn());
    }

    public function testStage4RejectsAConcurrentlyVerifiedPlatform(): void
    {
        $property = $this->activeProperty();
        $public = 'https://www.booking.com/hotel/br/race-lock.html';
        $ical = 'https://ical.booking.com/v1/export?t=race-lock';
        OtaVerificationService::$clock = static fn (): int => self::T0;
        $this->fixedRandom([60]);

        // Stage 2 passes, then the row becomes verified while the network fetch runs.
        OtaVerificationService::$httpTransport = function () use ($property, $public, $ical): string {
            $this->pdo->prepare(
                "INSERT INTO property_external_verifications
                    (property_id, platform, public_url, ical_url, status, canonical_listing_id,
                     ical_url_hash, credits_awarded, created_at, updated_at)
                 VALUES (?, 'booking', ?, ?, 'verified', 'booking:br/race-lock', ?, 1, now(), now())"
            )->execute([$property['id'], $public, $ical, hash('sha256', $ical)]);
            return $this->icsWithEvents([]);
        };

        try {
            OtaVerificationService::validateAndCreateChallenge(
                $property['id'],
                $property['user_id'],
                'booking',
                $public,
                $ical
            );
            $this->fail('a concurrently verified row must block the pending upsert');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('já está verificado e bloqueado', $e->getMessage());
        }
        $row = $this->pdo->prepare(
            "SELECT status FROM property_external_verifications WHERE property_id = ? AND platform = 'booking'"
        );
        $row->execute([$property['id']]);
        $this->assertSame('verified', $row->fetchColumn());
    }

    public function testStage4RejectsAConcurrentCrossHostCanonicalListing(): void
    {
        [$a] = $this->twoPropertiesSameOwner();
        $other = $this->propertyOfAnotherOwner($a['user_id']);
        $public = 'https://www.booking.com/hotel/br/race-canonical.html';
        $ical = 'https://ical.booking.com/v1/export?t=race-canonical';
        OtaVerificationService::$clock = static fn (): int => self::T0;
        $this->fixedRandom([60]);

        OtaVerificationService::$httpTransport = function () use ($other, $public): string {
            $otherIcal = 'https://ical.booking.com/v1/export?t=race-canonical-other';
            $this->pdo->prepare(
                "INSERT INTO property_external_verifications
                    (property_id, platform, public_url, ical_url, status, canonical_listing_id,
                     ical_url_hash, credits_awarded, created_at, updated_at)
                 VALUES (?, 'booking', ?, ?, 'verified', 'booking:br/race-canonical', ?, 1, now(), now())"
            )->execute([$other['id'], $public, $otherIcal, hash('sha256', $otherIcal)]);
            return $this->icsWithEvents([]);
        };

        try {
            OtaVerificationService::validateAndCreateChallenge(
                $a['id'],
                $a['user_id'],
                'booking',
                $public,
                $ical
            );
            $this->fail('the transaction must re-check the canonical listing lock');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('outro anfitrião', $e->getMessage());
        }
    }

    public function testCheckChallengeRejectsAConcurrentCrossHostCanonicalVerification(): void
    {
        [$a] = $this->twoPropertiesSameOwner();
        $other = $this->propertyOfAnotherOwner($a['user_id']);
        $public = 'https://www.booking.com/hotel/br/race-check.html';
        $ical = 'https://ical.booking.com/v1/export?t=race-check';
        OtaVerificationService::$clock = static fn (): int => self::T0;
        OtaVerificationService::$httpTransport = fn (): string => $this->icsWithEvents([]);
        $this->fixedRandom([60]);

        $start = OtaVerificationService::startChallenge($a['id'], 'booking', $public, $ical);
        $date = (string) $start['challenge_date'];
        $dt = new DateTimeImmutable($date . ' 00:00:00', new DateTimeZone('UTC'));

        OtaVerificationService::$httpTransport = function () use ($other, $public, $dt): string {
            $otherIcal = 'https://ical.booking.com/v1/export?t=race-check-other';
            $this->pdo->prepare(
                "INSERT INTO property_external_verifications
                    (property_id, platform, public_url, ical_url, status, canonical_listing_id,
                     ical_url_hash, credits_awarded, created_at, updated_at)
                 VALUES (?, 'booking', ?, ?, 'verified', 'booking:br/race-check', ?, 1, now(), now())"
            )->execute([$other['id'], $public, $otherIcal, hash('sha256', $otherIcal)]);
            return $this->icsWithEvents([
                [$dt->format('Ymd'), $dt->modify('+1 day')->format('Ymd'), 'Host block'],
            ]);
        };

        try {
            OtaVerificationService::checkChallenge((int) $start['verification_id'], true, $a['id']);
            $this->fail('checking must re-check the canonical listing lock');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('outro anfitrião', $e->getMessage());
        }
        $row = $this->pdo->prepare('SELECT status FROM property_external_verifications WHERE id = ?');
        $row->execute([(int) $start['verification_id']]);
        $this->assertSame('pending_challenge', $row->fetchColumn());
    }

    public function testRetentionPurgesOldChallengeAttemptsOnly(): void
    {
        $property = $this->activeProperty();
        $now = self::T0;
        OtaVerificationService::$clock = static fn (): int => $now;
        $insert = $this->pdo->prepare(
            'INSERT INTO ota_challenge_attempts (user_id, property_id, platform, created_at)
             VALUES (?, ?, ?, to_timestamp(?))'
        );
        $insert->execute([$property['user_id'], $property['id'], 'airbnb', $now - 8 * 86400]);
        $insert->execute([$property['user_id'], $property['id'], 'booking', $now - 86400]);

        $this->assertSame(1, OtaVerificationService::purgeOldChallengeAttempts());
        $count = $this->pdo->prepare('SELECT count(*) FROM ota_challenge_attempts WHERE user_id = ?');
        $count->execute([$property['user_id']]);
        $this->assertSame(1, (int) $count->fetchColumn());
        $old = $this->pdo->prepare(
            'SELECT count(*) FROM ota_challenge_attempts WHERE created_at <= to_timestamp(?)'
        );
        $old->execute([$now - 7 * 86400]);
        $this->assertSame(0, (int) $old->fetchColumn());
    }
}
