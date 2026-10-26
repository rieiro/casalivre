<?php
// fix/xss-views: header CSP, páginas sem script/handler/style inline, strField 422 (texto como array) e
// rooms/amenity só como array.
require __DIR__ . '/lib.php';
t_temp_db();
$owner = t_seed_owner();
db()->exec("INSERT INTO ceps (cep, street, neighborhood, city, uf) VALUES ('88000000', 'Rua A', 'Centro', 'Florianópolis', 'SC') ON CONFLICT DO NOTHING");
$base = t_server();
$jar = tempnam(sys_get_temp_dir(), 'jar');
$strictExpected = "default-src 'self'; script-src 'self'; style-src 'self'; style-src-attr 'none'; img-src 'self'; connect-src 'self'; worker-src 'self'; object-src 'none'; base-uri 'none'; frame-ancestors 'none'; form-action 'self'";
$csp = 'Content-Security-Policy: ' . $strictExpected;

echo "csp_header(): sempre estrita\n";
$parse = static function (string $policy): array {
    $dir = [];
    foreach (explode(';', $policy) as $part) {
        $words = preg_split('/\s+/', trim($part));
        $dir[array_shift($words)] = $words;
    }
    return $dir;
};
$strict = csp_header();
t_eq($strictExpected, $strict, 'csp_header() = política estrita exata');
$dir = $parse($strict);
t_eq(["'self'"], $dir['script-src'] ?? null, "script-src só 'self'");
t_eq(["'self'"], $dir['img-src'] ?? null, "img-src só 'self' (mapa PMTiles local; tiles raster do OSM removidos)");
t_ok(!str_contains($strict, 'openstreetmap') && !str_contains($strict, 'http'), 'CSP sem host externo (nem tile.openstreetmap.org)');
foreach (['object-src' => "'none'", 'base-uri' => "'none'", 'frame-ancestors' => "'none'", 'form-action' => "'self'",
    'style-src' => "'self'", 'style-src-attr' => "'none'", 'connect-src' => "'self'", 'worker-src' => "'self'"] as $d => $v) {
    t_eq([$v], $dir[$d] ?? null, "$d $v");
}
t_ok(!isset($dir['font-src']), "sem font-src próprio (cai em default-src 'self')");
foreach (['google', 'gstatic', 'unsafe', 'data:', 'blob:', '*'] as $bad) {
    t_ok(!str_contains($strict, $bad), "CSP sem '$bad'");
}
t_ok(!function_exists('maps_key'), 'maps_key() removida');
t_ok(Db::SCHEMA_VERSION >= 4, 'SCHEMA_VERSION >= 4 (err.field_type/js.* semeados em bancos existentes)');

echo "header CSP\n";
foreach (['/', '/entrar', '/busca?localidade=Florian%C3%B3polis', '/imoveis/' . $owner['hash'], '/api/busca?localidade=x'] as $path) {
    $r = t_http('GET', $base . $path, null, [], null, str_contains($path, 'localidade='));
    t_ok(stripos($r['headers'], $csp) !== false, "CSP presente em $path");
}

echo "HTML sem script/handler/style inline\n";
$csrf = t_login($base, $jar, $owner['email'], $owner['password']);
$pages = ['/', '/entrar', '/cadastro', '/busca?localidade=Florian%C3%B3polis', '/imoveis/' . $owner['hash'], '/painel',
    '/imoveis/novo', '/imoveis/' . $owner['hash'] . '/editar', '/imoveis/' . $owner['hash'] . '/calendario', '/conta'];
foreach ($pages as $path) {
    $r = t_http('GET', $base . $path, null, [], $jar, str_contains($path, 'localidade='));
    $body = $r['body'];
    preg_match_all('/<script\b([^>]*)>/i', $body, $m);
    $inline = array_filter($m[1], static fn (string $a) => !str_contains($a, 'src=') && !str_contains($a, 'application/json'));
    t_eq(0, count($inline), "$path: nenhum <script> inline (status {$r['status']})");
    t_ok(!preg_match('/\son[a-z]+\s*=/i', $body), "$path: nenhum handler on*=");
    t_ok(!preg_match('/\sstyle\s*=/i', $body), "$path: nenhum atributo style=");
}

echo "strField: texto como array -> 422\n";
$common = ['title' => 'Casa Teste', 'description' => 'Descrição de teste do imóvel.', 'cep' => '88000-000', 'street' => 'Rua A',
    'city' => 'Florianópolis, SC', 'address_number' => '1', 'address_complement' => '', 'max_guests' => '4',
    'nightly_price' => 'R$ 200,00', 'cleaning_fee' => 'R$ 0,00', 'checkin_time' => '15:00', 'checkout_time' => '11:00',
    'min_nights' => '1', 'calendar_months' => '6',
    'rooms' => [['beds' => [['type' => 'single', 'variant' => 'common']]]], 'amenity' => ['wifi' => '1']];
$put = static fn (array $data) => t_http('PUT', $base . '/api/casas/' . $owner['hash'], json_encode($data), ['Content-Type: application/json', 'X-CSRF-Token: ' . $csrf], $jar);
$r = $put($common);
t_eq(200, $r['status'], 'payload válido -> 200 (' . substr($r['body'], 0, 120) . ')');
foreach (['title', 'description', 'checkin_time', 'cep', 'nightly_price'] as $field) {
    $r = $put([$field => ['x' => 'y']] + $common);
    t_eq(422, $r['status'], "$field como array -> 422");
}
$r = $put(['title' => ['<b>a</b>']] + $common);
t_ok(!str_contains($r['body'], 'Array') && str_contains($r['body'], 'error'), '422 com mensagem de erro, sem "Array"');
require_once CL_ROOT . '/src/I18n.php';
t_eq(t('err.field_type'), json_decode($r['body'], true)['error'] ?? null, '422 usa a mensagem err.field_type');
foreach (['rooms', 'amenity'] as $field) {
    $r = $put([$field => 'texto'] + $common);
    t_eq(422, $r['status'], "$field como texto -> 422");
}
$r = t_http('POST', $base . '/api/casas', json_encode(['title' => ['a']] + $common), ['Content-Type: application/json', 'X-CSRF-Token: ' . $csrf], $jar);
t_eq(422, $r['status'], 'POST /api/casas com title array -> 422');
t_eq('Casa Teste', HouseRepository::find($owner['house_id'])['title'], 'imóvel não alterado pelos 422');

echo "sem caminho Google (mesmo com GOOGLE_MAPS_API_KEY no ambiente)\n";
putenv('GOOGLE_MAPS_API_KEY=chave-que-nao-deve-ser-usada');
$baseEnv = t_server();
$r = t_http('GET', $baseEnv . '/busca/mapa?localidade=' . urlencode('Florianópolis, SC'), null, [], null, true);
t_ok(stripos($r['headers'], $csp) !== false, 'CSP continua estrita com a variável no ambiente');
t_ok(!str_contains($r['body'], 'chave-que-nao-deve-ser-usada') && !str_contains($r['body'], 'data-key'), 'página do mapa sem chave/data-key');
putenv('GOOGLE_MAPS_API_KEY');
// helpers.php foi dividido em src/helpers/ (#50): varre helpers.php + src/helpers/**/*.php (recursivo).
$helperFiles = ['src/helpers.php'];
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(CL_ROOT . '/src/helpers', FilesystemIterator::SKIP_DOTS)) as $info) {
    if ($info->isFile() && $info->getExtension() === 'php') {
        $helperFiles[] = substr($info->getPathname(), strlen(CL_ROOT) + 1);
    }
}
sort($helperFiles);
t_ok(count($helperFiles) > 1, 'varredura de helpers cobre helpers.php e src/helpers/ (' . count($helperFiles) . ' arquivos)');
foreach (array_merge(['public/assets/pages/map.js', 'public/assets/pages/map-data.js', 'views/map.php', 'views/search.php', 'src/MapTiles.php', 'public/assets/map/style-light.json'], $helperFiles, ['public/index.php', '.env.example']) as $file) {
    $text = (string) file_get_contents(CL_ROOT . '/' . $file);
    t_ok(!preg_match('/google|gstatic|GOOGLE_MAPS/i', $text), "$file sem Google Maps");
}

echo "safe-dom.js (Node vm): safeHref e el()\n";
$js = <<<'JS'
const vm = require('vm'), fs = require('fs');
class Node {}
class El extends Node {
    constructor(t) { super(); this.tagName = String(t).toUpperCase(); this.attrs = {}; this.kids = []; }
    setAttribute(k, v) { this.attrs[k] = v; }
    appendChild(c) { this.kids.push(c); }
}
const ctx = { Node, document: { createElement: (t) => new El(t), createTextNode: (t) => ({ text: t }), getElementById: () => null } };
ctx.window = ctx; // no navegador window é o objeto global
vm.createContext(ctx);
vm.runInContext(fs.readFileSync(process.argv[1], 'utf8'), ctx);
const CL = ctx.window.CL;
const out = {};
for (const h of ['/imoveis/x', '/', '//evil.com', '/\\evil.com', '/\\/evil.com', 'javascript:alert(1)', 'https://evil.com', '/\t/evil.com', '/\n/x', ' /x', '']) {
    out['href:' + JSON.stringify(h)] = CL.safeHref(h);
}
for (const k of ['onclick', 'onError', 'ONLOAD', 'OnClick', 'srcdoc', 'SRCDOC', 'style', 'STYLE', 'Style']) {
    try { CL.el('a', { [k]: 'alert(1)' }); out['attr:' + k] = 'aceito'; } catch (e) { out['attr:' + k] = 'recusado'; }
}
out.ok = CL.el('a', { href: '/x', title: 't', 'data-x': '1' }).attrs.title;
out.okHref = CL.el('a', { href: '//evil.com' }).attrs.href;
// Atributos de URL (qualquer caixa) passam por safeHref; <img src> ainda só /uploads/.
const urlCases = {
    HREF: ['a', 'HREF', 'javascript:alert(1)'], Href: ['a', 'Href', '/\\evil.com'], action: ['form', 'action', 'https://evil.com/x'],
    ACTION: ['form', 'ACTION', '//evil.com'], formaction: ['button', 'formaction', 'javascript:alert(1)'],
    formAction: ['button', 'formAction', '//evil.com'], xlink: ['a', 'xlink:href', 'javascript:alert(1)'],
    XLINK: ['a', 'XLINK:HREF', 'data:text/html,x'], srcIframe: ['iframe', 'src', 'javascript:alert(1)'],
    SrcImg: ['img', 'Src', 'javascript:alert(1)'], srcImgUpload: ['img', 'SRC', '/uploads/a.jpg'],
    okAction: ['form', 'action', '/imoveis/x'], okFormaction: ['button', 'formAction', '/x/excluir'],
};
for (const [key, [tag, attr, val]] of Object.entries(urlCases)) {
    const node = CL.el(tag, { [attr]: val });
    out['url:' + key] = node.attrs[attr.toLowerCase()];
    out['urlraw:' + key] = Object.keys(node.attrs).filter((a) => a !== attr.toLowerCase()).length;
}
out.classProp = CL.el('p', { CLASSNAME: 'x' }).className;
console.log(JSON.stringify(out));
JS;
$tmp = tempnam(sys_get_temp_dir(), 'sd') . '.js';
file_put_contents($tmp, str_replace('process.argv[1]', var_export(CL_ROOT . '/public/assets/safe-dom.js', true), $js));
$res = json_decode((string) shell_exec('node ' . escapeshellarg($tmp) . ' 2>&1'), true) ?: [];
@unlink($tmp);
t_eq('/imoveis/x', $res['href:"/imoveis/x"'] ?? null, 'safeHref aceita /imoveis/x');
t_eq('/', $res['href:"/"'] ?? null, 'safeHref aceita /');
foreach (['"//evil.com"', '"/\\\\evil.com"', '"/\\\\/evil.com"', '"javascript:alert(1)"', '"https://evil.com"', '"/\\t/evil.com"', '"/\\n/x"', '" /x"', '""'] as $h) {
    t_eq('#', $res['href:' . $h] ?? null, "safeHref recusa $h");
}
foreach (['onclick', 'onError', 'ONLOAD', 'OnClick', 'srcdoc', 'SRCDOC', 'style', 'STYLE', 'Style'] as $k) {
    t_eq('recusado', $res['attr:' . $k] ?? null, "el() recusa atributo $k");
}
t_eq('t', $res['ok'] ?? null, 'el() aceita atributo comum');
t_eq('#', $res['okHref'] ?? null, 'el() passa href por safeHref');
$urlExpect = ['HREF' => '#', 'Href' => '#', 'action' => '#', 'ACTION' => '#', 'formaction' => '#', 'formAction' => '#',
    'xlink' => '#', 'XLINK' => '#', 'srcIframe' => '#', 'SrcImg' => '/assets/placeholder.svg', 'srcImgUpload' => '/uploads/a.jpg',
    'okAction' => '/imoveis/x', 'okFormaction' => '/x/excluir'];
foreach ($urlExpect as $key => $want) {
    t_eq($want, $res['url:' . $key] ?? null, "el(): atributo de URL $key -> $want");
    t_eq(0, $res['urlraw:' . $key] ?? null, "el(): $key não deixa cópia crua com outra caixa");
}
t_eq('x', $res['classProp'] ?? null, 'el(): CLASSNAME em qualquer caixa vira className');

t_done();
