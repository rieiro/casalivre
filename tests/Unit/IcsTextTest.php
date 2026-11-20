<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/src/CalendarSync.php';

/** CT-CAL-001: escape de texto RFC 5545 */
final class IcsTextTest extends TestCase
{
    public function testEscapaCaracteresEspeciais(): void { self::assertSame('a\\;b\\,c\\\\d\\ne', ics_text("a;b,c\\d\ne")); }
    public function testRemoveCarriageReturnSolto(): void { self::assertSame('ab', ics_text("a\rb")); }

    public function testEncryptUrlRoundTripsAndPassesPlaintextThrough(): void
    {
        $url = 'https://ical.booking.com/v1/export?t=secret';
        $encrypted = CalendarSync::encryptUrl($url);
        self::assertStringStartsWith('enc:v1:', $encrypted);
        self::assertNotSame($url, $encrypted);
        self::assertSame($url, CalendarSync::decryptUrl($encrypted));
        self::assertSame($url, CalendarSync::decryptUrl($url));
        self::assertSame($encrypted, CalendarSync::encryptUrl($encrypted));
    }

    public function testAcceptBodyRequiresCalendarPrefixAndStripsBom(): void
    {
        $ok = CalendarSync::acceptBody("\xEF\xBB\xBF\r\n BEGIN:VCALENDAR\r\nEND:VCALENDAR\r\n", 200);
        self::assertSame(200, $ok['http'] ?? null);
        self::assertStringStartsWith('BEGIN:VCALENDAR', (string) ($ok['body'] ?? ''));
        self::assertSame('not_ics', CalendarSync::acceptBody('<html><body>BEGIN:VCALENDAR</body></html>', 200)['error'] ?? null);
    }
}
