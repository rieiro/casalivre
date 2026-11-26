<?php
// Router do `php -S` dos testes: GET /__t_server_nonce devolve o nonce deste servidor (prova que a resposta vem
// do NOSSO processo, não de outro na mesma porta); o resto vai para o front controller normal.
if (($_SERVER['REQUEST_URI'] ?? '') === '/__t_server_nonce') {
    header('Content-Type: text/plain');
    echo (string) getenv('T_SERVER_NONCE');
    return true;
}
if (getenv('T_RATELIMIT_NS_FILE')) {
    require_once __DIR__ . '/../../src/IpRateLimiter.php';
    IpRateLimiter::$namespace = (string) @file_get_contents((string) getenv('T_RATELIMIT_NS_FILE'));
}
// Fixture for the OTA listing fetch (WAF/anti-bot statuses and headers) in route tests: a JSON map of URL => response.
if (getenv('T_OTA_HTML_FIXTURE')) {
    require_once __DIR__ . '/../../src/CalendarSync.php';
    $__otaFixture = (string) getenv('T_OTA_HTML_FIXTURE');
    CalendarSync::$httpTransport = static function (string $url, int $maxBytes) use ($__otaFixture): array {
        $data = json_decode((string) @file_get_contents($__otaFixture), true);
        if (!is_array($data)) {
            return ['error' => 'http_4xx', 'http' => 404];
        }
        $entry = $data[$url] ?? $data['*'] ?? ['error' => 'http_4xx', 'http' => 404];
        return is_array($entry) ? $entry : ['error' => 'http_4xx', 'http' => 404];
    };
}
// Teste pode pedir os avisos/erros do PHP num arquivo (ex.: provar que não há "Array to string conversion").
if (getenv('T_PHP_ERROR_LOG')) {
    ini_set('log_errors', '1');
    ini_set('error_log', (string) getenv('T_PHP_ERROR_LOG'));
}
return require __DIR__ . '/../../public/index.php';
