<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/src/Db.php';
require_once dirname(__DIR__, 2) . '/src/Settings.php';
require_once dirname(__DIR__, 2) . '/src/CalendarSync.php';
require_once dirname(__DIR__, 2) . '/src/HouseRepository.php';
require_once dirname(__DIR__, 2) . '/src/helpers.php';

final class IcalImportHardeningTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        putenv('APP_ENV=testing');
        Db::loadEnv();
        $this->pdo = db();
        $this->pdo->beginTransaction();
    }

    protected function tearDown(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    private function ymd(int $offset): string
    {
        return (new DateTimeImmutable('today', new DateTimeZone('UTC')))
            ->modify(($offset >= 0 ? '+' : '') . $offset . ' days')
            ->format('Ymd');
    }

    private function iso(int $offset): string
    {
        return (new DateTimeImmutable('today', new DateTimeZone('UTC')))
            ->modify(($offset >= 0 ? '+' : '') . $offset . ' days')
            ->format('Y-m-d');
    }

    /** @param list<string> $events */
    private function ics(array $events): string
    {
        return "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Hardening//EN\r\n"
            . implode('', $events)
            . "END:VCALENDAR\r\n";
    }

    public function testTransparentAndCancelledEventsDoNotBlockNights(): void
    {
        $propertyId = (int) $this->pdo->query("SELECT id FROM properties WHERE status = 'active' LIMIT 1")->fetchColumn();
        $this->assertGreaterThan(0, $propertyId);

        $body = $this->ics([
            "BEGIN:VEVENT\r\nUID:transparent\r\nTRANSP:TRANSPARENT\r\nDTSTART;VALUE=DATE:" . $this->ymd(40) . "\r\nDTEND;VALUE=DATE:" . $this->ymd(42) . "\r\nEND:VEVENT\r\n",
            "BEGIN:VEVENT\r\nUID:cancelled\r\nSTATUS:CANCELLED\r\nDTSTART;VALUE=DATE:" . $this->ymd(45) . "\r\nDTEND;VALUE=DATE:" . $this->ymd(47) . "\r\nEND:VEVENT\r\n",
            "BEGIN:VEVENT\r\nUID:opaque\r\nDTSTART;VALUE=DATE:" . $this->ymd(50) . "\r\nDTEND;VALUE=DATE:" . $this->ymd(52) . "\r\nEND:VEVENT\r\n",
        ]);

        $result = import_ics($propertyId, $body);
        $this->assertArrayHasKey('nights', $result);
        $this->assertSame([$this->iso(50), $this->iso(51)], $result['nights']);
    }

    public function testUtcTimestampEventsUseExclusiveDtendInUtc(): void
    {
        $propertyId = (int) $this->pdo->query("SELECT id FROM properties WHERE status = 'active' LIMIT 1")->fetchColumn();
        $this->assertGreaterThan(0, $propertyId);

        $body = $this->ics([
            "BEGIN:VEVENT\r\nUID:utc-range\r\nDTSTART:" . $this->ymd(70) . "T000000Z\r\nDTEND:" . $this->ymd(71) . "T000000Z\r\nEND:VEVENT\r\n",
            "BEGIN:VEVENT\r\nUID:utc-single\r\nDTSTART:" . $this->ymd(80) . "T000000Z\r\nDTEND:" . $this->ymd(80) . "T000000Z\r\nEND:VEVENT\r\n",
        ]);

        $result = import_ics($propertyId, $body);
        $this->assertArrayHasKey('nights', $result);
        $this->assertSame([$this->iso(70), $this->iso(80)], $result['nights']);
    }
}
