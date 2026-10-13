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

    /** HMAC-SHA256 truncado (16 hex); null sem LOG_SALT ou valor vazio. */
    public static function hmac(?string $value, string $context = ''): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $salt = self::salt();
        return $salt === null ? null : substr(hash_hmac('sha256', $context . "\0" . $value, $salt), 0, 16);
    }

    public static function user(?int $userId): ?string
    {
        return $userId === null ? null : self::hmac((string) $userId, 'user');
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
