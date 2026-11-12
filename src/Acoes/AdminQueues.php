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
            return new Resultado("forbidden", "Access restricted to administrators.");
        }

        $details = AdminRepository::getQueueDetails();
        return new Resultado("ok", null, $details);
    }

    /** @param array<string, mixed> $actor */
    public static function retry(array $actor, string $queue): Resultado
    {
        if ((int) ($actor["is_admin"] ?? 0) !== 1 || ($actor["status"] ?? "") !== "active") {
            return new Resultado("forbidden", "Access restricted to administrators.");
        }

        if (!in_array($queue, ["image_queue", "mail_queue"], true)) {
            return new Resultado("invalido", "Unknown queue.");
        }

        $count = AdminRepository::retryFailedQueueItems($queue);
        return new Resultado("ok", "{$count} item(s) requalified for retry.", ["count" => $count]);
    }
}
