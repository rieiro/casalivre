<?php
// Caracterização (Sprint 3, PR 1): snapshots do que o tema vai mexer — HTML de home/busca/mapa/imóvel e
// páginas de erro, header CSP (e demais headers de segurança), URLs de assets, texto dos e-mails e o
// style-light.json do mapa. Registra o comportamento ATUAL; não altera código de produção.
//
// Snapshots em tests/standalone/snapshots/*.txt. Partes voláteis são normalizadas (snap_norm()).
// Para atualizar depois de uma mudança INTENCIONAL:  UPDATE_SNAPSHOTS=1 php tests/standalone/char_snapshot_test.php
// e revise o diff dos .txt no commit.
require __DIR__ . '/lib.php';
require __DIR__ . '/char_helpers.php';

const SNAP_DIR = __DIR__ . '/snapshots';
$update = getenv('UPDATE_SNAPSHOTS') === '1';

/** Troca o que muda entre execuções/bancos por marcadores fixos. */
function snap_norm(string $s, array $fixos = []): string
{
    foreach ($fixos as $de => $para) {
        $s = str_replace($de, $para, $s);
    }
    $s = preg_replace('#https?://127\.0\.0\.1:\d+#', 'http://APP', $s);
    $s = preg_replace('/(name="_csrf" value=")[0-9a-f]+/', '$1<CSRF>', $s);
    $s = preg_replace('/("csrf":")[0-9a-f]+/', '$1<CSRF>', $s);
    $s = preg_replace('/(nonce=["\']?|\'nonce-)[A-Za-z0-9+\/=_-]{8,}/', '$1<NONCE>', $s);
    $s = preg_replace('/token=[0-9a-f]{16,}/', 'token=<TOKEN>', $s);
    $s = preg_replace('/\b[0-9a-f]{64}\b/', '<HASH64>', $s);
    $s = preg_replace('/\b[0-9a-f]{32}\b/', '<HASH32>', $s);
    $s = preg_replace('/\b[0-9a-f]{16}\b/', '<HASH16>', $s);
    $s = preg_replace('/CL-[0-9A-F]{8}/', 'CL-<REF>', $s);
    $s = preg_replace('/\d{4}-\d\d-\d\d([ T]\d\d:\d\d(:\d\d)?([+-]\d\d:\d\d|Z)?)?/', '<DATA>', $s);
    $s = preg_replace('/\d\d\/\d\d\/\d{4}/', '<DATA>', $s);
    $s = preg_replace('/^(Date|Set-Cookie|X-Request-Id|Host|Expires|Last-Modified|ETag|Retry-After):.*$/mi', '$1: <V>', $s);
    return str_replace("\r\n", "\n", $s);
}

/** Compara com o snapshot (ou grava em modo update). */
function snap(string $nome, string $conteudo): void
{
    global $update;
    $file = SNAP_DIR . '/' . $nome . '.txt';
    if ($update || !is_file($file)) {
        file_put_contents($file, $conteudo);
        t_ok(true, "$nome: snapshot " . ($update ? 'atualizado' : 'criado'));
        return;
    }
    $antes = (string) file_get_contents($file);
    if ($antes === $conteudo) {
        t_ok(true, "$nome: igual ao snapshot");
        return;
    }
    $a = explode("\n", $antes);
    $b = explode("\n", $conteudo);
    $i = 0;
    while ($i < min(count($a), count($b)) && $a[$i] === $b[$i]) {
        $i++;
    }
    file_put_contents(sys_get_temp_dir() . "/snap-$nome.atual.txt", $conteudo);
    t_ok(false, "$nome: difere do snapshot na linha " . ($i + 1) . "\n       esperado: " . substr($a[$i] ?? '<fim>', 0, 200)
        . "\n       obtido:   " . substr($b[$i] ?? '<fim>', 0, 200) . "\n       (atual em " . sys_get_temp_dir() . "/snap-$nome.atual.txt)");
}

/** Headers relevantes (status + segurança + tipo), normalizados e ordenados. */
function snap_headers(array $r): string
{
    $lines = preg_split('/\r?\n/', trim($r['headers'])) ?: [];
    $status = preg_replace('/^HTTP\/[\d.]+\s+/', '', (string) array_shift($lines));
    $keep = [];
    foreach ($lines as $l) {
        if (preg_match('/^(Content-Type|Content-Security-Policy[^:]*|X-Frame-Options|X-Content-Type-Options|Referrer-Policy|Permissions-Policy|Strict-Transport-Security|Cross-Origin-[^:]+|Cache-Control|Location|Vary|Retry-After):/i', $l)) {
            // Retry-After varia em 1 s conforme o relógio (3599/3600): placeholder.
            $keep[] = preg_replace('/^(Retry-After):.*$/i', '$1: <SEGUNDOS>', $l);
        }
    }
    sort($keep);
    return "STATUS $status\n" . implode("\n", $keep) . "\n";
}

// ---- dados fixos (banco temporário) ----
t_temp_db();
require_once CL_ROOT . '/src/Db.php';
require_once CL_ROOT . '/src/UserRepository.php';
require_once CL_ROOT . '/src/HouseRepository.php';
require_once CL_ROOT . '/src/helpers.php';
db()->exec("INSERT INTO ceps (cep, street, neighborhood, city, uf) VALUES ('88015200', 'Rua Bocaiúva', 'Centro', 'Florianópolis', 'SC') ON CONFLICT DO NOTHING");
$senha = 'Casa#Snap2026';
$userId = UserRepository::insert([
    'name' => 'Dona Snapshot', 'cpf' => '52998224725', 'address' => 'Rua Bocaiúva, 1', 'cep' => '88015200',
    'street' => 'Rua Bocaiúva', 'address_number' => '1', 'address_complement' => '', 'phone' => '48999999999',
    'email' => 'snap-dona@casalivre.test', 'password_hash' => password_make($senha), 'status' => 'active',
    'activated_at' => '2026-01-01 10:00:00',
]);
$houseId = HouseRepository::insert([
    'user_id' => $userId, 'title' => 'Casa Snapshot na Lagoa', 'description' => 'Casa ampla para caracterização do tema, com varanda e vista para a lagoa. Texto fixo do snapshot.',
    'summary' => 'Casa ampla para caracterização do tema', 'city' => 'Florianópolis, SC', 'address' => 'Rua Bocaiúva, 1', 'cep' => '88015200',
    'street' => 'Rua Bocaiúva', 'address_number' => '1', 'address_complement' => '', 'lat' => -27.5960, 'lng' => -48.5480,
    'bedrooms' => 2, 'beds' => 3, 'max_guests' => 4, 'nightly_price' => 250, 'checkin_time' => '15:00',
    'checkout_time' => '11:00', 'min_nights' => 1, 'pool' => 1, 'bbq' => 0, 'garage' => 1,
]);
$house = HouseRepository::find($houseId);
$hash = HouseRepository::ensurePublicHash($house);
$fixos = [$hash => '<IMOVEL>'];

// ---- páginas (servidor como em produção: sem APP_ENV) ----
$base = t_server();
$jar = tempnam(sys_get_temp_dir(), 'jar');
$loc = 'localidade=' . rawurlencode('Florianópolis, SC');
$paginas = [
    'home' => '/',
    'busca' => '/busca?' . $loc,
    'mapa' => '/busca/mapa?' . $loc,
    'imovel' => '/imoveis/' . $hash,
    'entrar' => '/entrar',
    'erro-404-rota' => '/rota-que-nao-existe',
    'erro-404-ical' => '/ical/' . str_repeat('0', 32) . '.ics',
];
$assets = [];
$csp = [];
foreach ($paginas as $nome => $path) {
    $r = t_http('GET', $base . $path, null, [], $jar);
    echo "$nome\n";
    snap("html-$nome", snap_headers($r) . "\n" . snap_norm($r['body'], $fixos));
    preg_match_all('#(?:src|href)="(/assets/[^"]+)"#', $r['body'], $m);
    foreach ($m[1] as $a) {
        $assets[$a][] = $nome;
    }
    preg_match('/^Content-Security-Policy:\s*(.*)$/mi', $r['headers'], $c);
    $csp[$nome] = trim($c[1] ?? '(sem CSP)');
}

echo "erros\n";
$csrf = c_csrf($base, $jar);
// 419: formulário web sem token
$r = c_form($base, '/entrar', ['email' => 'x@casalivre.test', 'password' => 'x'], $jar);
snap('html-erro-419-csrf', snap_headers($r) . "\n" . snap_norm($r['body'], $fixos));
// 404 do imóvel inexistente numa rota web pública (ImovelNaoEncontrado::publico)
$r = c_form($base, '/imoveis/ffffffffffffffff/avaliar', ['_csrf' => $csrf, 'score' => 8], $jar);
snap('html-erro-404-imovel', snap_headers($r) . "\n" . snap_norm($r['body'], $fixos));
// 413: corpo grande demais (BodyLimit) num formulário web
$r = t_http('POST', $base . '/entrar', http_build_query(['_csrf' => $csrf, 'x' => str_repeat('a', 2 * 1024 * 1024)]), ['Content-Type: application/x-www-form-urlencoded'], $jar);
snap('html-erro-413-corpo', snap_headers($r) . "\n" . snap_norm($r['body'], $fixos));
// 429 HTML: 11ª avaliação pela web no mesmo IP (limite 10/h compartilhado com a API)
t_ratelimit_reset();
for ($i = 0; $i < 10; $i++) {
    c_form($base, '/imoveis/' . $hash . '/avaliar', ['_csrf' => $csrf, 'score' => 0], $jar);
}
$r = c_form($base, '/imoveis/' . $hash . '/avaliar', ['_csrf' => $csrf, 'score' => 0], $jar);
snap('html-erro-429', snap_headers($r) . "\n" . snap_norm($r['body'], $fixos));
t_ratelimit_reset();
// 413 de upload acima do post_max_size (reject_oversize_upload, página com style inline)
$limite = ini_bytes((string) ini_get('post_max_size'));
$bound = 'snapbound';
$corpo = "--$bound\r\nContent-Disposition: form-data; name=\"_csrf\"\r\n\r\n$csrf\r\n--$bound\r\nContent-Disposition: form-data; name=\"fotos[0]\"; filename=\"a.png\"\r\nContent-Type: image/png\r\n\r\n"
    . str_repeat('a', $limite + 1024) . "\r\n--$bound--\r\n";
$r = t_http('POST', $base . '/imoveis/' . $hash . '/fotos', $corpo, ['Content-Type: multipart/form-data; boundary=' . $bound], $jar);
// Limite e tamanho saem do mesmo php.ini do app (post_max_size), via oversize_upload_message():
// o snapshot não fixa o limite (dev 20M, ambiente/produção 20M). A frase é conferida aqui e sai do snapshot.
$frase = oversize_upload_message(strlen($corpo), $limite);
t_ok(str_contains($r['body'], $frase), "413 upload: frase com o limite do php.ini ($frase)");
snap('html-erro-413-upload', snap_headers($r) . "\n" . snap_norm(str_replace($frase, '', $r['body']), $fixos));

// 500 do ExceptionShield (página própria com <style> inline): servidor com banco inacessível.
$dsn = (string) getenv('DB_DSN');
putenv('DB_DSN=pgsql:host=/proc/casalivre-snapshot-sem-banco;dbname=x;connect_timeout=2');
$quebrado = t_server();
putenv('DB_DSN=' . $dsn);
$r = t_http('GET', $quebrado . '/api/busca?' . $loc);
t_eq(500, $r['status'], 'banco inacessível -> 500 do ExceptionShield');
snap('html-erro-500-shield', snap_headers($r) . "\n" . snap_norm($r['body'], $fixos));

echo "CSP e assets\n";
$linhas = [];
foreach ($csp as $nome => $valor) {
    $linhas[] = "$nome: " . snap_norm($valor);
}
snap('csp', implode("\n", $linhas) . "\n");
ksort($assets);
$linhas = [];
foreach ($assets as $a => $onde) {
    $linhas[] = $a . '  <- ' . implode(',', array_values(array_unique($onde)));
}
snap('assets', implode("\n", $linhas) . "\n");

echo "estilo do mapa\n";
$style = (string) file_get_contents(CL_ROOT . '/public/assets/map/style-light.json');
t_ok(is_array(json_decode($style, true)), 'style-light.json é JSON válido');
snap('mapa-style-light', 'sha256 ' . hash('sha256', $style) . "\n" . json_encode(json_decode($style, true), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
$r = t_http('GET', $base . '/assets/map/style-light.json');
t_eq($style, $r['body'], 'style-light.json servido igual ao arquivo');

echo "e-mails (fila + mail-worker + mail-dev.log)\n";
// Servidor em dev (sem SMTP o worker grava no mail-dev.log); log numa pasta temporária.
$logDir = sys_get_temp_dir() . '/cl-snapmail-' . bin2hex(random_bytes(4));
@mkdir($logDir, 0700, true);
putenv('APP_ENV=dev');
putenv('APP_URL=http://127.0.0.1:1');
$dev = t_server();
$jar2 = tempnam(sys_get_temp_dir(), 'jar');
$c2 = c_csrf($dev, $jar2, '/cadastro');
$novo = ['name' => 'Pessoa Snapshot', 'cpf' => '52998224725', 'cep' => '88015200', 'address_number' => '7', 'phone' => '48999990000', 'password' => 'Senha-segura1', 'password_confirm' => 'Senha-segura1'];
c_api('POST', $dev, '/api/users', $novo + ['email' => 'snap-novo@casalivre.test'], $c2, $jar2);           // activation
c_api('POST', $dev, '/api/users', $novo + ['email' => 'snap-dona@casalivre.test'], $c2, $jar2);           // exists
c_api('POST', $dev, '/api/auth/senha/esqueci', ['email' => 'snap-dona@casalivre.test'], $c2, $jar2);      // reset
require_once CL_ROOT . '/src/Mailer.php';
require_once CL_ROOT . '/src/MailWorker.php';
Mailer::$devLogDir = $logDir;
MailWorker::run(MailQueues::make(), $logDir . '/mail-worker.lock');
$blocos = [];
foreach (array_filter(explode("\n", (string) @file_get_contents($logDir . '/mail-dev.log'))) as $l) {
    $j = json_decode($l, true) ?: [];
    $tipo = str_contains((string) ($j['subject'] ?? ''), 'redefini') ? 'reset' : (str_contains((string) ($j['subject'] ?? ''), 'ative') ? 'activation' : 'exists');
    [$assunto, $corpo] = $tipo === 'exists' ? Mailer::existsNotice() : Mailer::linkMail($tipo, (string) $j['link']);
    $blocos[$tipo] = "== $tipo\nmail-dev.log: para={$j['to']} | assunto={$j['subject']} | link=" . snap_norm((string) $j['link'])
        . "\nassunto: $assunto\n--- corpo ---\n" . snap_norm($corpo);
}
ksort($blocos);
t_eq(['activation', 'exists', 'reset'], array_keys($blocos), '3 e-mails no mail-dev.log (ativação, conta existente, redefinição)');
snap('emails', implode("\n", $blocos));
@exec('rm -rf ' . escapeshellarg($logDir));

t_done();
