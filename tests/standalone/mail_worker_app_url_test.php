<?php
// PR 9 (QA D1 do #68): fora de dev/local o mail-worker recusa iniciar sem APP_URL válida (http/https com host),
// antes de lock, banco ou fila: mensagem clara no stderr, saída != 0 e a fila intacta (attempts 0). Em dev, ou com
// APP_URL válida, segue rodando.
require __DIR__ . '/lib.php';
require __DIR__ . '/char_helpers.php';
require_once CL_ROOT . '/src/Db.php';
require_once CL_ROOT . '/src/helpers.php';
require_once CL_ROOT . '/src/MailWorker.php';
require_once CL_ROOT . '/conta/Account/AccountFlow.php';
t_temp_db();
$admin = c_user('active');
db()->exec('UPDATE users SET is_admin = 1 WHERE id = ' . (int) $admin['id']);
$envFile = (string) tempnam(sys_get_temp_dir(), 'env');
file_put_contents($envFile, "\n");
$run = static function (array $env) use ($envFile): array {
    $base = ['PATH' => (string) getenv('PATH'), 'DB_DSN' => (string) getenv('DB_DSN'), 'DB_USER' => (string) getenv('DB_USER'), 'DB_PASSWORD' => (string) getenv('DB_PASSWORD'), 'CASALIVRE_ENV_FILE' => $envFile, 'SMTP_HOST' => ''];
    $p = proc_open([PHP_BINARY, CL_ROOT . '/scripts/mail-worker.php'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, CL_ROOT, array_merge($base, $env));
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [proc_close($p), (string) $out, (string) $err];
};
$queue = static fn (): array => db()->query('SELECT type, status, attempts FROM mail_queue ORDER BY id')->fetchAll(PDO::FETCH_NUM);
$reset = static function () use ($admin): void {
    db()->exec('DELETE FROM mail_queue');
    MailQueues::make()->push('domain-notice', $admin['email']);
};
$reset();
$antes = $queue();
t_eq(1, count($antes), 'um aviso na fila');
t_eq('0', (string) $antes[0][2], 'attempts 0');

foreach ([['', 'APP_URL não configurada.'], ['ftp://x', 'APP_URL inválida.'], ['https://', 'APP_URL inválida.'], ['casalivre.com.br', 'APP_URL inválida.']] as [$url, $msg]) {
    foreach (['production', ''] as $appEnv) {
        foreach (['', '127.0.0.1'] as $smtp) {
            [$code, $out, $err] = $run(['APP_ENV' => $appEnv, 'APP_URL' => $url, 'SMTP_HOST' => $smtp]);
            $label = "APP_ENV='$appEnv' APP_URL='$url' SMTP_HOST='$smtp'";
            t_ok($code !== 0, "$label: saída != 0 ($code)");
            t_ok(str_contains($err, 'mail-worker: ' . $msg) && str_contains($err, 'nada foi tirado da fila'), "$label: mensagem clara no stderr");
            t_eq('', $out, "$label: nada no stdout");
            t_eq($antes, $queue(), "$label: fila intacta");
        }
    }
}

echo "dev e APP_URL válida seguem rodando\n";
[$code, $out, $err] = $run(['APP_ENV' => 'dev', 'APP_URL' => '']);
t_eq(0, $code, 'dev sem APP_URL: exit 0');
t_ok(!str_contains($err, 'APP_URL'), 'dev sem APP_URL: sem recusa');
t_eq([['domain-notice', 'sent']], array_map(static fn ($r) => [$r[0], $r[1]], $queue()), 'dev: aviso enviado (mail-dev.log)');
$reset();
[$code, $out, $err] = $run(['APP_ENV' => 'production', 'APP_URL' => 'https://casalivre.example']);
t_eq(0, $code, 'produção com APP_URL válida: exit 0');
t_ok(!str_contains($err, 'APP_URL') && str_contains($out, 'falhas'), 'produção com APP_URL válida: passa da checagem e processa o lote');
t_done();
