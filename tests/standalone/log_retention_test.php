<?php
// Retenção de logs em arquivo: api-*, error.log, calendar-sync.log, php.log. A do audit.activity_log (DROP de
// partição) está em activity_log_pg_test.php; aqui só o passo PHP (LogRetention::pgStep / scripts/log-retention.php).
require __DIR__ . '/lib.php';
require CL_ROOT . '/src/LogRetention.php';

$root = sys_get_temp_dir() . '/ret-' . bin2hex(random_bytes(3));
mkdir($root . '/storage/logs/api', 0700, true);
$now = strtotime('2026-10-13 12:00:00');
$old = $now - 20 * 86400;
$new = $now - 2 * 86400;

touch($root . '/storage/logs/api/api-old.log', $old);
touch($root . '/storage/logs/api/api-new.log', $new);

file_put_contents($root . '/storage/error.log', date('c', $old) . " CL-1 velho\n#0 trace velho\n" . date('c', $new) . " CL-2 novo\n#0 trace novo\n");
file_put_contents($root . '/storage/logs/calendar-sync.log', date('c', $old) . " resumo velho\nOK linha velha\n" . date('c', $new) . " resumo novo\nOK linha nova\n");
file_put_contents($root . '/storage/php.log', '[' . date('d-M-Y H:i:s', $old) . " UTC] PHP Warning velho\n[" . date('d-M-Y H:i:s', $new) . " UTC] PHP Warning novo\n");

$report = LogRetention::run($root, 14, $now);

t_ok(!is_file($root . '/storage/logs/api/api-old.log'), 'api-* antigo removido');
t_ok(is_file($root . '/storage/logs/api/api-new.log'), 'api-* recente mantido');
t_ok(!array_key_exists('activity_linhas_removidas', $report), 'arquivos: o relatório não fala mais de activity.sqlite');
$err = (string) file_get_contents($root . '/storage/error.log');
t_ok(!str_contains($err, 'velho') && str_contains($err, 'CL-2 novo') && str_contains($err, 'trace novo'), 'error.log: entrada antiga (com trace) removida, nova mantida');
$cal = (string) file_get_contents($root . '/storage/logs/calendar-sync.log');
t_ok(!str_contains($cal, 'velh') && str_contains($cal, 'OK linha nova'), 'calendar-sync.log podado');
$php = (string) file_get_contents($root . '/storage/php.log');
t_ok(!str_contains($php, 'velho') && str_contains($php, 'novo'), 'php.log podado (formato [d-M-Y])');
t_eq('600', substr(sprintf('%o', fileperms($root . '/storage/error.log')), -3), 'arquivo reescrito com 0600');
$again = LogRetention::run($root, 14, $now);
t_eq(0, $again['api_arquivos_removidos'] ?? 0, 'idempotente');

echo "PostgreSQL (pgStep)\n";
t_temp_db();
putenv('LOG_SALT=sal-retencao');
require_once CL_ROOT . '/src/LogPrivacy.php';
$own = t_pg_as('casalivre_retention');
// folha other de 20 dias atrás (vencida) e uma de 10 (dentro dos 14 d), criadas como o dono; as linhas datadas também
// (os papéis do app não têm INSERT em created_at: quem data a linha é o banco)
foreach ([20, 10] as $d) {
    $day = gmdate('Y-m-d', time() - $d * 86400);
    $own->exec("CREATE TABLE audit.activity_log_other_p" . str_replace('-', '', $day) . " PARTITION OF audit.activity_log_other FOR VALUES FROM ('$day 00:00+00') TO ('" . gmdate('Y-m-d', strtotime("$day +1 day")) . " 00:00+00')");
}
$app = new PDO((string) getenv('DB_DSN'), getenv('DB_USER') ?: null, getenv('DB_PASSWORD') ?: null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$ins = $own->prepare("INSERT INTO audit.activity_log (id, created_at, code, retention_class, actor_kind, actor_ref, actor_key_version) VALUES (gen_random_uuid(), now() - ?::interval, 5, 'other', ?, ?, ?)");
$held = LogPrivacy::userRef(42);
foreach ([['20 days', 'user', $held, 1], ['20 days', 'anon', null, null], ['10 days', 'anon', null, null]] as [$ago, $kind, $ref, $kv]) {
    $ins->bindValue(1, $ago);
    $ins->bindValue(2, $kind);
    $ins->bindValue(3, $ref, $ref === null ? PDO::PARAM_NULL : PDO::PARAM_LOB);
    $ins->bindValue(4, $kv, $kv === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
    $ins->execute();
}
$uid = t_seed_owner()['user_id'];
$app->exec("INSERT INTO legal_holds (hold_kind, subject_type, subject_id, case_ref) VALUES ('judicial', 'user', $uid, 'proc-1')");
$refs = LogRetention::heldRefs($app);
t_eq([bin2hex(LogPrivacy::userRef((int) $uid))], array_map('bin2hex', $refs), 'heldRefs: HMAC de 32 bytes do usuário com hold aberto');
$refs[] = $held; // o usuário 42 também está retido neste teste
$dry = LogRetention::pgStep($app, $refs);
t_eq('activity_log_other_p' . gmdate('Ymd', time() - 20 * 86400), $dry['activity_proxima_particao'], 'dry-run: aponta a folha vencida mais antiga');
t_eq(3, (int) $own->query('SELECT count(*) FROM audit.activity_log')->fetchColumn(), 'dry-run: nada removido');
$real = LogRetention::pgStep($app, $refs, false);
t_eq(1, $real['activity_particoes_removidas'], 'apply: 1 folha removida');
t_eq(1, $real['activity_linhas_retidas'], 'apply: 1 linha retida (hold) copiada para _held');
t_eq(1, (int) $own->query('SELECT count(*) FROM audit.activity_log')->fetchColumn(), 'apply: só a linha de 10 dias continua no log');
t_eq(1, (int) $own->query('SELECT count(*) FROM audit.activity_log_held')->fetchColumn(), 'apply: _held com a linha do usuário retido');
t_eq(true, (bool) $app->query('SELECT current_user = session_user')->fetchColumn(), 'pgStep volta ao papel do login (RESET ROLE)');
t_ok(str_contains((string) file_get_contents(CL_ROOT . '/scripts/log-retention.php'), '--apply'), 'scripts/log-retention.php: dry-run por padrão, --apply remove');
putenv('LOG_SALT');
$threw = false;
try {
    LogRetention::heldRefs($app);
} catch (RuntimeException) {
    $threw = true;
}
t_ok($threw, 'sem LOG_SALT e com hold aberto: heldRefs recusa (o passo nunca roda sem as refs)');

echo "runit\n";
$run = CL_ROOT . '/deploy/runit/casalivre-log-retention/run';
t_ok(is_file($run) && is_executable($run), 'deploy/runit/casalivre-log-retention/run existe e é executável');
exec('sh -n ' . escapeshellarg($run) . ' 2>&1', $o, $code);
t_eq(0, $code, 'script runit com sintaxe sh válida');
$runBody = (string) file_get_contents($run);
t_ok(str_contains($runBody, 'casalivre-daily') && str_contains($runBody, 'scripts/log-retention.php'), 'runit chama scripts/log-retention.php pelo helper diário');
t_ok(!preg_match('/\|\|\s*echo/', $runBody), 'runit não engole o código de saída (sem || echo)');
$daily = (string) file_get_contents(CL_ROOT . '/deploy/runit/casalivre-daily');
t_ok(str_contains($daily, 'exit "$status"') && !preg_match('/\|\|\s*echo/', $daily), 'saída diferente de zero volta para o runit');
t_ok(is_executable(CL_ROOT . '/deploy/runit/casalivre-log-retention/finish'), 'finish alerta a saída diferente de zero');

echo "scripts/log-retention.php: LOG_JOB_DB_USER obrigatório fora de dev (Arquitetura #148)\n";
$script = CL_ROOT . '/scripts/log-retention.php';
$envFile = (string) tempnam(sys_get_temp_dir(), 'env');
file_put_contents($envFile, "\n");
$runScript = static function (array $env) use ($script, $envFile): array {
    $base = ['PATH' => (string) getenv('PATH'), 'DB_DSN' => (string) getenv('DB_DSN'), 'DB_USER' => (string) getenv('DB_USER'),
        'DB_PASSWORD' => (string) getenv('DB_PASSWORD'), 'CASALIVRE_ENV_FILE' => $envFile];
    $p = proc_open([PHP_BINARY, $script], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, CL_ROOT, array_merge($base, $env));
    $out = (string) stream_get_contents($pipes[1]);
    $err = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [proc_close($p), $out, $err];
};
$runs0 = (int) $app->query('SELECT count(*) FROM purge_runs')->fetchColumn();
foreach (['production', '', 'staging'] as $appEnv) {
    [$code, $out, $err] = $runScript(['APP_ENV' => $appEnv, 'LOG_JOB_DB_USER' => '']);
    t_eq(2, $code, "APP_ENV='$appEnv' sem LOG_JOB_DB_USER: saída 2");
    t_ok(str_contains($err, 'LOG_JOB_DB_USER is not set') && str_contains($err, 'Nothing was done'), "APP_ENV='$appEnv': mensagem clara no stderr");
    t_eq('', $out, "APP_ENV='$appEnv': nada no stdout (não rodou)");
}
t_eq($runs0, (int) $app->query('SELECT count(*) FROM purge_runs')->fetchColumn(), 'recusa antes de tocar no PostgreSQL (purge_runs igual)');
[$code, $out, $err] = $runScript(['APP_ENV' => 'dev', 'LOG_JOB_DB_USER' => '', 'LOG_SALT' => 'sal-retencao']);
t_eq(0, $code, "APP_ENV=dev sem LOG_JOB_DB_USER: roda com o login do dev (saída 0) ($err)");
t_ok(str_contains($out, 'saude_criticos') && str_contains($out, 'activity_proxima_particao'), 'dev: relatório com o passo (dry-run) e a saúde');
// DEFAULT com linha: o run move a linha (leaf_ensure) e sai 0; sem conseguir criar a folha (critical) sai 1 com ALERT.
$own->exec("INSERT INTO audit.activity_log (id, created_at, code, retention_class, actor_kind) VALUES (gen_random_uuid(), now() + interval '21 days', 5, 'other', 'anon')");
[$code, $out, $err] = $runScript(['APP_ENV' => 'dev', 'LOG_SALT' => 'sal-retencao']);
t_ok($code === 0 && preg_match('/activity_linhas_movidas_da_default\s*1\b/', $out) === 1, 'linha na DEFAULT: movida para a folha do dia, saída 0');
$own->exec("INSERT INTO audit.activity_log (id, created_at, code, retention_class, actor_kind) VALUES (gen_random_uuid(), now() + interval '22 days', 5, 'other', 'anon')");
$lock = t_pg_as('casalivre_retention');
$lock->beginTransaction();
$lock->exec('LOCK TABLE audit.activity_log_other_default IN ACCESS SHARE MODE');
[$code, $out, $err] = $runScript(['APP_ENV' => 'dev', 'LOG_SALT' => 'sal-retencao']);
$lock->rollBack();
t_eq(1, $code, 'DEFAULT com linha e folha impossível de criar agora: saída 1');
t_ok(str_contains($err, 'ALERT saude default_empty activity_log_other: critical'), 'ALERT no stderr com o check critical');
t_ok(str_contains($err, 'WARN aviso_activity_particoes_falharam'), 'WARN com a folha que falhou');
[$code] = $runScript(['APP_ENV' => 'dev', 'LOG_SALT' => 'sal-retencao']);
t_eq(0, $code, 'próximo run (sem o leitor): repara e sai 0');
@unlink($envFile);

echo "dev-env: serviço log-retention (runit) como o papel do job\n";
$sv = CL_ROOT . '/ops/dev-env/sv/log-retention/run';
$body = (string) @file_get_contents($sv);
t_ok(is_file($sv), 'ops/dev-env/sv/log-retention/run existe');
exec('sh -n ' . escapeshellarg($sv) . ' 2>&1', $o2, $code);
t_eq(0, $code, 'sintaxe sh válida');
t_ok(str_contains($body, 'LOG_JOB_DB_USER=cl_retention_job') && str_contains($body, 'scripts/log-retention.php') && str_contains($body, 'sleep 86400'), 'roda log-retention.php como cl_retention_job, uma vez por dia');
t_ok(str_contains($body, 'CL_LOG_RETENTION_APPLY') && !preg_match('/^[^#]*--apply(?!\})/m', str_replace('${CL_LOG_RETENTION_APPLY:+--apply}', '', $body)), 'dry-run a menos que CL_LOG_RETENTION_APPLY=1');
$pgRun = (string) file_get_contents(CL_ROOT . '/ops/dev-env/sv/postgresql/run');
t_ok(str_contains($pgRun, 'local all cl_retention_job peer map=casalivre') && str_contains($pgRun, 'casalivre $CL_PG_USER cl_retention_job'), 'pg_hba/pg_ident: cl_retention_job por peer (como casalivre_dev)');
t_ok(str_contains((string) file_get_contents(CL_ROOT . '/ops/dev-env/pg-roles.sql'), "CREATE ROLE cl_retention_job LOGIN"), 'pg-roles.sql provisiona cl_retention_job');

exec('rm -rf ' . escapeshellarg($root));
t_done();
