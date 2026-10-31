<?php
// Fotos assíncronas: upload (finfo jpeg/png/webp) grava o original fora do public/ (0600, nome uuid) e cria a
// foto em processing + ImageQueue; o image-worker (flock 0600, teto de tempo) regrava pelos pixels com GD
// (sem EXIF/GPS), corrige a orientação, gera WebP 320/800/1920 com nome uuid (sem id de usuário/imóvel),
// apaga o original e marca ready; após N falhas (backoff da fila de e-mail) marca failed e apaga o original.
// Dono vê moldura de espera/falha; público só vê fotos prontas (com srcset); CSP continua estrita.
require __DIR__ . '/lib.php';
t_temp_db();
$owner = t_seed_owner();
require_once CL_ROOT . '/src/PhotoStorage.php';
require_once CL_ROOT . '/src/PhotoRepository.php';
require_once CL_ROOT . '/src/ImageWorker.php';
require_once CL_ROOT . '/src/HouseService.php';
require_once CL_ROOT . '/src/ActivityLog.php';
db()->prepare("UPDATE properties SET status = 'active' WHERE id = ?")->execute([$owner['house_id']]);
ListingSearch::sync((int) $owner['house_id']);
$tmp = sys_get_temp_dir() . '/cl-photo-' . bin2hex(random_bytes(3));
mkdir($tmp, 0700);
PhotoStorage::$originals = $tmp . '/storage/fotos/originais';
PhotoStorage::$public = $tmp . '/public/uploads/fotos';
PhotoStorage::$acceptFile = static fn (string $f): bool => is_file($f);
PhotoStorage::$moveFile = static fn (string $from, string $to): bool => copy($from, $to);
$hid = (int) $owner['house_id'];

/** JPEG com EXIF (Orientation e ImageDescription marcadora) inserido logo após o SOI. */
$jpegWithExif = static function (int $w, int $h, int $orientation): string {
    $img = imagecreatetruecolor($w, $h);
    imagefilledrectangle($img, 0, 0, (int) ($w / 2), $h, imagecolorallocate($img, 255, 0, 0));
    ob_start();
    imagejpeg($img, null, 90);
    $jpg = (string) ob_get_clean();
    $desc = "GPSMARKER-SEGREDO\0";
    $tiff = 'II' . pack('v', 42) . pack('V', 8) . pack('v', 2)
        . pack('vvVvv', 0x0112, 3, 1, $orientation, 0)
        . pack('vvVV', 0x010E, 2, strlen($desc), 8 + 2 + 24 + 4)
        . pack('V', 0) . $desc;
    $app1 = "Exif\0\0" . $tiff;
    return substr($jpg, 0, 2) . "\xFF\xE1" . pack('n', strlen($app1) + 2) . $app1 . substr($jpg, 2);
};
$gd = static function (string $fn, int $w, int $h): string {
    $img = imagecreatetruecolor($w, $h);
    ob_start();
    $fn($img);
    return (string) ob_get_clean();
};
$files = [
    'a.jpg' => $jpegWithExif(40, 20, 6),
    'b.png' => $gd('imagepng', 2400, 1200),
    'c.webp' => $gd('imagewebp', 500, 300),
    'd.gif' => $gd('imagegif', 10, 10),
    'e.jpg' => "<?php echo 'não é imagem';",
];
$up = ['name' => [], 'tmp_name' => [], 'error' => [], 'size' => []];
foreach ($files as $name => $bin) {
    $f = $tmp . '/up-' . $name;
    file_put_contents($f, $bin);
    $up['name'][] = $name;
    $up['tmp_name'][] = $f;
    $up['error'][] = UPLOAD_ERR_OK;
    $up['size'][] = strlen($bin);
}
t_ok(str_contains((string) @exif_read_data($tmp . '/up-a.jpg')['ImageDescription'], 'GPSMARKER') || !function_exists('exif_read_data'), 'fixture: JPEG com EXIF');

echo "upload\n";
$saved = PhotoStorage::storeUploads((int) $owner['user_id'], $hid, $up, $created);
t_eq(3, $saved['saved'], 'jpeg, png e webp aceitos; gif e arquivo falso recusados (finfo)');
t_eq(['wrong_type', 'wrong_type'], array_column($saved['rejected'], 'reason'), 'gif e arquivo falso: wrong_type');
$rows = PhotoRepository::list($hid);
t_eq(['processing', 'processing', 'processing'], array_column($rows, 'status'), 'fotos criadas em processing');
t_eq(['', '', ''], array_column($rows, 'path'), 'sem caminho público enquanto processa');
$origs = glob(PhotoStorage::$originals . '/*') ?: [];
t_eq(3, count($origs), 'originais gravados fora do public/');
t_ok(array_reduce($origs, static fn ($c, $f) => $c && preg_match('/^[0-9a-f-]{36}\.(jpg|png|webp)$/', basename($f)) === 1 && (fileperms($f) & 0777) === 0600, true), 'original com nome uuid e 0600');
t_eq('700', substr(sprintf('%o', fileperms(PhotoStorage::$originals)), -3), 'pasta de originais 0700');
t_eq(3, (int) db()->query("SELECT COUNT(*) FROM image_queue WHERE status = 'pending'")->fetchColumn(), '3 itens na ImageQueue');
t_eq([], PhotoRepository::listReady($hid), 'público: nenhuma foto pronta ainda');
t_eq(null, PhotoRepository::primaryPath($hid), 'público: sem capa enquanto processa');

echo "worker\n";
$lock = $tmp . '/image-worker.lock';
$h = fopen($lock, 'c');
flock($h, LOCK_EX);
t_eq(null, ImageWorker::run(ImageQueues::make(), $lock), 'lock ocupado: outro worker não roda');
flock($h, LOCK_UN);
fclose($h);
@unlink($lock);
$rep = ImageWorker::run(ImageQueues::make(), $lock);
t_eq(3, $rep['enviados'] ?? null, 'worker processa as 3');
t_eq('600', substr(sprintf('%o', fileperms($lock)), -3), 'lock 0600');
$rows = PhotoRepository::list($hid);
t_eq(['ready', 'ready', 'ready'], array_column($rows, 'status'), 'fotos prontas');
t_eq([], glob(PhotoStorage::$originals . '/*') ?: [], 'originais apagados');
$ok = true;
foreach ($rows as $r) {
    $ok = $ok && preg_match('#^/uploads/fotos/' . preg_quote((string) $r['uuid'], '#') . '-1920\.webp$#', (string) $r['path']) === 1 && $r['original'] === null;
}
t_ok($ok, 'caminho público /uploads/fotos/<uuid>-1920.webp');
t_ok(!str_contains(implode('|', array_column($rows, 'path')), '/' . $owner['user_id'] . '/') && !str_contains(implode('|', array_column($rows, 'path')), 'imoveis'), 'caminho sem id de usuário nem de imóvel');
$byName = [];
foreach ($rows as $r) {
    foreach (ImageWorker::SIZES as $name => $w) {
        $f = PhotoStorage::variantFile((string) $r['uuid'], $w);
        $byName[(int) $r['sort_order']][$name] = is_file($f) ? getimagesize($f) : null;
    }
}
t_ok(array_reduce($byName, static fn ($c, $s) => $c && $s['thumb'] && $s['card'] && $s['full'] && $s['full']['mime'] === 'image/webp', true), '3 WebP por foto');
t_eq([320, 800, 1920], [$byName[1]['thumb'][0], $byName[1]['card'][0], $byName[1]['full'][0]], 'PNG 2400 px: larguras 320/800/1920');
t_eq([500, 500, 500], [$byName[2]['thumb'][0] === 320 ? 500 : $byName[2]['thumb'][0], $byName[2]['card'][0], $byName[2]['full'][0]], 'WebP 500 px: sem ampliar (card/full ficam 500)');
if (function_exists('exif_read_data')) {
    t_eq([20, 40], [$byName[0]['full'][0], $byName[0]['full'][1]], 'orientação 6 corrigida (40x20 vira 20x40)');
}
$allWebp = '';
foreach ($rows as $r) {
    foreach (ImageWorker::SIZES as $w) {
        $allWebp .= (string) file_get_contents(PhotoStorage::variantFile((string) $r['uuid'], $w));
    }
}
t_ok(!str_contains($allWebp, 'GPSMARKER') && !str_contains($allWebp, 'Exif'), 'WebP sem EXIF/GPS (regravado pelos pixels)');
t_eq(3, count(PhotoRepository::listReady($hid)), 'público: 3 fotos prontas');
$first = (string) $rows[0]['path'];
t_ok(PhotoRepository::primaryPath($hid) !== null, 'capa pública disponível');

echo "falha\n";
$bad = PhotoRepository::insertProcessing($hid, PhotoStorage::randomId(), '', 0, 10);
$badOrig = PhotoRepository::findById((int) $bad['id'])['uuid'] . '.jpg';
db()->prepare('UPDATE photos SET original = ? WHERE id = ?')->execute([$badOrig, (int) $bad['id']]);
file_put_contents(PhotoStorage::$originals . '/' . $badOrig, 'lixo');
ImageQueues::make()->push((int) $bad['id']);
$rep = ImageWorker::run(ImageQueues::make(), $lock);
t_eq(1, $rep['falhas'] ?? null, '1ª tentativa falha');
$q = db()->query('SELECT * FROM image_queue WHERE photo_id = ' . (int) $bad['id'])->fetchAll(PDO::FETCH_ASSOC)[0];
$delay = strtotime($q['next_attempt_at'] . ' UTC') - time();
t_ok($q['status'] === 'pending' && $delay > 50 && $delay <= 60, 'reagendada com o backoff da fila de e-mail (60 s)');
for ($i = 0; $i < Queues::MAX_ATTEMPTS; $i++) {
    db()->exec("UPDATE image_queue SET next_attempt_at = '2000-01-01 00:00:00' WHERE status = 'pending'");
    ImageWorker::run(ImageQueues::make(), $lock);
}
t_eq('failed', PhotoRepository::findById((int) $bad['id'])['status'], 'após N tentativas: foto failed');
t_ok(!is_file(PhotoStorage::$originals . '/' . $badOrig), 'original apagado na desistência');
t_eq('failed', db()->query('SELECT status FROM image_queue WHERE photo_id = ' . (int) $bad['id'])->fetchColumn(), 'item da fila failed');

echo "telas\n";
$proc = PhotoRepository::insertProcessing($hid, PhotoStorage::randomId(), PhotoStorage::randomId() . '.jpg', 0, 11);
$base = t_server();
$jar = tempnam(sys_get_temp_dir(), 'jar');
t_login($base, $jar, $owner['email'], $owner['password']);
$r = t_http('GET', $base . '/imoveis/' . $owner['hash'] . '/editar', null, [], $jar);
t_ok(str_contains($r['body'], 'class="photo-wait"') && str_contains($r['body'], 'photo-wait is-failed'), 'dono: moldura de espera e estado de falha');
$csp = preg_match('/^Content-Security-Policy:\s*(.+)$/mi', $r['headers'], $m) ? $m[1] : '';
t_ok(str_contains($csp, "script-src 'self'") && !str_contains($csp, 'unsafe-inline') && !str_contains($r['body'], 'style="'), 'CSP estrita e sem style inline na tela');
$j = json_decode(t_http('GET', $base . '/api/imoveis/' . $owner['hash'])['body'], true) ?: [];
t_eq(3, count($j['photos'] ?? []), 'API pública: só as 3 prontas');
t_ok(!str_contains(json_encode($j), 'processing') && !str_contains(json_encode($j), 'failed'), 'API pública sem fotos em processamento/falha');
$j = json_decode(t_http('GET', $base . '/api/busca?localidade=' . urlencode('Florianópolis, SC'))['body'], true) ?: [];
t_ok(preg_match('#^/uploads/fotos/[0-9a-f-]{36}-1920\.webp$#', (string) ($j['items'][0]['photo'] ?? '')) === 1, 'busca: capa é uma foto pronta (WebP)');
t_ok(str_contains((string) file_get_contents(CL_ROOT . '/public/assets/pages/search.js'), 'CL.photoImg('), 'busca: cards com srcset (search.js)');
$attr = photo_srcset_attr($first, '100vw');
t_ok(str_contains($attr, '-320.webp 320w') && str_contains($attr, '-800.webp 800w') && str_contains($attr, '-1920.webp 1920w'), 'photo_srcset_attr (result_cards/property.php)');
$js = (string) file_get_contents(CL_ROOT . '/public/assets/safe-dom.js');
t_ok(str_contains($js, 'photoImg(') && str_contains((string) file_get_contents(CL_ROOT . '/public/assets/pages/property.js'), 'CL.photoImg('), 'front: srcset nas fotos públicas');

echo "exclusão\n";
$procRow = PhotoRepository::findById((int) $proc['id']);
file_put_contents(PhotoStorage::$originals . '/' . $procRow['original'], 'x');
PhotoStorage::deletePhotoFiles($procRow);
t_ok(!is_file(PhotoStorage::$originals . '/' . $procRow['original']), 'excluir foto em processamento apaga o original');
$readyRow = PhotoRepository::listReady($hid)[0];
PhotoStorage::deletePhotoFiles($readyRow);
t_ok(!is_file(PhotoStorage::variantFile((string) $readyRow['uuid'], 320)) && !is_file(PhotoStorage::variantFile((string) $readyRow['uuid'], 1920)), 'excluir foto pronta apaga as 3 variantes');

echo "teto de pixels (25 MP)\n";
t_eq(25_000_000, ImageWorker::MAX_PIXELS, 'MAX_PIXELS = 25 MP');
// PNG só com cabeçalho IHDR 6000x5000 (30 MP): recusado antes de decodificar.
$ihdr = pack('NNCCCCC', 6000, 5000, 8, 2, 0, 0, 0);
$png = "\x89PNG\r\n\x1a\n" . pack('N', 13) . 'IHDR' . $ihdr . pack('N', crc32('IHDR' . $ihdr)) . str_repeat("\0", 64);
$big = PhotoRepository::insertProcessing($hid, PhotoStorage::randomId(), '', 0, 20);
$bigOrig = PhotoRepository::findById((int) $big['id'])['uuid'] . '.png';
db()->prepare('UPDATE photos SET original = ? WHERE id = ?')->execute([$bigOrig, (int) $big['id']]);
file_put_contents(PhotoStorage::$originals . '/' . $bigOrig, $png);
$caught = null;
try {
    ImageWorker::process((int) $big['id']);
} catch (Throwable $e) {
    $caught = $e;
}
t_ok($caught instanceof PermanentJobFailure, '30 MP é falha permanente');
t_eq(PhotoFail::TOO_MANY_PIXELS, $caught instanceof PermanentJobFailure ? $caught->reason : '', '30 MP recusado sem decodificar');
db()->prepare('DELETE FROM photos WHERE id = ?')->execute([(int) $big['id']]);
@unlink(PhotoStorage::$originals . '/' . $bigOrig);

echo "seeds e fotos antigas pela fila\n";
$h2 = HouseRepository::insert(['user_id' => $owner['user_id'], 'title' => 'Casa Seed', 'description' => 'd', 'summary' => 's', 'city_place_id' => t_city_place_id('Florianópolis, SC'), 'address' => 'Rua B, 2', 'cep' => '88000000', 'street' => 'Rua B', 'address_number' => '2', 'address_complement' => '', 'lat' => -27.6, 'lng' => -48.5, 'bedrooms' => 1, 'beds' => 1, 'max_guests' => 2, 'nightly_price' => 100, 'checkin_time' => '15:00', 'checkout_time' => '11:00', 'min_nights' => 1, 'pool' => 0, 'bbq' => 0, 'garage' => 0]);
db()->prepare("UPDATE properties SET status = 'active' WHERE id = ?")->execute([$h2]);
ListingSearch::sync((int) $h2);
$seedFile = $tmp . '/seed.png';
file_put_contents($seedFile, $gd('imagepng', 900, 600));
$seedRow = PhotoStorage::enqueueOriginal($h2, $seedFile, 1, 0);
t_eq('processing', $seedRow['status'] ?? null, 'seed: foto em processing');
t_eq(null, PhotoStorage::enqueueOriginal($h2, $tmp . '/up-e.jpg', 0, 1), 'seed: arquivo que não é imagem recusado');
// P0-4: queue push error (here: QUEUE_DRIVER=valkey with an unreachable socket) marks the photo failed.
$savedEnv = [];
foreach (['QUEUE_DRIVER' => 'valkey', 'VALKEY_SOCKET' => $tmp . '/no-valkey.sock', 'VALKEY_USER' => 'cl_app', 'VALKEY_PASSWORD_FILE' => $seedFile] as $k => $v) {
    $savedEnv[$k] = getenv($k);
    putenv("$k=$v");
}
$qFail = PhotoStorage::enqueueOriginal($h2, $seedFile, 0, 30);
foreach ($savedEnv as $k => $v) {
    putenv($v === false ? $k : "$k=$v");
}
t_eq('failed', $qFail['status'] ?? null, 'queue push error: row returned as failed');
$st = db()->prepare('SELECT status FROM photos WHERE id = ?');
$st->execute([(int) ($qFail['id'] ?? 0)]);
t_eq('failed', $st->fetchColumn(), 'queue push error: photo failed in the DB, not stuck in processing');
db()->prepare('DELETE FROM photos WHERE id = ?')->execute([(int) ($qFail['id'] ?? 0)]);
// A second original with EXIF/GPS (PhotoStorage::migrateLegacy is gone: no data migration, 2026-10-21).
$exifFile = $tmp . '/exif.jpg';
file_put_contents($exifFile, $jpegWithExif(60, 30, 1));
t_ok(PhotoStorage::enqueueOriginal($h2, $exifFile, 0, 1) !== null, 'jpeg with EXIF queued');
$st = db()->prepare('SELECT status, path FROM photos WHERE property_id = ? ORDER BY sort_order');
$st->execute([$h2]);
t_eq(['processing', 'processing'], array_column($st->fetchAll(), 'status'), 'seed and EXIF jpeg in processing');
@unlink($lock);
$rep = ImageWorker::run(ImageQueues::make(), $lock);
t_eq(2, $rep['enviados'] ?? null, 'worker gera os WebP do seed e do jpeg com EXIF');
$st->execute([$h2]);
$after = $st->fetchAll();
t_ok($after[0]['status'] === 'ready' && $after[1]['status'] === 'ready' && preg_match('#^/uploads/fotos/[0-9a-f-]{36}-1920\.webp$#', (string) $after[1]['path']) === 1, 'seed e jpeg prontos em /uploads/fotos/<uuid>');
t_ok(!str_contains((string) file_get_contents(PhotoStorage::$public . '/' . basename(photo_variant((string) $after[1]['path'], 'full'))), 'GPSMARKER'), 'jpeg regravado sem EXIF');

echo "imóvel inativado\n";
$files2 = [];
foreach ($after as $r) {
    if ($r['status'] === 'ready') {
        foreach (ImageWorker::SIZES as $w) {
            $files2[] = PhotoStorage::variantFile((string) PhotoRepository::findById((int) db()->query("SELECT id FROM photos WHERE path = " . db()->quote((string) $r['path']))->fetchColumn())['uuid'], $w);
        }
    }
}
$pend = PhotoStorage::enqueueOriginal($h2, $seedFile, 0, 9);
t_eq(6, count(array_filter($files2, 'is_file')), '6 WebP públicos antes');
t_ok(HouseService::remove((int) $owner['user_id'], $h2), 'HouseService::remove inativa');
t_eq(0, count(array_filter($files2, 'is_file')), 'WebP públicos apagados ao inativar');
t_ok(!is_file(PhotoStorage::$originals . '/' . PhotoRepository::findById((int) $pend['id'])['uuid'] . '.png'), 'original pendente apagado');
$st = db()->prepare("SELECT DISTINCT status FROM photos WHERE property_id = ?");
$st->execute([$h2]);
t_eq(['removed'], array_column($st->fetchAll(), 'status'), 'fotos marcadas removed');
$rep = ImageWorker::run(ImageQueues::make(), $lock);
t_eq([0, 1], [$rep['enviados'] ?? null, $rep['ignorados'] ?? null], 'worker ignora foto de imóvel inativado');
t_eq([], glob(PhotoStorage::$public . '/' . PhotoRepository::findById((int) $pend['id'])['uuid'] . '-*') ?: [], 'nenhum WebP gerado depois de inativar');

echo "helpers\n";
$u = '/uploads/fotos/' . PhotoStorage::randomId();
t_eq($u . '-320.webp', photo_variant($u . '-1920.webp', 'thumb'), 'photo_variant');
t_eq('/uploads/users/1/x.jpg', photo_variant('/uploads/users/1/x.jpg', 'card'), 'caminho antigo sem variante');
t_eq('', photo_srcset_attr('/uploads/users/1/x.jpg', '100vw'), 'sem srcset em caminho antigo');
t_ok(Db::SCHEMA_VERSION >= 7, 'SCHEMA_VERSION >= 7');

exec('rm -rf ' . escapeshellarg($tmp));
t_done();
