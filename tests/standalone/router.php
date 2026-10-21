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
// Teste pode pedir os avisos/erros do PHP num arquivo (ex.: provar que não há "Array to string conversion").
if (getenv('T_PHP_ERROR_LOG')) {
    ini_set('log_errors', '1');
    ini_set('error_log', (string) getenv('T_PHP_ERROR_LOG'));
}
return require __DIR__ . '/../../public/index.php';
