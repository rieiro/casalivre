<?php
// QA #62 D1: scripts/build-assets.php (normal e --check) falha com exit != 0 e mensagem com tema, chave e valor
// quando brand.php tem cor/papel inválido em 'mapa' ou token CSS inválido; temas válidos continuam gerando.
// Roda numa cópia temporária do projeto (src, conta, scripts, themes, public/assets) para não tocar no repositório.
require __DIR__ . '/lib.php';
require_once CL_ROOT . '/src/Theme.php';

$tmp = sys_get_temp_dir() . '/cl-build-' . bin2hex(random_bytes(6));
$rm = static function (string $d) use (&$rm): void {
    foreach (array_merge(glob("$d/[!.]*") ?: [], glob("$d/.[!.]*") ?: []) as $f) { // sem flag de chaves no glob (indisponível no musl)
        is_dir($f) && !is_link($f) ? $rm($f) : unlink($f);
    }
    @rmdir($d);
};
register_shutdown_function(static fn () => $rm($tmp));
foreach (['src', 'conta', 'scripts', 'themes', 'public/assets'] as $d) {
    @mkdir(dirname("$tmp/$d"), 0700, true);
    exec('cp -a ' . escapeshellarg(CL_ROOT . "/$d") . ' ' . escapeshellarg("$tmp/$d"), $_o, $rc);
    t_eq(0, $rc, "cópia de $d");
}
$run = static function (bool $check) use ($tmp): array {
    $cmd = [PHP_BINARY, "$tmp/scripts/build-assets.php"];
    if ($check) {
        $cmd[] = '--check';
    }
    $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $tmp);
    $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    return [proc_close($p), $out];
};
$brand = "$tmp/themes/contraste/brand.php";
$orig = (string) file_get_contents($brand);
$manifest = (string) file_get_contents("$tmp/public/assets/manifest.json");
$mapFile = "$tmp/public/assets/themes/contraste/map-style.json";
$mapOrig = (string) file_get_contents($mapFile);
// Acrescenta entradas ao array do brand.php original (copiado ao lado, fora do glob */brand.php).
file_put_contents("$tmp/themes/contraste/brand-original.inc", $orig);
$with = static function (string $php) use ($brand): void {
    file_put_contents($brand, "<?php\n\$b = require __DIR__ . '/brand-original.inc';\n" . $php . "\nreturn \$b;\n");
};

echo "temas válidos\n";
foreach ([false, true] as $check) {
    [$rc, $out] = $run($check);
    t_eq(0, $rc, 'build válido' . ($check ? ' --check' : '') . ' sai 0' . ($rc === 0 ? '' : ': ' . trim($out)));
}
t_eq($manifest, (string) file_get_contents("$tmp/public/assets/manifest.json"), 'build válido não muda o manifesto');

$cases = [
    'cor inválida no mapa' => ["\$b['mapa']['agua'] = 'red;}';", "tema contraste: mapa.agua inválido ('red;}'), use #rgb ou #rrggbb"],
    'hex de 4 dígitos' => ["\$b['mapa']['agua'] = '#1234';", "tema contraste: mapa.agua inválido ('#1234')"],
    'cor não-string' => ["\$b['mapa']['fundo'] = 123;", 'tema contraste: mapa.fundo inválido (123)'],
    'url no mapa' => ["\$b['mapa']['via'] = 'url(https://evil.example/x.png)';", "tema contraste: mapa.via inválido ('url(https://evil.example/x.png)')"],
    'papel desconhecido' => ["\$b['mapa']['inventado'] = '#000000';", 'tema contraste: mapa.inventado papel desconhecido, use um de: fundo'],
    'mapa não-array' => ["\$b['mapa'] = '#000';", 'tema contraste: mapa deve ser um array'],
    'token CSS inválido' => ["\$b['tokens']['--bg'] = 'red;}';", "tema contraste: tokens.--bg inválido ('red;}')"],
    'token escuro inválido' => ["\$b['tokens_escuro']['--fg'] = 'url(x)';", "tema contraste: tokens_escuro.--fg inválido ('url(x)')"],
    'nome de token inválido' => ["\$b['tokens']['bg'] = '#fff';", "tema contraste: tokens.bg inválido ('#fff')"],
];
foreach ($cases as $label => [$php, $msg]) {
    echo "$label\n";
    $with($php);
    foreach ([false, true] as $check) {
        [$rc, $out] = $run($check);
        $mode = $check ? ' --check' : '';
        t_ok($rc !== 0, "$label: build$mode sai != 0");
        t_ok(str_contains($out, $msg), "$label: build$mode cita tema, chave e valor: " . trim($out));
    }
    t_eq($manifest, (string) file_get_contents("$tmp/public/assets/manifest.json"), "$label: manifesto não foi gravado");
    t_eq($mapOrig, (string) file_get_contents($mapFile), "$label: map-style.json não foi gravado");
}
echo "várias entradas inválidas: todas listadas\n";
$with("\$b['mapa']['agua'] = 'red'; \$b['tokens']['--bg'] = 'a;b';");
[$rc, $out] = $run(false);
t_ok($rc !== 0 && str_contains($out, 'mapa.agua') && str_contains($out, 'tokens.--bg'), 'lista todas: ' . trim($out));

echo "runtime continua filtrando (clean)\n";
$with("\$b['mapa']['agua'] = 'red;}'; \$b['mapa']['inventado'] = '#000';");
$b = Theme::readBrand($brand);
t_ok(!isset($b['mapa']['agua']) && !isset($b['mapa']['inventado']) && isset($b['mapa']['fundo']), 'readBrand descarta as inválidas e mantém as válidas');

file_put_contents($brand, $orig);
[$rc] = $run(true);
t_eq(0, $rc, 'brand.php restaurado: --check volta a 0');
t_done();
