<?php

declare(strict_types=1);

require_once __DIR__ . "/Resultado.php";
require_once dirname(__DIR__) . "/AdminRepository.php";

final class AdminAudit
{
    /**
     * @param array<string, mixed> $actor
     * @param array<string, mixed> $params
     */
    public static function index(array $actor, array $params = []): Resultado
    {
        if ((int) ($actor["is_admin"] ?? 0) !== 1 || ($actor["status"] ?? "") !== "active") {
            return new Resultado("forbidden", "Acesso restrito a administradores.");
        }

        $code = !empty($params["code"]) ? (int) $params["code"] : null;
        $class = !empty($params["class"]) ? (string) $params["class"] : null;
        $limit = !empty($params["limit"]) ? max(10, min(500, (int) $params["limit"])) : 100;

        $logs = AdminRepository::getAuditLogs($code, $class, $limit);
        $metrics = AdminRepository::getAuditMetrics();

        $actions = [];
        try {
            $actions = db()->query("SELECT code, description FROM activity_actions ORDER BY code")->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];
        } catch (Throwable) {
        }

        return new Resultado("ok", null, [
            "logs" => $logs,
            "metrics" => $metrics,
            "actions" => $actions,
            "current_code" => $code,
            "current_class" => $class,
            "current_limit" => $limit,
        ]);
    }
}
