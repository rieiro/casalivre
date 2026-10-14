<?php
// #39 (c25c61c): limite de 30 envios de fotos por 10 min somando formulário web e API.
// Uso (na raiz do app, servidor APP_ENV=dev no ar): APP_URL=http://127.0.0.1:PORT FIX=tests/e2e-playwright/fixtures/fotos php scripts/extras/fotos-limite-web-api.php
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


function web(string $jar, string $hash, string $csrf, string $file): array {
    return rq('POST', "/imoveis/$hash/fotos", ['_csrf' => $csrf, 'fotos[0]' => new CURLFile($file)], $jar, [], true);
}
function ra(string $h): string { return preg_match('/Retry-After:\s*(\d+)/i', $h, $m) ? $m[1] : '-'; }
function cenario(string $nome, int $api, int $webN, string $ultima): void {
    global $FIX; limpa(); [$jar] = dono(); [$hash, $pid] = casa($jar); $csrf = csrf($jar, "/imoveis/$hash/editar");
    limpa(); // o cadastro/login não usam este balde, mas zera tudo antes de contar
    $st = [];
    for ($i = 0; $i < $api; $i++) $st[] = 'A' . upload($jar, $hash, $csrf, ["$FIX/texto-falso.jpg"])[0];
    for ($i = 0; $i < $webN; $i++) $st[] = 'W' . web($jar, $hash, $csrf, "$FIX/texto-falso.jpg")[0];
    $ok30 = !array_filter($st, fn ($s) => !in_array($s, ['A201', 'W302'], true));
    [$s, $b, $h] = $ultima === 'web' ? web($jar, $hash, $csrf, "$FIX/texto-falso.jpg") : upload($jar, $hash, $csrf, ["$FIX/texto-falso.jpg"]);
    $ct = preg_match('/Content-Type:\s*([^;\r\n]+)/i', $h, $m) ? $m[1] : '';
    info("$nome: " . json_encode(array_count_values($st)) . " -> 31º ($ultima) $s Retry-After=" . ra($h) . " Content-Type=$ct");
    ok($ok30, "$nome: os 30 primeiros aceitos (API 201 / web 302)");
    ok($s === 429 && (int) ra($h) > 0 && (int) ra($h) <= 600, "$nome: 31º pela $ultima -> 429 com Retry-After");
    ok($ultima === 'web' ? str_contains($ct, 'text/html') : str_contains($ct, 'json'), "$nome: corpo " . ($ultima === 'web' ? 'HTML' : 'JSON'));
    if ($ultima === 'web') ok(!str_contains($b, 'Fatal') && str_contains($b, 'Muitas'), "$nome: página 429 com mensagem");
    $o = $ultima === 'web' ? upload($jar, $hash, $csrf, ["$FIX/texto-falso.jpg"]) : web($jar, $hash, $csrf, "$FIX/texto-falso.jpg");
    ok($o[0] === 429, "$nome: a outra rota também está bloqueada ({$o[0]})");
}
cenario('20 API + 10 web', 20, 10, 'web');
cenario('20 API + 10 web', 20, 10, 'api');
cenario('10 API + 20 web', 10, 20, 'api');
cenario('30 web', 0, 30, 'web');
// Outras rotas de fotos não entram no balde
limpa(); [$jar] = dono(); [$hash, $pid] = casa($jar); $csrf = csrf($jar, "/imoveis/$hash/editar"); limpa();
for ($i = 0; $i < 31; $i++) web($jar, $hash, $csrf, "$FIX/texto-falso.jpg");
[$s] = rq('POST', "/imoveis/$hash/fotos/excluir", http_build_query(['_csrf' => $csrf]), $jar, ['Content-Type: application/x-www-form-urlencoded']);
ok($s !== 429, "excluir fotos não é afetado pelo limite ($s)");
[$s] = rq('GET', "/imoveis/$hash/editar", null, $jar); ok($s === 200, "editar continua abrindo ($s)");
limpa();
echo "\n" . ($falhas === 0 ? 'Todos os casos passaram.' : "$falhas falha(s)") . "\n";
exit($falhas ? 1 : 0);
