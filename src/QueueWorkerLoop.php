<?php

declare(strict_types=1);

require_once __DIR__ . '/MailWorker.php';
require_once __DIR__ . '/ImageWorker.php';
require_once __DIR__ . '/GrupoResolvedor.php';
require_once __DIR__ . '/Settings.php';
require_once __DIR__ . '/AssetManifest.php';

/**
 * One iteration of the long-running scripts/queue-worker.php: one mail batch and, when $image is given, one image batch.
 * Per-process caches that are per request on the web must not outlive a batch here (the worker never exits), so
 * they are cleared first:
 *  - GrupoResolvedor (verified domains): otherwise MailHtml::isAllowedLink kept linking a domain that lost verification;
 *  - Settings (settings.json): otherwise mail kept the old theme brand and links the old expiry minutes;
 *  - AssetManifest (manifest.json): otherwise a rebuilt manifest (theme list) was never seen.
 */
final class QueueWorkerLoop
{
    /** @return array<string, ?array> report per queue ('mail', and 'image' when $image is not null) */
    public static function tick(MailQueue $mail, ?ImageQueue $image, string $storageDir): array
    {
        GrupoResolvedor::limparCache();
        Settings::clearCache();
        AssetManifest::clearCache();
        $reports = ['mail' => MailWorker::run($mail, $storageDir . '/mail-worker.lock')];
        if ($image !== null) {
            $reports['image'] = ImageWorker::run($image, $storageDir . '/image-worker.lock');
        }
        return $reports;
    }
}
