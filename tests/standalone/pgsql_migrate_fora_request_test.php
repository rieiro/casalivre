<?php
// pgsql: a requisição web só confere a versão (uma consulta) e nunca migra; a migração fica no
// scripts/migrate.php (pg_advisory_lock); só 42P01 vale versão 0; migrate.php sai com 2 em falha.
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
final class FailingPdo extends PDO
{
    public function __construct(private string $sqlstate)
    {
        parent::__construct('sqlite::memory:');
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$args): PDOStatement|false
    {
        throw new FakePdoError('erro simulado ' . $this->sqlstate, $this->sqlstate);
    }
}
try {
    Db::ensureSchemaCurrent(new FailingPdo('42P01'));
    t_ok(false, '42P01 deveria virar SchemaDesatualizado');
} catch (SchemaDesatualizado) {
    t_ok(true, '42P01 (tabela inexistente): versão 0 -> SchemaDesatualizado');
}
foreach (['08006' => 'conexão caiu', '42501' => 'sem GRANT SELECT', '57014' => 'timeout'] as $state => $why) {
    try {
        Db::ensureSchemaCurrent(new FailingPdo($state));
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
[$code, , $err] = $run([PHP_BINARY, CL_ROOT . '/scripts/migrate.php'], ['DB_DRIVER' => 'pgsql', 'DB_DSN' => 'pgsql:host=127.0.0.1;port=1;dbname=x;connect_timeout=2']);
t_eq(2, $code, 'conexão impossível: sai com 2');
t_ok(str_contains($err, 'FALHA na migração'), 'mensagem clara no STDERR');

echo "SQLite inalterado\n";
t_temp_sqlite();
$db = Db::pdo();
t_eq(Db::SCHEMA_VERSION, (int) $db->query('PRAGMA user_version')->fetchColumn(), 'SQLite segue migrando sozinho na conexão (user_version = SCHEMA_VERSION)');
t_ok(!str_contains((string) file_get_contents(CL_ROOT . '/src/Db.php'), 'nightly_price / 100'), 'conversão money_unit (divisão por 100) removida');

t_done();
