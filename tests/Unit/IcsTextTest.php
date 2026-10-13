<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;

/** CT-CAL-001: escape de texto RFC 5545 */
final class IcsTextTest extends TestCase
{
    public function testEscapaCaracteresEspeciais(): void { self::assertSame('a\\;b\\,c\\\\d\\ne', ics_text("a;b,c\\d\ne")); }
    public function testRemoveCarriageReturnSolto(): void { self::assertSame('ab', ics_text("a\rb")); }
}
