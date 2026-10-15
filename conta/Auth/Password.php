<?php

declare(strict_types=1);

final class PasswordPolicy
{
    public static int $minLength = 8;

    public static function isWeak(string $password): bool
    {
        $value = strtolower($password);
        $compact = preg_replace('/[^a-z0-9]/', '', $value) ?? '';
        $blocked = [
            '123456', '1234567', '12345678', '123456789', '1234567890',
            'abcdef', 'abc123', 'abc1234', 'abc12345', 'abcd1234',
            'qwerty', 'qwerty123', 'password', 'password1', 'senha123',
            '11111111', '00000000', '123123', '654321', 'admin123', 'letmein',
            '102030', '123321', '1q2w3e4r', 'qwertyui',
        ];
        if (in_array($value, $blocked, true) || in_array($compact, $blocked, true)) {
            return true;
        }
        if ($compact !== '' && preg_match('/^(.)\1+$/', $compact)) {
            return true;
        }
        $sequences = ['0123456789', 'abcdefghijklmnopqrstuvwxyz', 'qwertyuiop', 'asdfghjkl', 'zxcvbnm'];
        if (strlen($compact) >= 4) {
            foreach ($sequences as $sequence) {
                if (str_contains($sequence, $compact) || str_contains(strrev($sequence), $compact)) {
                    return true;
                }
            }
        }
        if (preg_match('/^[a-z]{2,12}[0-9]{2,12}$/', $compact) || preg_match('/^[0-9]{2,12}[a-z]{2,12}$/', $compact)) {
            return true;
        }
        return !preg_match('/[a-z]/', $value) || !preg_match('/[0-9]/', $value) || !preg_match('/[^a-z0-9]/', $value);
    }

    public static function hash(string $password): string
    {
        return password_hash($password, self::algo(), self::$options);
    }

    /**
     * Hash fictício de reserva (não é segredo: senha aleatória descartada), argon2id com as opções padrão do PHP 8.4,
     * as mesmas de hash(). Usado quando não há cache válido; se as opções mudarem, deixa de valer
     * (password_needs_rehash) e dummyVerify gera outro.
     */
    public const DUMMY_HASH = '$argon2id$v=19$m=65536,t=4,p=1$cklRWWU0SmFnczRYcHNwbA$09RcGJJAKcxM6CvHhHAGCX9CdHnSkC1uFdI23JZKY8U';

    /** Opções de password_hash (vazio = padrão do PHP); estático para os testes simularem mudança de opções. */
    public static array $options = [];

    /** Testes: caminho do cache do hash fictício (null = storage/cache/login_dummy_hash.json). */
    public static ?string $dummyCachePath = null;

    /** Testes: quantas vezes dummyVerify precisou gerar um hash (password_hash) neste processo. */
    public static int $dummyGenerated = 0;

    private static ?string $dummyHash = null;

    /**
     * Anti-enumeração por tempo: um password_verify contra um hash fictício com o mesmo algoritmo e as mesmas
     * opções dos hashes reais, nos caminhos de login que não chegam a verificar a senha. O resultado é descartado.
     * Ordem: estático da requisição -> cache em arquivo válido -> DUMMY_HASH válido -> gerar (e gravar no cache,
     * se der). Gerar custa um password_hash a mais (o caminho ficaria mais LENTO que o real), por isso só acontece
     * quando as opções mudaram; o gerado grava no cache e passa a ter preferência. Sem escrita: sem warning, e o
     * problema é registrado uma vez (marcador em sys_get_temp_dir()).
     */
    public static function dummyVerify(string $password): void
    {
        password_verify($password, self::$dummyHash ??= self::resolveDummyHash());
    }

    /** Testes: esquece o hash da requisição. */
    public static function resetDummy(): void
    {
        self::$dummyHash = null;
    }

    private static function resolveDummyHash(): string
    {
        $path = self::$dummyCachePath ?? dirname(__DIR__, 2) . '/storage/cache/login_dummy_hash.json';
        $raw = is_file($path) ? @file_get_contents($path) : false;
        $cached = is_string($raw) ? json_decode($raw, true) : null;
        $candidate = is_array($cached) ? (string) ($cached['hash'] ?? '') : '';
        if (self::dummyValid($candidate)) {
            return $candidate;
        }
        if (self::dummyValid(self::DUMMY_HASH)) {
            return self::DUMMY_HASH;
        }
        self::$dummyGenerated++;
        $fresh = self::hash(bin2hex(random_bytes(16)));
        $dir = dirname($path);
        $tmp = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';
        $ok = is_dir($dir) && is_writable($dir)
            && @file_put_contents($tmp, json_encode(['hash' => $fresh])) !== false && @rename($tmp, $path);
        if (!$ok) {
            if (is_file($tmp)) {
                @unlink($tmp);
            }
            $marker = sys_get_temp_dir() . '/casalivre-dummy-hash-' . md5($path) . '.warned';
            if (!is_file($marker)) {
                @touch($marker);
                error_log('CasaLivre: não foi possível gravar ' . $path . '; hash fictício do login gerado por requisição até corrigir a permissão.');
            }
        }
        return $fresh;
    }

    private static function dummyValid(string $hash): bool
    {
        return $hash !== '' && password_get_info($hash)['algo'] !== null && !password_needs_rehash($hash, self::algo(), self::$options);
    }

    /** Algoritmo dos hashes de senha: Argon2id quando disponível, senão bcrypt (opções padrão do PHP). */
    public static function algo(): string
    {
        return defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT;
    }

    public static function problem(string $password, ?string $confirm = null): ?string
    {
        if (strlen($password) < max(1, self::$minLength)) {
            return 'short';
        }
        if ($confirm !== null && $confirm !== $password) {
            return 'mismatch';
        }
        if (self::isWeak($password)) {
            return 'weak';
        }
        return null;
    }
}
