<?php
// Mailer SMTP contra servidor falso local: sem TLS (só localhost), STARTTLS e TLS implícito com AUTH;
// link sempre sobre APP_URL; mail-dev.log em dev sem SMTP; integração com POST /api/users.
// Transporte via Mailer::deliver(); a fila e a geração de token no worker estão em mail_queue_test.
require __DIR__ . '/lib.php';
require_once CL_ROOT . '/src/Db.php';
require_once CL_ROOT . '/src/Mailer.php';
require_once CL_ROOT . '/src/MailWorker.php';
t_temp_db();
$lock = tempnam(sys_get_temp_dir(), 'mwlock');
/** Entrega direta (como o worker faz) com o link remontado sobre APP_URL; true se entregou. */
function send_now(string $email, string $link): bool
{
    try {
        Mailer::deliver($email, 'CasaLivre: teste', "abra:\r\n" . Mailer::linkFromAppUrl($link) . "\r\n");
        return true;
    } catch (Throwable) {
        return false;
    }
}

$tmp = sys_get_temp_dir() . '/cl-mail-' . bin2hex(random_bytes(3));
mkdir($tmp, 0700);
$procs = [];
register_shutdown_function(static function () use ($tmp, &$procs): void {
    foreach ($procs as $p) {
        proc_terminate($p);
    }
    array_map('unlink', glob($tmp . '/*') ?: []);
    @rmdir($tmp);
});
foreach (['APP_ENV', 'APP_URL', 'SMTP_HOST', 'SMTP_PORT', 'SMTP_USER', 'SMTP_PASS', 'SMTP_FROM', 'SMTP_SECURE', 'SMTP_CA_FILE'] as $k) {
    putenv($k);
}

function fake_smtp(string $mode, string $out, array &$procs, ?string $cert = null, ?string $key = null): int
{
    $port = random_int(41000, 49000);
    $cmd = [PHP_BINARY, __DIR__ . '/fake_smtp.php', (string) $port, $mode, $out];
    if ($cert) {
        $cmd[] = $cert;
        $cmd[] = $key;
    }
    $procs[] = proc_open($cmd, [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
    for ($i = 0; $i < 50; $i++) {
        $s = @fsockopen('127.0.0.1', $port);
        if ($s) {
            fclose($s);
            usleep(50000);
            return $port;
        }
        usleep(100000);
    }
    throw new RuntimeException('fake smtp não subiu');
}

function last_msg(string $out): ?array
{
    for ($i = 0; $i < 30; $i++) {
        $lines = is_file($out) ? file($out) : [];
        // a 1ª linha é a conexão de sonda do fake_smtp() (sem MAIL)
        foreach (array_reverse($lines ?: []) as $l) {
            $m = json_decode($l, true);
            if (!empty($m['data'])) {
                return $m;
            }
        }
        usleep(100000);
    }
    return null;
}

function body_of(array $m): string
{
    [, $b] = explode("\r\n\r\n", (string) $m['data'], 2) + [1 => ''];
    return (string) base64_decode(str_replace(["\r", "\n"], '', $b));
}

echo "link sempre sobre APP_URL\n";
putenv('APP_URL=https://casalivre.example/');
t_eq('https://casalivre.example/verificar?token=abc', Mailer::linkFromAppUrl('http://atacante.example/verificar?token=abc'), 'host da requisição trocado por APP_URL');
putenv('APP_URL');
putenv('APP_ENV=production');
$err = null;
try {
    Mailer::linkFromAppUrl('http://x/verificar?token=a');
} catch (RuntimeException $e) {
    $err = $e->getMessage();
}
t_ok($err !== null, 'produção sem APP_URL: recusa montar link');
putenv('APP_ENV');

echo "dev sem SMTP: mail-dev.log 0600\n";
putenv('APP_ENV=dev');
putenv('APP_URL=http://127.0.0.1:8080');
Mailer::$devLogDir = $tmp;
t_ok(send_now('ana@casalivre.test', 'http://h/verificar?token=TOKDEV'), 'worker grava no mail-dev.log');
$f = $tmp . '/mail-dev.log';
t_eq('0600', substr(sprintf('%o', fileperms($f)), -4), 'permissão 0600');
t_ok(str_contains((string) file_get_contents($f), 'http://127.0.0.1:8080/verificar?token=TOKDEV'), 'link com APP_URL no arquivo');
putenv('APP_ENV=production');
t_ok(!send_now('ana@casalivre.test', 'http://h/verificar?token=TOKPROD'), 'produção sem SMTP: não envia');
t_ok(!Mailer::enqueueAccountMail('activation', 'ana@casalivre.test'), 'produção sem SMTP: enqueue recusa');
t_eq(0, (int) db()->query('SELECT COUNT(*) FROM mail_queue')->fetchColumn(), 'produção sem SMTP: nada enfileirado');
t_ok(!str_contains((string) file_get_contents($f), 'TOKPROD'), 'produção sem SMTP: nada no mail-dev.log');
Mailer::$devLogDir = null;

echo "SMTP sem TLS (somente localhost)\n";
$out = $tmp . '/plain.jsonl';
$port = fake_smtp('plain', $out, $procs);
putenv('APP_URL=https://casalivre.example');
putenv('SMTP_HOST=127.0.0.1');
putenv('SMTP_PORT=' . $port);
putenv('SMTP_SECURE=none');
putenv('SMTP_FROM=nao-responda@casalivre.example');
putenv('SMTP_USER=usuario');
putenv('SMTP_PASS=segredo');
t_ok(send_now('bia@casalivre.test', 'http://forjado/verificar?token=TOK1'), 'envio ok');
$m = last_msg($out);
t_eq('ok', $m['auth'] ?? null, 'AUTH aceito');
t_ok(str_contains($m['from'] ?? '', 'nao-responda@casalivre.example') && str_contains(implode(',', $m['to'] ?? []), 'bia@casalivre.test'), 'MAIL FROM / RCPT TO');
$body = $m ? body_of($m) : '';
t_ok(str_contains($body, 'https://casalivre.example/verificar?token=TOK1') && !str_contains($body, 'forjado'), 'corpo com link de APP_URL');
t_ok(str_contains((string) $m['data'], 'Subject: =?UTF-8?B?'), 'assunto codificado');
t_ok(!str_contains(implode("\n", Mailer::$lastTranscript), base64_encode("\0usuario\0segredo")), 'transcript sem credenciais');
putenv('SMTP_PASS=errada');
t_ok(!send_now('bia@casalivre.test', 'http://x/verificar?token=TOK2'), 'senha errada: falha sem exceção');
putenv('SMTP_PASS=segredo');
putenv('SMTP_HOST=smtp.exemplo.com');
$err = null;
try {
    Mailer::send('bia@casalivre.test', 's', 'b');
} catch (RuntimeException $e) {
    $err = $e->getMessage();
}
t_ok($err !== null && str_contains($err, 'none'), 'SMTP_SECURE=none recusado para host remoto');
putenv('SMTP_HOST=127.0.0.1');
$err = null;
try {
    Mailer::send("bia@casalivre.test\r\nBcc: x@y.z", 's', 'b');
} catch (RuntimeException $e) {
    $err = $e->getMessage();
}
t_ok($err !== null, 'destino com CRLF recusado');

$haveSsl = trim((string) shell_exec('command -v openssl')) !== '';
if ($haveSsl) {
    $cert = $tmp . '/cert.pem';
    $key = $tmp . '/key.pem';
    exec('openssl req -x509 -newkey rsa:2048 -nodes -days 1 -subj /CN=127.0.0.1 -addext subjectAltName=IP:127.0.0.1 -keyout '
        . escapeshellarg($key) . ' -out ' . escapeshellarg($cert) . ' 2>/dev/null', $o, $code);

    foreach (['starttls', 'tls'] as $mode) {
        echo "SMTP $mode\n";
        $out = $tmp . "/$mode.jsonl";
        $port = fake_smtp($mode, $out, $procs, $cert, $key);
        putenv('SMTP_PORT=' . $port);
        putenv('SMTP_SECURE=' . $mode);
        putenv('SMTP_CA_FILE');
        t_ok(!send_now('cris@casalivre.test', 'http://x/verificar?token=NOCA'), "$mode: certificado não confiável é recusado");
        putenv('SMTP_CA_FILE=' . $cert);
        t_ok(send_now('cris@casalivre.test', 'http://x/senha/redefinir?token=TOK3'), "$mode: envio ok com CA");
        $m = last_msg($out);
        t_ok(($m['tls'] ?? false) === true && ($m['auth'] ?? null) === 'ok', "$mode: TLS ativo antes do AUTH");
        t_ok(str_contains(body_of($m ?? []), 'https://casalivre.example/senha/redefinir?token=TOK3'), "$mode: link de redefinição");
    }
} else {
    echo "openssl ausente: testes TLS pulados\n";
}

echo "integração: POST /api/users enfileira; o worker envia\n";
// Mesmo banco temporário do início (db() já está aberto nele); só esvazia a fila dos casos anteriores.
db()->exec('DELETE FROM mail_queue');
db()->exec("INSERT OR IGNORE INTO ceps (cep, street, neighborhood, city, uf) VALUES ('88015200', 'Rua Teste', 'Centro', 'Florianópolis', 'SC')");
$out = $tmp . '/api.jsonl';
$port = fake_smtp('plain', $out, $procs);
putenv('SMTP_PORT=' . $port);
putenv('SMTP_SECURE=none');
putenv('SMTP_CA_FILE');
putenv('APP_URL=https://casalivre.example');
putenv('APP_ENV=production');
$base = t_server();
$jar = tempnam(sys_get_temp_dir(), 'jar');
$page = t_http('GET', $base . '/entrar', null, [], $jar);
preg_match('/name="_csrf" value="([^"]+)"/', $page['body'], $mm);
$pass = 'Casa#' . random_int(100000, 999999) . 'xY';
$email = 'api' . bin2hex(random_bytes(3)) . '@casalivre.test';
$r = t_http('POST', $base . '/api/users', json_encode(['name' => 'Api Teste', 'cpf' => '52998224725', 'cep' => '88015-200',
    'address_number' => '1', 'address_complement' => '', 'phone' => '(48) 99999-0000', 'email' => $email,
    'password' => $pass, 'password_confirm' => $pass]), ['Content-Type: application/json', 'X-CSRF-Token: ' . ($mm[1] ?? '')], $jar);
t_eq(201, $r['status'], 'cadastro 201');
t_eq(null, last_msg($out), 'requisição não fala com o SMTP (só enfileira)');
t_eq(1, (int) db()->query("SELECT COUNT(*) FROM mail_queue WHERE status = 'pending' AND type = 'activation'")->fetchColumn(), 'um pedido activation pendente na fila');
t_eq(1, MailWorker::run(Mailer::queue(), $lock)['enviados'] ?? null, 'worker envia o e-mail da fila');
$m = last_msg($out);
t_ok($m !== null && str_contains(implode(',', $m['to']), $email), 'e-mail entregue ao SMTP');
t_ok($m !== null && str_contains(body_of($m), 'https://casalivre.example/verificar?token='), 'link de ativação com APP_URL');
$tok = db()->prepare('SELECT activation_token FROM users WHERE email = ?');
$tok->execute([$email]);
$tok = (string) $tok->fetchColumn();
t_ok($tok !== '' && $m !== null && str_contains(body_of($m), 'token=' . $tok), 'token gerado pelo worker e gravado no usuário');
t_eq('', (string) db()->query('SELECT recipient FROM mail_queue')->fetchColumn(), 'após ack: destinatário apagado');

foreach (['APP_ENV', 'APP_URL', 'SMTP_HOST', 'SMTP_PORT', 'SMTP_USER', 'SMTP_PASS', 'SMTP_FROM', 'SMTP_SECURE', 'SMTP_CA_FILE'] as $k) {
    putenv($k);
}
t_done();
