<?php
// fix/xss-views: header CSP, páginas sem script/handler/style inline, strField 422 (texto como array) e
// rooms/amenity só como array.
require __DIR__ . '/lib.php';
t_temp_db();
putenv('GOOGLE_MAPS_API_KEY=');
$owner = t_seed_owner();
db()->exec("INSERT OR IGNORE INTO ceps (cep, street, neighborhood, city, uf) VALUES ('88000000', 'Rua A', 'Centro', 'Florianópolis', 'SC')");
$base = t_server();
$jar = tempnam(sys_get_temp_dir(), 'jar');
$strictExpected = "default-src 'self'; script-src 'self'; img-src 'self' https://tile.openstreetmap.org; object-src 'none'; base-uri 'none'; frame-ancestors 'self'; form-action 'self'";
$csp = 'Content-Security-Policy: ' . $strictExpected;

echo "csp_header(): sem e com maps_key\n";
$parse = static function (string $policy): array {
    $dir = [];
    foreach (explode(';', $policy) as $part) {
        $words = preg_split('/\s+/', trim($part));
        $dir[array_shift($words)] = $words;
    }
    return $dir;
};
$strict = csp_header('');
t_eq($strictExpected, $strict, 'sem chave: estrita + tiles OSM + frame-ancestors/form-action');
t_ok(!str_contains($strict, 'google') && !str_contains($strict, 'unsafe') && !str_contains($strict, '*'), 'sem chave: nenhum host Google, unsafe-* ou curinga');
$dir = $parse(csp_header('chave'));
t_eq(["'self'", 'https://maps.googleapis.com'], $dir['script-src'] ?? null, 'com chave: script-src = self + maps.googleapis.com');
t_eq(["'self'", 'https://maps.googleapis.com'], $dir['connect-src'] ?? null, 'com chave: connect-src = self + maps.googleapis.com');
foreach (['https://tile.openstreetmap.org', 'https://maps.gstatic.com', 'https://*.googleapis.com'] as $host) {
    t_ok(in_array($host, $dir['img-src'] ?? [], true), "com chave: img-src contém $host");
}
t_eq(['https://fonts.gstatic.com'], $dir['font-src'] ?? null, 'com chave: font-src = fonts.gstatic.com');
t_ok(in_array("'unsafe-inline'", $dir['style-src'] ?? [], true), "com chave: style-src com 'unsafe-inline' (atributos style= do Maps)");
t_ok(!in_array("'unsafe-inline'", $dir['script-src'] ?? [], true) && !str_contains(csp_header('chave'), 'unsafe-eval'), 'com chave: script sem unsafe-inline/unsafe-eval');
foreach (['object-src' => "'none'", 'base-uri' => "'none'", 'frame-ancestors' => "'self'", 'form-action' => "'self'"] as $d => $v) {
    t_eq([$v], $dir[$d] ?? null, "com chave: $d $v");
    t_eq([$v], $parse($strict)[$d] ?? null, "sem chave: $d $v");
}
t_eq(4, Db::SCHEMA_VERSION, 'SCHEMA_VERSION = 4 (err.field_type/js.* semeados em bancos existentes)');

echo "header CSP\n";
foreach (['/', '/entrar', '/busca?localidade=Florian%C3%B3polis', '/imoveis/' . $owner['hash'], '/api/busca?localidade=x'] as $path) {
    $r = t_http('GET', $base . $path);
    t_ok(stripos($r['headers'], $csp) !== false, "CSP presente em $path");
}

echo "HTML sem script/handler/style inline\n";
$csrf = t_login($base, $jar, $owner['email'], $owner['password']);
$pages = ['/', '/entrar', '/cadastro', '/busca?localidade=Florian%C3%B3polis', '/imoveis/' . $owner['hash'], '/painel',
    '/imoveis/novo', '/imoveis/' . $owner['hash'] . '/editar', '/imoveis/' . $owner['hash'] . '/calendario', '/conta'];
foreach ($pages as $path) {
    $r = t_http('GET', $base . $path, null, [], $jar);
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

echo "header com GOOGLE_MAPS_API_KEY configurada\n";
putenv('GOOGLE_MAPS_API_KEY=chave-falsa-teste');
$baseMaps = t_server();
$r = t_http('GET', $baseMaps . '/busca/mapa?localidade=x');
t_ok(stripos($r['headers'], 'Content-Security-Policy: ' . csp_header('chave-falsa-teste')) !== false, 'servidor com chave envia CSP do Maps');
t_ok(str_contains($r['body'], 'data-key="chave-falsa-teste"'), 'página do mapa recebe a chave');
putenv('GOOGLE_MAPS_API_KEY=');

echo "safe-dom.js (Node vm): safeHref e el()\n";
$js = <<<'JS'
const vm = require('vm'), fs = require('fs');
class Node {}
class El extends Node {
    constructor(t) { super(); this.tag = t; this.attrs = {}; this.kids = []; }
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
for (const k of ['onclick', 'onError', 'ONLOAD', 'srcdoc', 'formaction', 'formAction', 'style', 'STYLE']) {
    try { CL.el('a', { [k]: 'alert(1)' }); out['attr:' + k] = 'aceito'; } catch (e) { out['attr:' + k] = 'recusado'; }
}
out.ok = CL.el('a', { href: '/x', title: 't', 'data-x': '1' }).attrs.title;
out.okHref = CL.el('a', { href: '//evil.com' }).href;
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
foreach (['onclick', 'onError', 'ONLOAD', 'srcdoc', 'formaction', 'formAction', 'style', 'STYLE'] as $k) {
    t_eq('recusado', $res['attr:' . $k] ?? null, "el() recusa atributo $k");
}
t_eq('t', $res['ok'] ?? null, 'el() aceita atributo comum');
t_eq('#', $res['okHref'] ?? null, 'el() passa href por safeHref');

t_done();
