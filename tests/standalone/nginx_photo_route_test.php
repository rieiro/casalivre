<?php
// P0-3b (L3 of the #111 review): the nginx photo-upload location (ops/dev-env/templates/nginx.conf.in, where
// the upload body limits apply) must match exactly the POST photo routes of public/index.php.
declare(strict_types=1);
require __DIR__ . '/lib.php';

$conf = (string) file_get_contents(CL_ROOT . '/ops/dev-env/templates/nginx.conf.in');
preg_match_all('/location ~ "([^"]+)"/', $conf, $m);
$nginx = array_values(array_filter($m[1], static fn (string $re): bool => str_contains($re, 'fotos')));
t_eq(1, count($nginx), 'nginx.conf.in has one photo location');
$ngRe = '#' . str_replace('#', '\#', $nginx[0] ?? '^$') . '#';

// Upload routes in index.php: the POST .../fotos$ entries of the per-IP limits (the routes the router serves).
$index = (string) file_get_contents(CL_ROOT . '/public/index.php');
preg_match_all("/\\['POST', '(#\\^[^']*\\/fotos\\$#)'/", $index, $r);
$rotas = $r[1];
t_eq(2, count($rotas), 'index.php has 2 POST photo-upload routes (API and web)');

$hex = 'abcdef0123456789';
foreach ($rotas as $re) {
    $amostra = str_replace(['#^', '$#', '[a-f0-9]{16}'], ['', '', $hex], $re);
    t_ok(preg_match($re, $amostra) === 1, "sample $amostra matches the index.php route");
    t_ok(preg_match($ngRe, $amostra) === 1, "nginx matches $amostra");
}
// Negatives: nginx and index.php must agree on paths around the photo routes.
foreach (["/imoveis/$hex/fotos/excluir", "/imoveis/$hex/avaliar", '/api/casas/xyz/fotos', "/api/imoveis/$hex/fotos", "/imoveis/{$hex}0/fotos", "/x/imoveis/$hex/fotos"] as $neg) {
    $app = array_filter($rotas, static fn (string $re): bool => preg_match($re, $neg) === 1);
    t_eq($app !== [], preg_match($ngRe, $neg) === 1, "nginx and index.php agree on $neg");
}
t_done();
