<?php

declare(strict_types=1);

final class LoginRateLimiter
{
    public static function maxAttempts(): int
    {
        $value = class_exists('Settings', false) ? Settings::int('login_ip_max_failures') : 5;
        return max(1, $value ?: 5);
    }

    public static function lockSeconds(): int
    {
        $minutes = class_exists('Settings', false) ? Settings::int('login_ip_lock_minutes') : 15;
        return max(1, $minutes ?: 15) * 60;
    }

    public static function message(): string
    {
        $minutes = (int) (self::lockSeconds() / 60);
        $lang = function_exists('current_locale') ? current_locale() : 'pt-BR';
        return match ($lang) {
            'en' => 'Too many attempts from this address. Try again in ' . $minutes . ' minutes.',
            'es' => 'Demasiados intentos desde esta dirección. Espere ' . $minutes . ' minutos.',
            default => 'Muitas tentativas a partir deste endereço. Tente de novo em ' . $minutes . ' minutos.',
        };
    }

    public static function clientIp(): string
    {
        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        return $ip !== '' ? $ip : '0.0.0.0';
    }

    public static function blocked(?string $ip = null): bool
    {
        $ip ??= self::clientIp();
        $stmt = db()->prepare('SELECT locked_until FROM login_rate_limit WHERE ip = ?');
        $stmt->execute([$ip]);
        $until = $stmt->fetchColumn();
        return is_string($until) && $until !== '' && $until > date('Y-m-d H:i:s');
    }

    public static function hit(?string $ip = null): int
    {
        $ip ??= self::clientIp();
        $pdo = db();
        $stmt = $pdo->prepare('SELECT failures, locked_until FROM login_rate_limit WHERE ip = ?');
        $stmt->execute([$ip]);
        $row = $stmt->fetch();
        $now = time();
        if (!$row) {
            $pdo->prepare('INSERT INTO login_rate_limit (ip, failures, locked_until) VALUES (?, 1, NULL)')->execute([$ip]);
            return 1;
        }
        $until = (string) ($row['locked_until'] ?? '');
        $expired = $until !== '' && strtotime($until) !== false && strtotime($until) <= $now;
        $failures = $expired ? 1 : ((int) $row['failures'] + 1);
        $lock = $failures >= self::maxAttempts() ? date('Y-m-d H:i:s', $now + self::lockSeconds()) : null;
        $pdo->prepare('UPDATE login_rate_limit SET failures = ?, locked_until = ? WHERE ip = ?')->execute([$failures, $lock, $ip]);
        return $failures;
    }

    public static function clear(?string $ip = null): void
    {
        $ip ??= self::clientIp();
        db()->prepare('DELETE FROM login_rate_limit WHERE ip = ?')->execute([$ip]);
    }
}
