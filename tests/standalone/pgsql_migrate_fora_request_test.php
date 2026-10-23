<?php
// PostgreSQL only (#137): a requisição web só confere a versão (uma consulta) e nunca migra; a migração fica no
// scripts/migrate.php (pg_advisory_lock); só 42P01 vale versão 0; migrate.php sai com 2 em falha.
require __DIR__ . '/lib.php';
require_once CL_ROOT . '/src/Db.php';

$pg = static fn (string $dsn): PDO => new PDO($dsn, getenv('TEST_PG_USER') ?: null, getenv('TEST_PG_PASSWORD') ?: null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$tables = static fn (PDO $p): int => (int) $p->query("SELECT count(*) FROM pg_class WHERE relnamespace = 'public'::regnamespace")->fetchColumn();

echo "ensureSchemaCurrent (caminho web)\n";
$pdo = $pg(t_temp_pg_empty());
$before = $tables($pdo);
try {
    Db::ensureSchemaCurrent($pdo);
    t_ok(false, 'banco sem schema_meta deveria dar SchemaDesatualizado');
} catch (SchemaDesatualizado $e) {
    t_ok(str_contains($e->getMessage(), 'scripts/migrate.php'), 'sem schema_meta: SchemaDesatualizado com instrução clara');
}
t_eq($before, $tables($pdo), 'não cria nem altera tabelas (não migra)');
$pdo->exec('CREATE TABLE schema_meta (skey TEXT PRIMARY KEY, value TEXT NOT NULL)');
$pdo->exec("INSERT INTO schema_meta VALUES ('schema_version', '" . (Db::SCHEMA_VERSION - 1) . "')");
try {
    Db::ensureSchemaCurrent($pdo);
    t_ok(false, 'versão antiga deveria dar SchemaDesatualizado');
} catch (SchemaDesatualizado $e) {
    t_ok(str_contains($e->getMessage(), 'Recrie o banco'), 'versão antiga: SchemaDesatualizado (recriar o banco)');
}
$pdo->exec("UPDATE schema_meta SET value = '" . Db::SCHEMA_VERSION . "'");
Db::ensureSchemaCurrent($pdo);
t_ok(true, 'versão atual: passa sem erro');
$pdo = null;

echo "Db::pdo() não chama migrate() (inspeção do código)\n";
$src = (string) file_get_contents(CL_ROOT . '/src/Db.php');
preg_match('/public static function pdo\(\): PDO\s*\{(.*?)\n    \}\n/s', $src, $m);
$body = $m[1] ?? '';
t_ok($body !== '' && !str_contains($body, 'self::migrate($pdo)'), 'pdo() não chama migrate() direto');
t_ok(str_contains($body, 'self::ensureSchemaCurrent($pdo)'), 'pdo() chama ensureSchemaCurrent');
t_ok(str_contains($src, 'pg_advisory_lock(') && str_contains($src, 'pg_advisory_unlock('), 'migrateStep usa pg_advisory_lock/unlock');
foreach (['sqlite', 'DB_DRIVER', 'BEGIN IMMEDIATE', 'migrateSqlite', 'sqliteVersion'] as $gone) {
    t_ok(!str_contains($src, $gone), "Db.php sem '$gone'");
}

echo "ensureSchemaCurrent: só 'tabela não existe' vale versão 0 (B2)\n";
final class FakePdoError extends PDOException
{
    public function __construct(string $message, string $sqlstate)
    {
        parent::__construct($message);
        $this->code = $sqlstate;
        $this->errorInfo = [$sqlstate, 7, $message];
    }
}
$failing = static function (string $sqlstate): PDO {
    return new class ($sqlstate) extends PDO {
        public function __construct(private string $sqlstate)
        {
            // sem conexão: só query() é usada, e ela falha
        }

        public function query(string $query, ?int $fetchMode = null, mixed ...$args): PDOStatement|false
        {
            throw new FakePdoError('erro simulado ' . $this->sqlstate, $this->sqlstate);
        }
    };
};
try {
    Db::ensureSchemaCurrent($failing('42P01'));
    t_ok(false, '42P01 deveria virar SchemaDesatualizado');
} catch (SchemaDesatualizado) {
    t_ok(true, '42P01 (tabela inexistente): versão 0 -> SchemaDesatualizado');
}
foreach (['08006' => 'conexão caiu', '42501' => 'sem GRANT SELECT', '57014' => 'timeout'] as $state => $why) {
    try {
        Db::ensureSchemaCurrent($failing($state));
        t_ok(false, "$state deveria ser relançado");
    } catch (SchemaDesatualizado) {
        t_ok(false, "$state ($why) não pode virar 'rode migrate.php'");
    } catch (PDOException $e) {
        t_eq((string) $state, (string) $e->getCode(), "$state ($why): PDOException relançada");
    }
}

$run = static function (array $cmd, array $env = []): array {
    $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, CL_ROOT, array_merge(getenv(), $env));
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [proc_close($proc), $out, $err];
};

echo "ExceptionShield: SchemaDesatualizado vira 503\n";
$shield = (string) file_get_contents(CL_ROOT . '/src/ExceptionShield.php');
t_ok(str_contains($shield, 'instanceof SchemaDesatualizado') && str_contains($shield, '503'), '503 + Retry-After para schema desatualizado');

echo "migrate.php: código de saída em falha\n";
[$code, , $err] = $run([PHP_BINARY, CL_ROOT . '/scripts/migrate.php'], ['DB_DSN' => 'pgsql:host=127.0.0.1;port=1;dbname=x;connect_timeout=2']);
t_eq(2, $code, 'conexão impossível: sai com 2');
t_ok(str_contains($err, 'FALHA na migração'), 'mensagem clara no STDERR');
foreach (['sqlite:/tmp/x.sqlite' => 'DSN SQLite', '' => 'DSN vazio'] as $dsn => $why) {
    [$code, , $err] = $run([PHP_BINARY, CL_ROOT . '/scripts/migrate.php'], ['DB_DSN' => $dsn]);
    t_ok($code === 2 && str_contains($err, 'PostgreSQL only'), "$why: recusado com 'PostgreSQL only' (saída $code)");
}
t_ok(!str_contains($src, 'nightly_price / 100'), 'conversão money_unit (divisão por 100) removida');

t_done();
