<?php

declare(strict_types=1);

require_once __DIR__ . "/Resultado.php";
require_once dirname(__DIR__) . "/Settings.php";

final class AdminSettingsUpdate
{
    /**
     * @param array<string, mixed> $actor
     * @param array<string, mixed> $input
     */
    public static function run(array $actor, array $input): Resultado
    {
        if ((int) ($actor["is_admin"] ?? 0) !== 1 || ($actor["status"] ?? "") !== "active") {
            return new Resultado("forbidden", "Acesso restrito a administradores.");
        }

        $allowed = Settings::defaults();
        $updated = [];

        foreach ($input as $key => $raw) {
            if (!array_key_exists($key, $allowed)) {
                continue;
            }
            $val = trim((string) $raw);

            // Validation rules
            if (in_array($key, [
                "calendar_sync_interval_min",
                "calendar_sync_fail_stop",
                "calendar_fetch_timeout_sec",
                "quote_hold_minutes",
                "login_ip_lock_minutes",
                "login_ip_max_failures",
                "login_max_failures",
                "session_minutes",
                "password_min_length",
                "calendar_months",
                "email_verify_minutes",
                "password_reset_minutes",
                "max_photos_per_property",
                "results_per_page"
            ], true)) {
                if (!ctype_digit($val) || (int) $val < 1) {
                    return new Resultado("invalido", "O campo {$key} deve ser um número inteiro positivo.");
                }
            } elseif ($key === "photo_webp_quality") {
                if (!ctype_digit($val) || (int) $val < 50 || (int) $val > 95) {
                    return new Resultado("invalido", "A qualidade WebP deve estar entre 50 e 95.");
                }
            } elseif ($key === "maintenance_mode") {
                $val = ($val === "1" || $val === "true") ? "1" : "0";
            }

            Settings::set($key, $val);
            $updated[$key] = $val;
        }

        return new Resultado("ok", null, ["updated" => $updated]);
    }
}
