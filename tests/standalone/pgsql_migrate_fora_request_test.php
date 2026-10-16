<?php
// pgsql: a requisição web só confere a versão (uma consulta) e nunca migra; a migração fica no
// scripts/migrate.php (pg_advisory_lock). money_unit é passo único (marca em schema_meta).
// Sem Postgres no ambiente de teste: os caminhos são exercitados com PDO SQLite (SQL portátil).
require __DIR__ . '/lib.php';
require_once CL_ROOT . '/src/Db.php';

echo "ensureSchemaCurrent (caminho web do pgsql)\n";
$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$before = (int) $pdo->query("SELECT COUNT(*) FROM sqlite_master")->fetchColumn();
try {
    Db::ensureSchemaCurrent($pdo);
    t_ok(false, 'banco sem schema_meta deveria dar SchemaDesatualizado');
} catch (SchemaDesatualizado $e) {
    t_ok(str_contains($e->getMessage(), 'scripts/migrate.php'), 'sem schema_meta: SchemaDesatualizado com instrução clara');
}
t_eq($before, (int) $pdo->query("SELECT COUNT(*) FROM sqlite_master")->fetchColumn(), 'não cria nem altera tabelas (não migra)');
$pdo->exec('CREATE TABLE schema_meta (skey TEXT PRIMARY KEY, value TEXT NOT NULL)');
$pdo->exec("INSERT INTO schema_meta VALUES ('schema_version', '" . (Db::SCHEMA_VERSION - 1) . "')");
try {
    Db::ensureSchemaCurrent($pdo);
    t_ok(false, 'versão antiga deveria dar SchemaDesatualizado');
} catch (SchemaDesatualizado) {
    t_ok(true, 'versão antiga: SchemaDesatualizado');
}
$pdo->exec("UPDATE schema_meta SET value = '" . Db::SCHEMA_VERSION . "'");
Db::ensureSchemaCurrent($pdo);
t_ok(true, 'versão atual: passa sem erro');

echo "Db::pdo() no pgsql não chama migrate() (inspeção do código)\n";
$src = (string) file_get_contents(CL_ROOT . '/src/Db.php');
preg_match('/public static function pdo\(\): PDO\s*\{(.*?)\n    \}\n/s', $src, $m);
$body = $m[1] ?? '';
t_ok($body !== '' && !str_contains($body, 'self::migrate($pdo)'), 'pdo() não chama migrate() direto');
t_ok(str_contains($body, 'self::ensureSchemaCurrent($pdo)'), 'pdo() no pgsql chama ensureSchemaCurrent');
t_ok(str_contains($src, 'pg_advisory_lock(') && str_contains($src, 'pg_advisory_unlock('), 'migratePgsql usa pg_advisory_lock/unlock');

echo "ExceptionShield: SchemaDesatualizado vira 503\n";
$shield = (string) file_get_contents(CL_ROOT . '/src/ExceptionShield.php');
t_ok(str_contains($shield, 'instanceof SchemaDesatualizado') && str_contains($shield, '503'), '503 + Retry-After para schema desatualizado');

echo "money_unit idempotente\n";
$file = t_temp_db();
$db = Db::pdo();
t_eq(Db::SCHEMA_VERSION, (int) $db->query('PRAGMA user_version')->fetchColumn(), 'SQLite segue migrando sozinho (comportamento mantido)');
t_eq('reais', $db->query("SELECT value FROM schema_meta WHERE skey = 'money_unit'")->fetchColumn(), 'marca money_unit gravada na migração');
$cols = $db->query('PRAGMA table_info(properties)')->fetchAll(PDO::FETCH_COLUMN, 1);
t_ok(in_array('nightly_price', $cols, true), 'properties.nightly_price existe');
$db->exec('PRAGMA foreign_keys = OFF');
$db->exec("INSERT INTO properties (id, user_id, title, description, summary, city, address, lat, lng, bedrooms, beds, max_guests, nightly_price) VALUES (9001, 9001, 'Casa', '', '', 'X', '', 0, 0, 1, 1, 1, 250)");
$db->exec('PRAGMA foreign_keys = ON');
$db->exec("DELETE FROM settings WHERE skey = 'money_unit'");
t_eq(false, Db::convertMoneyUnitOnce($db), 'sem a setting mas com a marca: não converte');
t_eq(250, (int) $db->query('SELECT nightly_price FROM properties WHERE id = 9001')->fetchColumn(), 'preço intacto');
$v = Db::migrateNow(true);
t_eq(Db::SCHEMA_VERSION, $v, 'migrateNow(true) reaplica a migração');
t_eq(250, (int) $db->query('SELECT nightly_price FROM properties WHERE id = 9001')->fetchColumn(), 'migração reaplicada sem a setting: preço intacto');

echo "banco legado já convertido (setting = reais, sem marca)\n";
$db->exec("DELETE FROM schema_meta WHERE skey = 'money_unit'");
$db->exec("INSERT INTO settings (skey, value, description) VALUES ('money_unit', 'reais', 'x') ON CONFLICT(skey) DO UPDATE SET value = 'reais'");
t_eq(false, Db::convertMoneyUnitOnce($db), 'só grava a marca, não converte');
t_eq(250, (int) $db->query('SELECT nightly_price FROM properties WHERE id = 9001')->fetchColumn(), 'preço intacto');
t_eq('reais', $db->query("SELECT value FROM schema_meta WHERE skey = 'money_unit'")->fetchColumn(), 'marca gravada');

echo "banco legado em centavos (sem setting e sem marca)\n";
$db->exec("DELETE FROM schema_meta WHERE skey = 'money_unit'");
$db->exec("DELETE FROM settings WHERE skey = 'money_unit'");
t_eq(true, Db::convertMoneyUnitOnce($db), 'converte uma vez');
t_eq(3, (int) $db->query('SELECT nightly_price FROM properties WHERE id = 9001')->fetchColumn(), '250 centavos -> 3 reais');
t_eq(false, Db::convertMoneyUnitOnce($db), 'segunda chamada não converte');
t_eq(3, (int) $db->query('SELECT nightly_price FROM properties WHERE id = 9001')->fetchColumn(), 'preço não muda de novo');

t_done();
