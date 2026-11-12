<?php

declare(strict_types=1);

require_once __DIR__ . "/Resultado.php";
require_once dirname(__DIR__) . "/AdminRepository.php";
require_once dirname(__DIR__) . "/Settings.php";

final class AdminDashboard
{
    /** @param array<string, mixed> $actor */
    public static function run(array $actor): Resultado
    {
        if ((int) ($actor["is_admin"] ?? 0) !== 1 || ($actor["status"] ?? "") !== "active") {
            return new Resultado("forbidden", "Access restricted to administrators.");
        }

        $kpis = AdminRepository::getKpis();
        $env = AdminRepository::getEnvironmentDiagnostic();
        $services = AdminRepository::getRunitServices();
        $activity = AdminRepository::getRecentActivity(10);

        $settings = [];
        foreach (array_keys(Settings::defaults()) as $key) {
            $settings[$key] = Settings::get($key);
        }

        return new Resultado("ok", null, [
            "kpis" => $kpis,
            "env" => $env,
            "services" => $services,
            "recent_activity" => $activity,
            "settings" => $settings,
        ]);
    }
}
