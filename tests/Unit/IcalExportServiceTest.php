<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/src/Db.php';
require_once dirname(__DIR__, 2) . '/src/CalendarSync.php';
require_once dirname(__DIR__, 2) . '/src/IcalExportService.php';
require_once dirname(__DIR__, 2) . '/src/OtaVerificationService.php';
require_once dirname(__DIR__, 2) . '/src/HouseRepository.php';
require_once dirname(__DIR__, 2) . '/src/helpers.php';

final class IcalExportServiceTest extends TestCase
{
    private PDO $pdo;
    private int $propertyId;
    private int $userId;
    private int $now;
    private int $airbnbFeed;
    private int $bookingFeed;
    /** @var array<string, mixed> */
    private array $property;

    protected function setUp(): void
    {
        putenv('APP_ENV=testing');
        Db::loadEnv();
        $this->pdo = db();
        $this->pdo->beginTransaction();
        $this->now = strtotime('2026-12-01T12:00:00Z');
        IcalExportService::$clock = fn (): int => $this->now;

        $row = $this->pdo->query("SELECT * FROM properties WHERE status = 'active' ORDER BY id LIMIT 1")
            ->fetch(PDO::FETCH_ASSOC);
        $this->assertNotEmpty($row);
        $this->propertyId = (int) $row['id'];
        $this->userId = (int) $row['user_id'];

        $this->pdo->prepare('DELETE FROM property_ical_exports WHERE property_id = ?')->execute([$this->propertyId]);
        $this->pdo->prepare('DELETE FROM calendar_feeds WHERE property_id = ?')->execute([$this->propertyId]);
        $this->pdo->prepare('DELETE FROM calendar_days WHERE property_id = ?')->execute([$this->propertyId]);
        $this->pdo->prepare(
            'UPDATE properties SET ical_export_manual_blocks = 1, ical_export_imported_events = 0 WHERE id = ?'
        )->execute([$this->propertyId]);

        $this->airbnbFeed = $this->insertFeed(1, 'airbnb');
        $this->bookingFeed = $this->insertFeed(2, 'booking');

        $reserva1 = $this->day(2);
        $reserva2 = $this->day(3);
        $this->insertDay($reserva1, 1, 'reserva', null);
        $this->insertDay($reserva2, 1, 'reserva', null);
        $this->insertDay($this->day(5), 1, 'sistema', null);
        $this->insertDay($this->day(7), 1, 'sync', $this->airbnbFeed);
        $this->insertDay($this->day(8), 1, 'sync', $this->bookingFeed);
        $this->insertDay($this->day(9), 1, 'pre-reserva', null);
        $this->insertDay($this->day(-1), 1, 'reserva', null);
        $this->insertDay($this->day(10), 0, '', null);

        $this->property = $this->pdo->query('SELECT * FROM properties WHERE id = ' . $this->propertyId)
            ->fetch(PDO::FETCH_ASSOC);
    }

    protected function tearDown(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    private function insertFeed(int $slot, string $platform): int
    {
        $url = 'https://ical.example.test/' . $platform . '.ics';
        $stmt = $this->pdo->prepare(
            'INSERT INTO calendar_feeds (property_id, slot, url, url_hash, title, source_platform)
             VALUES (?, ?, ?, ?, ?, ?) RETURNING id'
        );
        $stmt->execute([
            $this->propertyId,
            $slot,
            CalendarSync::encryptUrl($url),
            hash('sha256', $url),
            ucfirst($platform),
            $platform,
        ]);
        return (int) $stmt->fetchColumn();
    }

    private function insertDay(string $iso, int $blocked, string $origin, ?int $feedId): void
    {
        $this->pdo->prepare(
            'INSERT INTO calendar_days (property_id, day, blocked, origin, feed_id) VALUES (?, ?, ?, ?, ?)'
        )->execute([$this->propertyId, $iso, $blocked, $origin, $feedId]);
    }

    private function day(int $offset): string
    {
        return gmdate('Y-m-d', $this->now + $offset * 86400);
    }

    /** @param list<array{day: string, summary: string}> $days */
    private function daySet(array $days): array
    {
        return array_map(static fn (array $day): string => $day['day'], $days);
    }

    public function testCreateExportGeneratesUniqueTokensAndListsActive(): void
    {
        $a = IcalExportService::createExport($this->propertyId, $this->userId, 'airbnb', 'Anúncio principal');
        $b = IcalExportService::createExport($this->propertyId, $this->userId, 'booking');
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', $a['token']);
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', $b['token']);
        $this->assertNotSame($a['token'], $b['token']);

        $list = IcalExportService::listForProperty($this->propertyId);
        $this->assertSame(1, (int) $list['settings']['ical_export_manual_blocks']);
        $this->assertSame(0, (int) $list['settings']['ical_export_imported_events']);
        $this->assertCount(2, $list['exports']);
        $this->assertSame('Anúncio principal', $list['exports'][1]['label']);

        $this->assertTrue(IcalExportService::revokeExport($this->propertyId, $this->userId, (int) $a['id']));
        $this->assertCount(1, IcalExportService::listForProperty($this->propertyId)['exports']);

        $this->expectException(InvalidArgumentException::class);
        IcalExportService::createExport($this->propertyId, $this->userId, 'expedia');
    }

    public function testSettingsTogglesPersist(): void
    {
        $settings = IcalExportService::updateSettings($this->propertyId, $this->userId, false, true);
        $this->assertSame(
            ['ical_export_manual_blocks' => false, 'ical_export_imported_events' => true],
            $settings
        );
        $stored = $this->pdo->query(
            'SELECT ical_export_manual_blocks, ical_export_imported_events FROM properties WHERE id = ' . $this->propertyId
        )->fetch(PDO::FETCH_ASSOC);
        $this->assertSame(0, (int) $stored['ical_export_manual_blocks']);
        $this->assertSame(1, (int) $stored['ical_export_imported_events']);
        $this->assertSame($settings, IcalExportService::listForProperty($this->propertyId)['settings']);
    }

    public function testEchoLoopExclusionAndToggles(): void
    {
        $property = $this->property;
        $airbnbExport = ['target_platform' => 'airbnb'];
        $bookingExport = ['target_platform' => 'booking'];
        $cleanerExport = ['target_platform' => 'cleaner'];

        // Manual blocks on, imported events off.
        $days = $this->daySet(IcalExportService::exportDaysForToken($airbnbExport, $property));
        $this->assertContains($this->day(2), $days);
        $this->assertContains($this->day(3), $days);
        $this->assertContains($this->day(5), $days);
        $this->assertNotContains($this->day(7), $days);
        $this->assertNotContains($this->day(8), $days);
        $this->assertNotContains($this->day(9), $days, 'pre-reservas are never exported');
        $this->assertNotContains($this->day(-1), $days, 'past days are never exported');
        $this->assertNotContains($this->day(10), $days, 'open days are not exported');

        // Imported events on: the target platform is excluded, others are forwarded.
        IcalExportService::updateSettings($this->propertyId, $this->userId, true, true);
        $property = $this->pdo->query('SELECT * FROM properties WHERE id = ' . $this->propertyId)->fetch(PDO::FETCH_ASSOC);
        $airbnbDays = $this->daySet(IcalExportService::exportDaysForToken($airbnbExport, $property));
        $this->assertContains($this->day(8), $airbnbDays);
        $this->assertNotContains($this->day(7), $airbnbDays, 'an Airbnb link never re-exports Airbnb events');
        $bookingDays = $this->daySet(IcalExportService::exportDaysForToken($bookingExport, $property));
        $this->assertContains($this->day(7), $bookingDays);
        $this->assertNotContains($this->day(8), $bookingDays, 'a Booking link never re-exports Booking events');
        $cleanerDays = $this->daySet(IcalExportService::exportDaysForToken($cleanerExport, $property));
        $this->assertContains($this->day(7), $cleanerDays);
        $this->assertContains($this->day(8), $cleanerDays);

        // Manual blocks off.
        IcalExportService::updateSettings($this->propertyId, $this->userId, false, true);
        $property = $this->pdo->query('SELECT * FROM properties WHERE id = ' . $this->propertyId)->fetch(PDO::FETCH_ASSOC);
        $this->assertNotContains($this->day(5), $this->daySet(IcalExportService::exportDaysForToken($airbnbExport, $property)));
    }

    public function testSingleLinkRevocationAndFetch(): void
    {
        $a = IcalExportService::createExport($this->propertyId, $this->userId, 'airbnb');
        $b = IcalExportService::createExport($this->propertyId, $this->userId, 'booking');

        $rendered = IcalExportService::renderIcsForToken($a['token']);
        $this->assertNotNull($rendered);
        $this->assertSame('airbnb', $rendered['export']['target_platform']);
        $last = $this->pdo->prepare('SELECT last_fetched_at FROM property_ical_exports WHERE id = ?');
        $last->execute([(int) $a['id']]);
        $this->assertNotNull($last->fetchColumn());

        $this->assertTrue(IcalExportService::revokeExport($this->propertyId, $this->userId, (int) $a['id']));
        $this->assertNull(IcalExportService::renderIcsForToken($a['token']));
        $this->assertNotNull(IcalExportService::renderIcsForToken($b['token']));

        $this->assertNull(IcalExportService::renderIcsForToken('not-a-token'));
        $this->assertNull(IcalExportService::renderIcsForToken(str_repeat('a', 64)));
    }

    public function testIcsIsLgpdSafeMergedAndUsesExclusiveDtend(): void
    {
        $export = IcalExportService::createExport($this->propertyId, $this->userId, 'airbnb');
        $rendered = IcalExportService::renderIcsForToken($export['token']);
        $this->assertNotNull($rendered);
        $ics = $rendered['ics'];

        $this->assertStringContainsString("\r\n", $ics);
        $this->assertStringContainsString('DTSTART;VALUE=DATE:' . str_replace('-', '', $this->day(2)), $ics);
        // The two contiguous reserva nights become one VEVENT with an exclusive DTEND (day 4).
        $this->assertStringContainsString('DTEND;VALUE=DATE:' . str_replace('-', '', $this->day(4)), $ics);
        $this->assertStringContainsString('SUMMARY:Reservado - CasaLivre', $ics);
        $this->assertStringContainsString('SUMMARY:Indisponível', $ics);
        $this->assertStringContainsString('STATUS:CONFIRMED', $ics);
        $this->assertStringContainsString('TRANSP:OPAQUE', $ics);

        $withoutIdentifiers = (string) preg_replace('/^(?:UID|DTSTAMP|DTSTART|DTEND)[^\r\n]*/m', '', $ics);
        $this->assertDoesNotMatchRegularExpression('/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/', $withoutIdentifiers);
        $this->assertDoesNotMatchRegularExpression('/(?:\+?55\s?)?\(?\d{2}\)?\s?9?\d{4}-?\d{4}/', $withoutIdentifiers);
        $this->assertStringNotContainsString('CPF', $ics);
        $this->assertStringNotContainsString('pix', strtolower($ics));
    }

    public function testDetectSourcePlatformAndEncryptedFeedStorage(): void
    {
        $this->assertSame('airbnb', HouseRepository::detectSourcePlatform('https://www.airbnb.com/calendar/ical/1.ics'));
        $this->assertSame('booking', HouseRepository::detectSourcePlatform('https://ical.booking.com/v1/export?t=x'));
        $this->assertSame('vrbo', HouseRepository::detectSourcePlatform('https://www.vrbo.com/icalendar/1.ics'));
        $this->assertSame('other', HouseRepository::detectSourcePlatform('https://pms.example.test/feed.ics'));

        $url = 'https://www.airbnb.com/calendar/ical/777.ics';
        // Updated: 2026-11 - addFeed fetches the feed to start the unified ownership challenge.
        OtaVerificationService::$httpTransport = static fn (): string =>
            "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//CasaLivre//Test//EN\r\nEND:VCALENDAR\r\n";
        $created = HouseRepository::addFeed($this->propertyId, $this->userId, $url, 'Auto Detect');
        OtaVerificationService::$httpTransport = null;
        $this->assertIsArray($created);
        $feedId = (int) $created['feed']['id'];
        $row = $this->pdo->prepare('SELECT url, url_hash, source_platform FROM calendar_feeds WHERE id = ?');
        $row->execute([$feedId]);
        $stored = $row->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('airbnb', $stored['source_platform']);
        $this->assertStringStartsWith('enc:v1:', (string) $stored['url']);
        $this->assertSame(hash('sha256', $url), (string) $stored['url_hash']);
    }

    public function testRetentionPurgesOnlyOldRevokedExports(): void
    {
        $insert = $this->pdo->prepare(
            'INSERT INTO property_ical_exports (property_id, target_platform, label, token, revoked_at)
             VALUES (?, ?, NULL, ?, ?)'
        );
        $insert->execute([$this->propertyId, 'airbnb', str_repeat('a', 64), null]);
        $insert->execute([
            $this->propertyId,
            'booking',
            str_repeat('b', 64),
            gmdate('Y-m-d H:i:sP', $this->now - 91 * 86400),
        ]);
        $insert->execute([
            $this->propertyId,
            'vrbo',
            str_repeat('c', 64),
            gmdate('Y-m-d H:i:sP', $this->now - 10 * 86400),
        ]);

        $this->assertSame(1, IcalExportService::purgeRevokedExports());
        $rows = $this->pdo->query(
            'SELECT token FROM property_ical_exports WHERE property_id = ' . $this->propertyId . ' ORDER BY token'
        )->fetchAll(PDO::FETCH_COLUMN);
        $this->assertSame([str_repeat('a', 64), str_repeat('c', 64)], $rows);
    }
}
