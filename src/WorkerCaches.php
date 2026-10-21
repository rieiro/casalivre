<?php

declare(strict_types=1);

require_once __DIR__ . '/Settings.php';
require_once __DIR__ . '/AssetManifest.php';
require_once __DIR__ . '/I18n.php';

/**
 * Per-process caches that are per request on the web but would live forever in a long-running worker
 * (QueueWorkerLoop::tick calls refreshIfChanged() before each batch). Without invalidation:
 *  - Settings (settings.json): mail kept the old theme brand and links the old expiry minutes;
 *  - AssetManifest (manifest.json): a rebuilt manifest (theme list) was never seen;
 *  - I18n (translations.json): a changed translation was never seen;
 *  - GrupoResolvedor (verified domains, DB-backed): MailHtml::isAllowedLink kept linking a domain that lost
 *    verification. It has no file to watch, so its entries expire by themselves (GrupoResolvedor::CACHE_TTL_SECONDS)
 *    and nothing is called here for it.
 * A new per-process file cache read while rendering mail or images belongs here.
 */
final class WorkerCaches
{
    /** File-backed caches reload only when their source file changed (FileStamp); unchanged files stay in memory. */
    public static function refreshIfChanged(): void
    {
        Settings::clearCache(true);
        AssetManifest::clearCache(true);
        I18n::clearCache(true);
    }
}
