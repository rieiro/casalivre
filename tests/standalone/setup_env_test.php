<?php
// scripts/setup-env.sh (chamado por setup.sh): instalação nova sem .env não pode falhar calado (QA #36).
// Casos: diretório sem .env e sem .env.example; com .env.example; .env existente com sal válido (mantido)
// e com sal curto (trocado). Sempre sai 0, imprime o que fez e deixa .env em 600.
require __DIR__ . '/lib.php';

function setup_env_run(string $dir): array
{
    $out = [];
    exec('bash ' . escapeshellarg($dir . '/scripts/setup-env.sh') . ' ' . escapeshellarg(PHP_BINARY) . ' 2>&1', $out, $code);
    clearstatcache();
    $env = is_file($dir . '/.env') ? (string) file_get_contents($dir . '/.env') : '';
    preg_match_all('/^GEO_SALT=(.*)$/m', $env, $m);
    return ['code' => $code, 'out' => implode("\n", $out), 'env' => $env, 'salts' => $m[1],
        'mode' => is_file($dir . '/.env') ? fileperms($dir . '/.env') & 0777 : -1];
}
function setup_env_dir(bool $example): string
{
    $dir = sys_get_temp_dir() . '/cl-setupenv-' . bin2hex(random_bytes(4));
    mkdir($dir . '/scripts', 0777, true);
    copy(CL_ROOT . '/scripts/setup-env.sh', $dir . '/scripts/setup-env.sh');
    if ($example) {
        copy(CL_ROOT . '/.env.example', $dir . '/.env.example');
    }
    return $dir;
}
$dirs = [];

// 1) sem .env e sem .env.example
$dirs[] = $d = setup_env_dir(false);
$r = setup_env_run($d);
t_eq(0, $r['code'], 'sem .env/.env.example: sai 0 (' . $r['out'] . ')');
t_ok(str_contains($r['out'], 'criado do zero') && str_contains($r['out'], 'GEO_SALT gerado'), 'sem .env/.env.example: saída explica o que fez');
t_ok(count($r['salts']) === 1 && preg_match('/^[a-f0-9]{64}$/', $r['salts'][0]) === 1, 'sem .env/.env.example: um GEO_SALT de 64 hex');
t_ok(str_contains($r['env'], 'PHP_BIN=' . PHP_BINARY) && str_contains($r['env'], 'APP_ENV=production'), 'sem .env/.env.example: PHP_BIN e APP_ENV');
t_eq(0600, $r['mode'], 'sem .env/.env.example: .env em 600');

// 2) com .env.example
$dirs[] = $d = setup_env_dir(true);
$r = setup_env_run($d);
t_eq(0, $r['code'], 'com .env.example: sai 0 (' . $r['out'] . ')');
t_ok(str_contains($r['out'], 'a partir de .env.example'), 'com .env.example: saída diz a origem');
t_ok(count($r['salts']) === 1 && preg_match('/^[a-f0-9]{64}$/', $r['salts'][0]) === 1, 'com .env.example: GEO_SALT vazio preenchido (uma linha só)');
t_ok(str_contains($r['env'], 'MAIL_QUEUE_RETENTION_DAYS=7') && str_contains($r['env'], 'PHP_BIN=' . PHP_BINARY), 'com .env.example: conteúdo do exemplo + PHP_BIN detectado');
t_eq(0600, $r['mode'], 'com .env.example: .env em 600');

// 3) .env existente com sal válido: mantido (e o resto também); 644 vira 600
$salt = str_repeat('s', 40);
$dirs[] = $d = setup_env_dir(true);
file_put_contents($d . '/.env', "APP_ENV=dev\nCUSTOM=1\nGEO_SALT=$salt\n");
chmod($d . '/.env', 0644);
$r = setup_env_run($d);
t_eq(0, $r['code'], '.env existente: sai 0');
t_eq([$salt], $r['salts'], '.env existente: GEO_SALT mantido');
t_eq("APP_ENV=dev\nCUSTOM=1\nGEO_SALT=$salt\n", $r['env'], '.env existente: conteúdo intacto');
t_ok(str_contains($r['out'], 'mantido'), '.env existente: saída diz que manteve');
t_eq(0600, $r['mode'], '.env existente: 644 -> 600');
$r2 = setup_env_run($d);
t_eq([$salt], $r2['salts'], '.env existente: segunda execução idempotente');

// 4) .env existente com sal curto: trocado
$dirs[] = $d = setup_env_dir(false);
file_put_contents($d . '/.env', "GEO_SALT=curto\nX=1\n");
$r = setup_env_run($d);
t_ok($r['code'] === 0 && count($r['salts']) === 1 && strlen($r['salts'][0]) === 64 && str_contains($r['env'], "X=1"), '.env com sal curto: trocado por 64, resto mantido');
t_eq(0600, $r['mode'], '.env com sal curto: 600');

// setup.sh delega ao setup-env.sh e não roda mais sed no .env diretamente
$setup = (string) file_get_contents(CL_ROOT . '/scripts/setup.sh');
t_ok(str_contains($setup, 'scripts/setup-env.sh') && !str_contains($setup, "sed -n 's/^GEO_SALT"), 'setup.sh chama setup-env.sh');

foreach ($dirs as $d) {
    exec('rm -rf ' . escapeshellarg($d));
}
t_done();
