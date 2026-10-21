<?php

declare(strict_types=1);

require_once __DIR__ . '/GrupoResolvedor.php';
require_once __DIR__ . '/Settings.php';
require_once __DIR__ . '/AssetManifest.php';
require_once __DIR__ . '/I18n.php';

/**
 * Single reset hook for the per-process caches that are per request on the web but would live forever in a
 * long-running worker (QueueWorkerLoop::tick calls it before each batch). Without it:
 *  - GrupoResolvedor (verified domains): MailHtml::isAllowedLink kept linking a domain that lost verification;
 *  - Settings (settings.json): mail kept the old theme brand and links the old expiry minutes;
 *  - AssetManifest (manifest.json): a rebuilt manifest (theme list) was never seen;
 *  - I18n (translations.json): a changed translation was never seen.
 * A new per-process cache read while rendering mail or images belongs here.
 */
final class WorkerCaches
{
    public static function reset(): void
    {
        GrupoResolvedor::limparCache();
        Settings::clearCache();
        AssetManifest::clearCache();
        I18n::clearCache();
    }
}
