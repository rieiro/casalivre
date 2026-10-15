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
        return password_hash($password, self::algo());
    }

    /**
     * Anti-enumeração por tempo: um password_verify contra um hash fictício, com o mesmo algoritmo e as mesmas
     * opções dos hashes reais (gerado por hash()), para os caminhos de login que não chegam a verificar a senha.
     * O hash é gerado uma vez e guardado em storage/cache (gerar a cada requisição custaria um password_hash
     * a mais, deixando o caminho "e-mail inexistente" mais LENTO que o real); é refeito se o algoritmo ou as
     * opções mudarem (password_needs_rehash). O resultado é descartado.
     */
    public static function dummyVerify(string $password): void
    {
        static $hash = null;
        if ($hash === null) {
            require_once dirname(__DIR__) . '/Geo/JsonCache.php';
            $cached = JsonCache::read('login_dummy_hash.json');
            $candidate = is_array($cached) ? (string) ($cached['hash'] ?? '') : '';
            if ($candidate === '' || password_get_info($candidate)['algo'] === null || password_needs_rehash($candidate, self::algo())) {
                $candidate = self::hash(bin2hex(random_bytes(16)));
                try {
                    JsonCache::write('login_dummy_hash.json', ['hash' => $candidate], false);
                } catch (Throwable) {
                    // Sem cache gravável: segue com o hash desta requisição.
                }
            }
            $hash = $candidate;
        }
        password_verify($password, $hash);
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
