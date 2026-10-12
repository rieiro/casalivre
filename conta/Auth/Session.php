<?php

declare(strict_types=1);

final class SessionGuard
{
    public static function start(string $directory, int $minutes): void
    {
        if (!is_dir($directory)) {
            mkdir($directory, 0775, true);
        }
        $minutes = max(1, $minutes);
        session_save_path($directory);
        $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
        $samesite = getenv('SESSION_SAMESITE') ?: 'Lax';
        if ($samesite === 'None' && !$https) {
            $samesite = 'Lax'; // None exige Secure
        }
        ini_set('session.gc_maxlifetime', (string) ($minutes * 60));
        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_strict_mode', '1');
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => $https || $samesite === 'None',
            'httponly' => true,
            'samesite' => $samesite,
        ]);
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
    }

    public static function expire(int $minutes): void
    {
        if (empty($_SESSION['user_id'])) {
            return;
        }
        $started = (int) ($_SESSION['auth_at'] ?? 0);
        $minutes = max(1, $minutes);
        if ($started > 0 && (time() - $started) < ($minutes * 60)) {
            return;
        }
        unset($_SESSION['user_id'], $_SESSION['auth_at']);
        $_SESSION['session_expired'] = 1;
    }
}
