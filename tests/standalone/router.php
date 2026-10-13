<?php
// Router do `php -S` dos testes: GET /__t_server_nonce devolve o nonce deste servidor (prova que a resposta vem
// do NOSSO processo, não de outro na mesma porta); o resto vai para o front controller normal.
if (($_SERVER['REQUEST_URI'] ?? '') === '/__t_server_nonce') {
    header('Content-Type: text/plain');
    echo (string) getenv('T_SERVER_NONCE');
    return true;
}
return require __DIR__ . '/../../public/index.php';
