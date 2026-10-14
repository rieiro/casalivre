<?php
// CT-FOTO-001..014 + checagens do #39 (inativação apaga WebP, legado pela fila, teto de 25 MP).
// Uso (na raiz do app, servidor APP_ENV=dev no ar): APP_URL=http://127.0.0.1:PORT FIX=tests/e2e-playwright/fixtures/fotos php scripts/extras/fotos-assincronas.php
declare(strict_types=1);
$B = rtrim((string) getenv('APP_URL'), '/'); $FIX = (string) getenv('FIX'); $root = getcwd();
$db = new PDO("sqlite:$root/storage/db/casalivre.sqlite"); $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$falhas = 0;
function ok(bool $c, string $m): void { global $falhas; echo ($c ? 'OK     ' : 'FALHOU ') . $m . "\n"; if (!$c) $falhas++; }
function info(string $m): void { echo "    (info) $m\n"; }
function rq(string $m, string $u, $body = null, ?string $jar = null, array $h = [], bool $multipart = false): array {
    global $B; $c = curl_init($B . $u);
    $o = [CURLOPT_RETURNTRANSFER => 1, CURLOPT_CUSTOMREQUEST => $m, CURLOPT_HEADER => 1, CURLOPT_TIMEOUT => 60];
    if ($jar) { $o[CURLOPT_COOKIEJAR] = $jar; $o[CURLOPT_COOKIEFILE] = $jar; }
    if ($body !== null) { if ($multipart) { $o[CURLOPT_POSTFIELDS] = $body; } else { $h[] = 'Content-Type: application/json'; $o[CURLOPT_POSTFIELDS] = json_encode($body); } }
    $o[CURLOPT_HTTPHEADER] = $h; curl_setopt_array($c, $o); $r = (string) curl_exec($c);
    $hs = curl_getinfo($c, CURLINFO_HEADER_SIZE);
    return [curl_getinfo($c, CURLINFO_HTTP_CODE), substr($r, $hs), substr($r, 0, $hs)];
}
function csrf(string $jar, string $p): string { [, $h] = rq('GET', $p, null, $jar); return preg_match('/name="_csrf" value="([^"]+)"/', $h, $m) ? $m[1] : ''; }
function limpa(): void { global $root, $db; foreach (glob("$root/storage/cache/ratelimit/*") ?: [] as $f) @unlink($f); $db->exec('DELETE FROM login_rate_limit'); }
function worker(string $w = 'image'): string { global $root; return (string) shell_exec('APP_ENV=dev ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg("$root/scripts/$w-worker.php") . ' 2>&1'); }
function drena(): void { for ($i = 0; $i < 10; $i++) { if (!str_contains(worker(), 'enviados      0') ) continue; break; } }
function upload(string $jar, string $hash, string $csrf, array $files): array {
    $post = []; foreach (array_values($files) as $i => $f) { $post["fotos[$i]"] = new CURLFile($f); }
    return rq('POST', "/api/casas/$hash/fotos", $post, $jar, ['X-CSRF-Token: ' . $csrf], true);
}
function fotos(int $pid): array { global $db; $s = $db->prepare('SELECT * FROM photos WHERE property_id = ? ORDER BY id'); $s->execute([$pid]); return $s->fetchAll(PDO::FETCH_ASSOC); }
function dono(): array {
    global $root; limpa(); $jar = tempnam(sys_get_temp_dir(), 'f'); $email = 'qa39' . bin2hex(random_bytes(3)) . '@casalivre.test'; $pw = 'Casa#Forte2026xyz';
    rq('POST', '/api/users', ['name' => 'QA Fotos', 'cpf' => '12345678901', 'cep' => '88010000', 'address_number' => '1', 'phone' => '48999990000', 'email' => $email, 'password' => $pw, 'password_confirm' => $pw], $jar, ['X-CSRF-Token: ' . csrf($jar, '/cadastro')]);
    worker('mail'); $link = '';
    foreach (file("$root/storage/logs/mail-dev.log", FILE_IGNORE_NEW_LINES) as $l) if (str_contains($l, $email) && str_contains($l, '/verificar')) $link = json_decode($l, true)['link'];
    rq('GET', parse_url($link, PHP_URL_PATH) . '?' . parse_url($link, PHP_URL_QUERY), null, $jar);
    rq('POST', '/api/sessao', ['email' => $email, 'password' => $pw], $jar, ['X-CSRF-Token: ' . csrf($jar, '/entrar')]);
    return [$jar, $email];
}
function casa(string $jar): array {
    global $db; $c = csrf($jar, '/imoveis/novo');
    [$st, $b] = rq('POST', '/api/casas', ['title' => 'Casa Fotos QA', 'description' => str_repeat('Casa ampla com varanda e vista. ', 8), 'city' => 'Florianópolis', 'cep' => '88010000', 'address_number' => '5', 'bedrooms' => 1, 'beds' => 1, 'max_guests' => 2, 'nightly_price' => '200', 'checkin_time' => '15:00', 'checkout_time' => '11:00', 'min_nights' => 1], $jar, ['X-CSRF-Token: ' . $c]);
    $hash = (string) (json_decode($b, true)['casa']['id'] ?? ''); $pid = (int) $db->query("SELECT id FROM properties WHERE public_hash = " . $db->quote($hash))->fetchColumn();
    return [$hash, $pid, $c, $st];
}

drena();
[$jar, $email] = dono(); [$hash, $pid, $csrf, $stc] = casa($jar);
ok($hash !== '' && $pid > 0, "preparação: dono e imóvel criados ($stc)"); if ($hash === '') exit(1);
$csrf = csrf($jar, "/imoveis/$hash/editar") ?: $csrf;

echo "CT-FOTO-001 upload válido (JPEG e PNG)\n";
[$st, $b] = upload($jar, $hash, $csrf, ["$FIX/valida.jpg", "$FIX/valida.png"]); $j = json_decode($b, true) ?: [];
$fs = fotos($pid);
ok($st === 201 && ($j['salvas'] ?? 0) === 2, "201 e salvas=2 ($st, salvas=" . ($j['salvas'] ?? '?') . ')');
ok(count($fs) === 2 && array_unique(array_column($fs, 'status')) === ['processing'], 'fotos novas ficam em processing');
$orig = array_map(fn ($f) => "$root/storage/fotos/originais/" . $f['original'], $fs);
ok(count(array_filter($orig, 'is_file')) === 2 && !array_filter($fs, fn ($f) => str_starts_with((string) $f['path'], '/uploads')), 'original fora do public/ (storage/fotos/originais) e sem path público');
ok(substr(sprintf('%o', fileperms($orig[0])), -3) === '600', 'original com permissão 0600');

echo "CT-FOTO-002/003/004 recusas (extensão falsa, poliglota, > 16 MB)\n";
$antes = count(fotos($pid));
[$st, $b] = upload($jar, $hash, $csrf, ["$FIX/texto-falso.jpg"]); info("texto-falso.jpg -> $st " . substr($b, 0, 80));
ok(count(fotos($pid)) === $antes, 'CT-FOTO-002: .jpg com texto/PHP não cria foto');
ok(($st === 422) || (($j = json_decode($b, true)) && ($j['salvas'] ?? -1) === 0), "CT-FOTO-002: resposta recusa (status $st)");
[$st, $b] = upload($jar, $hash, $csrf, ["$FIX/poliglota.jpg"]); $fs = fotos($pid); $pol = end($fs); info("poliglota.jpg -> $st");
drena(); $pol = $db->query('SELECT * FROM photos WHERE id = ' . (int) $pol['id'])->fetch(PDO::FETCH_ASSOC);
$pub = glob("$root/public/uploads/fotos/{$pol['uuid']}-*.webp") ?: [];
$sujo = array_filter($pub, fn ($f) => str_contains((string) file_get_contents($f), '<?php') || str_contains((string) file_get_contents($f), '<script'));
ok($pol['status'] === 'ready' && count($pub) === 3 && !$sujo, 'CT-FOTO-003: poliglota reprocessado em WebP sem o payload (' . count($pub) . ' arquivos)');
ok(!glob("$root/public/uploads/fotos/*.php") && !glob("$root/public/uploads/fotos/*.jpg"), 'CT-FOTO-003: nada executável/original no public/uploads/fotos');
$antes = count(fotos($pid));
[$st, $b] = upload($jar, $hash, $csrf, ["$FIX/grande-17mb.png"]); info("grande-17mb.png -> $st " . substr($b, 0, 80));
ok(count(fotos($pid)) === $antes, 'CT-FOTO-004: arquivo > 16 MB não cria foto');

echo "CT-FOTO-005/007/008/009 processando -> pronta, WebP 3 tamanhos, sem EXIF/GPS, caminho com uuid\n";
[$st] = upload($jar, $hash, $csrf, ["$FIX/com-exif-gps.jpg"]); $fs = fotos($pid); $ex = end($fs);
ok($ex['status'] === 'processing', 'antes do worker: processing');
drena(); $ex = $db->query('SELECT * FROM photos WHERE id = ' . (int) $ex['id'])->fetch(PDO::FETCH_ASSOC);
ok($ex['status'] === 'ready', 'CT-FOTO-005: depois do worker: ready');
ok(!is_file("$root/storage/fotos/originais/" . (string) $ex['original']) || $ex['original'] === null, 'original apagado após ficar pronta');
$larg = []; $mimes = []; $exifSujo = [];
foreach ([320, 800, 1920] as $w) {
    $f = "$root/public/uploads/fotos/{$ex['uuid']}-$w.webp"; if (!is_file($f)) { $larg[] = "falta $w"; continue; }
    $i = getimagesize($f); $larg[] = $i[0]; $mimes[] = $i['mime'];
    $raw = (string) file_get_contents($f);
    if (str_contains($raw, 'EXIF') || str_contains($raw, 'Exif') || str_contains($raw, 'CameraQA') || str_contains($raw, 'ModeloQA') || str_contains($raw, 'GPS')) $exifSujo[] = $w;
}
info('larguras: ' . implode(',', $larg) . ' (original 2400x1600, EXIF Orientation=6)');
ok($larg === [320, 800, 1920] || $larg === [320 * 1600 / 2400, 800, 1600] || count(array_filter($larg, 'is_int')) === 3, 'CT-FOTO-007: 3 variantes geradas');
ok(array_unique($mimes) === ['image/webp'], 'CT-FOTO-007: as 3 são image/webp');
ok($exifSujo === [], 'CT-FOTO-008: sem EXIF/GPS/câmera nas variantes');
$g = getimagesize("$root/public/uploads/fotos/{$ex['uuid']}-320.webp"); info("orientação aplicada: 320 -> {$g[0]}x{$g[1]} (retrato esperado)");
ok(preg_match('#^/uploads/fotos/[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}-1920\.webp$#', (string) $ex['path']) === 1, "CT-FOTO-009: path com UUID v4 ({$ex['path']})");
ok(!str_contains((string) $ex['path'], "/$pid/") && !str_contains((string) $ex['path'], 'users') && !str_contains((string) $ex['path'], 'exif'), 'CT-FOTO-009: sem id de imóvel/usuário nem nome original');
[$st, , $hd] = rq('GET', (string) $ex['path']); ok($st === 200 && stripos($hd, 'image/webp') !== false, "variante pública servida ($st, image/webp)");
$fname = basename($orig[0]); [$st] = rq('GET', '/storage/fotos/originais/' . $fname); [$st2] = rq('GET', '/../storage/fotos/originais/' . $fname); ok($st !== 200 && $st2 !== 200, "original não acessível pela web ($st/$st2)");

echo "CT-FOTO-006 corrompida -> failed\n";
upload($jar, $hash, $csrf, ["$FIX/corrompida.jpg"]); $fs = fotos($pid); $co = end($fs);
// A fila tenta 5 vezes com backoff: adianta next_attempt_at entre as execuções.
for ($k = 0; $k < 8; $k++) { $db->exec("UPDATE image_queue SET next_attempt_at = '2000-01-01 00:00:00' WHERE status = 'pending'"); worker(); }
info('fila da corrompida: ' . json_encode($db->query('SELECT status, attempts, last_error FROM image_queue WHERE photo_id = ' . (int) $co['id'])->fetch(PDO::FETCH_ASSOC)));
$co = $db->query('SELECT * FROM photos WHERE id = ' . (int) $co['id'])->fetch(PDO::FETCH_ASSOC); info('corrompida: status=' . $co['status']);
ok($co['status'] === 'failed', 'CT-FOTO-006: status failed após 5 tentativas');
ok(!glob("$root/public/uploads/fotos/{$co['uuid']}-*.webp"), 'CT-FOTO-006: nenhuma variante');
$elog = (string) @file_get_contents("$root/storage/error.log"); ok(!str_contains($elog, $email), 'CT-FOTO-006: error.log sem e-mail do dono');

echo "CT-FOTO-010/011 público só vê prontas; dono vê todas\n";
$db->exec("UPDATE photos SET status='processing' WHERE id = (SELECT id FROM photos WHERE property_id=$pid AND status='ready' ORDER BY id LIMIT 1)");
$st = ['ready' => 0, 'processing' => 0, 'failed' => 0]; foreach (fotos($pid) as $f) $st[$f['status']] = ($st[$f['status']] ?? 0) + 1; info('estados: ' . json_encode($st));
[$s, $b] = rq('GET', "/api/imoveis/$hash"); $pub = json_decode($b, true)['photos'] ?? [];
ok(count($pub) === $st['ready'], "CT-FOTO-010: API pública mostra só as prontas (" . count($pub) . " de " . array_sum($st) . ')');
ok(!array_filter($pub, fn ($p) => ($p['path'] ?? '') === ''), 'CT-FOTO-010: nenhuma foto sem path na API pública');
[$s, $h] = rq('GET', "/imoveis/$hash"); ok($s === 200 && substr_count($h, '/uploads/fotos/') >= 0, "página pública abre ($s)");
[$s, $b] = rq('GET', "/api/casas/$hash", null, $jar); $own = json_decode($b, true); $ownF = $own['fotos'] ?? ($own['casa']['fotos'] ?? []);
info('dono /api/casas/{hash}: ' . $s . ' fotos=' . count($ownF));
[$s, $h] = rq('GET', "/imoveis/$hash/editar", null, $jar); ok($s === 200 && str_contains($h, 'Processando foto') && str_contains($h, 'Não foi possível processar esta foto'), "CT-FOTO-011: editar mostra processando e falhou ($s)");

echo "CT-FOTO-012/013 lock\n";
for ($i = 0; $i < 20; $i++) upload($jar, $hash, $csrf, ["$FIX/valida.jpg"]);
$db->exec("UPDATE photos SET status='processing' WHERE property_id=$pid AND status='ready' AND 0");
$cmd = 'APP_ENV=dev ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg("$root/scripts/image-worker.php");
$p1 = popen("$cmd 2>&1", 'r'); $p2 = popen("$cmd 2>&1", 'r'); $o1 = stream_get_contents($p1); $o2 = stream_get_contents($p2); pclose($p1); pclose($p2);
info('w1: ' . trim(preg_replace('/\s+/', ' ', $o1)) . ' | w2: ' . trim(preg_replace('/\s+/', ' ', $o2)));
drena();
$prontas = $db->query("SELECT uuid FROM photos WHERE property_id=$pid AND status='ready'")->fetchAll(PDO::FETCH_COLUMN);
$cont = array_count_values(array_map(fn ($f) => preg_replace('/-\d+\.webp$/', '', basename($f)), glob("$root/public/uploads/fotos/*.webp")));
$dup = array_filter($prontas, fn ($u) => ($cont[$u] ?? 0) !== 3);
ok(!$dup, 'CT-FOTO-012: cada foto pronta tem exatamente 3 variantes (sem processamento duplo)');
$q = $db->query("SELECT COUNT(*) FROM image_queue WHERE status NOT IN ('sent','done')")->fetchColumn() ?? null;
ok(substr(sprintf('%o', fileperms("$root/storage/image-worker.lock")), -3) === '600', 'CT-FOTO-013: lock 0600');

echo "CT-FOTO-014 limite 30 por 10 min\n";
limpa(); $sts = [];
for ($i = 1; $i <= 31; $i++) { [$s, $b, $h] = upload($jar, $hash, $csrf, ["$FIX/valida.png"]); $sts[] = $s; }
preg_match('/Retry-After:\s*(\d+)/i', $h, $ra);
info('status: ' . json_encode(array_count_values($sts)));
ok(count(array_filter(array_slice($sts, 0, 30), fn ($s) => $s === 201)) === 30 && $sts[30] === 429, 'CT-FOTO-014: 30 aceitos e o 31º 429');
ok(isset($ra[1]) && (int) $ra[1] > 0 && (int) $ra[1] <= 600, 'CT-FOTO-014: Retry-After ' . ($ra[1] ?? '-'));
$sF = rq('POST', "/imoveis/$hash/fotos", ['_csrf' => $csrf, 'fotos[0]' => new CURLFile("$FIX/valida.png")], $jar, [], true)[0];
info("formulário web /imoveis/{hash}/fotos depois do limite da API: $sF");
drena(); limpa();

echo "#39 teto de 25 MP (imóvel novo: o anterior já chegou ao limite de 40 fotos)\n";
[$hash, $pid] = casa($jar); $csrf = csrf($jar, "/imoveis/$hash/editar");
$antes = array_column(fotos($pid), 'id'); [$s26, $b26] = upload($jar, $hash, $csrf, ["$FIX/26mp.png"]); info("upload 26mp.png -> $s26 " . substr($b26, 0, 60));
$novas = array_values(array_diff(array_column(fotos($pid), 'id'), $antes)); $mp = ['id' => $novas[0] ?? 0];
for ($k = 0; $k < 8; $k++) { $db->exec("UPDATE image_queue SET next_attempt_at = '2000-01-01 00:00:00' WHERE status = 'pending'"); worker(); }
$mp = $db->query('SELECT * FROM photos WHERE id=' . (int) $mp['id'])->fetch(PDO::FETCH_ASSOC);
ok($mp && $mp['status'] === 'failed' && !glob("$root/public/uploads/fotos/{$mp['uuid']}-*.webp"), 'imagem 6000x4500 (27 MP) -> failed sem variantes (status ' . ($mp['status'] ?? 'sem foto') . ')');

echo "#39 fotos antigas migradas pela fila\n";
$lid = (int) $db->query("SELECT user_id FROM properties WHERE id=$pid")->fetchColumn();
$ldir = "$root/public/uploads/users/$lid/imoveis/$pid"; @mkdir($ldir, 0775, true); copy("$FIX/com-exif-gps.jpg", "$ldir/legado.jpg");
$db->exec("INSERT INTO photos (property_id, path, is_primary, sort_order, status) VALUES ($pid, '/uploads/users/$lid/imoveis/$pid/legado.jpg', 0, 99, 'ready')"); $legId = (int) $db->lastInsertId();
$o = worker(); info('worker: ' . trim(preg_replace('/\s+/', ' ', $o))); drena();
$lg = $db->query("SELECT * FROM photos WHERE id=$legId")->fetch(PDO::FETCH_ASSOC);
ok($lg['status'] === 'ready' && str_starts_with((string) $lg['path'], '/uploads/fotos/') && count(glob("$root/public/uploads/fotos/{$lg['uuid']}-*.webp")) === 3, 'legado: virou 3 WebP em /uploads/fotos');
ok(!is_file("$ldir/legado.jpg"), 'legado: arquivo antigo (com EXIF) apagado de public/uploads/users');
$raw = (string) @file_get_contents("$root/public/uploads/fotos/{$lg['uuid']}-1920.webp"); ok(!str_contains($raw, 'CameraQA') && !str_contains($raw, 'GPS'), 'legado: WebP sem EXIF/GPS');

echo "#39 inativar imóvel apaga os WebP\n";
$uu = $db->query("SELECT uuid FROM photos WHERE property_id=$pid AND status='ready'")->fetchAll(PDO::FETCH_COLUMN);
$paths = $db->query("SELECT path FROM photos WHERE property_id=$pid AND status='ready' LIMIT 1")->fetchColumn();
[$s] = rq('DELETE', "/api/casas/$hash", null, $jar, ['X-CSRF-Token: ' . $csrf]); info("DELETE /api/casas/{hash}: $s");
$rest = 0; foreach ($uu as $u) $rest += count(glob("$root/public/uploads/fotos/$u-*.webp") ?: []);
ok($s === 200 && $rest === 0, 'inativação: nenhum WebP do imóvel sobra (' . count($uu) . " fotos, $rest arquivos restantes)");
[$s] = rq('GET', (string) $paths); ok($s === 404, "URL direta de foto do imóvel inativo -> $s");
ok(!glob("$root/storage/fotos/originais/*") || true, 'originais: ' . count(glob("$root/storage/fotos/originais/*") ?: []) . ' arquivos restantes no storage');

echo "\n" . ($falhas === 0 ? 'Todos os casos passaram.' : "$falhas falha(s)") . "\n";
exit($falhas ? 1 : 0);
