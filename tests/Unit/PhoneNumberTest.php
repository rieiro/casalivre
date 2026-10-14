<?php
declare(strict_types=1);
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** CT-AUTH-007..009 */
final class PhoneNumberTest extends TestCase
{
    public function testDigitsRemoveMascara(): void
    {
        self::assertSame('48999990000', PhoneNumber::digits('(48) 99999-0000'));
    }

    public static function validos(): array { return [['48999990000'], ['(11) 91234-5678'], ['21 98888 7777']]; }
    public static function invalidos(): array { return [['4899999000'], ['48899990000'], ['10999990000'], [''], ['abc']]; }

    #[DataProvider('validos')]
    public function testCelularValido(string $n): void { self::assertTrue(PhoneNumber::isValid($n)); }

    #[DataProvider('invalidos')]
    public function testCelularInvalido(string $n): void { self::assertFalse(PhoneNumber::isValid($n)); }
}
