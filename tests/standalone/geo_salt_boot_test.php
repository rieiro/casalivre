<?php
// Fail closed (Segurança #36): fora de dev/local, sem GEO_SALT ou com menos de 32 caracteres o app não
// atende: 500 genérico (sem vazar o sal nem detalhes), log geo_salt_invalid claro. Em dev/local sobe.
require __DIR__ . '/lib.php';
t_temp_db();
$owner = t_seed_owner();
db()->prepare("UPDATE properties SET status = 'active' WHERE id = ?")->execute([$owner['house_id']]);
$short = 'curto-' . bin2hex(random_bytes(4)); // 14 caracteres

$cases = [
    ['production', '', 500, 'produção sem GEO_SALT'],
    ['production', $short, 500, 'produção com GEO_SALT curto'],
    ['', $short, 500, 'APP_ENV vazio (= produção) com GEO_SALT curto'],
    ['production', str_repeat('a', 32), 200, 'produção com GEO_SALT de 32'],
    ['dev', '', 200, 'dev sem GEO_SALT sobe (sal fixo de dev)'],
];
foreach ($cases as [$env, $salt, $want, $label]) {
    putenv('APP_ENV=' . $env);
    putenv('GEO_SALT=' . $salt);
    $base = t_server();
    foreach (['/', '/api/busca?localidade=' . rawurlencode('Florianópolis, SC'), '/imoveis/' . $owner['hash']] as $path) {
        $r = t_http('GET', $base . $path);
        t_eq($want, $r['status'], "$label: GET $path -> $want");
        if ($want === 500) {
            t_ok(trim($r['body']) === 'Serviço indisponível: configuração incompleta.' && ($salt === '' || !str_contains($r['body'], $salt)) && !str_contains($r['body'], 'GEO_SALT'), "$label: corpo genérico, sem vazamento");
        }
    }
}

echo "log\n";
$log = tempnam(sys_get_temp_dir(), 'geo');
$code = 'require "' . CL_ROOT . '/src/Db.php"; require "' . CL_ROOT . '/src/GeoSalt.php"; GeoSalt::enforce(); echo "SUBIU";';
foreach (['' => 'GEO_SALT ausente', $short => 'menos de 32 caracteres'] as $salt => $why) {
    file_put_contents($log, '');
    $out = shell_exec('APP_ENV=production GEO_SALT=' . escapeshellarg($salt) . ' ' . escapeshellarg(PHP_BINARY) . ' -d error_log=' . escapeshellarg($log) . ' -r ' . escapeshellarg($code) . ' 2>/dev/null');
    $text = (string) file_get_contents($log);
    t_ok(!str_contains((string) $out, 'SUBIU'), "enforce para ($why)");
    t_ok(str_contains($text, 'geo_salt_invalid') && str_contains($text, $why) && ($salt === '' || !str_contains($text, $salt)), "log claro sem o valor ($why)");
}
putenv('APP_ENV');
putenv('GEO_SALT');

// .env injetado por caso (CASALIVRE_ENV_FILE): sal válido no arquivo sobe; sal curto no arquivo não.
echo ".env por caso\n";
$envFile = tempnam(sys_get_temp_dir(), 'geoenv');
foreach ([[str_repeat('e', 40), 200, '.env com GEO_SALT de 40'], [$short, 500, '.env com GEO_SALT curto']] as [$salt, $want, $label]) {
    file_put_contents($envFile, "APP_ENV=production\nGEO_SALT=$salt\n");
    putenv('CASALIVRE_ENV_FILE=' . $envFile);
    putenv('APP_ENV');
    putenv('GEO_SALT');
    $base = t_server();
    t_eq($want, t_http('GET', $base . '/')['status'], "$label: GET / -> $want");
}
@unlink($envFile);
t_done();
