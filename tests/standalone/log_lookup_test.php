<?php
// scripts/log-lookup.php: recusa sem LOG_SALT; encontra registros no audit.activity_log (função DEFINER, como
// casalivre_ops_logread) e nos api-*; a leitura fica registrada no audit_log (ACTIVITY_LOG_READ).
require __DIR__ . '/lib.php';
require_once CL_ROOT . '/src/LogPrivacy.php';

$root = sys_get_temp_dir() . '/lookup-' . bin2hex(random_bytes(3));
mkdir($root . '/storage/logs/api', 0700, true);
$script = CL_ROOT . '/scripts/log-lookup.php';
$run = static function (string $salt, string $args) use ($script): array {
    $env = 'LOG_SALT=' . escapeshellarg($salt);
    exec($env . ' ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' ' . $args . ' 2>/dev/null', $out, $code);
    return [$code, $out];
};

[$code] = $run('', '42 --root=' . escapeshellarg($root));
t_eq(2, $code, 'sem LOG_SALT: recusa (código 2)');
[$code] = $run('s', '--root=' . escapeshellarg($root));
t_eq(64, $code, 'sem user_id: uso inválido (código 64)');

t_temp_db();
putenv('LOG_SALT=sal-lookup');
$ref = LogPrivacy::user(42);
$other = LogPrivacy::user(7);
t_ok(preg_match('/^[0-9a-f]{64}$/', (string) $ref) === 1, 'pseudônimo do api-* com 64 hex (sem truncar)');
$pdo = new PDO((string) getenv('DB_DSN'), getenv('DB_USER') ?: null, getenv('DB_PASSWORD') ?: null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$ins = $pdo->prepare("INSERT INTO audit.activity_log (id, code, retention_class, actor_kind, actor_ref, actor_key_version, access_event_id) VALUES (uuidv7(), 1, 'security', 'user', ?, 1, gen_random_uuid())");
foreach ([LogPrivacy::userRef(42), LogPrivacy::userRef(7)] as $r) {
    $ins->bindValue(1, $r, PDO::PARAM_LOB);
    $ins->execute();
}
file_put_contents($root . '/storage/logs/api/api-2026-10-13.log',
    json_encode(['request_id' => 'r1', 'user' => $ref, 'path' => '/api/painel']) . "\n" .
    json_encode(['request_id' => 'r2', 'user' => $other, 'path' => '/api/painel']) . "\n");

[$code, $out] = $run('sal-lookup', '42 --root=' . escapeshellarg($root));
t_eq(0, $code, 'com LOG_SALT: executa');
$rows = array_map(static fn ($l) => json_decode($l, true), $out);
t_eq(2, count($rows), 'encontra 2 registros do usuário 42');
t_eq(['activity_log', 'api-2026-10-13.log'], array_column($rows, 'fonte'), 'um no audit.activity_log e um no api-*');
t_ok(!in_array('r2', array_column($rows, 'request_id'), true), 'não traz registros de outro usuário');
$reads = array_values(array_filter(t_log_rows(), static fn ($r) => $r['code'] === 47));
t_eq(['activity_by_actor', 1], [$reads[0]['params']['function'] ?? null, $reads[0]['params']['refs'] ?? null], 'leitura registrada no audit_log (ACTIVITY_LOG_READ: função e nº de refs, sem as refs)');
t_ok(!str_contains(json_encode($reads), bin2hex(LogPrivacy::userRef(42))), 'o registro da leitura não contém a ref');
echo "paginação (before_id)\n";
foreach ([1, 2] as $_) {
    $ins->bindValue(1, LogPrivacy::userRef(42), PDO::PARAM_LOB);
    $ins->execute();
}
$m = t_log_mark();
[$code, $out] = $run('sal-lookup', '42 --pagina=2 --root=' . escapeshellarg($root));
$rows = array_values(array_filter(array_map(static fn ($l) => json_decode($l, true), $out), static fn ($r) => $r['fonte'] === 'activity_log'));
t_eq([0, 3], [$code, count($rows)], 'páginas de 2: as 3 linhas do usuário 42');
t_eq(count(array_unique(array_column($rows, 'id'))), count($rows), 'sem linha repetida entre páginas');
$ids = array_column($rows, 'id');
$sorted = $ids;
rsort($sorted);
t_eq($sorted, $ids, 'mais novas primeiro (id desc)');
$pages = array_values(array_filter(t_log_rows($m), static fn ($r) => $r['code'] === 47));
t_eq([[2, false], [2, true]], array_map(static fn ($r) => [$r['params']['limit'] ?? null, $r['params']['cursor'] ?? null], $pages), 'uma leitura auditada por página: limite e cursor registrados');
[$code, $out] = $run('sal-errado', '42 --root=' . escapeshellarg($root));
t_eq(0, count($out), 'com sal diferente: nada encontrado');

exec('rm -rf ' . escapeshellarg($root));
t_done();
