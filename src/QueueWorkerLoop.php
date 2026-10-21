<?php

declare(strict_types=1);

require_once __DIR__ . '/MailWorker.php';
require_once __DIR__ . '/ImageWorker.php';
require_once __DIR__ . '/GrupoResolvedor.php';

/**
 * One iteration of the long-running scripts/queue-worker.php: one mail batch and, when $image is given, one image batch.
 * Per-process caches that must not outlive a batch are cleared first: GrupoResolvedor's verified-domain cache is
 * per request on the web, but the worker never exits, so without this MailHtml::isAllowedLink kept linking a group
 * domain after it lost verification.
 */
final class QueueWorkerLoop
{
    /** @return array<string, ?array> report per queue ('mail', and 'image' when $image is not null) */
    public static function tick(MailQueue $mail, ?ImageQueue $image, string $storageDir): array
    {
        GrupoResolvedor::limparCache();
        $reports = ['mail' => MailWorker::run($mail, $storageDir . '/mail-worker.lock')];
        if ($image !== null) {
            $reports['image'] = ImageWorker::run($image, $storageDir . '/image-worker.lock');
        }
        return $reports;
    }
}
