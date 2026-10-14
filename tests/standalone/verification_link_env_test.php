<?php
// Token/link de conta são gerados só pelo worker da fila: nunca aparecem na API nem nas telas,
// em nenhum APP_ENV. Em dev o link sai no mail-dev.log, sobre APP_URL.
require __DIR__ . '/lib.php';
t_temp_db();
require_once CL_ROOT . '/src/Db.php';
require_once CL_ROOT . '/src/helpers.php';
db()->exec("INSERT OR IGNORE INTO ceps (cep, street, neighborhood, city, uf) VALUES ('88015200', 'Rua Teste', 'Centro', 'Florianópolis', 'SC')");

function csrf_of(string $base, string $jar, string $page = '/entrar'): string
{
    $r = t_http('GET', $base . $page, null, [], $jar);
    preg_match('/name="_csrf" value="([^"]+)"/', $r['body'], $m);
    return $m[1] ?? '';
}

function form(array $data): string
{
    return http_build_query($data);
}

foreach (['' => false, 'production' => false, 'prod' => false, 'dev' => true, 'local' => true] as $env => $expose) {
    $label = $env === '' ? 'APP_ENV ausente' : "APP_ENV=$env";
    echo "$label\n";
    putenv($env === '' ? 'APP_ENV' : "APP_ENV=$env");
    t_eq($expose, expose_account_links(), "$label: expose_account_links()");
    $base = t_server();
    $jar = tempnam(sys_get_temp_dir(), 'jar');
    $email = 'novo' . bin2hex(random_bytes(3)) . '@casalivre.test';
    $pass = 'Casa#' . random_int(100000, 999999) . 'xY';
    $user = ['name' => 'Novo Usuário', 'cpf' => '52998224725', 'cep' => '88015-200', 'address_number' => '10',
        'address_complement' => '', 'phone' => '(48) 99999-0000', 'email' => $email, 'password' => $pass, 'password_confirm' => $pass];

    // API: POST /api/users
    $csrf = csrf_of($base, $jar);
    $r = t_http('POST', $base . '/api/users', json_encode($user), ['Content-Type: application/json', 'X-CSRF-Token: ' . $csrf], $jar);
    $j = json_decode($r['body'], true) ?: [];
    t_eq(201, $r['status'], "$label: POST /api/users -> 201");
    t_ok(!str_contains($r['body'], 'token='), "$label: nenhum token no corpo do 201");

    // API: login com conta pendente
    $r = t_http('POST', $base . '/api/sessao', json_encode(['email' => $email, 'password' => $pass]), ['Content-Type: application/json', 'X-CSRF-Token: ' . $csrf], $jar);
    t_eq(403, $r['status'], "$label: login pendente -> 403");
    t_ok(!str_contains($r['body'], '/verificar?token='), "$label: link fora do 403 de conta pendente");

    // Web: login com conta pendente -> tela verify_pending
    $wjar = tempnam(sys_get_temp_dir(), 'jar');
    $c = csrf_of($base, $wjar);
    $r = t_http('POST', $base . '/entrar', form(['_csrf' => $c, 'email' => $email, 'password' => $pass]), ['Content-Type: application/x-www-form-urlencoded'], $wjar);
    t_ok(!str_contains($r['body'], '/verificar?token='), "$label: link fora da tela de conta pendente (web)");

    // Web: cadastro -> tela verify_pending
    $email2 = 'web' . bin2hex(random_bytes(3)) . '@casalivre.test';
    $c = csrf_of($base, $wjar, '/cadastro');
    $r = t_http('POST', $base . '/cadastro', form(['_csrf' => $c] + ['email' => $email2] + $user), ['Content-Type: application/x-www-form-urlencoded'], $wjar);
    t_ok(str_contains($r['body'], 'Enviamos um link'), "$label: tela de cadastro concluído coerente");
    t_ok(!str_contains($r['body'], '/verificar?token='), "$label: link fora da tela pós-cadastro (web)");

    // Web: esqueci a senha
    $c = csrf_of($base, $wjar, '/senha/esqueci');
    $r = t_http('POST', $base . '/senha/esqueci', form(['_csrf' => $c, 'email' => $email]), ['Content-Type: application/x-www-form-urlencoded'], $wjar);
    t_ok(!str_contains($r['body'], '/senha/redefinir?token='), "$label: link de redefinição não aparece");
}

echo "APP_URL fixa (request_base)\n";
$_SERVER['HTTP_HOST'] = 'atacante.example';
putenv('APP_URL');
putenv('APP_ENV=production');
$err = null;
try {
    request_base();
} catch (RuntimeException $e) {
    $err = $e->getMessage();
}
t_ok($err !== null && str_contains($err, 'APP_URL não configurada'), 'produção sem APP_URL: erro claro');
putenv('APP_ENV');
$err = null;
try {
    request_base();
} catch (RuntimeException $e) {
    $err = $e->getMessage();
}
t_ok($err !== null, 'APP_ENV ausente sem APP_URL: também falha');
putenv('APP_ENV=dev');
t_eq('http://atacante.example', request_base(), 'dev sem APP_URL: cai no Host');
putenv('APP_URL=https://casalivre.example/');
putenv('APP_ENV=production');
t_eq('https://casalivre.example', request_base(), 'APP_URL usada (sem barra final), Host ignorado');
foreach (['ftp://x.com', 'casalivre.example', 'https://x.com/?a=1', 'https://u:p@x.com'] as $bad) {
    putenv('APP_URL=' . $bad);
    $err = null;
    try {
        request_base();
    } catch (RuntimeException $e) {
        $err = $e->getMessage();
    }
    t_ok($err !== null && str_contains($err, 'APP_URL inválida'), "APP_URL inválida recusada: $bad");
}
unset($_SERVER['HTTP_HOST']);

echo "link montado com APP_URL mesmo com Host forjado (HTTP)\n";
putenv('APP_ENV=dev');
putenv('APP_URL=https://casalivre.example');
$base = t_server();
$jar = tempnam(sys_get_temp_dir(), 'jar');
$csrf = csrf_of($base, $jar);
$email = 'host' . bin2hex(random_bytes(3)) . '@casalivre.test';
$pass = 'Casa#' . random_int(100000, 999999) . 'xY';
$r = t_http('POST', $base . '/api/users', json_encode(['name' => 'Host Teste', 'cpf' => '52998224725', 'cep' => '88015-200',
    'address_number' => '10', 'address_complement' => '', 'phone' => '(48) 99999-0000', 'email' => $email, 'password' => $pass,
    'password_confirm' => $pass]), ['Content-Type: application/json', 'X-CSRF-Token: ' . $csrf], $jar);
t_eq(201, $r['status'], 'cadastro 201');
require_once CL_ROOT . '/src/MailWorker.php';
$dir = sys_get_temp_dir() . '/cl-vl-' . bin2hex(random_bytes(3));
mkdir($dir, 0700);
Mailer::$devLogDir = $dir;
$lock = $dir . '/w.lock';
MailWorker::run(MailQueues::make(), $lock);
$dev = (string) @file_get_contents($dir . '/mail-dev.log');
t_ok(str_contains($dev, $email) && str_contains($dev, 'https://casalivre.example/verificar?token='), 'dev: link no mail-dev.log usa APP_URL');
// Depois do worker o link está gravado no usuário; o 403 de conta pendente (dev) não pode expô-lo.
$r = t_http('POST', $base . '/api/sessao', json_encode(['email' => $email, 'password' => $pass]), ['Content-Type: application/json', 'X-CSRF-Token: ' . $csrf], $jar);
t_eq(403, $r['status'], 'dev: login pendente -> 403');
t_ok(!str_contains($r['body'], 'token') && !str_contains($r['body'], 'verificar') && !str_contains($r['body'], 'verification_link'), 'dev: 403 sem link/token (só no mail-dev.log)');
array_map('unlink', glob($dir . '/*') ?: []);
@rmdir($dir);
putenv('APP_URL');
putenv('APP_ENV');

t_done();
