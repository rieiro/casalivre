<?php
// Migração em banco novo: CityRepository::ensure roda dentro do BEGIN IMMEDIATE da migração.
// No PHP 8.2/8.3 o PDO::inTransaction() não enxerga esse BEGIN e o beginTransaction() falhava.
require __DIR__ . '/lib.php';
require_once CL_ROOT . '/src/Db.php';

$bins = [PHP_BINARY];
foreach (['php8.2', 'php8.3'] as $b) {
    $path = trim((string) shell_exec('command -v ' . $b . ' 2>/dev/null'));
    if ($path !== '' && realpath($path) !== realpath(PHP_BINARY)) {
        $bins[] = $path;
    }
}

echo "scripts/migrate.php em banco novo\n";
foreach ($bins as $bin) {
    $ver = trim((string) shell_exec(escapeshellarg($bin) . ' -r "echo PHP_VERSION;"'));
    $file = t_temp_sqlite();
    exec(escapeshellarg($bin) . ' ' . escapeshellarg(CL_ROOT . '/scripts/migrate.php') . ' 2>&1', $out, $code);
    $text = implode("\n", $out);
    $out = [];
    t_eq(0, $code, "PHP $ver: migrate.php sai com 0");
    t_ok(!str_contains($text, 'within a transaction'), "PHP $ver: sem 'cannot start a transaction within a transaction'");
    $pdo = new PDO('sqlite:' . $file);
    t_eq(Db::SCHEMA_VERSION, (int) $pdo->query('PRAGMA user_version')->fetchColumn(), "PHP $ver: user_version = SCHEMA_VERSION");
    t_ok((int) $pdo->query('SELECT COUNT(*) FROM cities')->fetchColumn() > 1000, "PHP $ver: cidades carregadas");
}

echo "Db::pdo() em banco novo (mesmo processo)\n";
$file = t_temp_sqlite();
$pdo = Db::pdo();
t_eq(Db::SCHEMA_VERSION, (int) $pdo->query('PRAGMA user_version')->fetchColumn(), 'Db::pdo() migra banco novo');
t_ok(!Db::inTransaction($pdo), 'nenhuma transação pendente após a migração');

echo "Db::transactional\n";
$pdo->exec('CREATE TABLE tx_t (v INTEGER)');
Db::transactional($pdo, static fn (PDO $p) => $p->exec('INSERT INTO tx_t VALUES (1)'));
t_eq(1, (int) $pdo->query('SELECT COUNT(*) FROM tx_t')->fetchColumn(), 'commit fora de transação');
try {
    Db::transactional($pdo, static function (PDO $p): void {
        $p->exec('INSERT INTO tx_t VALUES (2)');
        throw new RuntimeException('x');
    });
} catch (RuntimeException) {
}
t_eq(1, (int) $pdo->query('SELECT COUNT(*) FROM tx_t')->fetchColumn(), 'rollback em erro');
$pdo->exec('BEGIN IMMEDIATE');
try {
    Db::transactional($pdo, static function (PDO $p): void {
        $p->exec('INSERT INTO tx_t VALUES (3)');
        throw new RuntimeException('x');
    });
} catch (RuntimeException) {
}
Db::transactional($pdo, static fn (PDO $p) => $p->exec('INSERT INTO tx_t VALUES (4)'));
$pdo->exec('COMMIT');
t_eq([1, 4], array_map('intval', $pdo->query('SELECT v FROM tx_t ORDER BY v')->fetchAll(PDO::FETCH_COLUMN)), 'dentro de BEGIN cru: savepoint desfaz só o bloco com erro');
$v = Db::migrateNow(true);
t_eq(Db::SCHEMA_VERSION, $v, 'migrateNow(true) reaplica dentro do BEGIN IMMEDIATE');

t_done();
