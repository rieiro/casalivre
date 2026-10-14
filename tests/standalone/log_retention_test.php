<?php
// Retenção de logs: api-*, activity.sqlite (DELETE + VACUUM), error.log, calendar-sync.log, php.log.
require __DIR__ . '/lib.php';
require CL_ROOT . '/src/LogRetention.php';

$root = sys_get_temp_dir() . '/ret-' . bin2hex(random_bytes(3));
mkdir($root . '/storage/logs/api', 0700, true);
$now = strtotime('2026-10-13 12:00:00');
$old = $now - 20 * 86400;
$new = $now - 2 * 86400;

touch($root . '/storage/logs/api/api-old.log', $old);
touch($root . '/storage/logs/api/api-new.log', $new);

$pdo = new PDO('sqlite:' . $root . '/storage/logs/activity.sqlite');
$pdo->exec((string) file_get_contents(CL_ROOT . '/database/activity.sql'));
$ins = $pdo->prepare("INSERT INTO activity_log (created_at, action_code, action, params) VALUES (?, 1, 'x', '{}')");
$ins->execute([date('Y-m-d H:i:s', $old)]);
$ins->execute([date('Y-m-d H:i:s', $old)]);
$ins->execute([date('Y-m-d H:i:s', $new)]);
$pdo = null;

file_put_contents($root . '/storage/error.log', date('c', $old) . " CL-1 velho\n#0 trace velho\n" . date('c', $new) . " CL-2 novo\n#0 trace novo\n");
file_put_contents($root . '/storage/logs/calendar-sync.log', date('c', $old) . " resumo velho\nOK linha velha\n" . date('c', $new) . " resumo novo\nOK linha nova\n");
file_put_contents($root . '/storage/php.log', '[' . date('d-M-Y H:i:s', $old) . " UTC] PHP Warning velho\n[" . date('d-M-Y H:i:s', $new) . " UTC] PHP Warning novo\n");

$report = LogRetention::run($root, 14, $now);

t_ok(!is_file($root . '/storage/logs/api/api-old.log'), 'api-* antigo removido');
t_ok(is_file($root . '/storage/logs/api/api-new.log'), 'api-* recente mantido');
t_eq(2, $report['activity_linhas_removidas'], 'activity: 2 linhas antigas removidas');
$pdo = new PDO('sqlite:' . $root . '/storage/logs/activity.sqlite');
t_eq(1, (int) $pdo->query('SELECT COUNT(*) FROM activity_log')->fetchColumn(), 'activity: 1 linha recente mantida');
$pdo = null;
$err = (string) file_get_contents($root . '/storage/error.log');
t_ok(!str_contains($err, 'velho') && str_contains($err, 'CL-2 novo') && str_contains($err, 'trace novo'), 'error.log: entrada antiga (com trace) removida, nova mantida');
$cal = (string) file_get_contents($root . '/storage/logs/calendar-sync.log');
t_ok(!str_contains($cal, 'velh') && str_contains($cal, 'OK linha nova'), 'calendar-sync.log podado');
$php = (string) file_get_contents($root . '/storage/php.log');
t_ok(!str_contains($php, 'velho') && str_contains($php, 'novo'), 'php.log podado (formato [d-M-Y])');
t_eq('600', substr(sprintf('%o', fileperms($root . '/storage/error.log')), -3), 'arquivo reescrito com 0600');
$again = LogRetention::run($root, 14, $now);
t_eq(0, $again['activity_linhas_removidas'], 'idempotente');

echo "MongoDB\n";
t_eq('nao_configurado', $report['activity_mongo'], 'sem LOG_DRIVER=mongodb: Mongo não é tocado');
t_eq(['created_at' => ['$lt' => '2026-09-29 12:00:00']], LogRetention::mongoFilter($now - 14 * 86400), 'filtro deleteMany por created_at (string)');
putenv('LOG_DRIVER=mongodb');
$prev = ini_set('error_log', '/dev/null');
$res = LogRetention::pruneMongoFromEnv($now);
ini_set('error_log', (string) $prev);
putenv('LOG_DRIVER');
t_ok(class_exists(\MongoDB\Driver\Manager::class) ? is_int($res) || $res === 'erro' : $res === 'extensao_ausente', 'sem extensão mongodb: ignora com aviso (' . var_export($res, true) . ')');

echo "runit\n";
$run = CL_ROOT . '/deploy/runit/casalivre-log-retention/run';
t_ok(is_file($run) && is_executable($run), 'deploy/runit/casalivre-log-retention/run existe e é executável');
exec('sh -n ' . escapeshellarg($run) . ' 2>&1', $o, $code);
t_eq(0, $code, 'script runit com sintaxe sh válida');
t_ok(str_contains((string) file_get_contents($run), 'scripts/log-retention.php'), 'runit chama scripts/log-retention.php');

exec('rm -rf ' . escapeshellarg($root));
t_done();
