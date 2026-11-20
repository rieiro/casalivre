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
        OtaVerificationService::$randomInt = static function () use (&$queue): int {
            return $queue === [] ? 0 : (int) array_shift($queue);
        };
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

    public function testStartChallengePicksOpenDate14To16MonthsAhead(): void
    {
        OtaVerificationService::$clock = static fn (): int => self::T0;
        OtaVerificationService::$httpTransport = fn (): string => $this->airbnbIcs();
        $this->fixedRandom([0, 60]); // first pick (2028-01-20) is blocked, second (2028-03-20) is open

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

        // Re-verification keeps credits_awarded = 1 and never pays again.
        OtaVerificationService::$httpTransport = fn (): string => $this->bookingIcs(false);
        $restart = OtaVerificationService::startChallenge(
            $property['id'],
            'booking',
            'https://www.booking.com/hotel/br/casa-livre.html',
            'https://ical.booking.com/v1/export?t=secret-token'
        );
        $this->assertSame(1, (int) $this->pdo->query(
            'SELECT credits_awarded FROM property_external_verifications WHERE id = ' . (int) $restart['verification_id']
        )->fetchColumn());
        OtaVerificationService::$httpTransport = fn (): string => $this->bookingIcs(true, (string) $restart['challenge_date']);
        $recheck = OtaVerificationService::checkChallenge((int) $restart['verification_id']);
        $this->assertSame('verified', $recheck['status']);
        $this->assertFalse($recheck['credits_awarded']);
        $this->assertSame(1, $hookCalls);
        $this->assertSame(
            $creditsAfter,
            (int) $this->pdo->query('SELECT quote_credits FROM users WHERE id = ' . $property['user_id'])->fetchColumn()
        );

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
            $this->assertStringContainsString('já está verificado', $e->getMessage());
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
            $this->assertStringContainsString('já está verificado', $e->getMessage());
        }
    }
}
