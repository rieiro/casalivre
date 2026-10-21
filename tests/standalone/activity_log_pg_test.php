<?php
// Log de atividades no PostgreSQL (#137, spec BD "activity log on PostgreSQL" §11 + respostas Q1-Q8):
// mapa de classes, actor_ref sem truncar, roteamento, privilégios (como casalivre_dev NOSUPERUSER + SET ROLE),
// CHECKs, partições/DEFAULT/fuso, retenção por DROP, leitura auditada (Q4), redação (Q7/Q8) e UUIDv7.
declare(strict_types=1);
require __DIR__ . '/lib.php';
t_temp_db();
require_once CL_ROOT . '/src/helpers.php';
require_once CL_ROOT . '/src/ActivityLog.php';
require_once CL_ROOT . '/src/LogPrivacy.php';
require_once CL_ROOT . '/src/Uuid.php';
require_once CL_ROOT . '/src/ExceptionShield.php';
putenv('LOG_SALT=sal-activity-pg-test');

/** SQLSTATE de $fn (null = sem erro). */
function t_state(callable $fn): ?string
{
    try {
        $fn();
        return null;
    } catch (PDOException $e) {
        return (string) ($e->errorInfo[0] ?? $e->getCode());
    }
}
$as = static fn (string $role): PDO => t_pg_as($role);
$owner = $as('casalivre_retention');
$uuid = static fn (): string => Uuid::v7();

echo "mapa de classes (SVU §1.5 + Q1)\n";
t_eq([], array_values(array_diff(array_keys(ActivityLog::CATALOG), array_keys(ActivityLog::CLASSES))), 'todo código do CATALOG tem classe em CLASSES');
t_eq([], array_values(array_diff(array_keys(ActivityLog::CLASSES), array_keys(ActivityLog::CATALOG))), 'CLASSES sem código fora do CATALOG');
t_eq([], array_values(array_diff(array_unique(array_values(ActivityLog::CLASSES)), ['admin', 'security', 'other'])), 'classes só admin/security/other');
t_eq(['security', 'security', 'security'], [ActivityLog::classOf(ActivityLog::LOGIN_OK), ActivityLog::classOf(ActivityLog::LOGIN_FAIL), ActivityLog::classOf(ActivityLog::LOGOUT)], 'Q1: LOGIN_OK/LOGIN_FAIL/LOGOUT em security');
t_eq(['other', 'security', 'admin'], [ActivityLog::classOf(ActivityLog::SEARCH), ActivityLog::classOf(ActivityLog::INTERNAL_ERROR), ActivityLog::classOf(ActivityLog::ACTIVITY_LOG_READ)], 'SEARCH other, INTERNAL_ERROR security, ACTIVITY_LOG_READ admin');
$db = db()->query('SELECT code, retention_class FROM activity_actions ORDER BY code')->fetchAll(PDO::FETCH_KEY_PAIR);
ksort($db);
$map = ActivityLog::CLASSES;
ksort($map);
t_eq($map, array_map('strval', $db), 'activity_actions (seed) = CLASSES');

echo "LogPrivacy sem truncar (D1)\n";
$ref = LogPrivacy::userRef(42);
t_eq(32, strlen((string) $ref), 'userRef: 32 bytes');
t_ok(hash_equals(hash_hmac('sha256', "user\0" . '42', 'sal-activity-pg-test', true), (string) $ref), 'userRef = HMAC-SHA256(LOG_SALT, "user\\0" || id) completo (hash_equals)');
t_ok(preg_match('/^[0-9a-f]{64}$/', (string) LogPrivacy::user(42)) === 1 && LogPrivacy::user(42) === bin2hex((string) $ref), 'user(): 64 hex = bin2hex(userRef)');
t_eq(1, LogPrivacy::keyVersion(), 'LOG_SALT_KEY_VERSION padrão 1');

echo "UUIDv7\n";
$ids = [];
for ($i = 0; $i < 2000; $i++) {
    $ids[] = Uuid::v7();
}
$sorted = $ids;
sort($sorted);
t_ok($ids === $sorted && count(array_unique($ids)) === 2000, 'Uuid::v7: 2000 únicos e em ordem');
t_ok(preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $ids[0]) === 1, 'Uuid::v7: versão 7, variante RFC 9562');

echo "roteamento (CLI grava na hora)\n";
$mark = t_log_mark();
$aev = ActivityLog::beginRequest();
ActivityLog::record(ActivityLog::LOGIN_FAIL, ['email' => 'a@b.com']);
ActivityLog::record(ActivityLog::SEARCH, ['localidade' => 'Floripa']);
ActivityLog::record(ActivityLog::THEME_CHANGE, ['tema' => 'contraste', 'tema_anterior' => 'padrao']);
$rows = t_log_rows($mark);
t_eq([[2, 'security'], [5, 'other'], [32, 'admin']], array_map(static fn ($r) => [$r['code'], $r['class']], $rows), 'security e other no activity_log, admin no audit_log');
t_eq([$aev, $aev], [$rows[0]['access_event_id'], $rows[1]['access_event_id']], 'access_event_id = o UUIDv7 da requisição');
t_eq('system', $rows[0]['actor_kind'], 'CLI: actor_kind system');

echo "Q3: o INSERT de shutdown nunca leva security\n";
$row = ActivityLog::buildRow(ActivityLog::LOGIN_OK, []);
$thrown = null;
try {
    ActivityLog::deferOther($row);
} catch (LogicException $e) {
    $thrown = $e::class;
}
t_eq('LogicException', $thrown, 'deferOther recusa código security');
$thrown = null;
try {
    ActivityLog::deferOther(ActivityLog::buildRow(ActivityLog::THEME_CHANGE, []));
} catch (LogicException $e) {
    $thrown = $e::class;
}
t_eq('LogicException', $thrown, 'deferOther recusa código admin');
ActivityLog::$deferOther = true;
$m2 = t_log_mark();
ActivityLog::deferOther(ActivityLog::buildRow(ActivityLog::SEARCH, ['localidade' => 'x']));
t_eq(1, count(ActivityLog::pendingOther()), 'other fica no buffer até o shutdown');
t_eq([], t_log_rows($m2), 'nada gravado antes do flush');
ActivityLog::flushOther();
t_eq([ActivityLog::SEARCH], array_column(t_log_rows($m2), 'code'), 'flushOther grava o other num INSERT');
ActivityLog::$deferOther = null;
$thrown = null;
try {
    ActivityLog::insertActivity(db(), [ActivityLog::buildRow(ActivityLog::THEME_CHANGE, [])]);
} catch (LogicException $e) {
    $thrown = $e::class;
}
t_eq('LogicException', $thrown, 'insertActivity recusa linha admin');

echo "tamanho (4096) e truncamento no PHP\n";
$big = ActivityLog::buildRow(ActivityLog::GROUP_UPDATE, ['grupo' => 'g'], null, array_fill(0, 40, str_repeat('x', 200)));
t_eq(['{"truncated": true}', null, null], [$big['payload'], $big['before_value'], $big['after_value']], 'linha > 4096 bytes: payload {"truncated": true}, sem before/after');

echo "privilégios (42501), como casalivre_dev NOSUPERUSER + SET ROLE\n";
t_eq(false, (bool) db()->query('SELECT rolsuper FROM pg_roles WHERE rolname = current_user')->fetchColumn(), 'login de teste não é superusuário');
$leaf = 'audit.activity_log_other_p' . gmdate('Ymd');
$app = $as('casalivre_app');
foreach (['audit.activity_log', 'audit.audit_log', $leaf, 'audit.activity_log_held'] as $t) {
    foreach (["SELECT 1 FROM $t LIMIT 1", "UPDATE $t SET code = code", "DELETE FROM $t", "TRUNCATE $t"] as $sql) {
        t_eq('42501', t_state(static fn () => $app->exec($sql)), "casalivre_app: " . strtok($sql, ' ') . " em $t negado");
    }
}
t_eq(null, t_state(static fn () => $app->exec("INSERT INTO audit.activity_log (id, created_at, code, retention_class, actor_kind) VALUES ('" . $uuid() . "', now(), 5, 'other', 'anon')")), 'casalivre_app: INSERT no activity_log permitido');
t_eq(null, t_state(static fn () => $app->exec("INSERT INTO audit.audit_log (id, created_at, code, retention_class, actor_kind) VALUES ('" . $uuid() . "', now(), 32, 'admin', 'system')")), 'casalivre_app: INSERT no audit_log permitido');
$logw = $as('cl_logw');
t_eq('42501', t_state(static fn () => $logw->exec("INSERT INTO audit.audit_log (id, created_at, code, retention_class, actor_kind) VALUES ('" . $uuid() . "', now(), 32, 'admin', 'system')")), 'cl_logw: INSERT no audit_log negado');
t_eq(null, t_state(static fn () => $logw->exec("INSERT INTO audit.activity_log (id, created_at, code, retention_class, actor_kind) VALUES ('" . $uuid() . "', now(), 5, 'other', 'anon')")), 'cl_logw: INSERT no activity_log permitido');
t_eq('42501', t_state(static fn () => $logw->query('SELECT 1 FROM audit.activity_log LIMIT 1')), 'cl_logw: SELECT negado');
$ops = $as('casalivre_ops_logread');
t_eq('42501', t_state(static fn () => $ops->query('SELECT 1 FROM audit.activity_log LIMIT 1')), 'casalivre_ops_logread: SELECT direto negado');
t_eq('42501', t_state(static fn () => $ops->query('SELECT audit.activity_partitions_ensure(7)')), 'casalivre_ops_logread: sem EXECUTE na retenção');
$job = $as('cl_retention_job');
t_eq('42501', t_state(static fn () => $job->exec("DROP TABLE $leaf")), 'cl_retention_job: DROP TABLE de folha negado');
t_eq('42501', t_state(static fn () => $job->query('SELECT * FROM audit.activity_recent(NULL, 1)')), 'cl_retention_job: sem EXECUTE na leitura');
t_eq(null, t_state(static fn () => $job->query('SELECT audit.activity_partitions_ensure(7)')), 'cl_retention_job: EXECUTE em activity_partitions_ensure');
$pub = (int) $owner->query("SELECT count(*) FROM pg_proc p WHERE p.pronamespace = 'audit'::regnamespace AND has_function_privilege('public', p.oid, 'EXECUTE')")->fetchColumn();
t_eq(0, $pub, 'nenhuma função do schema audit executável por PUBLIC');
t_eq(false, (bool) $owner->query("SELECT pg_has_role('cl_logw', 'casalivre_retention', 'MEMBER') OR pg_has_role('cl_retention_job', 'casalivre_retention', 'MEMBER')")->fetchColumn(), 'logins não são membros de casalivre_retention');

echo "CHECKs e FK\n";
$ins = static function (array $over) use ($app, $uuid): ?string {
    $r = array_merge(['code' => 5, 'class' => 'other', 'kind' => 'anon', 'ref' => null, 'kv' => null, 'aev' => null, 'payload' => '{}'], $over);
    return t_state(static function () use ($app, $r, $uuid): void {
        $st = $app->prepare('INSERT INTO audit.activity_log (id, created_at, code, retention_class, actor_kind, actor_ref, actor_key_version, access_event_id, payload) VALUES (?::uuid, now(), ?, ?, ?, ?, ?, ?::uuid, ?::jsonb)');
        $st->bindValue(1, $uuid());
        $st->bindValue(2, $r['code'], PDO::PARAM_INT);
        $st->bindValue(3, $r['class']);
        $st->bindValue(4, $r['kind']);
        $st->bindValue(5, $r['ref'], $r['ref'] === null ? PDO::PARAM_NULL : PDO::PARAM_LOB);
        $st->bindValue(6, $r['kv'], $r['kv'] === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $st->bindValue(7, $r['aev'], $r['aev'] === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $st->bindValue(8, $r['payload']);
        $st->execute();
    });
};
$r32 = (string) LogPrivacy::userRef(7);
t_eq(null, $ins(['kind' => 'user', 'ref' => $r32, 'kv' => 1]), 'linha válida (user + ref 32 bytes + versão)');
t_eq('23514', $ins(['kind' => 'user']), 'user sem actor_ref');
t_eq('23514', $ins(['ref' => $r32, 'kv' => 1]), 'anon com actor_ref');
t_eq('23514', $ins(['kind' => 'user', 'ref' => substr($r32, 0, 16), 'kv' => 1]), 'actor_ref de 16 bytes (truncamento antigo)');
t_eq('23514', $ins(['kind' => 'user', 'ref' => $r32]), 'ref sem key version');
t_eq('23514', $ins(['code' => 2, 'class' => 'security']), 'security + anon sem access_event_id (V-4)');
t_eq(null, $ins(['code' => 2, 'class' => 'security', 'aev' => $uuid()]), 'security + anon com access_event_id');
$p4097 = json_encode(['x' => str_repeat('a', 4088)]);
t_eq(4097, (int) $app->query("SELECT octet_length('$p4097'::jsonb::text)")->fetchColumn(), 'payload do caso seguinte: 4097 bytes como jsonb::text');
t_eq('23514', $ins(['payload' => $p4097]), 'payload de 4097 bytes');
t_eq(null, $ins(['payload' => json_encode(['x' => str_repeat('a', 4087)])]), 'payload de 4096 bytes passa');
t_eq('23514', $ins(['payload' => '[1]']), 'payload que não é objeto');
t_eq('23503', $ins(['code' => 32]), 'código admin no activity_log (FK 23503)');

echo "partições: DEFAULT, ensure e fuso\n";
$future = gmdate('Y-m-d', time() + 8 * 86400) . ' 12:00+00';
$app->exec("INSERT INTO audit.activity_log (id, created_at, code, retention_class, actor_kind) VALUES ('" . $uuid() . "', '$future', 5, 'other', 'anon')");
t_eq('audit.activity_log_other_default', (string) $owner->query("SELECT tableoid::regclass::text FROM audit.activity_log WHERE created_at = '$future'")->fetchColumn(), 'linha de hoje+8 cai na DEFAULT');
$jobLike = $as('casalivre_log_job');
t_eq('P0001', t_state(static fn () => $jobLike->query("SELECT * FROM audit.activity_retention_step('{}'::bytea[], true)")), 'DEFAULT com linha: retention_step recusa (alerta)');
$owner->exec("DELETE FROM audit.activity_log_other_default");
t_eq(2, (int) $jobLike->query('SELECT audit.activity_partitions_ensure(8)')->fetchColumn(), 'ensure(8) cria as duas folhas de hoje+8');
t_eq(0, (int) $jobLike->query('SELECT audit.activity_partitions_ensure(8)')->fetchColumn(), 'ensure idempotente');
$sp = t_pg_as('casalivre_app');
$sp->exec("SET TimeZone = 'America/Sao_Paulo'");
$localDay = (string) $sp->query("SELECT to_char(now() AT TIME ZONE 'America/Sao_Paulo', 'YYYY-MM-DD')")->fetchColumn();
$sp->exec("INSERT INTO audit.activity_log (id, created_at, code, retention_class, actor_kind) VALUES ('" . $uuid() . "', '$localDay 23:30-03', 5, 'other', 'anon')");
t_eq('audit.activity_log_other_p' . gmdate('Ymd', strtotime("$localDay +1 day")), (string) $owner->query("SELECT tableoid::regclass::text FROM audit.activity_log WHERE created_at = '$localDay 23:30-03'")->fetchColumn(), 'sessão -03: 23:30 local cai na folha do dia UTC seguinte');

echo "retenção (DROP, holds, dry-run, lock, purge_runs)\n";
$mk = static function (string $class, int $daysAgo) use ($owner): string {
    $d = gmdate('Y-m-d', time() - $daysAgo * 86400);
    $name = "activity_log_{$class}_p" . str_replace('-', '', $d);
    $owner->exec("CREATE TABLE IF NOT EXISTS audit.$name PARTITION OF audit.activity_log_$class FOR VALUES FROM ('$d 00:00+00') TO ('" . gmdate('Y-m-d', strtotime("$d +1 day")) . " 00:00+00')");
    return $name;
};
$sec91 = $mk('security', 91);
$sec89 = $mk('security', 89);
$oth15 = $mk('other', 15);
$oth13 = $mk('other', 13);
$heldRef = (string) LogPrivacy::userRef(99);
$put = static function (string $class, int $daysAgo, ?string $ref) use ($app, $uuid): void {
    $st = $app->prepare("INSERT INTO audit.activity_log (id, created_at, code, retention_class, actor_kind, actor_ref, actor_key_version, access_event_id) VALUES (?::uuid, now() - make_interval(days => ?) - interval '1 hour', ?, ?, ?, ?, ?, ?::uuid)");
    $st->bindValue(1, $uuid());
    $st->bindValue(2, $daysAgo, PDO::PARAM_INT);
    $st->bindValue(3, $class === 'security' ? 2 : 5, PDO::PARAM_INT);
    $st->bindValue(4, $class);
    $st->bindValue(5, $ref === null ? 'anon' : 'user');
    $st->bindValue(6, $ref, $ref === null ? PDO::PARAM_NULL : PDO::PARAM_LOB);
    $st->bindValue(7, $ref === null ? null : 1, $ref === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
    $st->bindValue(8, $uuid());
    $st->execute();
};
foreach ([['security', 91], ['security', 89], ['other', 15], ['other', 13]] as [$c, $d]) {
    $put($c, $d, null);
}
$put('security', 91, $heldRef);
$exists = static fn (string $name): bool => (bool) $owner->query("SELECT to_regclass('audit.$name') IS NOT NULL")->fetchColumn();
$step = static function (array $refs, bool $dry) use ($jobLike): array {
    $lit = Db::byteaArrayLiteral($refs);
    $st = $jobLike->prepare('SELECT leaf, held, dropped FROM audit.activity_retention_step(?::bytea[], ?)');
    $st->bindValue(1, $lit);
    $st->bindValue(2, $dry, PDO::PARAM_BOOL);
    $st->execute();
    return $st->fetchAll(PDO::FETCH_ASSOC);
};
$runs0 = (int) db()->query('SELECT count(*) FROM purge_runs')->fetchColumn();
$total0 = (int) $owner->query('SELECT count(*) FROM audit.activity_log')->fetchColumn();
$dry = $step([$heldRef], true);
t_eq([$sec91], array_column($dry, 'leaf'), 'dry-run: a folha vencida mais antiga (security 91 d)');
t_ok($exists($sec91) && $total0 === (int) $owner->query('SELECT count(*) FROM audit.activity_log')->fetchColumn(), 'dry-run não muda nada');
t_eq($runs0, (int) db()->query('SELECT count(*) FROM purge_runs')->fetchColumn(), 'dry-run não grava purge_runs');
$holdUser = t_seed_owner()['user_id'];
db()->exec("INSERT INTO legal_holds (hold_kind, subject_type, subject_id, case_ref) VALUES ('judicial', 'user', $holdUser, 'proc-xyz')");
t_eq('P0001', t_state(static fn () => $step([], false)), 'hold aberto e refs vazias: recusa');
t_eq('P0001', t_state(static fn () => $step([substr($heldRef, 0, 16)], false)), 'ref com tamanho errado: recusa');
$locker = t_pg_as('casalivre_log_job');
$locker->beginTransaction();
$locker->query("SELECT pg_advisory_xact_lock(hashtext('activity_retention'))");
t_eq('P0001', t_state(static fn () => $step([$heldRef], false)), 'outra sessão com o lock: recusa (advisory lock)');
$locker->rollBack();
$dropped = [];
while (($r = $step([$heldRef], false)) !== []) {
    $dropped[] = $r[0]['leaf'];
    if (count($dropped) > 500) {
        break;
    }
}
t_ok(in_array($sec91, $dropped, true) && in_array($oth15, $dropped, true), 'security 91 d e other 15 d removidas');
t_ok($exists($sec89) && $exists($oth13), 'security 89 d e other 13 d ficam');
t_eq(1, (int) $owner->query('SELECT count(*) FROM audit.activity_log_held')->fetchColumn(), 'a linha retida foi copiada uma vez para _held');
t_eq([], $step([$heldRef], false), 'nada mais a remover: nenhuma linha (rerun não copia nada)');
t_eq(1, (int) $owner->query('SELECT count(*) FROM audit.activity_log_held')->fetchColumn(), 'rerun: _held igual');
t_eq(count($dropped), (int) db()->query('SELECT count(*) FROM purge_runs')->fetchColumn() - $runs0, 'purge_runs: uma linha por passo');
t_eq(1, (int) db()->query("SELECT sum(skipped_hold) FROM purge_runs WHERE job = 'activity_retention'")->fetchColumn(), 'purge_runs: skipped_hold = linhas retidas');

echo "Q4: leitura auditada (a linha ACTIVITY_LOG_READ é gravada dentro da função DEFINER, na mesma transação)\n";
$n47 = static fn (): int => (int) $owner->query('SELECT count(*) FROM audit.audit_log WHERE code = 47')->fetchColumn();
$refLit = Db::byteaArrayLiteral([$heldRef]);
$readers = [
    'activity_recent' => static fn (): array => $ops->query('SELECT * FROM audit.activity_recent(NULL, 5)')->fetchAll(),
    'activity_by_actor' => static function () use ($ops, $refLit): array {
        $st = $ops->prepare('SELECT * FROM audit.activity_by_actor(?::bytea[])');
        $st->execute([$refLit]);
        return $st->fetchAll();
    },
    'audit_recent' => static fn (): array => $ops->query('SELECT * FROM audit.audit_recent(NULL, 5)')->fetchAll(),
];
foreach ($readers as $fn => $read) {
    $mark = t_log_mark();
    $before = $n47();
    $rows = $read();
    t_ok(count($rows) >= 1, "$fn: devolve linhas");
    t_eq($before + 1, $n47(), "$fn: exatamente uma linha ACTIVITY_LOG_READ no audit_log por chamada");
    $reads = array_values(array_filter(t_log_rows($mark), static fn ($r) => $r['code'] === ActivityLog::ACTIVITY_LOG_READ));
    t_eq([1, 'admin', 'system', $fn], [count($reads), $reads[0]['class'] ?? null, $reads[0]['actor_kind'] ?? null, $reads[0]['params']['function'] ?? null], "$fn: linha admin/system com o nome da função");
    t_eq((string) db()->query('SELECT session_user')->fetchColumn(), $reads[0]['params']['session_user'] ?? null, "$fn: session_user do leitor");
    // Mesma transação: antes do COMMIT outra sessão não vê a linha; ROLLBACK leva a linha junto com a leitura.
    $ops->beginTransaction();
    $read();
    t_eq($before + 1, $n47(), "$fn: dentro da transação aberta, outra sessão ainda não vê a linha nova");
    $ops->rollBack();
    t_eq($before + 1, $n47(), "$fn: ROLLBACK da leitura desfaz a linha de auditoria (nada gravado fora da transação)");
    $ops->beginTransaction();
    $read();
    $ops->commit();
    t_eq($before + 2, $n47(), "$fn: COMMIT grava a linha");
}
$m4 = t_log_mark();
$ops->query('SELECT count(*) FROM audit.activity_recent(2::smallint, 100000)')->fetchColumn();
$capped = array_values(array_filter(t_log_rows($m4), static fn ($r) => $r['code'] === ActivityLog::ACTIVITY_LOG_READ));
t_eq([500, 2], [$capped[0]['params']['limit'] ?? null, $capped[0]['params']['code'] ?? null], 'limite pedido 100000 vira 500 (registrado com o código)');
$st = $ops->prepare('SELECT count(*) FROM audit.activity_by_actor(?::bytea[])');
$st->execute([$refLit]);
t_eq(1, (int) $st->fetchColumn(), 'activity_by_actor: acha a linha retida no _held (a folha original já foi removida)');
$byActor = array_values(array_filter(t_log_rows($m4), static fn ($r) => ($r['params']['function'] ?? '') === 'activity_by_actor'));
t_eq(1, $byActor[0]['params']['refs'] ?? null, 'activity_by_actor registra o nº de refs');
t_ok(!str_contains(json_encode(t_log_rows()), bin2hex($heldRef)), 'a ref nunca vai para o audit_log');
$own = $ops->query('SELECT code, payload::text AS payload FROM audit.audit_recent(47::smallint, 1)')->fetch();
t_eq([47, 'audit_recent'], [(int) $own['code'], json_decode((string) $own['payload'], true)['function'] ?? null], 'audit_recent: a linha da própria leitura vem no resultado (mesma transação)');
$m5 = t_log_mark();
$ops->query('SELECT count(*) FROM audit.audit_recent(NULL, 100000)')->fetchColumn();
$cap5 = array_values(array_filter(t_log_rows($m5), static fn ($r) => $r['code'] === ActivityLog::ACTIVITY_LOG_READ));
t_eq([1, 500], [count($cap5), $cap5[0]['params']['limit'] ?? null], 'audit_recent: limite 100000 vira 500');
t_eq('42501', t_state(static fn () => $app->query('SELECT * FROM audit.audit_recent(NULL, 1)')), 'casalivre_app: sem EXECUTE em audit_recent');
t_eq('42501', t_state(static fn () => $job->query('SELECT * FROM audit.audit_recent(NULL, 1)')), 'cl_retention_job: sem EXECUTE em audit_recent');
t_eq('42501', t_state(static fn () => $ops->query('SELECT 1 FROM audit.audit_log LIMIT 1')), 'casalivre_ops_logread: SELECT direto no audit_log negado');

echo "Q4: INSERT da auditoria falha -> a leitura falha e não devolve linhas\n";
// Gatilho só de teste na folha anual do audit_log: recusa o INSERT do código 47 (as leituras gravam lá).
$yearLeaf = 'audit.audit_log_admin_y' . gmdate('Y');
$owner->exec("CREATE FUNCTION audit.t_fail_47() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN RAISE EXCEPTION 'teste: audit_log recusa 47' USING ERRCODE = 'P0099'; END \$\$");
$owner->exec("CREATE TRIGGER t_fail_47 BEFORE INSERT ON $yearLeaf FOR EACH ROW WHEN (NEW.code = 47) EXECUTE FUNCTION audit.t_fail_47()");
foreach ($readers as $fn => $read) {
    $before = $n47();
    $got = null;
    $state = null;
    try {
        $got = $read();
    } catch (PDOException $e) {
        $state = (string) ($e->errorInfo[0] ?? $e->getCode());
    }
    t_eq(['P0099', null], [$state, $got], "$fn: a leitura levanta o erro do INSERT e não devolve linhas");
    t_eq($before, $n47(), "$fn: nenhuma linha de auditoria (nem parcial)");
}
$owner->exec("DROP TRIGGER t_fail_47 ON $yearLeaf");
$owner->exec('DROP FUNCTION audit.t_fail_47()');
// A outra via do teste anterior: CHECK na folha (23514) também derruba a leitura.
$owner->exec("ALTER TABLE $yearLeaf ADD CONSTRAINT t_no_read CHECK (code <> 47) NOT VALID");
t_eq('23514', t_state(static fn () => $ops->query('SELECT * FROM audit.activity_recent(NULL, 5)')->fetchAll()), 'CHECK na folha: activity_recent falha');
$owner->exec("ALTER TABLE $yearLeaf DROP CONSTRAINT t_no_read");
t_ok(count($readers['activity_recent']()) >= 1, 'sem o gatilho/CHECK a leitura volta a funcionar');

echo "Q7: INTERNAL_ERROR redigido\n";
try {
    throw new RuntimeException('falha joao@x.com cpf 529.982.247-25 tel (48) 99999-0000 jwt eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxIn0.abc dsn pgsql:host=db;password=s3nha password=abc pedido 12345678 ' . str_repeat('z', 300));
} catch (RuntimeException $e) {
    $msg = ExceptionShield::message($e);
    $frames = ExceptionShield::frames($e);
}
foreach (['joao@x.com', '529.982.247-25', '99999-0000', 'eyJhbGci', 'pgsql:host', 's3nha', 'abc pedido', '12345678'] as $leak) {
    t_ok(!str_contains($msg, $leak), "mensagem sem '$leak'");
}
t_ok(str_starts_with($msg, 'RuntimeException: ') && mb_strlen($msg) <= 200, 'mensagem: classe + texto, até 200 caracteres');
t_ok($frames !== [] && count($frames) <= 10, 'rastreio: 1..10 frames');
t_ok(array_filter($frames, static fn ($f) => preg_match('#^[A-Za-z0-9_./-]+\.php:\d+$#', $f) !== 1 || str_starts_with($f, '/')) === [], 'rastreio: só arquivo:linha relativos ao repositório, sem args');
$row = ActivityLog::buildRow(ActivityLog::INTERNAL_ERROR, ['mensagem' => $msg, 'rastreio' => $frames, 'arquivo' => 'src/x.php']);
t_ok(str_contains($row['payload'], 'rastreio') && !str_contains($row['payload'], 'joao@'), 'INTERNAL_ERROR: payload com mensagem redigida e rastreio');

echo "Q8: SEARCH redigido, place_id\n";
t_eq(['localidade' => 'florianopolis sc'], ActivityLog::safeParams(['localidade' => '  Florianópolis   SC '], ActivityLog::SEARCH), 'termo: minúsculas, sem acento, espaços colapsados');
foreach (['joao@x.com', 'https://x.test/a', 'www.exemplo.com', '48 99999-0000', '529.982.247-25', 'rua 123456'] as $bad) {
    t_eq([], ActivityLog::safeParams(['localidade' => $bad], ActivityLog::SEARCH), "termo descartado: '$bad'");
}
t_eq(64, mb_strlen((string) (ActivityLog::safeParams(['localidade' => str_repeat('ab ', 40)], ActivityLog::SEARCH)['localidade'] ?? '')), 'termo limitado a 64 caracteres');
t_eq(['place_id' => 12], ActivityLog::safeParams(['place_id' => 12], ActivityLog::SEARCH), 'place_id permitido no SEARCH');

echo "HTTP: access_event_id do servidor, X-Request-Id ignorado (Q2)\n";
$base = t_server();
$m3 = t_log_mark();
$r = t_http('GET', $base . '/api/busca?localidade=' . urlencode('Florianópolis, SC'), null, ['X-Request-Id: cliente-forjado-123']);
t_eq(200, $r['status'], 'GET /api/busca: 200');
// (a linha de 23:30 local de hoje, do teste de fuso, tem created_at no futuro e não tem access_event_id)
$search = array_values(array_filter(t_log_rows($m3), static fn ($x) => $x['code'] === ActivityLog::SEARCH && $x['access_event_id'] !== null));
t_eq(1, count($search), 'um SEARCH gravado (other, no shutdown)');
$aev = (string) ($search[0]['access_event_id'] ?? '');
t_ok(preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-7/', $aev) === 1, 'access_event_id é um UUIDv7 do servidor');
$apiLines = [];
foreach (glob(CL_ROOT . '/storage/logs/api/api-*') ?: [] as $f) {
    foreach (file($f, FILE_IGNORE_NEW_LINES) ?: [] as $l) {
        if (str_contains($l, $aev) || str_contains($l, 'cliente-forjado-123')) {
            $apiLines[] = json_decode($l, true);
        }
    }
}
t_eq([$aev], array_map(static fn ($x) => $x['request_id'] ?? null, $apiLines), 'log da API: request_id = access_event_id; o X-Request-Id do cliente não aparece');
t_done();
