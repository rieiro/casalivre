<?php

declare(strict_types=1);

require_once __DIR__ . "/Resultado.php";
require_once dirname(__DIR__) . "/AdminRepository.php";
require_once dirname(__DIR__) . "/LoginRateLimiter.php";

final class AdminSecurity
{
    /** @param array<string, mixed> $actor */
    public static function index(array $actor): Resultado
    {
        if ((int) ($actor["is_admin"] ?? 0) !== 1 || ($actor["status"] ?? "") !== "active") {
            return new Resultado("forbidden", "Acesso restrito a administradores.");
        }

        $ips = AdminRepository::getSecurityIps(100);
        return new Resultado("ok", null, ["ips" => $ips]);
    }

    /** @param array<string, mixed> $actor */
    public static function unblock(array $actor, string $ip): Resultado
    {
        if ((int) ($actor["is_admin"] ?? 0) !== 1 || ($actor["status"] ?? "") !== "active") {
            return new Resultado("forbidden", "Acesso restrito a administradores.");
        }

        $cleanIp = trim($ip);
        if ($cleanIp === "" || !filter_var($cleanIp, FILTER_VALIDATE_IP)) {
            return new Resultado("invalido", "Endereço IP inválido.");
        }

        AdminRepository::unblockIp($cleanIp);
        return new Resultado("ok", "IP desbloqueado com sucesso.", ["ip" => $cleanIp]);
    }

    /** @param array<string, mixed> $actor */
    public static function block(array $actor, string $ip, int $minutes = 60): Resultado
    {
        if ((int) ($actor["is_admin"] ?? 0) !== 1 || ($actor["status"] ?? "") !== "active") {
            return new Resultado("forbidden", "Acesso restrito a administradores.");
        }

        $cleanIp = trim($ip);
        if ($cleanIp === "" || !filter_var($cleanIp, FILTER_VALIDATE_IP)) {
            return new Resultado("invalido", "Endereço IP inválido.");
        }

        $duration = max(1, min(525600, $minutes));
        AdminRepository::blockIp($cleanIp, $duration);
        return new Resultado("ok", "IP bloqueado com sucesso.", ["ip" => $cleanIp, "minutes" => $duration]);
    }
}
