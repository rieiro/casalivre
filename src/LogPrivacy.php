<?php

declare(strict_types=1);

/**
 * Pseudonimização para logs (RequestLog, ActivityLog, ExceptionShield via activity()).
 *
 * Exige LOG_SALT (sem fallback). Sem LOG_SALT, os identificadores (usuário, e-mail,
 * user agent, URL) são OMITIDOS e um aviso sem dados é emitido uma vez via error_log().
 */
final class LogPrivacy
{
    private static bool $warned = false;

    public static function salt(): ?string
    {
        $salt = (string) (getenv('LOG_SALT') ?: '');
        if ($salt === '' && class_exists('Db', false) && method_exists('Db', 'loadEnv')) {
            Db::loadEnv();
            $salt = (string) (getenv('LOG_SALT') ?: '');
        }
        if ($salt === '') {
            if (!self::$warned) {
                self::$warned = true;
                error_log('CasaLivre: LOG_SALT não configurado; identificadores omitidos dos logs.');
            }
            return null;
        }
        return $salt;
    }

    /** HMAC-SHA256 completo (64 hex, nunca truncado); null sem LOG_SALT ou valor vazio. */
    public static function hmac(?string $value, string $context = ''): ?string
    {
        $raw = self::hmacRaw($value, $context);
        return $raw === null ? null : bin2hex($raw);
    }

    /** HMAC-SHA256 completo em bytes (32); null sem LOG_SALT ou valor vazio. */
    public static function hmacRaw(?string $value, string $context = ''): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $salt = self::salt();
        return $salt === null ? null : hash_hmac('sha256', $context . "\0" . $value, $salt, true);
    }

    public static function user(?int $userId): ?string
    {
        return $userId === null ? null : self::hmac((string) $userId, 'user');
    }

    /** actor_ref of the PG logs: the full 32-byte HMAC of the user id (bytea), same input as user(). */
    public static function userRef(?int $userId): ?string
    {
        return $userId === null ? null : self::hmacRaw((string) $userId, 'user');
    }

    /** Version of the current LOG_SALT (LOG_SALT_KEY_VERSION, default 1), stored as actor_key_version. */
    public static function keyVersion(): int
    {
        $v = (int) (getenv('LOG_SALT_KEY_VERSION') ?: 1);
        return $v >= 1 && $v <= 32767 ? $v : 1;
    }

    public static function email(?string $email): ?string
    {
        return $email === null ? null : self::hmac(strtolower(trim($email)), 'email');
    }

    public static function userAgent(?string $ua): ?string
    {
        return self::hmac($ua, 'ua');
    }

    /** IP truncado: IPv4 /24, IPv6 /48. Inválido -> ''. */
    public static function ip(?string $ip): string
    {
        $bin = @inet_pton((string) $ip);
        if ($bin === false) {
            return '';
        }
        if (strlen($bin) === 4) {
            return (string) inet_ntop(substr($bin, 0, 3) . "\0");
        }
        return (string) inet_ntop(substr($bin, 0, 6) . str_repeat("\0", 10));
    }

    /** URL -> host#hmac(url). Sem LOG_SALT -> host#-. */
    public static function url(?string $url): ?string
    {
        if ($url === null || $url === '') {
            return $url;
        }
        $host = parse_url($url, PHP_URL_HOST);
        return (is_string($host) && $host !== '' ? strtolower($host) : '?') . '#' . (self::hmac($url, 'url') ?? '-');
    }
}
