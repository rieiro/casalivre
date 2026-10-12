<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/conta/Auth/Session.php';

final class SecureSession
{
    public static function start(): void
    {
        $minutes = 60;
        if (class_exists('Settings', false)) {
            $minutes = max(1, Settings::int('session_minutes') ?: 60);
        }
        SessionGuard::start(dirname(__DIR__) . '/storage/sessions', $minutes);
    }
}
