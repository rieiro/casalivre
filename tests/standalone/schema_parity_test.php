<?php
// Paridade schema.sql (SQLite) x schema.pgsql.sql (PG), v19:
// - SQLite (sempre): banco do zero; migrate() depois não muda o schema (zero DDL); FK properties.user_id RESTRICT;
//   sem idx_ceps_cep/idx_photos_property (inclusive após CepRepository); EXPLAIN usa idx_photos_cover e o UNIQUE de ceps;
//   tokens parciais; as 7 tabelas que vinham do migrate() estão no schema.
// - PG (só com PARITY_PG_DSN, ex.: pgsql:host=127.0.0.1;dbname=postgres): cria o banco temporário
//   casalivre_parity_<pid>, carrega schema.pgsql.sql, compara com o SQLite e apaga SÓ esse banco.
// Exceções de tipo documentadas no topo de database/schema.pgsql.sql.
declare(strict_types=1);
require __DIR__ . '/lib.php';
require_once CL_ROOT . '/src/Db.php';
require_once CL_ROOT . '/src/JsonCache.php';
require_once CL_ROOT . '/src/CepRepository.php';

const TYPE_MAP = [
    'integer' => 'INTEGER', 'bigint' => 'INTEGER', 'text' => 'TEXT',
    'double precision' => 'REAL', 'timestamp without time zone' => 'TEXT', 'date' => 'TEXT',
];
const DEFAULT_MAP = ['CURRENT_TIMESTAMP' => "datetime('now')", 'now()' => "datetime('now')"];
const IDX_EXCEPTIONS = []; // nenhuma diferença de índice aceita hoje (índices da busca voltam no PR do ADR 001)
const SEVEN = ['settings', 'languages', 'translations', 'cities', 'activity_actions', 'login_rate_limit', 'ceps'];

/** Ordena recursivamente as chaves (comparação independente da ordem do catálogo). */
function ksortDeep(array $a): array
{
    ksort($a);
    foreach ($a as $k => $v) {
        if (is_array($v) && !array_is_list($v)) {
            $a[$k] = ksortDeep($v);
        }
    }
    return $a;
}

/** Lista as diferenças entre dois snapshots (caminho: antes => depois), para a mensagem do teste. */
function snapDiff(array $x, array $y, string $path = ''): array
{
    $out = [];
    foreach (array_unique([...array_keys($x), ...array_keys($y)]) as $k) {
        $p = $path . '/' . $k;
        $a = $x[$k] ?? null;
        $b = $y[$k] ?? null;
        if (is_array($a) && is_array($b) && !array_is_list($a)) {
            $out = [...$out, ...snapDiff($a, $b, $p)];
        } elseif ($a !== $b) {
            $out[] = $p . ': ' . json_encode($a) . ' => ' . json_encode($b);
        }
    }
    return $out;
}

function normDefault(?string $d): ?string
{
    if ($d === null) {
        return null;
    }
    while (strlen($d) >= 2 && $d[0] === '(' && $d[-1] === ')') {
        $d = substr($d, 1, -1);
    }
    $d = (string) preg_replace('/::[a-z ]+$/', '', $d);
    if (str_contains($d, 'nextval')) {
        return null;
    }
    return DEFAULT_MAP[$d] ?? $d;
}

function sqliteSnapshot(PDO $db): array
{
    $s = ['col' => [], 'fk' => [], 'idx' => []];
    foreach ($db->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN) as $t) {
        foreach ($db->query("PRAGMA table_xinfo('$t')") as $c) {
            $s['col'][$t][$c['name']] = [strtoupper((string) $c['type']), (bool) $c['notnull'] || $c['pk'] > 0, $c['pk'] > 0 ? null : normDefault($c['dflt_value'])];
        }
        foreach ($db->query("PRAGMA foreign_key_list('$t')") as $f) {
            $s['fk'][$t][$f['from']] = [$f['table'], $f['to'], strtoupper((string) $f['on_delete'])];
        }
        foreach ($db->query("PRAGMA index_list('$t')") as $i) {
            if ($i['origin'] !== 'c') {
                continue;
            }
            $cols = array_column($db->query("PRAGMA index_info('{$i['name']}')")->fetchAll(PDO::FETCH_ASSOC), 'name');
            $s['idx'][$i['name']] = [$t, $cols, (bool) $i['unique'], (bool) $i['partial']];
        }
    }
    ksort($s['idx']);
    return $s;
}

function pgSnapshot(PDO $db): array
{
    $s = ['col' => [], 'fk' => [], 'idx' => []];
    foreach ($db->query("SELECT table_name, column_name, data_type, is_nullable, column_default, is_identity FROM information_schema.columns WHERE table_schema = 'public' ORDER BY table_name, ordinal_position") as $c) {
        $type = TYPE_MAP[$c['data_type']] ?? strtoupper($c['data_type']);
        $s['col'][$c['table_name']][$c['column_name']] = [$type, $c['is_nullable'] === 'NO', $c['is_identity'] === 'YES' ? null : normDefault($c['column_default'])];
    }
    $fks = $db->query("SELECT c.conrelid::regclass::text t, a.attname col, c.confrelid::regclass::text rt, af.attname rcol,
        CASE c.confdeltype WHEN 'c' THEN 'CASCADE' WHEN 'r' THEN 'RESTRICT' WHEN 'n' THEN 'SET NULL' WHEN 'a' THEN 'NO ACTION' WHEN 'd' THEN 'SET DEFAULT' END del
        FROM pg_constraint c JOIN pg_attribute a ON a.attrelid = c.conrelid AND a.attnum = c.conkey[1]
        JOIN pg_attribute af ON af.attrelid = c.confrelid AND af.attnum = c.confkey[1] WHERE c.contype = 'f'");
    foreach ($fks as $f) {
        $s['fk'][$f['t']][$f['col']] = [$f['rt'], $f['rcol'], $f['del']];
    }
    $idx = $db->query("SELECT i.relname n, t.relname t, x.indisunique u, x.indpred IS NOT NULL p,
        array_to_string(ARRAY(SELECT pg_get_indexdef(x.indexrelid, k, true) FROM generate_series(1, x.indnkeyatts) k), ',') cols
        FROM pg_index x JOIN pg_class i ON i.oid = x.indexrelid JOIN pg_class t ON t.oid = x.indrelid
        JOIN pg_namespace ns ON ns.oid = t.relnamespace
        WHERE ns.nspname = 'public' AND NOT x.indisprimary
          AND NOT EXISTS (SELECT 1 FROM pg_constraint c WHERE c.conindid = x.indexrelid)");
    foreach ($idx as $i) {
        $s['idx'][$i['n']] = [$i['t'], explode(',', $i['cols']), (bool) $i['u'], (bool) $i['p']];
    }
    ksort($s['col']);
    ksort($s['idx']);
    return $s;
}

/** INSERT com valores dummy em toda coluna NOT NULL sem default; retorna o id. */
function insertRow(PDO $db, string $table, array $values): int
{
    foreach ($db->query("PRAGMA table_info('$table')") as $c) {
        if ($c['notnull'] && $c['dflt_value'] === null && !$c['pk'] && !array_key_exists($c['name'], $values)) {
            $values[$c['name']] = str_contains(strtoupper((string) $c['type']), 'INT') || str_contains(strtoupper((string) $c['type']), 'REAL') ? 1 : 'x' . bin2hex(random_bytes(4));
        }
    }
    $cols = array_keys($values);
    $db->prepare("INSERT INTO $table (" . implode(',', $cols) . ') VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')')->execute(array_values($values));
    return (int) $db->lastInsertId();
}

$masterSql = static fn (PDO $db): array => $db->query("SELECT type, name, sql FROM sqlite_master WHERE name NOT LIKE 'sqlite_%' ORDER BY type, name")->fetchAll(PDO::FETCH_ASSOC);

echo "SQLite do zero (schema.sql)\n";
$file = t_temp_db();
$lite = new PDO('sqlite:' . $file, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$lite->exec('PRAGMA foreign_keys = ON');
$a = sqliteSnapshot($lite);
$beforeSql = $masterSql($lite);
foreach (SEVEN as $t) {
    t_ok(isset($a['col'][$t]), "tabela $t vem do schema.sql");
}
t_eq(['users', 'id', 'RESTRICT'], $a['fk']['properties']['user_id'] ?? null, 'properties.user_id ON DELETE RESTRICT');
foreach (['photos', 'calendar_days', 'calendar_feeds', 'reviews', 'group_property_invites'] as $child) {
    t_eq('CASCADE', $a['fk'][$child]['property_id'][2] ?? null, "$child.property_id continua CASCADE");
}
t_ok(!isset($a['idx']['idx_photos_property']) && !isset($a['idx']['idx_ceps_cep']), 'sem idx_photos_property e idx_ceps_cep');
t_eq(true, $a['idx']['idx_users_activation_token'][3] ?? null, 'idx_users_activation_token parcial');
t_eq(true, $a['idx']['idx_users_reset_token'][3] ?? null, 'idx_users_reset_token parcial');

echo "zero DDL: migrate() e CepRepository depois do schema não mudam nada\n";
$v = Db::migrateNow(true);
t_eq(Db::SCHEMA_VERSION, $v, 'migrateNow(true) ok');
t_eq(19, Db::SCHEMA_VERSION, 'SCHEMA_VERSION = 19');
CepRepository::ensureTable();
t_eq($beforeSql, $masterSql($lite), 'sqlite_master idêntico (nenhum DDL do migrate()/CepRepository)');
$ceps = array_column($lite->query('PRAGMA index_list(ceps)')->fetchAll(), 'name');
t_eq(['sqlite_autoindex_ceps_1'], $ceps, 'ceps só com o UNIQUE');
t_ok((int) $lite->query('SELECT COUNT(*) FROM cities')->fetchColumn() > 1000 && (int) $lite->query('SELECT COUNT(*) FROM settings')->fetchColumn() > 0, 'seeds (cities, settings) continuam');
t_eq([], $lite->query('PRAGMA foreign_key_check')->fetchAll(), 'PRAGMA foreign_key_check vazio');

echo "EXPLAIN\n";
$plan = static fn (string $sql): string => implode(' | ', array_column($lite->query('EXPLAIN QUERY PLAN ' . $sql)->fetchAll(), 'detail'));
t_ok(str_contains($plan('SELECT * FROM photos WHERE property_id = 1'), 'idx_photos_cover'), 'photos por property_id usa idx_photos_cover');
t_ok(str_contains($plan("SELECT * FROM ceps WHERE cep = '01001000'"), 'sqlite_autoindex_ceps_1'), 'ceps por cep usa o UNIQUE');

echo "RESTRICT e CASCADE (FK ligada)\n";
t_eq(1, (int) $lite->query('PRAGMA foreign_keys')->fetchColumn(), 'PRAGMA foreign_keys = 1');
$uid = insertRow($lite, 'users', ['email' => 'parity-' . bin2hex(random_bytes(3)) . '@example.com']);
$pid = insertRow($lite, 'properties', ['user_id' => $uid]);
insertRow($lite, 'photos', ['property_id' => $pid]);
try {
    $lite->exec("DELETE FROM users WHERE id = $uid");
    t_ok(false, 'DELETE de dono com imóvel deveria falhar');
} catch (PDOException $e) {
    t_eq('23000', (string) $e->getCode(), 'DELETE de dono com imóvel: 23000 (RESTRICT; no PG 23001 ou 23503)');
}
$lite->exec("DELETE FROM properties WHERE id = $pid");
t_eq(0, (int) $lite->query("SELECT COUNT(*) FROM photos WHERE property_id = $pid")->fetchColumn(), 'DELETE do imóvel leva as fotos (CASCADE)');
$lite->exec("DELETE FROM users WHERE id = $uid");
t_eq(0, (int) $lite->query("SELECT COUNT(*) FROM users WHERE id = $uid")->fetchColumn(), 'sem imóvel, o usuário pode ser apagado');

echo "migrate aborta se foreign_key_check falhar\n";
$lite->exec('PRAGMA foreign_keys = OFF');
insertRow($lite, 'properties', ['user_id' => 999999]);
$lite->exec('PRAGMA foreign_keys = ON');
$lite->exec('PRAGMA user_version = 0');
$lite = null;
exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(CL_ROOT . '/scripts/migrate.php') . ' 2>&1', $out, $code);
t_eq(2, $code, 'migrate.php sai com 2');
t_ok(str_contains(implode("\n", $out), 'foreign_key_check'), 'mensagem cita foreign_key_check');
$check = new PDO('sqlite:' . $file);
t_eq(0, (int) $check->query('PRAGMA user_version')->fetchColumn(), 'ROLLBACK: user_version continua 0');
$check = null;

echo "PG\n";
$pgDsn = (string) getenv('PARITY_PG_DSN');
if ($pgDsn === '') {
    echo "  PG: PULADO (defina PARITY_PG_DSN)\n";
    t_done();
}
$user = getenv('PARITY_PG_USER') ?: null;
$pass = getenv('PARITY_PG_PASSWORD') ?: null;
$opts = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC];
$admin = new PDO($pgDsn, $user, $pass, $opts);
$dbName = 'casalivre_parity_' . getmypid();
$admin->exec("CREATE DATABASE $dbName");
try {
    $pgDbDsn = preg_match('/dbname=[^;]+/', $pgDsn) ? (string) preg_replace('/dbname=[^;]+/', "dbname=$dbName", $pgDsn) : $pgDsn . ";dbname=$dbName";
    $pg = new PDO($pgDbDsn, $user, $pass, $opts);
    $pg->exec((string) file_get_contents(CL_ROOT . '/database/schema.pgsql.sql'));
    $b = pgSnapshot($pg);
    t_ok(isset($b['col']['schema_meta']), 'PG: schema_meta vem do schema.pgsql.sql');
    t_eq(false, $pg->query("SELECT value FROM schema_meta WHERE skey = 'schema_version'")->fetchColumn(), 'PG: schema_meta nasce sem schema_version (versão 0)');
    $bTables = $b;
    unset($bTables['col']['schema_meta']); // exceção documentada: só no PG
    t_eq(array_keys($a['col']), array_keys($bTables['col']), 'mesmas tabelas (fora schema_meta, só do PG)');
    foreach ($a['col'] as $t => $cols) {
        foreach ($cols as $c => $def) {
            t_eq($def, $b['col'][$t][$c] ?? null, "coluna $t.$c (tipo/null/default)");
        }
        t_eq([], array_keys(array_diff_key($b['col'][$t] ?? [], $cols)), "PG sem colunas extras em $t");
    }
    $fkDiff = snapDiff(ksortDeep($a['fk']), ksortDeep($b['fk']));
    t_eq([], $fkDiff, 'FKs e ON DELETE (ordem das colunas ignorada)');
    foreach (array_unique([...array_keys($a['idx']), ...array_keys($b['idx'])]) as $n) {
        if (array_key_exists($n, IDX_EXCEPTIONS)) {
            continue;
        }
        t_eq($a['idx'][$n] ?? null, $b['idx'][$n] ?? null, "índice $n (colunas/unique/parcial)");
    }
    t_eq('r', $pg->query("SELECT confdeltype FROM pg_constraint WHERE conrelid = 'properties'::regclass AND contype = 'f' AND conkey = ARRAY[(SELECT attnum FROM pg_attribute WHERE attrelid = 'properties'::regclass AND attname = 'user_id')]::smallint[]")->fetchColumn(), 'PG: confdeltype = r');
    // RESTRICT no PG: aceita 23001 (restrict_violation) e 23503 (foreign_key_violation). No PG 17 local
    // o DELETE simples deu 23503; o Cori relatou 23001. Código que trata FK deve aceitar os dois.
    $pgInsert = static function (PDO $pg, string $table, array $values): int {
        $cols = $pg->prepare("SELECT column_name, data_type FROM information_schema.columns WHERE table_schema = 'public' AND table_name = ? AND is_nullable = 'NO' AND column_default IS NULL AND is_identity = 'NO'");
        $cols->execute([$table]);
        foreach ($cols as $c) {
            if (!array_key_exists($c['column_name'], $values)) {
                $values[$c['column_name']] = match (true) {
                    in_array($c['data_type'], ['integer', 'bigint', 'double precision'], true) => 1,
                    $c['data_type'] === 'date' => '2026-01-01',
                    str_starts_with($c['data_type'], 'timestamp') => '2026-01-01 00:00:00',
                    default => 'x' . bin2hex(random_bytes(4)),
                };
            }
        }
        $names = array_keys($values);
        $st = $pg->prepare("INSERT INTO $table (" . implode(',', $names) . ') VALUES (' . implode(',', array_fill(0, count($names), '?')) . ') RETURNING id');
        $st->execute(array_values($values));
        return (int) $st->fetchColumn();
    };
    $pgUid = $pgInsert($pg, 'users', []);
    $pgPid = $pgInsert($pg, 'properties', ['user_id' => $pgUid]);
    $pgInsert($pg, 'photos', ['property_id' => $pgPid]);
    try {
        $pg->exec("DELETE FROM users WHERE id = $pgUid");
        t_ok(false, 'PG: DELETE de dono com imóvel deveria falhar');
    } catch (PDOException $e) {
        t_ok(in_array((string) $e->getCode(), ['23001', '23503'], true), 'PG: DELETE de dono com imóvel falha com 23001/23503 (obtido ' . $e->getCode() . ')');
    }
    $pg->exec("DELETE FROM properties WHERE id = $pgPid");
    t_eq(0, (int) $pg->query("SELECT COUNT(*) FROM photos WHERE property_id = $pgPid")->fetchColumn(), 'PG: DELETE do imóvel leva as fotos (CASCADE)');
    $pg->exec("DELETE FROM users WHERE id = $pgUid");
    // zero DDL no PG: migrate.php no banco temporário não muda nada.
    $pg = null;
    exec('DB_DRIVER=pgsql DB_DSN=' . escapeshellarg($pgDbDsn) . ' DB_USER=' . escapeshellarg((string) $user) . ' DB_PASSWORD=' . escapeshellarg((string) $pass) . ' ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(CL_ROOT . '/scripts/migrate.php') . ' 2>&1', $out2, $code2);
    t_eq(0, $code2, 'PG: migrate.php sai com 0' . ($code2 ? ' ' . implode(' ', $out2) : ''));
    $pg = new PDO($pgDbDsn, $user, $pass, $opts);
    $after = pgSnapshot($pg);
    t_eq([], snapDiff(ksortDeep($b), ksortDeep($after)), 'PG: migrate() não muda o schema (schema_meta já vem do schema.pgsql.sql)');
    // Política v19 no PG: versão 1..18 aborta (exit 2, 'recrie o banco do zero'); 19 segue ok.
    $pgMigrate = static function () use ($pgDbDsn, $user, $pass): array {
        exec('DB_DRIVER=pgsql DB_DSN=' . escapeshellarg($pgDbDsn) . ' DB_USER=' . escapeshellarg((string) $user) . ' DB_PASSWORD=' . escapeshellarg((string) $pass) . ' ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(CL_ROOT . '/scripts/migrate.php') . ' 2>&1', $o, $c);
        return [$c, implode("\n", $o)];
    };
    $pg->exec("UPDATE schema_meta SET value = '18' WHERE skey = 'schema_version'");
    [$c18, $o18] = $pgMigrate();
    t_eq(2, $c18, 'PG v18: migrate.php sai com 2');
    t_ok(str_contains($o18, 'Recrie o banco do zero'), 'PG v18: mensagem "recrie o banco do zero"');
    t_eq('18', $pg->query("SELECT value FROM schema_meta WHERE skey = 'schema_version'")->fetchColumn(), 'PG v18: versão intacta (não carimba 19)');
    $pg->exec("UPDATE schema_meta SET value = '19' WHERE skey = 'schema_version'");
    [$c19] = $pgMigrate();
    t_eq(0, $c19, 'PG v19: migrate.php sai com 0');
} finally {
    $pg = null;
    $admin->exec("DROP DATABASE IF EXISTS $dbName");
}
t_done();
