<?php
// Casos novos: contratos /api/*, paridade iCal e log JSON de requisições.
// Incluído por scripts/run-casos-de-teste.php (CASOS=novos|todos).
declare(strict_types=1);

/** PR #32: o link de ativação não volta mais na API; roda o worker (APP_ENV=dev) e lê o último link do e-mail no mail-dev.log. */
if (!function_exists('link_ativacao')) {
    function link_ativacao(string $email): string
    {
        $root = dirname(__DIR__, 2);
        $cmd = 'APP_ENV=dev ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/scripts/mail-worker.php') . ' 2>&1';
        $arq = $root . '/storage/logs/mail-dev.log';
        for ($i = 0; $i < 5; $i++) {
            shell_exec($cmd);
            $link = '';
            foreach (is_file($arq) ? (file($arq, FILE_IGNORE_NEW_LINES) ?: []) : [] as $l) {
                if (str_contains($l, $email) && str_contains($l, '/verificar')) {
                    $link = (string) ((json_decode($l, true) ?: [])['link'] ?? $link);
                }
            }
            if ($link !== '') {
                return $link;
            }
            usleep(300000);
        }
        return '';
    }
}

// Exige APP_ENV=dev (ou local): os casos leem o link de verificação do mail-dev.log (worker da fila),
// que só é gravado nesses ambientes. O servidor de teste também deve subir com APP_ENV=dev.
if (!in_array(strtolower(trim((string) getenv('APP_ENV'))), ['dev', 'local'], true)) {
    fwrite(STDERR, "ERRO: rode com APP_ENV=dev (ex.: APP_ENV=dev CASOS=todos php scripts/run-casos-de-teste.php) e suba o servidor de teste com APP_ENV=dev.\n");
    exit(2);
}

$jarA = sys_get_temp_dir() . '/casalivre-a.cookie';
$jarB = sys_get_temp_dir() . '/casalivre-b.cookie';
$jarAnon = sys_get_temp_dir() . '/casalivre-anon.cookie';
foreach ([$jarA, $jarB, $jarAnon] as $j) { @unlink($j); }
$reqCount = 0;

function api(string $jar, string $method, string $path, ?array $json = null, ?string $csrf = null): array
{
    global $base, $reqCount, $apiCount;
    $reqCount++;
    if (str_starts_with($path, '/api')) { $apiCount++; }
    $ch = curl_init($base . $path);
    $h = [];
    if ($csrf !== null) { $h[] = 'X-CSRF-Token: ' . $csrf; }
    $o = [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HEADER => true,
          CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_FOLLOWLOCATION => false];
    if ($json !== null) { $h[] = 'Content-Type: application/json'; $o[CURLOPT_POSTFIELDS] = json_encode($json); }
    $o[CURLOPT_HTTPHEADER] = $h;
    curl_setopt_array($ch, $o);
    $raw = (string) curl_exec($ch);
    $hs = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $st = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $body = substr($raw, $hs);
    return [$st, substr($raw, 0, $hs), $body, json_decode($body, true)];
}

function csrf_of(string $jar, string $page = '/entrar'): string
{
    [, , $html] = api($jar, 'GET', $page);
    return preg_match('/name="_csrf" value="([^"]+)"/', $html, $m) ? $m[1] : '';
}

function is_json(string $headers): bool { return stripos($headers, 'content-type: application/json') !== false; }

function normalize_ics(string $ics): string
{
    $lines = preg_split('/\r?\n/', trim($ics)) ?: [];
    $lines = array_filter($lines, static fn ($l) => !preg_match('/^(DTSTAMP|UID):/', $l));
    return implode("\n", $lines);
}

function new_user(string $jar, string $tag): array
{
    $email = $tag . bin2hex(random_bytes(4)) . '@casalivre.test';
    $pass = 'Senha-segura1';
    $csrf = csrf_of($jar, '/cadastro');
    [$st, , , $j] = api($jar, 'POST', '/api/users', [
        'name' => 'Teste ' . strtoupper($tag), 'cpf' => '12345678901', 'cep' => '88010000', 'address_number' => '10',
        'phone' => '48999990000', 'email' => $email, 'password' => $pass, 'password_confirm' => $pass,
    ], $csrf);
    $link = $st === 201 ? link_ativacao($email) : '';
    if ($link !== '') {
        $path = parse_url($link, PHP_URL_PATH) . '?' . parse_url($link, PHP_URL_QUERY);
        api($jar, 'GET', $path);
    }
    $csrf = csrf_of($jar);
    [$st2] = api($jar, 'POST', '/api/sessao', ['email' => $email, 'password' => $pass], $csrf);
    return ['email' => $email, 'pass' => $pass, 'ok' => $st === 201 && $st2 === 200, 'csrf' => csrf_of($jar, '/imoveis/novo')];
}

function request_log_lines(): array
{
    $env = getenv('REQUEST_LOG');
    $files = $env ? [$env] : array_merge(glob(dirname(__DIR__, 2) . '/storage/logs/api/api-*.log*') ?: [], glob(dirname(__DIR__, 2) . '/storage/logs/requests*.log') ?: []);
    $all = [];
    foreach ($files as $f) { if (is_readable($f)) { $all = array_merge($all, file($f, FILE_IGNORE_NEW_LINES) ?: []); } }
    return $all;
}
$logStart = count(request_log_lines());
$reqCount = 0;
$apiCount = 0;

echo "Caso API-1: contratos de autenticação, CSRF e formato JSON\n";
foreach ([['GET', '/api/casas'], ['GET', '/api/users/eu'], ['GET', '/api/imoveis/0123456789abcdef/calendario']] as [$m, $p]) {
    [$st, $h, , $j] = api($jarAnon, $m, $p);
    assert_true($st === 401 && is_json($h) && is_string($j['error'] ?? null), "$m $p sem login → 401 JSON {error}");
}
foreach ([['POST', '/api/casas'], ['PUT', '/api/users/eu'], ['DELETE', '/api/sessao']] as [$m, $p]) {
    [$st, $h, , $j] = api($jarAnon, $m, $p, ['x' => 1]);
    assert_true($st === 419 && is_json($h) && is_string($j['error'] ?? null), "$m $p sem token CSRF → 419 JSON {error}");
}
[$st, $h, , $j] = api($jarAnon, 'GET', '/api/rota-inexistente');
assert_true($st === 404 && is_json($h) && isset($j['error']), 'rota /api inexistente → 404 JSON {error}');

echo "Caso API-2: /api/cidades e /api/ceps\n";
[$st, $h, , $j] = api($jarAnon, 'GET', '/api/cidades?q=' . rawurlencode('florian'));
assert_true($st === 200 && is_json($h) && is_array($j) && isset($j[0]['text'], $j[0]['name'], $j[0]['uf']), "GET /api/cidades → 200 lista [{text,name,uf}] (obtido $st)");
[$st, $h, , $j] = api($jarAnon, 'GET', '/api/ceps?q=88010000');
$shape = is_array($j) && count(array_intersect(['cep', 'street', 'neighborhood', 'city', 'uf'], array_keys($j))) === 5;
assert_true($st === 200 && is_json($h) && $shape, "GET /api/ceps?q=88010000 → 200 {cep,street,neighborhood,city,uf} (obtido $st)");
[$st, , , $j] = api($jarAnon, 'GET', '/api/ceps?q=00000000');
assert_true(in_array($st, [404, 422], true) && isset($j['error']), "GET /api/ceps CEP inexistente → 404/422 {error} (obtido $st)");
// Rotas legadas sem prefixo /api foram removidas (PR #3): devem responder 404
[$st, $h, , $j] = api($jarAnon, 'GET', '/cidades?q=florian');
assert_true($st === 404, "rota legada GET /cidades removida → 404 (obtido $st)");
[$st, , , $j] = api($jarAnon, 'GET', '/ceps?q=88010000');
assert_true($st === 404, "rota legada GET /ceps removida → 404 (obtido $st)");

echo "Caso API-3: IDOR — usuário B não acessa imóvel do usuário A (404)\n";
$a = new_user($jarA, 'a');
$b = new_user($jarB, 'b');
assert_true($a['ok'] && $b['ok'], 'usuários A e B cadastrados, ativados e logados via API');
[$st, , , $j] = api($jarA, 'POST', '/api/casas', [
    'title' => 'Casa IDOR Teste', 'description' => str_repeat('Casa ampla com varanda e vista para o mar. ', 6),
    'city' => 'Florianópolis', 'cep' => '88010000', 'address_number' => '99', 'bedrooms' => 2, 'beds' => 3,
    'max_guests' => 4, 'nightly_price' => '300', 'checkin_time' => '15:00', 'checkout_time' => '11:00', 'min_nights' => 1,
], $a['csrf']);
$hash = (string) ($j['casa']['id'] ?? '');
assert_true($st === 201 && preg_match('/^[a-f0-9]{16}$/', $hash) === 1, "A cria imóvel via POST /api/casas → 201 {casa.id} (obtido $st)");
if ($hash !== '') {
    $casa = $j['casa'];
    $keys = ['id', 'user_id', 'title', 'description', 'summary', 'city', 'address', 'bedrooms', 'beds', 'max_guests', 'nightly_price', 'checkin_time', 'checkout_time', 'min_nights', 'pool', 'bbq', 'garage', 'criado_em', 'foto'];
    assert_true(array_diff($keys, array_keys($casa)) === [], 'JSON de casa contém todos os campos do contrato');
    [$st] = api($jarA, 'GET', "/api/casas/$hash");
    assert_true($st === 200, 'dono acessa GET /api/casas/{id} → 200');
    foreach ([['GET', "/api/casas/$hash", null], ['PUT', "/api/casas/$hash", ['title' => 'invadido']], ['DELETE', "/api/casas/$hash", null],
              ['GET', "/api/imoveis/$hash/calendario", null], ['POST', "/api/imoveis/$hash/calendario", ['acao' => 'bloquear']],
              ['GET', "/api/imoveis/$hash/editar", null]] as [$m, $p, $body]) {
        [$st, , , $jj] = api($jarB, $m, $p, $body, $m === 'GET' ? null : $b['csrf']);
        assert_true($st === 404 && isset($jj['error']), "IDOR: B $m $p → 404 (obtido $st)");
    }
    [$st, , , $j2] = api($jarA, 'GET', "/api/casas/$hash");
    assert_true(($j2['casa']['title'] ?? '') === 'Casa IDOR Teste', 'imóvel de A permanece inalterado após tentativas de B');
    [$st, , , $me] = api($jarA, 'GET', '/api/users/eu');
    assert_true($st === 200 && isset($me['user']['id'], $me['user']['email']), 'GET /api/users/eu → 200 {user{id,email,...}}');
    assert_true(!isset($me['user']['password'], $me['user']['password_hash']), '/api/users/eu não expõe hash de senha');
}

echo "Caso ICAL-1: paridade de exportação iCal\n";
if ($hash !== '') {
    $day = (new DateTimeImmutable('+5 days'))->format('Y-m-d');
    $day2 = (new DateTimeImmutable('+7 days'))->format('Y-m-d');
    [$st] = api($jarA, 'POST', "/api/imoveis/$hash/calendario", ['acao' => 'bloquear', 'inicio' => $day, 'fim' => $day2], $a['csrf']);
    assert_true($st === 200, 'A bloqueia 3 noites via API');
    [$st, , $html] = api($jarA, 'GET', "/imoveis/$hash/calendario");
    $icalHash = preg_match('#/ical/([a-f0-9]{32})#', $html, $mm) ? $mm[1] : '';
    assert_true($icalHash !== '', 'página de calendário mostra link /ical/{ical_hash} (32 hex)');
    [$st, $h, $ics] = api($jarAnon, 'GET', "/ical/$icalHash");
    assert_true($st === 200 && stripos($h, 'text/calendar') !== false && str_contains($ics, 'BEGIN:VCALENDAR') && str_contains($ics, 'END:VCALENDAR'), 'GET /ical/{ical_hash} → 200 text/calendar');
    $dt = 'DTSTART;VALUE=DATE:' . str_replace('-', '', $day);
    $dtEnd = 'DTEND;VALUE=DATE:' . (new DateTimeImmutable($day2))->modify('+1 day')->format('Ymd');
    assert_true(str_contains($ics, $dt) && str_contains($ics, $dtEnd) && str_contains($ics, 'SUMMARY:Bloqueado'), 'bloqueio aparece como 1 VEVENT agrupado (DTEND exclusivo)');
    // Paridade: rota HTML antiga por public_hash já devolve 404
    [$st] = api($jarAnon, 'GET', "/imoveis/$hash/calendario.ics");
    assert_true($st === 404, 'GET /imoveis/{public_hash}/calendario.ics → 404');
    // Exportação via API por public_hash: antes da correção = 200 com mesmo conteúdo; depois da correção deve ser 404
    [$st, , $apiIcs] = api($jarAnon, 'GET', "/api/imoveis/$hash/calendario.ics");
    if ($st === 200) {
        assert_true(normalize_ics($apiIcs) === normalize_ics($ics), '[antes da correção] export via API tem o mesmo conteúdo do /ical (paridade)');
    }
    assert_true($st === 404, "[após correção] GET /api/imoveis/{public_hash}/calendario.ics → 404 (obtido $st): public_hash não pode expor o calendário");
    [$st] = api($jarAnon, 'GET', '/ical/' . str_repeat('0', 32));
    assert_true($st === 404, '/ical com hash inexistente → 404');
    [$st] = api($jarAnon, 'GET', '/ical/' . $hash);
    assert_true($st === 404, '/ical/{public_hash} (16 hex) → 404');
    api($jarA, 'DELETE', "/api/casas/$hash", null, $a['csrf']);
    [$st] = api($jarAnon, 'GET', "/ical/$icalHash");
    assert_true($st === 404, 'imóvel inativado → /ical/{ical_hash} → 404');
}

echo "Caso RL-1: limite de tentativas de login (429)\n";
$jarRl = sys_get_temp_dir() . '/casalivre-rl.cookie';
@unlink($jarRl);
$csrfRl = csrf_of($jarRl);
$got429 = false;
for ($i = 0; $i < 12 && !$got429; $i++) {
    [$st, , , $jj] = api($jarRl, 'POST', '/api/sessao', ['email' => 'naoexiste@casalivre.test', 'password' => 'Errada-123!'], $csrfRl);
    $got429 = $st === 429;
}
assert_true($got429, "logins errados repetidos → 429 após $i tentativa(s)");
if ($got429 && !empty($a['email'])) {
    [$st, $h, , $jj] = api($jarRl, 'POST', '/api/sessao', ['email' => $a['email'], 'password' => $a['pass']], $csrfRl);
    assert_true($st === 429 && isset($jj['error']), "IP bloqueado: login com senha correta também → 429 JSON (obtido $st)");
    [$st] = api($jarRl, 'GET', '/api/users/eu');
    assert_true($st === 401, "requisição bloqueada por 429 não abre sessão (GET /api/users/eu → $st)");
}

echo "Caso LOG-1: log JSON local de requisições (/api/*)\n";
usleep(300000);
$all = request_log_lines();
$lines = array_slice($all, $logStart);
assert_true($all !== [], 'arquivo de log de requisições existe (storage/logs/api/api-*.log ou REQUEST_LOG)');
$statuses = [];
foreach ($lines as $l) { $j = json_decode($l, true); if (isset($j['status'])) { $statuses[(int) $j['status']] = ($statuses[(int) $j['status']] ?? 0) + 1; } }
ksort($statuses);
$hist = implode(', ', array_map(static fn ($k, $v) => "{$k}×{$v}", array_keys($statuses), $statuses));
assert_true(count($lines) === $apiCount, "uma linha por requisição /api em qualquer status (esperado $apiCount, obtido " . count($lines) . "; status: $hist)");
foreach ([401, 404, 419, 429] as $code) {
    assert_true(isset($statuses[$code]), "requisições com status $code também são registradas");
}
$sensitive = ['password', 'senha', 'token', 'cookie', 'csrf', 'cpf', 'email', 'authorization', 'phone', 'telefone'];
$keysRaw = []; $keysMasked = []; $leak = []; $noId = 0; $ids = [];
$secrets = [$a['email'] ?? '', $b['email'] ?? '', 'Senha-segura1', $a['csrf'] ?? '', $b['csrf'] ?? '', '12345678901', '48999990000'];
$walk = static function ($v, string $path) use (&$walk, &$keysRaw, &$keysMasked, $sensitive, $secrets): void {
    if (!is_array($v)) { return; }
    foreach ($v as $k => $x) {
        foreach ($sensitive as $s) {
            if (is_string($k) && str_contains(strtolower($k), $s)) {
                $isSecret = is_string($x) && in_array($x, $secrets, true);
                if ($isSecret) { $keysRaw[] = "$path.$k"; } else { $keysMasked[] = "$k"; }
            }
        }
        $walk($x, "$path.$k");
    }
};
foreach ($lines as $i => $line) {
    $j = json_decode($line, true);
    if (!is_array($j) || empty($j['request_id'])) { $noId++; continue; }
    $ids[] = $j['request_id'];
    $walk($j, "linha$i");
    foreach ($secrets as $secret) {
        if ($secret !== '' && str_contains($line, $secret)) { $leak[] = "linha$i"; }
    }
}
assert_true($lines !== [] && $noId === 0, "toda linha é JSON com request_id ($noId sem)");
assert_true($ids !== [] && count($ids) === count(array_unique($ids)), 'request_id é único por requisição');
assert_true($lines !== [] && $keysRaw === [], 'nenhum campo sensível com valor em claro' . ($keysRaw ? ': ' . implode(', ', array_slice(array_unique($keysRaw), 0, 5)) : ''));
assert_true($lines !== [] && $leak === [], 'nenhum valor sensível (e-mail, senha, CSRF, CPF, telefone) aparece em texto no log' . ($leak ? ' (' . count(array_unique($leak)) . ' linhas)' : ''));
assert_true($lines !== [] && $keysMasked === [], 'campos sensíveis ausentes do log (nem mascarados)' . ($keysMasked ? ': presentes mascarados: ' . implode(', ', array_unique($keysMasked)) : ''));
