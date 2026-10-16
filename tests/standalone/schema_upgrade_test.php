<?php
// Política v19 (banco recriado do zero): o migrate.php NÃO leva bancos antigos (versão 1..18) ao schema atual.
// Ele aborta com saída 2 e "Recrie o banco do zero", sem tocar no banco. Versão 0 (banco novo, vindo do
// schema.sql) e versão já atual seguem funcionando. (Antes este teste cobria upgrades v2..v12 -> atual.)
require __DIR__ . '/lib.php';
require_once CL_ROOT . '/src/Db.php';
t_eq(19, Db::SCHEMA_VERSION, 'SCHEMA_VERSION = 19');
t_eq(19, Db::MIN_UPGRADE_FROM, 'MIN_UPGRADE_FROM = 19');

$migrate = static function (array $args = []): array {
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(CL_ROOT . '/scripts/migrate.php') . ' ' . implode(' ', array_map('escapeshellarg', $args)) . ' 2>&1', $out, $code);
    return [$code, implode("\n", $out)];
};
$master = static fn (PDO $p): array => $p->query("SELECT type, name, sql FROM sqlite_master ORDER BY type, name")->fetchAll(PDO::FETCH_ASSOC);

echo "banco novo (versão 0, vindo do schema.sql)\n";
$file = t_temp_sqlite();
[$code, $text] = $migrate();
t_eq(0, $code, "versão 0: migrate.php sai com 0 ($text)");
$pdo = new PDO('sqlite:' . $file, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
t_eq(19, (int) $pdo->query('PRAGMA user_version')->fetchColumn(), 'versão 0 -> 19');
t_ok((int) $pdo->query("SELECT COUNT(*) FROM settings WHERE skey = 'theme'")->fetchColumn() === 1, 'seeds aplicados');
$pdo = null;

echo "banco já em 19\n";
[$code] = $migrate();
t_eq(0, $code, 'v19: migrate.php sai com 0');
[$code] = $migrate(['--force']);
t_eq(0, $code, 'v19: migrate.php --force sai com 0');

foreach ([1, 2, 4, 11, 12, 17, 18] as $from) {
    echo "banco antigo v$from\n";
    $file = t_temp_sqlite();
    $migrate();
    $pdo = new PDO('sqlite:' . $file, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec('PRAGMA user_version = ' . $from);
    $before = $master($pdo);
    $pdo = null;
    foreach ([[], ['--force']] as $args) {
        $label = "v$from" . ($args ? ' --force' : '');
        [$code, $text] = $migrate($args);
        t_eq(2, $code, "$label: migrate.php sai com 2");
        t_ok(str_contains($text, 'Recrie o banco do zero'), "$label: mensagem \"Recrie o banco do zero\"");
    }
    $pdo = new PDO('sqlite:' . $file, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    t_eq($from, (int) $pdo->query('PRAGMA user_version')->fetchColumn(), "v$from: user_version intacto (não carimba 19)");
    t_eq($before, $master($pdo), "v$from: schema intacto");
    $pdo = null;
}

t_done();
