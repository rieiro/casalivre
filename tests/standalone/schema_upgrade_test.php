<?php
// PostgreSQL only, sem migração de dados (decisão de 2026-10-21, #137): o migrate.php NÃO leva bancos antigos
// (versão 1..SCHEMA_VERSION-1) ao schema atual. Ele aborta com saída 2 e "Recrie o banco do zero", sem tocar no
// banco. Banco novo (schema.pgsql.sql carregado, versão 0) e versão já atual seguem funcionando.
require __DIR__ . '/lib.php';
require_once CL_ROOT . '/src/Db.php';
t_eq(26, Db::SCHEMA_VERSION, 'SCHEMA_VERSION = 26');
t_ok(!defined('Db::MIN_UPGRADE_FROM'), 'sem MIN_UPGRADE_FROM (sem caminho incremental: uma checagem de versão só)');

$migrate = static function (array $args = []): array {
    exec(t_php_shell() . ' ' . escapeshellarg(CL_ROOT . '/scripts/migrate.php') . ' ' . implode(' ', array_map('escapeshellarg', $args)) . ' 2>&1', $out, $code);
    return [$code, implode("\n", $out)];
};
$pdo = static fn (): PDO => new PDO((string) getenv('DB_DSN'), getenv('DB_USER') ?: null, getenv('DB_PASSWORD') ?: null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$version = static fn (PDO $p): ?string => ($v = $p->query("SELECT value FROM schema_meta WHERE skey = 'schema_version'")->fetchColumn()) === false ? null : (string) $v;
$shape = static fn (PDO $p): array => $p->query("SELECT n.nspname || '.' || c.relname || ':' || c.relkind::text FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace WHERE n.nspname IN ('public', 'audit') ORDER BY 1")->fetchAll(PDO::FETCH_COLUMN);

echo "banco novo (schema.pgsql.sql, versão 0)\n";
t_temp_db(); // carrega o schema e migra em processo
$p = $pdo();
$p->exec("DELETE FROM schema_meta");
$p->exec("DELETE FROM settings");
[$code, $text] = $migrate();
t_eq(0, $code, "versão 0: migrate.php sai com 0 ($text)");
t_eq('26', $version($p), 'versão 0 -> 26');
t_ok((int) $p->query("SELECT COUNT(*) FROM settings WHERE skey = 'theme'")->fetchColumn() === 1, 'seeds aplicados');
t_eq(48, (int) $p->query('SELECT COUNT(*) FROM activity_actions')->fetchColumn(), 'activity_actions: 48 códigos com classe');

echo "banco já em 26\n";
[$code] = $migrate();
t_eq(0, $code, 'v26: migrate.php sai com 0');
[$code] = $migrate(['--force']);
t_eq(0, $code, 'v26: migrate.php --force sai com 0');

foreach ([1, 12, 18, 19, 20, 21, 22, 23, 24, 25] as $from) {
    echo "banco antigo v$from\n";
    $p->exec("UPDATE schema_meta SET value = '$from' WHERE skey = 'schema_version'");
    $before = $shape($p);
    foreach ([[], ['--force']] as $args) {
        $label = "v$from" . ($args ? ' --force' : '');
        [$code, $text] = $migrate($args);
        t_eq(2, $code, "$label: migrate.php sai com 2");
        t_ok(str_contains($text, 'Recrie o banco do zero'), "$label: mensagem \"Recrie o banco do zero\"");
    }
    t_eq((string) $from, $version($p), "v$from: versão intacta (não carimba 26)");
    t_eq($before, $shape($p), "v$from: schema intacto");
}
$p->exec("UPDATE schema_meta SET value = '26' WHERE skey = 'schema_version'");

echo "migrate() só seeds + versão (sem ALTER, backfill ou conversão)\n";
$src = (string) file_get_contents(CL_ROOT . '/src/Db.php');
preg_match('/private static function migrate\(PDO \$pdo\): void\s*\{(.*?)\n    \}\n/s', $src, $m);
$body = $m[1] ?? '';
t_ok($body !== '', 'migrate() encontrado');
foreach (['ALTER', 'UPDATE ', 'CREATE INDEX', 'renameUsers', 'public_hash', 'calendarMonthsDefault'] as $gone) {
    t_ok(!str_contains($body, $gone), "migrate() sem '$gone'");
}
t_done();
