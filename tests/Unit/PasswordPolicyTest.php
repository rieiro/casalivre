<?php
declare(strict_types=1);
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** CT-AUTH-001..006 */
final class PasswordPolicyTest extends TestCase
{
    public function testSenhaCurta(): void { self::assertSame('short', PasswordPolicy::problem('Ab1-')); }
    public function testConfirmacaoDiferente(): void { self::assertSame('mismatch', PasswordPolicy::problem('Senha-segura1', 'outra')); }
    public function testSenhaForteAceita(): void { self::assertNull(PasswordPolicy::problem('Senha-segura1', 'Senha-segura1')); }

    public static function fracas(): array
    {
        return [['12345678'], ['password1'], ['aaaaaaaa'], ['abcdefgh'], ['senha123'], ['SemDigito!'], ['semsimbolo1']];
    }

    #[DataProvider('fracas')]
    public function testSenhasFracas(string $s): void { self::assertTrue(PasswordPolicy::isWeak($s)); }

    public function testHashVerificavel(): void
    {
        $h = PasswordPolicy::hash('Senha-segura1');
        self::assertTrue(password_verify('Senha-segura1', $h));
        self::assertFalse(password_verify('errada', $h));
    }
}
