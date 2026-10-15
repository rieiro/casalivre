<?php
// PR 7, condição C4 da Segurança (pr64): o HTML muda por Host (tema do grupo) e por sessão, então toda resposta HTML
// sai com "Cache-Control: private, no-cache" (nenhum proxy/CDN guarda). Assets e tiles mantêm os headers de antes.
require __DIR__ . '/lib.php';
require __DIR__ . '/char_helpers.php';
require_once CL_ROOT . '/src/MapTiles.php';
t_temp_db();
$owner = t_seed_owner();
$dir = MapTiles::tilesDir() . '/cl-test-' . bin2hex(random_bytes(4));
mkdir($dir, 0700, true);
$file = $dir . '/teste.pmtiles';
file_put_contents($file, random_bytes(1000));
putenv('APP_ENV=dev');
putenv('MAP_TILES_FILE=' . $file);
$base = t_server();
$cc = static fn (array $r): ?string => preg_match('/^Cache-Control:\s*(.*?)\r?$/mi', $r['headers'], $m) === 1 ? $m[1] : null;
// Página que abre sessão fica com o no-store da sessão PHP (mais restrito); sem sessão, private, no-cache.
$naoCompartilhado = static fn (?string $v): bool => $v === 'private, no-cache' || ($v !== null && str_contains($v, 'no-store') && !str_contains($v, 'public'));
$ct = static fn (array $r): string => preg_match('/^Content-Type:\s*(.*?)\r?$/mi', $r['headers'], $m) === 1 ? $m[1] : '';

echo "HTML\n";
foreach (['/', '/busca', '/entrar', '/cadastro', '/imoveis/' . $owner['hash'], '/nao-existe', '/g/qualquer/'] as $p) {
    $r = t_http('GET', $base . $p);
    t_ok(str_starts_with($ct($r), 'text/html'), "$p: HTML ({$r['status']})");
    t_ok($naoCompartilhado($cc($r)), "$p: Cache-Control privado (" . $cc($r) . ')');
}
$r = t_http('GET', $base . '/', null, ['Host: qualquer.example.com']);
t_ok($naoCompartilhado($cc($r)), 'Host de grupo/desconhecido: Cache-Control privado');
$jar = tempnam(sys_get_temp_dir(), 'jar');
t_login($base, $jar, $owner['email'], $owner['password']);
$r = t_http('GET', $base . '/painel', null, [], $jar);
t_eq([200, 'no-store, no-cache, must-revalidate'], [$r['status'], $cc($r)], 'página logada: no-store da sessão mantido (não enfraquece)');

@unlink($jar);
foreach (['/idioma', '/entrar'] as $p) {
    $r = t_http('POST', $base . $p, '_csrf=errado', ['Content-Type: application/x-www-form-urlencoded']);
    t_eq([419, 'private, no-cache'], [$r['status'], $cc($r)], "$p sem sessão (página de erro HTML): private, no-cache");
    t_ok(str_starts_with($ct($r), 'text/html'), "$p 419: HTML");
}

echo "assets e tiles sem mudança\n";
foreach (['/assets/app.css', '/assets/safe-dom.js', '/assets/manifest.json'] as $p) {
    $r = t_http('GET', $base . $p);
    t_eq([200, null], [$r['status'], $cc($r)], "$p: sem Cache-Control do HTML (servido como arquivo)");
}
$r = t_http('GET', $base . '/tiles/brasil.pmtiles', null, ['Range: bytes=0-9']);
t_eq([206, 'public, max-age=86400'], [$r['status'], $cc($r)], 'tiles: Cache-Control público mantido');
putenv('MAP_TILES_FILE=' . $dir . '/nao-existe.pmtiles');
$r = t_http('GET', t_server() . '/tiles/brasil.pmtiles');
t_eq([404, 'no-store'], [$r['status'], $cc($r)], 'tiles ausente: no-store mantido');
putenv('MAP_TILES_FILE');
putenv('APP_ENV');
@unlink($file);
@rmdir($dir);
t_done();
