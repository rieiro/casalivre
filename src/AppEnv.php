<?php

declare(strict_types=1);

/**
 * Ambiente da aplicação. Fonte única da checagem "dev/local" que antes estava copiada em helpers.php,
 * MapTiles, Mailer, GeoSalt e nos scripts de casos de teste.
 * Semântica (inalterada): getenv('APP_ENV') com trim + strtolower, igual a 'dev' ou 'local'.
 * Não carrega o .env: quem precisa disso chama Db::loadEnv() antes (como já fazia).
 */
final class AppEnv
{
    public static function isDev(): bool
    {
        return in_array(strtolower(trim((string) getenv('APP_ENV'))), ['dev', 'local'], true);
    }
}
