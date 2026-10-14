<?php
// fix/csp-413: o 413 de upload (reject_oversize_upload, antes dos headers globais do index.php)
// manda CSP, nosniff e X-Frame-Options, e não usa href javascript:.
require __DIR__ . '/lib.php';
t_temp_db();
require_once CL_ROOT . '/src/helpers.php';
$base = t_server();
$limite = ini_bytes((string) ini_get('post_max_size'));
$bound = 'b413';
$corpo = "--$bound\r\nContent-Disposition: form-data; name=\"fotos[0]\"; filename=\"a.png\"\r\nContent-Type: image/png\r\n\r\n"
    . str_repeat('a', $limite + 1024) . "\r\n--$bound--\r\n";
$r = t_http('POST', $base . '/imoveis/x/fotos', $corpo, ['Content-Type: multipart/form-data; boundary=' . $bound]);
t_eq(413, $r['status'], 'upload acima do post_max_size -> 413');
t_ok((bool) preg_match('/^Content-Security-Policy:\s*(.+?)\s*$/mi', $r['headers'], $m), '413 com Content-Security-Policy');
t_eq(csp_header(), $m[1] ?? null, '413: CSP igual à global');
t_ok((bool) preg_match('/^X-Content-Type-Options:\s*nosniff\s*$/mi', $r['headers']), '413 com nosniff');
t_ok((bool) preg_match('/^X-Frame-Options:\s*DENY\s*$/mi', $r['headers']), '413 com X-Frame-Options DENY');
t_ok(stripos($r['body'], 'javascript:') === false, '413 sem href javascript:');
t_ok(str_contains($r['body'], '<a href="/">'), '413 com link para /');
t_ok(!preg_match('/<style|style="/i', $r['body']), '413 sem style inline');
t_done();
