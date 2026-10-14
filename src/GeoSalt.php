<?php

declare(strict_types=1);

/**
 * GEO_SALT (sal do deslocamento das coordenadas públicas). Fora de dev/local é obrigatório e com
 * pelo menos MIN_LENGTH caracteres: sem ele o app não sobe (fail closed, ver public/index.php).
 */
final class GeoSalt
{
    public const MIN_LENGTH = 32;

    public static function isDev(): bool
    {
        return in_array(strtolower(trim((string) getenv('APP_ENV'))), ['dev', 'local'], true);
    }

    /** Motivo da recusa (sem o valor do sal), ou null se o ambiente está ok. */
    public static function problem(): ?string
    {
        Db::loadEnv();
        if (self::isDev()) {
            return null;
        }
        $salt = trim((string) getenv('GEO_SALT'));
        if ($salt === '') {
            return 'GEO_SALT ausente';
        }
        if (strlen($salt) < self::MIN_LENGTH) {
            return 'GEO_SALT com menos de ' . self::MIN_LENGTH . ' caracteres';
        }
        return null;
    }

    /** Recusa a requisição: log claro (sem o valor), 500 genérico, nada mais roda. */
    public static function enforce(): void
    {
        $problem = self::problem();
        if ($problem === null) {
            return;
        }
        error_log('geo_salt_invalid: ' . $problem . ' fora de dev/local; o app não atende sem ele. Gere com: php -r "echo bin2hex(random_bytes(32));"');
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: text/plain; charset=utf-8');
            header('Cache-Control: no-store');
        }
        echo "Serviço indisponível: configuração incompleta.\n";
        exit;
    }
}
