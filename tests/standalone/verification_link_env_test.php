<?php
// verification_link (e links de ativação/redefinição) só aparecem em APP_ENV=dev/local.
// Sem APP_ENV ou com APP_ENV=production: nunca na API nem nas telas.
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
    t_eq($expose, isset($j['verification_link']), "$label: verification_link no 201 " . ($expose ? 'presente' : 'ausente'));
    t_eq($expose, str_contains($r['body'], '/verificar?token='), "$label: token de ativação no corpo do 201");

    // API: login com conta pendente
    $r = t_http('POST', $base . '/api/sessao', json_encode(['email' => $email, 'password' => $pass]), ['Content-Type: application/json', 'X-CSRF-Token: ' . $csrf], $jar);
    t_eq(403, $r['status'], "$label: login pendente -> 403");
    t_eq($expose, str_contains($r['body'], '/verificar?token='), "$label: link no 403 de conta pendente");

    // Web: login com conta pendente -> tela verify_pending
    $wjar = tempnam(sys_get_temp_dir(), 'jar');
    $c = csrf_of($base, $wjar);
    $r = t_http('POST', $base . '/entrar', form(['_csrf' => $c, 'email' => $email, 'password' => $pass]), ['Content-Type: application/x-www-form-urlencoded'], $wjar);
    t_eq($expose, str_contains($r['body'], '/verificar?token='), "$label: link na tela de conta pendente (web)");

    // Web: cadastro -> tela verify_pending
    $email2 = 'web' . bin2hex(random_bytes(3)) . '@casalivre.test';
    $c = csrf_of($base, $wjar, '/cadastro');
    $r = t_http('POST', $base . '/cadastro', form(['_csrf' => $c] + ['email' => $email2] + $user), ['Content-Type: application/x-www-form-urlencoded'], $wjar);
    t_ok(str_contains($r['body'], $expose ? 'Ativar conta' : 'Enviamos um link'), "$label: tela de cadastro concluído coerente");
    t_eq($expose, str_contains($r['body'], '/verificar?token='), "$label: link na tela pós-cadastro (web)");

    // Web: esqueci a senha
    $c = csrf_of($base, $wjar, '/senha/esqueci');
    $r = t_http('POST', $base . '/senha/esqueci', form(['_csrf' => $c, 'email' => $email]), ['Content-Type: application/x-www-form-urlencoded'], $wjar);
    if (!$expose) {
        t_ok(!str_contains($r['body'], '/senha/redefinir?token='), "$label: link de redefinição não aparece");
    }
}
putenv('APP_ENV');
t_done();
