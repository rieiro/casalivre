<?php
// Deploy limpo (PostgreSQL only): scripts/migrate.php num banco sem schema recusa com instrução clara (carregar
// database/schema.pgsql.sql antes), cria as pastas de runtime e, com o schema, migra; o carregamento de CEPs dá erro
// claro quando não há como descompactar o cep.csv.bz2 (antes: 0 CEPs em silêncio).
require __DIR__ . '/lib.php';
require_once CL_ROOT . '/src/Db.php';

$dsn = t_temp_pg_empty();
$env = array_merge(getenv(), ['DB_DSN' => $dsn, 'DB_USER' => (string) (getenv('TEST_PG_USER') ?: ''), 'DB_PASSWORD' => (string) (getenv('TEST_PG_PASSWORD') ?: '')]);
$pdo = new PDO($dsn, getenv('TEST_PG_USER') ?: null, getenv('TEST_PG_PASSWORD') ?: null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$tables = static fn (): int => (int) $pdo->query("SELECT count(*) FROM pg_class WHERE relnamespace = 'public'::regnamespace")->fetchColumn();

$run = static function (array $cmd, array $env): array {
    $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, CL_ROOT, $env);
    $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [proc_close($proc), $out];
};

$migrateCmd = array_merge(t_php_cmd(), [CL_ROOT . '/scripts/migrate.php']);

echo "migrate.php sem schema\n";
[$code, $out] = $run($migrateCmd, $env);
t_eq(2, $code, 'migrate.php sai com 2 num banco vazio');
t_ok(str_contains($out, 'database/schema.pgsql.sql'), 'mensagem manda carregar database/schema.pgsql.sql');
t_eq(0, $tables(), 'banco vazio continua vazio (não cria schema_meta nem tabelas)');
foreach (['storage/mail', 'storage/sessions', 'public/uploads/users'] as $d) {
    t_ok(is_dir(CL_ROOT . '/' . $d), "pasta $d existe");
}

echo "migrate.php com o schema carregado\n";
$pdo->exec((string) file_get_contents(CL_ROOT . '/database/schema.pgsql.sql'));
[$code, $out] = $run($migrateCmd, $env);
t_eq(0, $code, "migrate.php sai com 0 ($out)");
t_eq((string) Db::SCHEMA_VERSION, (string) $pdo->query("SELECT value FROM schema_meta WHERE skey = 'schema_version'")->fetchColumn(), 'schema_version = SCHEMA_VERSION');
[$code, $out] = $run($migrateCmd, $env);
t_eq(0, $code, 'segunda execução sai com 0');

echo "CEPs sem bz2 do PHP e sem python3\n";
if (function_exists('bzopen')) {
    echo "  (pulado: este PHP tem a extensão bz2)\n";
} else {
    [$code, $out] = $run([PHP_BINARY, CL_ROOT . '/scripts/load-ceps.php'], $env + ['CEP_PYTHON' => '/nao/existe/python3']);
    t_ok($code !== 0, 'load-ceps.php falha (não importa 0 CEPs em silêncio)');
    t_ok(str_contains($out, 'python3 não foi encontrado') && str_contains($out, 'bz2'), 'mensagem clara cita bz2 e python3');
    t_eq(0, (int) $pdo->query('SELECT COUNT(*) FROM ceps')->fetchColumn(), 'nenhum CEP gravado');
}
$pdo = null;
t_done();
