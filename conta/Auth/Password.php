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
        if (defined('PASSWORD_ARGON2ID')) {
            return password_hash($password, PASSWORD_ARGON2ID);
        }
        return password_hash($password, PASSWORD_BCRYPT);
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
