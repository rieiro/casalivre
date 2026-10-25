<?php

declare(strict_types=1);

/**
 * URL pública da aplicação, usada em links enviados ao usuário (ativação, redefinição, .ics).
 * Sempre vem de APP_URL (nunca do header Host, que o cliente controla).
 * Sem APP_URL: em APP_ENV=dev/local cai no Host da requisição; fora disso, falha com erro claro.
 */
function app_url(): string
{
    if (class_exists('Db')) {
        Db::loadEnv();
    }
    $env = trim((string) getenv('APP_URL'));
    if ($env !== '') {
        $parts = parse_url($env);
        if (!is_array($parts) || !in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true) || empty($parts['host'])
            || isset($parts['query']) || isset($parts['fragment']) || isset($parts['user'])) {
            throw new RuntimeException('APP_URL inválida: use esquema://host[:porta][/caminho], sem query.');
        }
        return rtrim($env, '/');
    }
    if (expose_account_links()) {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? '127.0.0.1:8080';
        return $scheme . '://' . $host;
    }
    throw new RuntimeException('APP_URL não configurada: obrigatória fora de APP_ENV=dev/local (links de e-mail e .ics).');
}

function base_url(): string
{
    return app_url();
}

function request_base(): string
{
    return app_url();
}

function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8', false);
}

function redirect(string $path): never
{
    header('Location: ' . $path);
    exit;
}

/**
 * Caminho local seguro para redirect (mesma regra do safe_search_return, sem restringir o caminho): precisa começar
 * com uma "/" só; "//", "\\" e CR/LF (crus ou codificados em %) caem em "/" (evita Location: //evil.com).
 */
function safe_local_return(?string $value): string
{
    $value = (string) $value;
    $decoded = rawurldecode($value);
    foreach ([$value, $decoded] as $v) {
        if ($v === '' || $v[0] !== '/' || str_starts_with($v, '//') || str_contains($v, '\\') || preg_match('/[\x00-\x1f\x7f]/', $v) === 1) {
            return '/';
        }
    }
    return $value;
}

function safe_search_return(?string $value): string
{
    $value = str_replace(["\r", "\n"], '', (string) $value);
    if ($value === '' || $value[0] !== '/' || str_starts_with($value, '//') || str_contains($value, '\\')) {
        return '/';
    }
    $path = parse_url($value, PHP_URL_PATH);
    if (!is_string($path) || !in_array($path, ['/', '/busca'], true)) {
        return '/';
    }
    $query = parse_url($value, PHP_URL_QUERY);
    return is_string($query) && $query !== '' ? $path . '?' . $query : $path;
}

function property_ref(array $property): string
{
    return HouseRepository::ensurePublicHash($property);
}

function search_query(array $filters): string
{
    $query = $filters;
    $amenities = $query['amenidades'] ?? [];
    unset($query['amenidades']);
    if (is_array($amenities) && $amenities !== []) {
        $query['amenidade'] = array_values($amenities);
    }
    return http_build_query($query);
}

function ini_bytes(string $value): int
{
    $value = trim($value);
    if ($value === '' || $value === '-1') {
        return 0;
    }
    $unit = strtolower(substr($value, -1));
    $number = (float) $value;
    return match ($unit) {
        'g' => (int) ($number * 1073741824),
        'm' => (int) ($number * 1048576),
        'k' => (int) ($number * 1024),
        default => (int) $number,
    };
}

/** Parágrafo do 413 de upload: tamanho enviado e limite (post_max_size), em MB. */
function oversize_upload_message(int $length, int $max): string
{
    return '<p>Este envio tem ' . number_format($length / 1048576, 1, ',', '') . ' MB e o limite do sistema é '
        . (int) floor($max / 1048576) . ' MB. Escolha menos fotos ou arquivos menores.</p>';
}

function reject_oversize_upload(): void
{
    $length = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
    $max = ini_bytes((string) ini_get('post_max_size'));
    if ($length <= 0 || $max <= 0 || $length <= $max) {
        return;
    }
    http_response_code(413);
    // Roda antes dos headers globais do index.php: manda aqui os mesmos headers de segurança.
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: same-origin');
    header('Content-Security-Policy: ' . csp_header());
    header('Content-Type: text/html; charset=utf-8');
    if (!headers_sent() && !preg_grep('/^cache-control:/i', headers_list())) {
        header('Cache-Control: private, no-cache');
    }
    echo '<!DOCTYPE html><html lang="pt-BR"><meta charset="utf-8"><title>Envio grande demais</title><link rel="stylesheet" href="' . e(asset_url('/assets/app.css')) . '"><body class="err-page err-pad"><h1>Envio grande demais</h1>' . oversize_upload_message($length, $max) . '<p><a href="/">Voltar ao início</a></p></body></html>';
    exit;
}

/**
 * Único ponto que monta o valor do header Content-Security-Policy. Sempre estrita: sem inline/eval em
 * script, sem 'unsafe-inline' em style, sem data:/blob:, sem host externo. O mapa (MapLibre build CSP + PMTiles)
 * usa só arquivos do próprio site: script e worker em /assets/vendor, estilo/fontes/sprites em /assets/map,
 * tiles em /tiles/brasil.pmtiles (fetch com Range, connect-src 'self'). style-src-attr 'none': sem style=
 * no HTML (o MapLibre usa CSSOM, que a CSP não bloqueia).
 */
function csp_header(): string
{
    return implode('; ', [
        "default-src 'self'",
        "script-src 'self'",
        "style-src 'self'",
        "style-src-attr 'none'",
        "img-src 'self'",
        "connect-src 'self'",
        "worker-src 'self'",
        "object-src 'none'",
        "base-uri 'none'",
        "frame-ancestors 'none'",
        "form-action 'self'",
    ]);
}

require_once dirname(__DIR__) . '/AssetManifest.php';
require_once dirname(__DIR__) . '/Theme.php';

/**
 * URL de asset com hash do conteúdo: "/assets/app.css" -> "/assets/app.css?v=<12 hex>" (public/assets/manifest.json,
 * gerado por scripts/build-assets.php). Fora do manifesto (asset novo antes do build) calcula o hash do arquivo;
 * arquivo inexistente volta sem ?v=.
 */
function asset_url(string $path): string
{
    $manifest = AssetManifest::load();
    if (isset($manifest[$path])) {
        return $path . '?v=' . $manifest[$path];
    }
    $abs = AssetManifest::publicDir() . $path;
    if (!str_contains($path, '..') && is_file($abs)) {
        return $path . '?v=' . AssetManifest::hashFile($abs);
    }
    return $path;
}
