<?php

declare(strict_types=1);

require_once __DIR__ . "/Resultado.php";
require_once dirname(__DIR__) . "/AdminRepository.php";

final class AdminQueues
{
    /** @param array<string, mixed> $actor */
    public static function index(array $actor): Resultado
    {
        if ((int) ($actor["is_admin"] ?? 0) !== 1 || ($actor["status"] ?? "") !== "active") {
            return new Resultado("forbidden", "Acesso restrito a administradores.");
        }

        $details = AdminRepository::getQueueDetails();
        return new Resultado("ok", null, $details);
    }

    /** @param array<string, mixed> $actor */
    public static function retry(array $actor, string $queue): Resultado
    {
        if ((int) ($actor["is_admin"] ?? 0) !== 1 || ($actor["status"] ?? "") !== "active") {
            return new Resultado("forbidden", "Acesso restrito a administradores.");
        }

        if (!in_array($queue, ["image_queue", "mail_queue"], true)) {
            return new Resultado("invalido", "Fila desconhecida.");
        }

        $count = AdminRepository::retryFailedQueueItems($queue);
        return new Resultado("ok", "{$count} item(ns) requalificados para nova tentativa.", ["count" => $count]);
    }
}
