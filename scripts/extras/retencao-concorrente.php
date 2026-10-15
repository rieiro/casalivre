#!/usr/bin/env php
<?php
// CT-RET-006..010: retenção rodando em paralelo com escritores de log.
// Uso: APP_URL=http://127.0.0.1:PORT php scripts/extras/retencao-concorrente.php [rodadas] [requisicoes] [paralelo]
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

$root = dirname(__DIR__, 2);
$base = getenv('APP_URL') ?: 'http://127.0.0.1:8080';
$rounds = (int) ($argv[1] ?? 5);
$nReq = (int) ($argv[2] ?? 400);
$par = (int) ($argv[3] ?? 16);
$fail = 0;
function ok(bool $c, string $m): void { global $fail; echo ($c ? 'OK  ' : 'FALHOU  ') . $m . "\n"; if (!$c) { $fail++; } }

function http(string $method, string $path, ?array $json = null, string $jar = '', array $hdr = []): array
{
    global $base;
    $ch = curl_init($base . $path);
    $o = [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_TIMEOUT => 30];
    if ($jar) { $o[CURLOPT_COOKIEJAR] = $jar; $o[CURLOPT_COOKIEFILE] = $jar; }
    if ($json !== null) { $hdr[] = 'Content-Type: application/json'; $o[CURLOPT_POSTFIELDS] = json_encode($json); }
    $o[CURLOPT_HTTPHEADER] = $hdr;
    curl_setopt_array($ch, $o);
    $b = (string) curl_exec($ch);
    return [curl_getinfo($ch, CURLINFO_HTTP_CODE), $b, json_decode($b, true)];
}
function csrf(string $jar, string $page): string
{
    // O token fica na sessão (cookie no jar); vem do campo oculto _csrf da página (sem seguir redirecionamento).
    [$st, $h] = http('GET', $page, null, $jar);
    $t = preg_match('/name="_csrf" value="([^"]+)"/', $h, $m) ? html_entity_decode($m[1]) : '';
    if ($t === '') { fwrite(STDERR, "sem _csrf em $page (HTTP $st)\n"); }
    return $t;
}
function pdo(string $file): PDO
{
    $p = new PDO('sqlite:' . $file, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $p->setAttribute(PDO::ATTR_TIMEOUT, 20);
    return $p;
}

// Limpa sobras do limite por IP deixadas pelo CASOS/standalone (storage/cache/ratelimit), para o login abaixo não cair em 429.
// Idem para o bloqueio de login por IP (tabela login_rate_limit), que o CASOS deixa preenchido com as senhas erradas dos casos.
foreach (glob(dirname(__DIR__, 2) . '/storage/cache/ratelimit/*') ?: [] as $f) {
    @unlink($f);
}
$appDb = dirname(__DIR__, 2) . '/storage/db/casalivre.sqlite';
if (is_file($appDb)) {
    (new PDO('sqlite:' . $appDb))->exec("DELETE FROM login_rate_limit WHERE ip IN ('127.0.0.1', '::1')");
}

// Preparação (sem criar imóvel): usa a massa dos seeds.
// 1) Sessão + CSRF: conta própria do teste, cadastrada pela API, ativada pelo link do mail-dev.log (mail-worker)
//    e logada com o mesmo cookie; confere /api/users/eu = 200 (o 419 antigo vinha de token lido de um redirect).
$appDb = pdo("$root/storage/db/casalivre.sqlite");
$jar = tempnam(sys_get_temp_dir(), 'conc');
$email = 'conc' . bin2hex(random_bytes(3)) . '@casalivre.test';
$cep = (string) ($appDb->query("SELECT cep FROM ceps WHERE street <> '' AND city LIKE 'Florian%' ORDER BY cep LIMIT 1")->fetchColumn() ?: '88010000');
[$stCad] = http('POST', '/api/users', ['name' => 'Conc Teste', 'cpf' => '12345678909', 'cep' => $cep, 'address_number' => '1',
    'phone' => '48999990000', 'email' => $email, 'password' => 'Senha-segura1', 'password_confirm' => 'Senha-segura1'], $jar, ['X-CSRF-Token: ' . csrf($jar, '/cadastro')]);
ok($stCad === 201, "preparação: cadastro pela API com CSRF da sessão ($stCad)");
$link = link_ativacao($email);
ok($link !== '', 'preparação: link de ativação no mail-dev.log após o mail-worker');
// Ativação por POST com CSRF (o GET do link só mostra o botão).
if ($link !== '') { parse_str((string) parse_url($link, PHP_URL_QUERY), $q); http('POST', '/api/auth/verificar', ['token' => (string) ($q['token'] ?? '')], $jar, ['X-CSRF-Token: ' . csrf($jar, '/entrar')]); }
[$stLogin] = http('POST', '/api/sessao', ['email' => $email, 'password' => 'Senha-segura1'], $jar, ['X-CSRF-Token: ' . csrf($jar, '/entrar')]);
[$stEu] = http('GET', '/api/users/eu', null, $jar);
ok($stLogin === 200 && $stEu === 200, "preparação: login com cookie + CSRF (sessao $stLogin, /api/users/eu $stEu)");

// 2) Export .ics: ids reais da massa (5 imóveis ativos dos seeds, um a cada ~1/5 da tabela).
//    O ical_hash é criado sob demanda (HouseRepository::ensureIcalHash, o mesmo da tela do calendário do dono);
//    os seeds não o preenchem, então a preparação o materializa pelo próprio código do app num processo à parte.
$ids = array_map('intval', $appDb->query("SELECT id FROM properties WHERE status = 'active' ORDER BY id")->fetchAll(PDO::FETCH_COLUMN));
$pick = [];
for ($k = 0; $k < 5 && $ids !== []; $k++) { $pick[] = $ids[intdiv($k * count($ids), 5)]; }
$pick = array_values(array_unique($pick));
$code = 'foreach (["Db", "UserRepository", "HouseRepository", "helpers"] as $f) { require_once ' . var_export("$root/src/", true) . ' . $f . ".php"; } foreach (array_slice($argv, 1) as $id) { echo HouseRepository::ensureIcalHash(HouseRepository::find((int) $id) ?? []), "\n"; }';
$icsPaths = [];
if ($pick !== []) {
    $out = (string) shell_exec('cd ' . escapeshellarg($root) . ' && ' . escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($code) . ' ' . implode(' ', $pick) . ' 2>&1');
    foreach (preg_split('/\R/', trim($out)) ?: [] as $h) { if (preg_match('/^[a-f0-9]{32}$/', $h)) { $icsPaths[] = "/ical/$h"; } }
}
echo '    (info) imóveis da massa usados no export: ids ' . implode(',', $pick) . "\n";
ok($icsPaths !== [], 'preparação: ' . count($icsPaths) . ' imóveis ativos da massa com link .ics');
$icsPath = $icsPaths[0] ?? '/ical/' . str_repeat('0', 32);
$stIcs = 0;
foreach ($icsPaths as $pp) { [$stIcs, $bIcs] = http('GET', $pp); if ($stIcs !== 200 || !str_starts_with($bIcs, 'BEGIN:VCALENDAR')) { break; } }
echo "    (info) export .ics (token /ical/{hash32}) de " . count($icsPaths) . " imóveis da massa -> $stIcs\n";
$icsGeraAtividade = $stIcs === 200;

$apiDir = "$root/storage/logs/api";
$actDb = "$root/storage/logs/activity.sqlite";
$phpLog = "$root/storage/logs/php.log";
$errLog = "$root/storage/error.log";
$countAct = static function () use ($actDb): int {
    $pdo = pdo($actDb);
    // CALENDAR_EXPORT = 15 (src/ActivityLog.php): gravado por /ical/{hash} (public/index.php) e pelo export da API.
    return (int) $pdo->query('SELECT COUNT(*) FROM activity_log WHERE action_code = 15')->fetchColumn();
};

for ($r = 1; $r <= $rounds; $r++) {
    echo "Rodada $r/$rounds: $nReq requisições ($par em paralelo) + 2 escritores de texto + retenção em laço\n";
    // material antigo para a retenção ter trabalho real a cada passada
    for ($k = 0; $k < 3; $k++) { $f = "$apiDir/api-2026-08-0$k.log"; file_put_contents($f, "{}\n"); touch($f, time() - 40 * 86400); }
    $oldStamp = '[' . date('d-M-Y H:i:s', time() - 30 * 86400) . ' UTC]';
    foreach ([$phpLog, $errLog] as $f) { file_put_contents($f, "$oldStamp ANTIGA-$r\n", FILE_APPEND); }
    $actBefore = $countAct();

    $tag = "conc$r" . bin2hex(random_bytes(3));
    $stop = "$root/storage/.conc-stop";
    @unlink($stop);
    // retenção em laço (processo separado)
    $semRet = getenv('SEM_RETENCAO') === '1';
    $ret = proc_open(['bash', '-c', $semRet ? "while [ ! -f '$stop' ]; do sleep 0.05; done; echo 0" : "n=0; while [ ! -f '$stop' ]; do php '$root/scripts/log-retention.php' >/dev/null 2>>'$root/storage/.conc-ret-err'; n=\$((n+1)); done; echo \$n"], [1 => ['pipe', 'w']], $p1);
    // escritores de texto (simulam error_log/ExceptionShield: append sem lock)
    $writers = [];
    foreach ([[$phpLog, 'PHP'], [$errLog, 'ERR']] as [$file, $kind]) {
        $code = '$f=' . var_export($file, true) . ';for($i=0;$i<' . $nReq . ';$i++){$h=fopen($f,"a");fwrite($h,"[".date("d-M-Y H:i:s")." UTC] ' . $tag . '-' . $kind . '-".$i." ".str_repeat("x",200)."\n");fclose($h);usleep(500);}';
        $writers[] = proc_open(['php', '-r', $code], [], $pw);
    }
    // requisições HTTP em paralelo (curl_multi)
    $mh = curl_multi_init();
    $queue = range(0, $nReq - 1); $active = []; $icsSent = 0; $sent = 0; $apiIds = [];
    $mk = static function (int $i) use ($base, $icsPaths, $icsPath, $tag, &$icsSent, &$apiIds, $icsGeraAtividade) {
        $ics = $icsPaths ? $icsPaths[intdiv($i, 4) % count($icsPaths)] : $icsPath;
        $paths = ["$ics?t=$tag", '/api/cidades?q=flor', '/api/rota-inexistente', '/api/casas'];
        $so = getenv('SO_ICS') === '1';
        $p = $so ? $paths[0] : $paths[$i % 4];
        if ($so || $i % 4 === 0) { $icsSent++; }
        // RequestLog só cobre rotas /api (public/index.php: str_starts_with($path, '/api')); /ical/{hash} não entra nele.
        if (str_starts_with($p, '/api')) { $apiIds["$tag-req-$i"] = true; }
        $ch = curl_init($base . $p);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60, CURLOPT_HTTPHEADER => ["X-Request-Id: $tag-req-$i"]]);
        return $ch;
    };
    $codes = []; $icsBad = 0; $locked = false;
    while ($queue || $active) {
        while ($queue && count($active) < $par) { $i = array_shift($queue); $ch = $mk($i); curl_multi_add_handle($mh, $ch); $active[(int) $ch] = $ch; $sent++; }
        curl_multi_exec($mh, $running); curl_multi_select($mh, 0.05);
        while ($info = curl_multi_info_read($mh)) {
            $ch = $info['handle']; $c = curl_getinfo($ch, CURLINFO_HTTP_CODE); $codes[$c] = ($codes[$c] ?? 0) + 1;
            if (str_contains((string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL), '/ical/')) {
                $bodyX = (string) curl_multi_getcontent($ch);
                if ($c === 200 && (!str_starts_with($bodyX, 'BEGIN:VCALENDAR') || !str_contains($bodyX, 'END:VCALENDAR'))) { $icsBad++; }
                if ($c === 500 && str_contains((string) @file_get_contents("$root/storage/error.log"), 'database is locked')) { $locked = true; }
            }
            curl_multi_remove_handle($mh, $ch); unset($active[(int) $ch]);
        }
    }
    foreach ($writers as $w) { proc_close($w); }
    touch($stop); $passes = trim((string) stream_get_contents($p1[1])); proc_close($ret); @unlink($stop);
    ksort($codes);
    ok($icsBad === 0, "rodada $r: todo .ics 200 tem VCALENDAR completo ($icsBad inválidos)");
    echo "    retenção executou $passes vez(es); status HTTP: " . json_encode($codes) . "\n";
    usleep(300000);

    // Verificação 1: log de requisições — 1 linha por requisição, JSON íntegro
    $lines = []; $bad = 0;
    foreach (glob("$apiDir/api-*") ?: [] as $f) {
        foreach (file($f, FILE_IGNORE_NEW_LINES) ?: [] as $l) {
            if ($l === '') { continue; }
            $jj = json_decode($l, true);
            if (!is_array($jj)) { $bad++; continue; }
            $lines[] = $jj;
        }
    }
    $ids = array_count_values(array_filter(array_map(static fn ($x) => (string) ($x['request_id'] ?? ''), $lines), static fn ($id) => str_starts_with($id, "$tag-req-")));
    $nApi = count($apiIds);
    $missing = count(array_diff_key($apiIds, $ids)); $extra = count(array_diff_key($ids, $apiIds));
    $dups = count(array_filter($ids, static fn ($n) => $n > 1));
    ok($bad === 0, "rodada $r: todas as linhas do log de API são JSON válidos ($bad inválidas/truncadas/intercaladas)");
    ok($missing === 0 && $extra === 0 && $dups === 0, "rodada $r: RequestLog com 1 linha por requisição /api ($nApi enviadas a /api, " . (count($ids) - $extra) . " registradas, $missing faltando, $extra de fora de /api, $dups duplicadas)");
    $leak = array_filter($lines, static fn ($x) => str_contains(json_encode($x), $tag . '"') || str_contains((string) json_encode($x['query'] ?? []), $tag));
    ok($leak === [], "rodada $r: valor ?t= ausente das linhas sob concorrência");
    // Verificação 2: activity_log — nenhuma inserção perdida durante DELETE/VACUUM
    $actAfter = $countAct();
    // Exatamente 1 linha CALENDAR_EXPORT no ActivityLog por requisição .ics (nem perdida, nem duplicada).
    ok($icsGeraAtividade, "rodada $r: export .ics (" . count($icsPaths) . " imóveis da massa) responde 200 na preparação");
    ok($actAfter - $actBefore === $icsSent && ($codes[500] ?? 0) === 0, "rodada $r: ActivityLog com exatamente 1 CALENDAR_EXPORT por requisição .ics (" . ($actAfter - $actBefore) . "/$icsSent; HTTP 500: " . ($codes[500] ?? 0) . ")");
    // Verificação 3: php.log e error.log reescritos pela retenção — nenhuma linha recente perdida/corrompida
    foreach ([[$phpLog, 'PHP'], [$errLog, 'ERR']] as [$file, $kind]) {
        $txt = (string) @file_get_contents($file);
        preg_match_all('/^\[[^\]]+\] ' . $tag . '-' . $kind . '-(\d+) x{200}$/m', $txt, $m);
        $got = count(array_unique($m[1]));
        $partial = preg_match_all('/' . $tag . '-' . $kind . '-\d+/', $txt) - count($m[1]);
        ok($got === $nReq, "rodada $r: " . basename($file) . " manteve $got/$nReq linhas recentes escritas durante a retenção");
        ok($partial === 0, "rodada $r: " . basename($file) . " sem linhas truncadas/intercaladas ($partial)");
        if (!$semRet) ok(!str_contains($txt, "ANTIGA-$r"), "rodada $r: " . basename($file) . " teve a entrada antiga removida");
    }
    $old = glob("$apiDir/api-2026-08-0*") ?: [];
    if (!$semRet) ok($old === [], "rodada $r: arquivos antigos de API removidos durante a carga");
}
$err = (string) @file_get_contents("$root/storage/.conc-ret-err");
ok(trim($err) === '', 'retenção não emitiu erros durante as rodadas' . ($err ? ': ' . substr(trim($err), 0, 300) : ''));
echo $fail ? "\n$fail falha(s)\n" : "\nTodos os casos de concorrência passaram.\n";
exit($fail ? 1 : 0);
