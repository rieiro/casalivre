<?php

declare(strict_types=1);

/**
 * Arquivo de tiles vetoriais do mapa (PMTiles, basemap Protomaps recortado para o Brasil).
 *
 * Produção: o nginx serve /tiles/brasil.pmtiles como arquivo estático (Range nativo); esta classe não
 * é usada. Dev/local (php -S, sem nginx): o front controller chama serve() nessa URL exata, com suporte
 * a Range (206/416), HEAD e caminho fixo (a requisição nunca escolhe o arquivo).
 * Fora de dev/local a rota não existe (404).
 */
final class MapTiles
{
    public const URL_PATH = '/tiles/brasil.pmtiles';
    /** Relativo à raiz do projeto; storage/ já é ignorado pelo git (e *.pmtiles também). */
    public const DEFAULT_FILE = 'storage/tiles/brasil.pmtiles';
    public const MIME = 'application/vnd.pmtiles';
    private const CHUNK = 65536;

    /** A rota PHP só existe em dev/local. */
    public static function devRouteEnabled(): bool
    {
        return in_array(strtolower(trim((string) getenv('APP_ENV'))), ['dev', 'local'], true);
    }

    /**
     * Caminho do arquivo no disco: fixo (DEFAULT_FILE). MAP_TILES_FILE (configuração do servidor, nunca da
     * requisição) permite apontar outro arquivo .pmtiles absoluto, p.ex. nos testes.
     */
    public static function file(): string
    {
        $custom = (string) getenv('MAP_TILES_FILE');
        if ($custom !== '' && str_starts_with($custom, '/') && str_ends_with($custom, '.pmtiles') && !str_contains($custom, '..')) {
            return $custom;
        }
        return dirname(__DIR__) . '/' . self::DEFAULT_FILE;
    }

    /**
     * Interpreta o header Range para um arquivo de $size bytes.
     * - null: sem Range utilizável (ausente, sintaxe inválida, outra unidade ou várias faixas) -> 200 completo;
     * - false: faixa válida mas fora do arquivo -> 416;
     * - [início, fim] inclusivos -> 206.
     *
     * @return array{0: int, 1: int}|false|null
     */
    public static function parseRange(?string $header, int $size): array|false|null
    {
        $header = trim((string) $header);
        if ($header === '' || preg_match('/^bytes=(\d*)-(\d*)$/i', $header, $m) !== 1 || ($m[1] === '' && $m[2] === '')) {
            return null;
        }
        if (strlen($m[1]) > 18 || strlen($m[2]) > 18) {
            return false;
        }
        if ($m[1] === '') {
            $suffix = (int) $m[2]; // bytes=-N: os últimos N bytes
            if ($suffix === 0 || $size === 0) {
                return false;
            }
            return [max(0, $size - $suffix), $size - 1];
        }
        $start = (int) $m[1];
        if ($m[2] !== '' && (int) $m[2] < $start) {
            return null; // fim antes do início: sintaxe inválida, ignora o Range (RFC 9110)
        }
        if ($start >= $size) {
            return false;
        }
        $end = $m[2] === '' ? $size - 1 : min((int) $m[2], $size - 1);
        return [$start, $end];
    }

    /** Atende GET/HEAD em URL_PATH (dev). Envia status, headers e corpo; quem chama encerra a requisição. */
    public static function serve(string $method, ?string $rangeHeader): void
    {
        header_remove('X-Powered-By');
        if ($method !== 'GET' && $method !== 'HEAD') {
            http_response_code(405);
            header('Allow: GET, HEAD');
            header('Content-Type: text/plain; charset=utf-8');
            echo "Método não permitido.\n";
            return;
        }
        $file = self::file();
        clearstatcache(true, $file);
        $size = is_file($file) && is_readable($file) ? filesize($file) : false;
        if ($size === false) {
            http_response_code(404);
            header('Content-Type: text/plain; charset=utf-8');
            header('Cache-Control: no-store');
            if ($method === 'GET') {
                echo "Arquivo de mapa ausente (" . self::DEFAULT_FILE . "). Veja o README do mapa.\n";
            }
            return;
        }
        $mtime = (int) filemtime($file);
        $etag = '"' . dechex($mtime) . '-' . dechex($size) . '"';
        header('Accept-Ranges: bytes');
        header('Content-Type: ' . self::MIME);
        header('ETag: ' . $etag);
        header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT');
        header('Cache-Control: public, max-age=86400');
        header('X-Content-Type-Options: nosniff');

        $range = self::parseRange($rangeHeader, $size);
        $ifRange = trim((string) ($_SERVER['HTTP_IF_RANGE'] ?? ''));
        if ($range !== null && $ifRange !== '' && $ifRange !== $etag) {
            $range = null; // arquivo mudou desde a primeira leitura: manda inteiro
        }
        if ($range === false) {
            http_response_code(416);
            header('Content-Range: bytes */' . $size);
            header('Content-Length: 0');
            return;
        }
        [$start, $end] = $range ?? [0, $size - 1];
        $length = $size === 0 ? 0 : $end - $start + 1;
        if ($range !== null) {
            http_response_code(206);
            header('Content-Range: bytes ' . $start . '-' . $end . '/' . $size);
        } else {
            http_response_code(200);
        }
        header('Content-Length: ' . $length);
        if ($method === 'HEAD' || $length === 0) {
            return;
        }
        $fh = fopen($file, 'rb');
        if ($fh === false) {
            return;
        }
        fseek($fh, $start);
        $left = $length;
        while ($left > 0 && !feof($fh)) {
            $chunk = fread($fh, min(self::CHUNK, $left));
            if ($chunk === false || $chunk === '') {
                break;
            }
            echo $chunk;
            $left -= strlen($chunk);
        }
        fclose($fh);
    }
}
