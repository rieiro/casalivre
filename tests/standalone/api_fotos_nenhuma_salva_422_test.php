<?php
/**
 * Contrato: POST /api/casas/{hash}/fotos com nenhuma foto salva responde 422
 * ({error, erros.fotos, enviadas, salvas:0}) e não cria linhas em photos.
 *
 * Pendente desde #87; implementado no #90 (salvas===0 → 422; parcial continua 201).
 * Merge após #90 (ou junto com ele).
 */
declare(strict_types=1);

require __DIR__ . '/lib.php';
require __DIR__ . '/char_helpers.php';
require_once CL_ROOT . '/src/PhotoRepository.php';
require_once CL_ROOT . '/src/ActivityLog.php';
require_once CL_ROOT . '/src/Acoes/PhotoAdd.php';

t_temp_db();
$owner = t_seed_owner();
$base = t_server();
$jar = tempnam(sys_get_temp_dir(), 'jar');
$csrf = t_login($base, $jar, $owner['email'], $owner['password']);
$hash = $owner['hash'];
$hid = $owner['house_id'];
$act = c_activity_file();

$multipart = static function (array $parts, string $bound): string {
    $b = '';
    foreach ($parts as $i => [$filename, $body, $ct]) {
        $b .= "--$bound\r\nContent-Disposition: form-data; name=\"fotos[$i]\"; filename=\"$filename\"\r\nContent-Type: $ct\r\n\r\n$body\r\n";
    }
    return $b . "--$bound--\r\n";
};
$post = static function (string $body, string $bound) use ($base, $hash, $csrf, $jar): array {
    return t_http(
        'POST',
        $base . '/api/casas/' . $hash . '/fotos',
        $body,
        ['Content-Type: multipart/form-data; boundary=' . $bound, 'X-CSRF-Token: ' . $csrf, 'Accept: application/json'],
        $jar
    );
};

echo "API: todas as fotos recusadas -> 422\n";
$antes = count(PhotoRepository::list($hid));
$m = c_mark($act);
$r = $post($multipart([['x.jpg', 'nao-e-imagem', 'image/jpeg']], 'b0'), 'b0');
$j = $r['json'] ?? (json_decode($r['body'], true) ?: []);
t_eq(422, $r['status'], '1 inválida -> 422');
t_eq(0, $j['salvas'] ?? null, 'salvas=0');
t_eq(1, $j['enviadas'] ?? null, 'enviadas=1');
t_ok(isset($j['error'], $j['erros']['fotos']) && $j['error'] === $j['erros']['fotos'] && $j['error'] !== '', '{error, erros.fotos} iguais e não vazios');
t_eq($antes, count(PhotoRepository::list($hid)), 'DB: nenhuma foto criada');

echo "API: nada chegou (multipart vazio) -> 422 photo.none_arrived\n";
$antes = count(PhotoRepository::list($hid));
$r = $post("--b1--\r\n", 'b1');
$j = $r['json'] ?? (json_decode($r['body'], true) ?: []);
t_eq([422, 0, 0, t('photo.none_arrived')], [$r['status'], $j['salvas'] ?? null, $j['enviadas'] ?? null, $j['error'] ?? null], 'vazio -> 422 photo.none_arrived');
t_eq($antes, count(PhotoRepository::list($hid)), 'DB: ainda sem fotos');

echo "API: parcial (1 ok + 1 inválida) continua 201\n";
$img = imagecreatetruecolor(8, 8);
ob_start();
imagejpeg($img);
$jpeg = (string) ob_get_clean();
$antes = count(PhotoRepository::list($hid));
$m = c_mark($act);
$r = $post($multipart([['ok.jpg', $jpeg, 'image/jpeg'], ['x.jpg', 'nao-e-imagem', 'image/jpeg']], 'b2'), 'b2');
$j = $r['json'] ?? (json_decode($r['body'], true) ?: []);
t_eq(201, $r['status'], 'parcial -> 201');
t_eq([1, 2], [$j['salvas'] ?? null, $j['enviadas'] ?? null], 'parcial: salvas=1 enviadas=2');
t_eq($antes + 1, count(PhotoRepository::list($hid)), 'DB: +1 foto');
$logs = c_logs($act, $m);
t_eq([ActivityLog::PHOTO_ADD], array_column($logs, 'code'), 'PHOTO_ADD gravado');
t_eq([2, 1], [$logs[0]['params']['enviadas'] ?? null, $logs[0]['params']['salvas'] ?? null], 'log enviadas=2 salvas=1');

t_done();
