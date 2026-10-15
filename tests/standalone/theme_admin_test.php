<?php
// Etapa 2 (temas): lista de temas = manifesto de assets; settings.theme fora da lista (inclusive ../) cai no
// padrão; /admin/tema só para admin, POST exige CSRF, troca grava THEME_CHANGE no activity_log; CSP intacta
// (tema só por <link> a arquivo do próprio site, sem style inline).
require __DIR__ . '/lib.php';
require __DIR__ . '/char_helpers.php';
require_once CL_ROOT . '/src/ThemeBuilder.php';
t_temp_db();
$owner = t_seed_owner();
$act = c_activity_file();
// settings.json (storage/cache) é compartilhado: volta o tema ao padrão no fim, aconteça o que acontecer.
$restore = static function (): void {
    $c = JsonCache::read('settings.json');
    if (is_array($c) && isset($c['theme'])) {
        $c['theme']['value'] = Theme::DEFAULT;
        JsonCache::write('settings.json', $c);
    }
};
register_shutdown_function($restore);
$setTheme = static function (string $v): void {
    db()->prepare('UPDATE settings SET value = ? WHERE skey = ?')->execute([$v, 'theme']);
    Settings::refresh();
};
Settings::refresh();

echo "lista de temas (manifesto)\n";
t_eq(['contraste', 'padrao'], Theme::available(), 'temas do manifesto');
t_eq(['contraste', 'padrao'], Theme::available(AssetManifest::build()), 'manifesto em dia com public/assets/themes');
t_eq([], ThemeBuilder::outdated(), 'CSS dos temas em dia com themes/*/brand.php');
$orfao = ThemeBuilder::outDir() . 'orfao-teste.css';
file_put_contents($orfao, '');
t_eq([$orfao], ThemeBuilder::outdated(), '--check aponta CSS órfão (tema removido)');
unlink($orfao);
$exp = ThemeBuilder::expected();
t_eq(['contraste', 'padrao'], $exp['names'], 'expected(): temas de themes/');
t_eq(Theme::css(Theme::readBrand(CL_ROOT . '/themes/padrao/brand.php')), $exp['files'][ThemeBuilder::outDir() . 'padrao.css'] ?? null, 'expected(): CSS do padrão');
$tmpBrand = sys_get_temp_dir() . '/tb' . bin2hex(random_bytes(3)) . '/brand.php';
mkdir(dirname($tmpBrand));
file_put_contents($tmpBrand, "<?php return ['rotulo' => 'X', 'logo' => 'a.gif'];");
$b = Theme::readBrand($tmpBrand);
t_eq(Theme::brand(Theme::DEFAULT)['nome'], $b['nome'], 'sem nome: vale o nome do tema padrão');
t_eq(null, $b['logo'], 'logo com extensão fora de Theme::IMAGE_EXT descartado');
unlink($tmpBrand);
rmdir(dirname($tmpBrand));
foreach (['../padrao', '..', 'padrao/../contraste', '/etc/passwd', 'Padrao', 'padrao.css', 'padrao ', "padrao\0", 'x', '', 'inexistente', '-padrao', 'padrao-'] as $bad) {
    t_ok(!Theme::isAvailable($bad), 'fora da lista: ' . json_encode($bad));
}
t_eq(false, Theme::available(['/assets/themes/../x.css' => 'h', '/assets/themes/a/b.css' => 'h', '/assets/themes/nao-existe.css' => 'h']) !== [], 'manifesto forjado com ../ ou sem pasta do tema não vira tema');
t_eq(Theme::DEFAULT, Theme::current(), 'padrão sem configuração');
foreach (['../../etc/passwd', 'contraste/../../x', 'nao-existe', "padrao\nx"] as $bad) {
    $setTheme($bad);
    t_eq(Theme::DEFAULT, Theme::current(), 'settings.theme inválido cai no padrão: ' . json_encode($bad));
}
$setTheme(Theme::DEFAULT);

echo "tokens do brand.php\n";
$clean = Theme::cleanTokens(['--ok' => '#fff', '--x' => 'red;} body{display:none', '--u' => 'url(//evil)', '--i' => '@import x', 'cor' => '#000', '--q' => '"a"', '--lt' => '</style>', '--c1' => '#fff /* x', '--c2' => 'red */', '--c3' => '1px/*a*/2px']);
t_eq(['--ok' => '#fff'], $clean, 'tokens perigosos descartados');
t_ok(!str_contains(Theme::css(Theme::readBrand(CL_ROOT . '/themes/contraste/brand.php')), 'url('), 'CSS gerado sem url()');

$base = t_server();
echo "acesso\n";
$r = t_http('GET', $base . '/admin/tema');
t_eq(302, $r['status'], 'sem login: redireciona');
t_eq('/entrar', c_location($r), 'sem login: para /entrar');
$jar = tempnam(sys_get_temp_dir(), 'jar');
$csrf = t_login($base, $jar, $owner['email'], $owner['password']);
$r = t_http('GET', $base . '/admin/tema', null, [], $jar);
t_eq(403, $r['status'], 'logado sem admin: GET 403');
$mark = c_mark($act);
$r = c_form($base, '/admin/tema', ['_csrf' => $csrf, 'tema' => 'contraste'], $jar);
t_eq(403, $r['status'], 'logado sem admin: POST 403');
t_eq(Theme::DEFAULT, db()->query("SELECT value FROM settings WHERE skey = 'theme'")->fetchColumn(), 'não-admin não muda o tema');
t_eq([], array_values(array_filter(c_codes($act, $mark), static fn ($c) => $c === ActivityLog::THEME_CHANGE)), 'não-admin: sem THEME_CHANGE');
$r = c_api('PUT', $base, '/api/users/eu', ['is_admin' => 1, 'name' => 'X'], $csrf, $jar);
t_eq(0, (int) db()->query('SELECT is_admin FROM users WHERE id = ' . (int) $owner['user_id'])->fetchColumn(), 'is_admin não muda pela API da conta');

$cli = static function (string $args) use (&$code): string {
    $out = [];
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(CL_ROOT . '/scripts/set-admin.php') . ' ' . $args . ' 2>&1', $out, $code);
    return implode("\n", $out);
};
$cli(escapeshellarg('ninguem@casalivre.test'));
t_eq(1, $code, 'set-admin: e-mail inexistente sai com 1');
$cli(escapeshellarg($owner['email']));
t_eq(0, $code, 'set-admin: liga admin');
t_eq(1, (int) db()->query('SELECT is_admin FROM users WHERE id = ' . (int) $owner['user_id'])->fetchColumn(), 'set-admin: is_admin = 1');
$r = t_http('GET', $base . '/admin/tema', null, [], $jar);
t_eq(200, $r['status'], 'admin: GET 200');
t_ok(str_contains($r['body'], 'value="contraste"') && str_contains($r['body'], 'value="padrao"'), 'admin: lista com os temas do manifesto');

echo "CSRF\n";
$mark = c_mark($act);
$r = c_form($base, '/admin/tema', ['tema' => 'contraste'], $jar);
t_eq(419, $r['status'], 'POST sem _csrf: 419');
$r = c_form($base, '/admin/tema', ['_csrf' => str_repeat('0', 64), 'tema' => 'contraste'], $jar);
t_eq(419, $r['status'], 'POST com _csrf errado: 419');
$jar2 = tempnam(sys_get_temp_dir(), 'jar');
$r = c_form($base, '/admin/tema', ['_csrf' => $csrf, 'tema' => 'contraste'], $jar2);
t_ok($r['status'] !== 200 && c_location($r) !== '/admin/tema?ok=1', 'token de outra sessão não vale');
t_eq(Theme::DEFAULT, db()->query("SELECT value FROM settings WHERE skey = 'theme'")->fetchColumn(), 'sem CSRF válido o tema não muda');

echo "allowlist no POST\n";
foreach (['../padrao', '../../etc/passwd', 'nao-existe', 'contraste/..', 'CONTRASTE'] as $bad) {
    $r = c_form($base, '/admin/tema', ['_csrf' => $csrf, 'tema' => $bad], $jar);
    t_eq(422, $r['status'], 'POST tema inválido 422: ' . $bad);
}
$r = t_http('POST', $base . '/admin/tema', '_csrf=' . urlencode($csrf) . '&tema[]=contraste', ['Content-Type: application/x-www-form-urlencoded'], $jar);
t_eq(422, $r['status'], 'POST tema como array: 422');
t_eq(Theme::DEFAULT, db()->query("SELECT value FROM settings WHERE skey = 'theme'")->fetchColumn(), 'tema inválido não grava');
t_eq([], array_values(array_filter(c_codes($act, $mark), static fn ($c) => $c === ActivityLog::THEME_CHANGE)), 'tentativas recusadas: sem THEME_CHANGE');

echo "troca válida + log\n";
$mark = c_mark($act);
$r = c_form($base, '/admin/tema', ['_csrf' => $csrf, 'tema' => 'contraste'], $jar);
t_eq(302, $r['status'], 'admin troca o tema: 302');
t_eq('/admin/tema?ok=1', c_location($r), 'volta para /admin/tema?ok=1');
t_eq('contraste', db()->query("SELECT value FROM settings WHERE skey = 'theme'")->fetchColumn(), 'settings.theme gravado');
$logs = array_values(array_filter(c_logs($act, $mark), static fn ($l) => $l['code'] === ActivityLog::THEME_CHANGE));
t_eq(1, count($logs), 'um THEME_CHANGE gravado');
t_eq('contraste', $logs[0]['params']['tema'] ?? null, 'log: tema novo');
t_eq('padrao', $logs[0]['params']['tema_anterior'] ?? null, 'log: tema anterior');
t_eq(['tema' => 'padrao'], $logs[0]['anterior'], 'log: valor_anterior {tema}');
t_eq(['tema' => 'contraste'], $logs[0]['atual'], 'log: valor_atual {tema}');
$home = t_http('GET', $base . '/', null, [], $jar);
t_ok((bool) preg_match('#<link rel="stylesheet" href="/assets/themes/contraste\.css\?v=[0-9a-f]{12}">#', $home['body']), 'página usa o CSS do tema com ?v=');
preg_match('/^Content-Security-Policy:\s*(.+?)\s*$/mi', $home['headers'], $m);
t_eq(csp_header(), $m[1] ?? null, 'CSP intacta com o tema trocado');
t_ok(!preg_match('/<style|\sstyle=/i', $home['body']), 'sem style inline com o tema trocado');
$css = t_http('GET', $base . '/assets/themes/contraste.css');
t_eq(200, $css['status'], 'CSS do tema servido como arquivo');
$mark = c_mark($act);
c_form($base, '/admin/tema', ['_csrf' => $csrf, 'tema' => 'contraste'], $jar);
t_eq([], array_values(array_filter(c_codes($act, $mark), static fn ($c) => $c === ActivityLog::THEME_CHANGE)), 'mesmo tema: sem log novo');
$r = c_form($base, '/admin/tema', ['_csrf' => $csrf, 'tema' => 'padrao'], $jar);
t_eq(302, $r['status'], 'volta ao padrão');
$home = t_http('GET', $base . '/');
t_ok(str_contains($home['body'], '/assets/themes/padrao.css?v='), 'padrão de volta na home');
$restore();
t_done();
