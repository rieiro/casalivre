<?php
// asset_url() + public/assets/manifest.json (Sprint 3, Etapa 0): manifesto em dia, URLs com hash do conteúdo,
// nenhum ?v= manual nas views/JS e a CSP continua estrita.
require __DIR__ . '/lib.php';
require_once CL_ROOT . '/src/helpers.php';

exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(CL_ROOT . '/scripts/build-assets.php') . ' --check 2>&1', $out, $code);
t_eq(0, $code, 'manifest.json em dia (php scripts/build-assets.php --check): ' . implode(' ', $out));

$manifest = AssetManifest::load();
t_ok(count($manifest) > 5, 'manifesto não vazio (' . count($manifest) . ' arquivos)');
foreach ($manifest as $path => $hash) {
    t_ok((bool) preg_match('/^[0-9a-f]{12}$/', $hash), "$path: hash de 12 hex");
    t_eq(AssetManifest::hashFile(CL_ROOT . '/public' . $path), $hash, "$path: hash confere com o conteúdo");
}
t_ok(!isset($manifest['/assets/manifest.json']), 'manifesto não lista a si mesmo');

$h = AssetManifest::hashFile(CL_ROOT . '/public/assets/app.css');
t_eq('/assets/app.css?v=' . $h, asset_url('/assets/app.css'), 'asset_url: caminho + ?v=hash');
t_eq('/assets/nao-existe.js', asset_url('/assets/nao-existe.js'), 'asset_url: arquivo inexistente sem ?v=');
t_eq('/assets/../src/Db.php', asset_url('/assets/../src/Db.php'), 'asset_url: caminho com .. não é resolvido');

$manual = [];
foreach (array_merge(glob(CL_ROOT . '/views/*.php') ?: [], glob(CL_ROOT . '/public/assets/*.js') ?: [], glob(CL_ROOT . '/public/assets/pages/*.js') ?: []) as $f) {
    if (preg_match('#/assets/[^"\'\s?]+\?v=#', (string) file_get_contents($f))) {
        $manual[] = basename($f);
    }
}
t_eq([], $manual, 'nenhum ?v= manual em views/ e nos JS do app');

t_temp_db();
$base = t_server();
$urls = [];
foreach (['/', '/busca', '/busca/mapa?localidade=' . urlencode('Florianópolis, SC'), '/entrar'] as $page) {
    $r = t_http('GET', $base . $page);
    t_eq(csp_header(), (function () use ($r) {
        preg_match('/^Content-Security-Policy: (.+)$/mi', $r['headers'], $m);
        return trim($m[1] ?? '');
    })(), "$page: CSP estrita inalterada");
    preg_match_all('#(?:src|href|data-style|data-worker)="(/assets/[^"]+)"#', $r['body'], $m);
    $urls = array_merge($urls, $m[1]);
}
$urls = array_unique($urls);
t_ok(count($urls) >= 8, 'páginas referenciam assets (' . count($urls) . ')');
foreach ($urls as $u) {
    [$p, $q] = array_pad(explode('?v=', $u, 2), 2, '');
    t_eq($manifest[$p] ?? null, $q, "$u: ?v= é o hash do manifesto");
    $r = t_http('GET', $base . $u);
    t_eq(200, $r['status'], "$u servido (200)");
}
t_done();
