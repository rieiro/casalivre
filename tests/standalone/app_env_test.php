<?php
// AppEnv::isDev(): dev/local com trim + case-insensitive; ausente/vazio/qualquer outro valor => false.
require __DIR__ . '/lib.php';
require_once CL_ROOT . '/src/AppEnv.php';
require_once CL_ROOT . '/src/GeoSalt.php';
require_once CL_ROOT . '/src/MapTiles.php';

$cases = [
    'dev' => true, 'local' => true, 'DEV' => true, ' Local ' => true, "dev\n" => true,
    '' => false, 'production' => false, 'prod' => false, 'development' => false, 'd e v' => false, 'dev,local' => false,
];
foreach ($cases as $value => $expected) {
    putenv('APP_ENV=' . $value);
    t_eq($expected, AppEnv::isDev(), 'APP_ENV=' . json_encode((string) $value) . ' => ' . var_export($expected, true));
    t_eq(AppEnv::isDev(), GeoSalt::isDev(), 'GeoSalt::isDev igual a AppEnv para ' . json_encode((string) $value));
    t_eq(AppEnv::isDev(), MapTiles::devRouteEnabled(), 'MapTiles::devRouteEnabled igual a AppEnv para ' . json_encode((string) $value));
}
putenv('APP_ENV');
t_eq(false, AppEnv::isDev(), 'APP_ENV ausente => false');

t_done();
