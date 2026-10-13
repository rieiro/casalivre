<?php
// scripts/log-lookup.php: recusa sem LOG_SALT; encontra registros no activity.sqlite e api-*.
require __DIR__ . '/lib.php';
require CL_ROOT . '/src/LogPrivacy.php';

$root = sys_get_temp_dir() . '/lookup-' . bin2hex(random_bytes(3));
mkdir($root . '/storage/logs/api', 0700, true);
$script = CL_ROOT . '/scripts/log-lookup.php';
$run = static function (string $salt, string $args) use ($script): array {
    $env = 'LOG_SALT=' . escapeshellarg($salt) . ' LOG_DRIVER=sqlite';
    exec($env . ' ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' ' . $args . ' 2>/dev/null', $out, $code);
    return [$code, $out];
};

[$code] = $run('', '42 --root=' . escapeshellarg($root));
t_eq(2, $code, 'sem LOG_SALT: recusa (código 2)');
[$code] = $run('s', '--root=' . escapeshellarg($root));
t_eq(64, $code, 'sem user_id: uso inválido (código 64)');

putenv('LOG_SALT=sal-lookup');
$ref = LogPrivacy::user(42);
$other = LogPrivacy::user(7);
$pdo = new PDO('sqlite:' . $root . '/storage/logs/activity.sqlite');
$pdo->exec((string) file_get_contents(CL_ROOT . '/database/activity.sql'));
$ins = $pdo->prepare("INSERT INTO activity_log (created_at, action_code, action, actor_id, params) VALUES ('2026-10-13 10:00:00', 1, ?, ?, '{}')");
$ins->execute(['login', $ref]);
$ins->execute(['outro', $other]);
$pdo = null;
file_put_contents($root . '/storage/logs/api/api-2026-10-13.log',
    json_encode(['request_id' => 'r1', 'user' => $ref, 'path' => '/api/painel']) . "\n" .
    json_encode(['request_id' => 'r2', 'user' => $other, 'path' => '/api/painel']) . "\n");

[$code, $out] = $run('sal-lookup', '42 --root=' . escapeshellarg($root));
t_eq(0, $code, 'com LOG_SALT: executa');
$rows = array_map(static fn ($l) => json_decode($l, true), $out);
t_eq(2, count($rows), 'encontra 2 registros do usuário 42');
t_eq(['activity.sqlite', 'api-2026-10-13.log'], array_column($rows, 'fonte'), 'um no activity.sqlite e um no api-*');
t_ok(!in_array($other, array_column($rows, 'actor_id'), true) && !in_array('r2', array_column($rows, 'request_id'), true), 'não traz registros de outro usuário');
[$code, $out] = $run('sal-errado', '42 --root=' . escapeshellarg($root));
t_eq(0, count($out), 'com sal diferente: nada encontrado');

exec('rm -rf ' . escapeshellarg($root));
t_done();
