<?php
// Precedência do ambiente sobre o .env (bug do #40: `source .env` no start.sh trocava APP_ENV=dev por production
// e a rota de tiles sumia). scripts/env-lib.sh (start.sh) e Db::loadEnv só preenchem chaves ainda não definidas;
// o .env é lido como KEY=VALUE, sem executar nada.
require __DIR__ . '/lib.php';
require_once CL_ROOT . '/src/MapTiles.php';
t_temp_db();

$tmp = sys_get_temp_dir() . '/cl-envprec-' . bin2hex(random_bytes(4));
mkdir($tmp, 0700, true);
$marker = $tmp . '/executou';
$envFile = $tmp . '/.env';
file_put_contents($envFile, implode("\n", [
    '# comentário',
    'APP_ENV=production',
    'PORT=9999',
    'SO_NO_ENV=do-arquivo',
    'export EXPORTADA=sim',
    'ASPAS_D="valor com espaço"',
    "ASPAS_S='literal \$HOME'",
    'MAL=$(touch ' . $marker . ')',
    'CRASE=`touch ' . $marker . '`',
    'PONTO_VIRGULA=a; touch ' . $marker,
    '1INVALIDA=x',
    'touch ' . $marker,
    'VAZIA_NO_ENV=do-arquivo',
    '',
]) . "\r\n");

echo "scripts/env-lib.sh (start.sh)\n";
$start = (string) file_get_contents(CL_ROOT . '/scripts/start.sh');
t_ok(!preg_match('/^\s*(source|\.)\s+[\'"]?(\$ROOT\/)?\.env/m', $start), 'start.sh não faz source do .env');
t_ok(str_contains($start, 'load_env_file "$ROOT/.env"'), 'start.sh usa load_env_file');
$print = 'for k in APP_ENV PORT SO_NO_ENV EXPORTADA ASPAS_D ASPAS_S MAL CRASE PONTO_VIRGULA VAZIA_NO_ENV; do printf "%s=%s\n" "$k" "${!k-<unset>}"; done; printf "invalidas=%s\n" "$(env | grep -c "^1INVALIDA=" || true)"';
$run = static function (string $prefix) use ($envFile, $print): array {
    $cmd = $prefix . ' bash -c ' . escapeshellarg('set -euo pipefail; source ' . CL_ROOT . '/scripts/env-lib.sh; load_env_file ' . $envFile . '; ' . $print . '; bash -c \'printf "child=%s\n" "${SO_NO_ENV-}"\'');
    $out = [];
    exec('env -u APP_ENV -u PORT -u SO_NO_ENV -u VAZIA_NO_ENV ' . $cmd . ' 2>&1', $out, $code);
    $vars = ['__code' => $code];
    foreach ($out as $line) {
        [$k, $v] = array_pad(explode('=', $line, 2), 2, '');
        $vars[$k] = $v;
    }
    return $vars;
};
$v = $run('APP_ENV=dev PORT=8081 VAZIA_NO_ENV=');
t_eq(0, $v['__code'], 'load_env_file sai com 0 (set -euo pipefail)');
t_eq('dev', $v['APP_ENV'] ?? null, 'APP_ENV=dev do ambiente vence APP_ENV=production do .env');
t_eq('8081', $v['PORT'] ?? null, 'PORT do ambiente vence o .env');
t_eq('', $v['VAZIA_NO_ENV'] ?? null, 'variável definida vazia no ambiente também vence');
t_eq('do-arquivo', $v['SO_NO_ENV'] ?? null, 'chave ausente no ambiente vem do .env');
t_eq('do-arquivo', $v['child'] ?? null, 'e é exportada para os filhos (php -S)');
t_eq('sim', $v['EXPORTADA'] ?? null, "aceita 'export KEY=...'");
t_eq('valor com espaço', $v['ASPAS_D'] ?? null, 'aspas duplas removidas');
t_eq('literal $HOME', $v['ASPAS_S'] ?? null, 'aspas simples removidas, sem expansão');
t_eq('$(touch ' . $marker . ')', $v['MAL'] ?? null, '$(...) fica literal');
t_eq('`touch ' . $marker . '`', $v['CRASE'] ?? null, 'crase fica literal');
t_eq('a; touch ' . $marker, $v['PONTO_VIRGULA'] ?? null, '; fica literal');
t_ok(!file_exists($marker), 'nenhum comando do .env foi executado');
t_eq('0', $v['invalidas'] ?? null, 'chave inválida (1INVALIDA) ignorada');
$v = $run('');
t_ok(($v['APP_ENV'] ?? null) === 'production' && ($v['PORT'] ?? null) === '9999', 'sem nada no ambiente, vale o .env');

echo "Db::loadEnv\n";
$php = static function (string $prefix, string $key) use ($envFile): string {
    $code = 'require "' . CL_ROOT . '/src/Db.php"; Db::loadEnv(); $v = getenv(' . var_export($key, true) . '); echo $v === false ? "<unset>" : $v;';
    return (string) shell_exec('env -u APP_ENV -u SO_NO_ENV ' . $prefix . ' CASALIVRE_ENV_FILE=' . escapeshellarg($envFile) . ' ' . escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($code));
};
t_eq('dev', $php('APP_ENV=dev', 'APP_ENV'), 'loadEnv não sobrescreve APP_ENV do ambiente');
t_eq('', $php('APP_ENV=', 'APP_ENV'), 'loadEnv não sobrescreve variável vazia do ambiente');
t_eq('production', $php('', 'APP_ENV'), 'sem APP_ENV no ambiente, vem do .env');
t_eq('valor com espaço', $php('', 'ASPAS_D'), 'loadEnv tira as aspas como o start.sh');
t_eq('$(touch ' . $marker . ')', $php('', 'MAL'), 'loadEnv: valor literal');
$code = '$_ENV["SO_NO_ENV"] = "de-ENV"; require "' . CL_ROOT . '/src/Db.php"; Db::loadEnv(); var_export(getenv("SO_NO_ENV"));';
t_eq('false', (string) shell_exec('env -u SO_NO_ENV CASALIVRE_ENV_FILE=' . escapeshellarg($envFile) . ' ' . escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($code)), 'chave já em $_ENV não é sobrescrita');
$code = '$_SERVER["SO_NO_ENV"] = "de-SERVER"; require "' . CL_ROOT . '/src/Db.php"; Db::loadEnv(); var_export(getenv("SO_NO_ENV"));';
t_eq('false', (string) shell_exec('env -u SO_NO_ENV CASALIVRE_ENV_FILE=' . escapeshellarg($envFile) . ' ' . escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($code)), 'chave já em $_SERVER não é sobrescrita');

echo "HTTP: .env com APP_ENV=production + ambiente APP_ENV=dev -> rota de tiles ativa\n";
$tilesDir = MapTiles::tilesDir() . '/cl-envprec-' . bin2hex(random_bytes(3));
mkdir($tilesDir, 0700, true);
file_put_contents($tilesDir . '/t.pmtiles', str_repeat('P', 1000));
putenv('CASALIVRE_ENV_FILE=' . $envFile);
putenv('MAP_TILES_FILE=' . $tilesDir . '/t.pmtiles');
putenv('APP_ENV=dev');
$r = t_http('GET', t_server() . '/tiles/brasil.pmtiles', null, ['Range: bytes=0-9']);
t_ok($r['status'] === 206 && $r['body'] === 'PPPPPPPPPP', 'APP_ENV=dev do ambiente: 206 (a rota dev existe)');
putenv('APP_ENV');
$r = t_http('GET', t_server() . '/tiles/brasil.pmtiles', null, ['Range: bytes=0-9']);
t_eq(404, $r['status'], 'sem APP_ENV no ambiente: vale production do .env -> 404');
putenv('MAP_TILES_FILE');

@unlink($tilesDir . '/t.pmtiles');
@rmdir($tilesDir);
@unlink($envFile);
@unlink($marker);
@rmdir($tmp);
t_done();
