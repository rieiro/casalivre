<?php

declare(strict_types=1);

// Security 3.2: a rejected upload name is the basename, without control, bidi, zero-width, or <> characters, capped at 100 + ellipsis.
require __DIR__ . '/lib.php';
require_once CL_ROOT . '/src/PhotoStorage.php';
require_once CL_ROOT . '/src/PhotoFail.php';
require_once CL_ROOT . '/src/Acoes/PhotoAdd.php';

t_temp_db();
$owner = t_seed_owner();
$hid = (int) $owner['house_id'];
$tmp = sys_get_temp_dir() . '/cl-label-' . bin2hex(random_bytes(3));
mkdir($tmp, 0700);
PhotoStorage::$originals = $tmp . '/orig';
PhotoStorage::$public = $tmp . '/pub';
PhotoStorage::$acceptFile = static fn (string $f): bool => is_file($f);
PhotoStorage::$moveFile = static fn (string $from, string $to): bool => copy($from, $to);
file_put_contents($tmp . '/bad', 'not-an-image');

$raw = '../secret/' . str_repeat('a', 5000) . "\n" . "\u{202E}" . '<>';
$expected = str_repeat('a', 100) . '…';
$upload = static function (string $name, int $error = UPLOAD_ERR_OK) use ($tmp): array {
    return [
        'name' => [$name],
        'tmp_name' => [$error === UPLOAD_ERR_OK ? $tmp . '/bad' : ''],
        'error' => [$error],
        'size' => [strlen('not-an-image')],
    ];
};
$safe = static function (string $label, string $msg): void {
    t_ok(mb_strlen($label, 'UTF-8') <= 101, $msg . ' is at most 101 chars');
    t_eq(0, preg_match('/[\p{Cc}\x{202A}-\x{202E}\x{200B}-\x{200F}<>]/u', $label), $msg . ' has no control, bidi, or <>');
};

echo "storage\n";
$stored = PhotoStorage::storeUploads((int) $owner['user_id'], $hid, $upload($raw));
t_eq([['name' => $expected, 'reason' => PhotoFail::WRONG_TYPE]], $stored['rejected'], 'wrong type returns the capped basename');
$safe($stored['rejected'][0]['name'], 'rejected name');
$tooBig = PhotoStorage::storeUploads((int) $owner['user_id'], $hid, $upload($raw, UPLOAD_ERR_FORM_SIZE));
t_eq($expected, $tooBig['rejected'][0]['name'], 'the form-size path uses the same label');
$safe($tooBig['rejected'][0]['name'], 'form-size name');
$plain = PhotoStorage::storeUploads((int) $owner['user_id'], $hid, $upload('dir/foto.png'));
t_eq('foto.png', $plain['rejected'][0]['name'], 'a short path keeps the basename');
$marked = PhotoStorage::storeUploads((int) $owner['user_id'], $hid, $upload('a<b>.jpg'));
t_eq('ab.jpg', $marked['rejected'][0]['name'], 'angle brackets are removed');

echo "action\n";
$res = PhotoAdd::run((int) $owner['user_id'], $hid, $upload($raw));
t_eq($expected, $res->dados['rejeitadas'][0]['name'], 'PhotoAdd keeps the sanitized name');
t_ok(str_contains((string) $res->mensagem, $expected), 'the notice uses the sanitized name');
t_eq(0, preg_match('/[\p{Cc}\x{202A}-\x{202E}\x{200B}-\x{200F}<>]/u', (string) $res->mensagem), 'the notice has no control, bidi, or <>');
t_eq(0, preg_match('/\p{Cc}/u', (string) $res->dados['erros']['fotos']), 'the error text has no control chars');

echo "web and API\n";
$base = t_server();
$jar = tempnam(sys_get_temp_dir(), 'jar');
$csrf = t_login($base, $jar, $owner['email'], $owner['password']);
$boundary = 'b' . bin2hex(random_bytes(4));
$body = '--' . $boundary . "\r\n"
    . 'Content-Disposition: form-data; name="fotos[0]"; filename="' . $raw . "\"\r\n"
    . "Content-Type: application/octet-stream\r\n\r\n"
    . "not-an-image\r\n--" . $boundary . "--\r\n";
$api = t_http('POST', $base . '/api/casas/' . $owner['hash'] . '/fotos', $body, ['Content-Type: multipart/form-data; boundary=' . $boundary, 'X-CSRF-Token: ' . $csrf], $jar);
$json = json_decode($api['body'], true);
t_eq(422, $api['status'], 'API rejects the file');
$apiName = (string) ($json['rejeitadas'][0]['name'] ?? '');
t_eq($expected, $apiName, 'API JSON returns the capped name');
$safe($apiName, 'API name');
t_ok(str_contains((string) ($json['error'] ?? ''), $expected), 'API error uses the capped name');
t_eq(0, preg_match('/[\p{Cc}\x{202A}-\x{202E}\x{200B}-\x{200F}<>]/u', (string) ($json['error'] ?? '')), 'API error has no control, bidi, or <>');
$webBoundary = 'w' . bin2hex(random_bytes(4));
$webBody = '--' . $webBoundary . "\r\nContent-Disposition: form-data; name=\"_csrf\"\r\n\r\n" . $csrf . "\r\n"
    . '--' . $webBoundary . "\r\n"
    . 'Content-Disposition: form-data; name="fotos[0]"; filename="' . $raw . "\"\r\n"
    . "Content-Type: application/octet-stream\r\n\r\n"
    . "not-an-image\r\n--" . $webBoundary . "--\r\n";
$web = t_http('POST', $base . '/imoveis/' . $owner['hash'] . '/fotos', $webBody, ['Content-Type: multipart/form-data; boundary=' . $webBoundary], $jar);
t_eq(302, $web['status'], 'web upload redirects');
$edit = t_http('GET', $base . '/imoveis/' . $owner['hash'] . '/editar', null, [], $jar);
t_ok(str_contains($edit['body'], $expected), 'the edit page shows the capped name');
t_ok(!str_contains($edit['body'], "\u{202E}"), 'the edit page has no RLO');

t_done();
