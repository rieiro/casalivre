<?php
// Deploy limpo: scripts/migrate.php cria o banco a partir de database/schema.sql quando ele não existe,
// e o carregamento de CEPs dá erro claro quando não há como descompactar o cep.csv.bz2 (antes: 0 CEPs em silêncio).
require __DIR__ . '/lib.php';
require_once CL_ROOT . '/src/Db.php';

$dir = sys_get_temp_dir() . '/casalivre-boot-' . bin2hex(random_bytes(4));
register_shutdown_function(static fn () => @exec('rm -rf ' . escapeshellarg($dir)));
$file = $dir . '/sub/novo.sqlite';
$env = array_merge(getenv(), ['DB_DRIVER' => 'sqlite', 'DB_DSN' => 'sqlite:' . $file]);

$run = static function (array $cmd, array $env): array {
    $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, CL_ROOT, $env);
    $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [proc_close($proc), $out];
};

echo "migrate.php sem banco\n";
[$code, $out] = $run([PHP_BINARY, CL_ROOT . '/scripts/migrate.php'], $env);
t_eq(0, $code, 'migrate.php sai com 0 sem banco existente');
t_ok(str_contains($out, 'database/schema.sql'), 'avisa que criou o banco pelo schema.sql');
t_ok(is_file($file), 'arquivo do banco criado (inclusive a pasta)');
$pdo = new PDO('sqlite:' . $file);
t_eq(Db::SCHEMA_VERSION, (int) $pdo->query('PRAGMA user_version')->fetchColumn(), 'user_version = SCHEMA_VERSION');
t_ok((int) $pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='users'")->fetchColumn() === 1, 'tabela users do schema.sql existe');
foreach (['storage/mail', 'storage/sessions', 'public/uploads/users'] as $d) {
    t_ok(is_dir(CL_ROOT . '/' . $d), "pasta $d existe");
}
$pdo = null;

echo "migrate.php com banco existente (não recarrega o schema)\n";
[$code, $out] = $run([PHP_BINARY, CL_ROOT . '/scripts/migrate.php'], $env);
t_eq(0, $code, 'segunda execução sai com 0');
t_ok(!str_contains($out, 'Banco criado'), 'não recria o banco');

echo "CEPs sem bz2 do PHP e sem python3\n";
if (function_exists('bzopen')) {
    echo "  (pulado: este PHP tem a extensão bz2)\n";
} else {
    [$code, $out] = $run([PHP_BINARY, CL_ROOT . '/scripts/load-ceps.php'], $env + ['CEP_PYTHON' => '/nao/existe/python3']);
    t_ok($code !== 0, 'load-ceps.php falha (não importa 0 CEPs em silêncio)');
    t_ok(str_contains($out, 'python3 não foi encontrado') && str_contains($out, 'bz2'), 'mensagem clara cita bz2 e python3');
    $pdo = new PDO('sqlite:' . $file);
    t_eq(0, (int) $pdo->query('SELECT COUNT(*) FROM ceps')->fetchColumn(), 'nenhum CEP gravado');
}

t_done();
