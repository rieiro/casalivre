<?php
// Rota dev do arquivo de tiles do mapa (/tiles/brasil.pmtiles, MapTiles): Range (206/416), arquivo inteiro (200),
// HEAD, caminho fixo (sem traversal) e só em dev/local. Cobre CT-MAPA-001..004.
// Em produção o nginx serve o arquivo (ver prompts/mapa-pmtiles.md); a rota PHP não existe (404).
require __DIR__ . '/lib.php';
require_once CL_ROOT . '/src/MapTiles.php';
t_temp_db();

// MAP_TILES_FILE só vale dentro de storage/tiles/: o arquivo de teste fica num subdiretório próprio dele
// (nunca mexe num brasil.pmtiles real).
$dir = MapTiles::tilesDir() . '/cl-test-' . bin2hex(random_bytes(4));
mkdir($dir, 0700, true);
$file = $dir . '/teste.pmtiles';
$data = random_bytes(70000) . 'FIM';
file_put_contents($file, $data);
$total = strlen($data);
$url = '/tiles/brasil.pmtiles';
$hdr = static function (array $r, string $name): ?string {
    return preg_match('/^' . preg_quote($name, '/') . ':\s*(.*?)\r?$/mi', $r['headers'], $m) === 1 ? $m[1] : null;
};

// HEAD por socket cru: prova que o servidor não manda corpo (curl com CUSTOMREQUEST esperaria o corpo).
$head = static function (string $base, string $path, array $headers = []): array {
    $u = parse_url($base);
    $fp = fsockopen((string) $u['host'], (int) $u['port'], $errno, $errstr, 5);
    fwrite($fp, "HEAD $path HTTP/1.1\r\nHost: {$u['host']}\r\nConnection: close\r\n" . implode('', array_map(static fn ($h) => "$h\r\n", $headers)) . "\r\n");
    stream_set_timeout($fp, 5);
    $raw = (string) stream_get_contents($fp);
    fclose($fp);
    [$h, $body] = array_pad(explode("\r\n\r\n", $raw, 2), 2, '');
    preg_match('#^HTTP/\S+ (\d+)#', $h, $m);
    return ['status' => (int) ($m[1] ?? 0), 'headers' => $h, 'body' => $body];
};

echo "parseRange (unidade)\n";
t_eq([0, 511], MapTiles::parseRange('bytes=0-511', 1000), 'bytes=0-511');
t_eq([900, 999], MapTiles::parseRange('bytes=900-', 1000), 'bytes=900- até o fim');
t_eq([900, 999], MapTiles::parseRange('bytes=-100', 1000), 'sufixo bytes=-100');
t_eq([0, 999], MapTiles::parseRange('bytes=-5000', 1000), 'sufixo maior que o arquivo = arquivo todo');
t_eq([990, 999], MapTiles::parseRange('bytes=990-5000', 1000), 'fim além do arquivo é cortado');
t_eq(false, MapTiles::parseRange('bytes=1000-', 1000), 'início = tamanho -> 416');
t_eq(false, MapTiles::parseRange('bytes=-0', 1000), 'sufixo zero -> 416');
t_eq(false, MapTiles::parseRange('bytes=99999999999999999999-', 1000), 'número gigante -> 416');
foreach (['', 'bytes=', 'bytes=-', 'bytes=5-1', 'items=0-1', 'bytes=0-1,5-9', 'bytes=a-b', 'bytes= 0-1'] as $h) {
    t_eq(null, MapTiles::parseRange($h, 1000), "Range '$h' ignorado (arquivo inteiro)");
}

echo "dev: GET/HEAD com e sem Range\n";
putenv('APP_ENV=dev');
putenv('MAP_TILES_FILE=' . $file);
$base = t_server();

$r = t_http('GET', $base . $url, null, ['Range: bytes=0-511']);
t_eq(206, $r['status'], 'CT-MAPA-001: Range 0-511 -> 206');
t_eq('bytes 0-511/' . $total, $hdr($r, 'Content-Range'), 'CT-MAPA-001: Content-Range bytes 0-511/<total>');
t_eq('bytes', $hdr($r, 'Accept-Ranges'), 'CT-MAPA-001: Accept-Ranges: bytes');
t_eq('512', $hdr($r, 'Content-Length'), 'Content-Length 512');
t_ok($r['body'] === substr($data, 0, 512), 'CT-MAPA-001: corpo = 512 bytes certos');
t_eq('application/vnd.pmtiles', $hdr($r, 'Content-Type'), 'Content-Type application/vnd.pmtiles');

$r = t_http('GET', $base . $url, null, ['Range: bytes=' . ($total - 3) . '-']);
t_ok($r['status'] === 206 && $r['body'] === 'FIM', 'faixa aberta até o fim');
$r = t_http('GET', $base . $url, null, ['Range: bytes=-3']);
t_ok($r['status'] === 206 && $r['body'] === 'FIM' && $hdr($r, 'Content-Range') === 'bytes ' . ($total - 3) . '-' . ($total - 1) . '/' . $total, 'sufixo bytes=-3');
$r = t_http('GET', $base . $url, null, ['Range: bytes=1000-1999']);
t_ok($r['status'] === 206 && $r['body'] === substr($data, 1000, 1000), 'faixa no meio do arquivo');

$r = t_http('GET', $base . $url, null, ['Range: bytes=' . ($total + 10) . '-']);
t_eq(416, $r['status'], 'CT-MAPA-002: Range além do fim -> 416');
t_eq('bytes */' . $total, $hdr($r, 'Content-Range'), 'CT-MAPA-002: Content-Range bytes */<total>');
t_eq('', $r['body'], '416 sem corpo');

$r = t_http('GET', $base . $url);
t_eq(200, $r['status'], 'CT-MAPA-003: sem Range -> 200');
t_eq((string) $total, $hdr($r, 'Content-Length'), 'CT-MAPA-003: Content-Length = tamanho do arquivo');
t_ok($r['body'] === $data, 'CT-MAPA-003: corpo = arquivo inteiro');
t_eq('bytes', $hdr($r, 'Accept-Ranges'), '200 também anuncia Accept-Ranges');
t_ok(($hdr($r, 'ETag') ?? '') !== '' && ($hdr($r, 'Last-Modified') ?? '') !== '' && str_contains((string) $hdr($r, 'Cache-Control'), 'max-age'), 'ETag, Last-Modified e Cache-Control');
$etag = (string) $hdr($r, 'ETag');

$r = t_http('GET', $base . $url, null, ['Range: bytes=0-1,5-9']);
t_ok($r['status'] === 200 && strlen($r['body']) === $total, 'várias faixas -> 200 arquivo inteiro');
$r = t_http('GET', $base . $url, null, ['Range: bytes=9-2']);
t_eq(200, $r['status'], 'Range malformado (fim < início) -> 200');
$r = t_http('GET', $base . $url, null, ['Range: bytes=0-9', 'If-Range: "outro"']);
t_ok($r['status'] === 200 && strlen($r['body']) === $total, 'If-Range diferente do ETag -> 200 inteiro');
$r = t_http('GET', $base . $url, null, ['Range: bytes=0-9', 'If-Range: ' . $etag]);
t_ok($r['status'] === 206 && strlen($r['body']) === 10, 'If-Range igual ao ETag -> 206');

$r = $head($base, $url);
t_ok($r['status'] === 200 && $hdr($r, 'Content-Length') === (string) $total && $hdr($r, 'Accept-Ranges') === 'bytes' && $r['body'] === '', 'HEAD: 200, Content-Length, Accept-Ranges, sem corpo');
$r = $head($base, $url, ['Range: bytes=0-99']);
t_ok($r['status'] === 206 && $hdr($r, 'Content-Range') === 'bytes 0-99/' . $total && $hdr($r, 'Content-Length') === '100' && $r['body'] === '', 'HEAD com Range: 206 sem corpo');
$r = t_http('POST', $base . $url, 'x');
t_ok($r['status'] === 405 && str_contains((string) $hdr($r, 'Allow'), 'GET'), 'POST -> 405 com Allow');

echo "caminho fixo (sem traversal)\n";
file_put_contents(CL_ROOT . '/storage/cache/segredo-tiles-test.txt', 'SEGREDO-NAO-VAZA');
foreach ([
    '/tiles/../.env', '/tiles/%2e%2e/.env', '/tiles/..%2f..%2fsrc/Db.php', '/tiles/brasil.pmtiles/../../src/Db.php',
    '/tiles/outro.pmtiles', '/tiles/brasil.pmtiles%00', '/tiles/BRASIL.pmtiles', '/tiles/brasil.pmtiles/', '/tiles/',
    '/tiles/..%2f..%2fstorage/cache/segredo-tiles-test.txt', '/tiles/brasil.pmtiles?file=../../storage/cache/segredo-tiles-test.txt',
] as $p) {
    $r = t_http('GET', $base . $p, null, ['Range: bytes=0-99']);
    $leak = str_contains($r['body'], 'SEGREDO') || str_contains($r['body'], '<?php') || str_contains($r['body'], 'GEO_SALT=');
    if (str_contains($p, '?file=')) {
        t_ok($r['status'] === 206 && $r['body'] === substr($data, 0, 100) && !$leak, "$p: query ignorada, sempre o arquivo fixo");
        continue;
    }
    t_ok($r['status'] === 404 && !$leak && $hdr($r, 'Content-Range') === null, "$p -> 404, sem servir outro arquivo (status {$r['status']})");
}
@unlink(CL_ROOT . '/storage/cache/segredo-tiles-test.txt');
echo "MAP_TILES_FILE restrito a storage/tiles/ (realpath)\n";
$out = sys_get_temp_dir() . '/cl-fora-' . bin2hex(random_bytes(4));
mkdir($out, 0700, true);
file_put_contents($out . '/fora.pmtiles', 'FORA-DO-DIRETORIO');
$sibling = MapTiles::tilesDir() . '-irmao-' . bin2hex(random_bytes(3)); // storage/tiles-irmao-x: prefixo sem separador
mkdir($sibling, 0700, true);
file_put_contents($sibling . '/x.pmtiles', 'IRMAO');
symlink($out . '/fora.pmtiles', $dir . '/link-fora.pmtiles');
symlink($out, $dir . '/dir-fora');
$log = tempnam(sys_get_temp_dir(), 'tileslog');
$oldLog = ini_set('error_log', $log);
$cases = [
    [$out . '/fora.pmtiles', 'caminho fora de storage/tiles/'],
    [$dir . '/link-fora.pmtiles', 'symlink dentro de storage/tiles/ apontando para fora'],
    [$dir . '/dir-fora/fora.pmtiles', 'diretório symlink para fora'],
    [$dir . '/../../../src/Db.php', "'..' saindo de storage/tiles/"],
    [$dir . '/../../tiles/../../' . basename($out) . '/fora.pmtiles', "'..' encadeado"],
    [$sibling . '/x.pmtiles', 'diretório irmão storage/tiles-* (prefixo sem separador)'],
    ['/etc/passwd', '/etc/passwd'],
    ['relativo.pmtiles', 'caminho relativo'],
    [$dir . '/nao-existe.pmtiles', 'arquivo inexistente'],
];
foreach ($cases as [$path, $label]) {
    putenv('MAP_TILES_FILE=' . $path);
    file_put_contents($log, '');
    t_eq(null, MapTiles::file(), "$label -> recusado (null)");
    t_ok(str_contains((string) file_get_contents($log), 'map_tiles_file_rejected') && !str_contains((string) file_get_contents($log), $path), "$label -> log sem o caminho");
}
putenv('MAP_TILES_FILE=' . $dir . '/../' . basename($dir) . '/teste.pmtiles');
t_eq(realpath($file), MapTiles::file(), "'..' que continua dentro de storage/tiles/ resolve para o realpath");
putenv('MAP_TILES_FILE=' . $file);
t_eq(realpath($file), MapTiles::file(), 'arquivo dentro de storage/tiles/ aceito');
putenv('MAP_TILES_FILE');
t_eq(MapTiles::tilesDir() . '/brasil.pmtiles', MapTiles::file(), 'sem MAP_TILES_FILE: caminho fixo storage/tiles/brasil.pmtiles');
ini_set('error_log', (string) $oldLog);

echo "HTTP com MAP_TILES_FILE fora: 404\n";
foreach ([$out . '/fora.pmtiles' => 'fora', $dir . '/link-fora.pmtiles' => 'symlink'] as $path => $label) {
    putenv('MAP_TILES_FILE=' . $path);
    $b = t_server();
    $r = t_http('GET', $b . $url, null, ['Range: bytes=0-99']);
    t_ok($r['status'] === 404 && !str_contains($r['body'], 'FORA-DO-DIRETORIO') && $hdr($r, 'Content-Range') === null, "$label: 404 sem servir o arquivo");
}

echo "arquivo ausente\n";
putenv('MAP_TILES_FILE=' . $dir . '/nao-existe.pmtiles');
$base2 = t_server();
$r = t_http('GET', $base2 . $url, null, ['Range: bytes=0-511']);
t_ok($r['status'] === 404 && str_contains($r['body'], 'ausente'), 'sem o arquivo: 404 com mensagem (o front mostra aviso)');
$r = $head($base2, $url);
t_ok($r['status'] === 404 && $r['body'] === '', 'HEAD sem o arquivo: 404 sem corpo');

echo "produção: rota não existe\n";
putenv('APP_ENV=production');
putenv('MAP_TILES_FILE=' . $file);
$base3 = t_server();
$r = t_http('GET', $base3 . $url, null, ['Range: bytes=0-511']);
t_eq(404, $r['status'], 'CT-MAPA-004: APP_ENV=production -> 404');
t_ok(!str_contains($r['body'], substr($data, 0, 64)) && $hdr($r, 'Content-Range') === null, 'CT-MAPA-004: nada do arquivo');

putenv('APP_ENV');
putenv('MAP_TILES_FILE');
@unlink($file);
@unlink($dir . '/link-fora.pmtiles');
@unlink($dir . '/dir-fora');
@rmdir($dir);
@unlink($out . '/fora.pmtiles');
@rmdir($out);
@unlink($sibling . '/x.pmtiles');
@rmdir($sibling);
@unlink($log);
t_done();
