<?php
// Formulários web (sem JS): o erro do POST vem renderizado no servidor dentro de #account-error / #register-error,
// escapado, com role="alert"; sem erro o elemento fica vazio e hidden.
require __DIR__ . '/lib.php';
require __DIR__ . '/char_helpers.php';
require_once CL_ROOT . '/src/Api.php';
t_temp_db();
$owner = t_seed_owner();
require_once CL_ROOT . '/conta/Geo/CepRepository.php';
CepRepository::ensureTable();
db()->prepare("INSERT INTO ceps (cep, street, neighborhood, city, uf) VALUES ('88000000', 'Rua A', 'Centro', 'Florianópolis', 'SC')")->execute();
putenv('APP_ENV=dev');
putenv('SMTP_HOST');
t_ratelimit_reset();
$base = t_server();
$box = static function (string $html, string $id): ?array {
    if (preg_match('#<p class="error" id="' . $id . '"([^>]*)>(.*?)</p>#s', $html, $m) !== 1) {
        return null;
    }
    return ['attrs' => $m[1], 'text' => $m[2]];
};
$profile = ['name' => 'Dono', 'cpf' => '52998224725', 'cep' => '88000000', 'address_number' => '1', 'address_complement' => '', 'phone' => '48999999999'];
$pass = 'Casa#' . random_int(100000, 999999) . 'xY';

echo "conta\n";
$jar = tempnam(sys_get_temp_dir(), 'jar');
$csrf = t_login($base, $jar, $owner['email'], $owner['password']);
$b = $box(t_http('GET', $base . '/conta', null, [], $jar)['body'], 'account-error');
t_ok($b !== null && $b['text'] === '' && str_contains($b['attrs'], ' hidden') && str_contains($b['attrs'], 'role="alert"'), 'GET /conta: #account-error vazio, hidden, role=alert');
$cases = [
    'nome vazio' => [['name' => ''], 'err.profile_required'],
    'CEP inválido' => [['cep' => '00000001'], 'err.cep'],
    'número vazio' => [['address_number' => ''], 'err.cep'],
    'telefone' => [['phone' => '123'], 'err.phone'],
    'CPF' => [['cpf' => '123'], 'err.cpf'],
];
foreach ($cases as $label => [$over, $key]) {
    $r = c_form($base, '/conta', ['_csrf' => $csrf] + $over + $profile, $jar);
    $b = $box($r['body'], 'account-error');
    t_eq(e(t($key)), $b['text'] ?? null, "conta $label: $key em #account-error");
    t_ok($b !== null && !str_contains($b['attrs'], 'hidden') && str_contains($b['attrs'], 'role="alert"'), "conta $label: visível, role=alert");
}
$r = c_form($base, '/conta', ['_csrf' => $csrf] + $profile, $jar);
t_eq(302, $r['status'], 'conta: sucesso continua 302 (sem página de erro)');
$b = $box(t_http('GET', $base . '/conta', null, [], $jar)['body'], 'account-error');
t_eq('', $b['text'] ?? null, 'conta após sucesso: erro vazio');

echo "cadastro\n";
$reg = static function (array $fields) use ($base): array {
    $jar = tempnam(sys_get_temp_dir(), 'jar');
    $csrf = c_csrf($base, $jar, '/cadastro');
    return c_form($base, '/cadastro', ['_csrf' => $csrf] + $fields, $jar);
};
$full = $profile + ['email' => 'novo' . bin2hex(random_bytes(3)) . '@casalivre.test', 'password' => $pass, 'password_confirm' => $pass];
$b = $box(t_http('GET', $base . '/cadastro')['body'], 'register-error');
t_ok($b !== null && $b['text'] === '' && str_contains($b['attrs'], ' hidden') && str_contains($b['attrs'], 'role="alert"'), 'GET /cadastro: #register-error vazio, hidden, role=alert');
$weak = str_repeat('a', 12);
$rcases = [
    'tudo vazio' => [array_fill_keys(array_keys($full), ''), e(t('err.required'))],
    'nome vazio' => [['name' => ''], e(t('err.required'))],
    'e-mail inválido' => [['email' => 'x@'], e(t('err.required'))],
    'CEP' => [['cep' => '00000001'], e(t('err.cep'))],
    'telefone' => [['phone' => '1'], e(t('err.phone'))],
    'CPF' => [['cpf' => '1'], e(t('err.cpf'))],
    'senha curta' => [['password' => 'a', 'password_confirm' => 'a'], e((string) account_text('short'))],
    'senhas diferentes' => [['password_confirm' => $pass . 'z'], e(t('err.password_mismatch'))],
    'senha fraca' => [['password' => $weak, 'password_confirm' => $weak], e((string) account_text(PasswordPolicy::problem($weak, $weak)))],
];
foreach ($rcases as $label => [$over, $want]) {
    t_ratelimit_reset(); // /cadastro tem limite por IP; aqui interessa só a mensagem
    $b = $box($reg($over + $full)['body'], 'register-error');
    t_eq($want, $b['text'] ?? null, "cadastro $label: mensagem em #register-error");
    t_ok($b !== null && !str_contains($b['attrs'], 'hidden'), "cadastro $label: visível");
}
t_ok(PasswordPolicy::problem($weak, $weak) !== null, 'senha fraca de fato reprovada');
t_ratelimit_reset();
$r = $reg($full);
t_eq(null, $box($r['body'], 'register-error'), 'cadastro ok: página de pendência, sem caixa de erro');
// Anti-enumeração (#35): e-mail já cadastrado não gera erro; mesma página de sucesso.
$r = $reg(['email' => $owner['email']] + $full);
t_eq(null, $box($r['body'], 'register-error'), 'cadastro com e-mail existente: sem erro (anti-enumeração preservada)');

echo "login\n";
t_ratelimit_reset();
$lj = tempnam(sys_get_temp_dir(), 'jar');
$b = $box(t_http('GET', $base . '/entrar', null, [], $lj)['body'], 'login-error');
t_ok($b !== null && $b['text'] === '' && str_contains($b['attrs'], ' hidden') && str_contains($b['attrs'], 'role="alert"'), 'GET /entrar: #login-error vazio, hidden, role=alert');
$u = c_user('active');
$r1 = c_form($base, '/entrar', ['_csrf' => c_csrf($base, $lj), 'email' => $u['email'], 'password' => 'errada'], $lj);
$r2 = c_form($base, '/entrar', ['_csrf' => c_csrf($base, $lj), 'email' => 'ninguem' . bin2hex(random_bytes(3)) . '@casalivre.test', 'password' => 'errada'], $lj);
$b1 = $box($r1['body'], 'login-error');
$b2 = $box($r2['body'], 'login-error');
t_eq(200, $r1['status'], 'login inválido: continua 200');
t_ok(($b1['text'] ?? '') !== '' && !str_contains($b1['attrs'] ?? ' hidden', 'hidden'), 'login inválido: mensagem visível');
t_eq($b1['text'] ?? null, $b2['text'] ?? null, 'login: conta existente e inexistente com a mesma mensagem genérica (sem enumeração)');
t_ratelimit_reset();
$r = c_form($base, '/entrar', ['_csrf' => c_csrf($base, $lj), 'email' => $u['email'], 'password' => $u['password']], $lj);
t_eq(302, $r['status'], 'login certo: 302, sem página de erro');
db()->prepare('UPDATE users SET session_version = session_version + 1 WHERE id = ?')->execute([$u['id']]);
t_http('GET', $base . '/painel', null, [], $lj);
$b = $box(t_http('GET', $base . '/entrar', null, [], $lj)['body'], 'login-error');
t_eq(e(t('auth.session_expired')), $b['text'] ?? null, 'sessão expirada: auth.session_expired em #login-error');
$b = $box(t_http('GET', $base . '/entrar', null, [], $lj)['body'], 'login-error');
t_eq('', $b['text'] ?? null, 'sessão expirada: aviso só uma vez');

echo "redefinir senha\n";
$rj = tempnam(sys_get_temp_dir(), 'jar');
$raw = bin2hex(random_bytes(16));
UserRepository::setReset((int) $u['id'], $raw, date('Y-m-d H:i:s', time() + 600));
$page = t_http('GET', $base . '/senha/redefinir?token=' . $raw, null, [], $rj)['body'];
$b = $box($page, 'reset-error');
t_ok($b !== null && $b['text'] === '' && str_contains($b['attrs'], ' hidden') && str_contains($b['attrs'], 'role="alert"'), 'GET /senha/redefinir: #reset-error vazio, hidden, role=alert');
preg_match('/name="_csrf" value="([^"]+)"/', $page, $mm);
$rc = $mm[1] ?? '';
$bad = ($raw[0] === 'a' ? 'b' : 'a') . substr($raw, 1);
$r = c_form($base, '/senha/redefinir', ['_csrf' => $rc, 'token' => $bad, 'password' => $pass, 'password_confirm' => $pass], $rj);
t_eq([200, e(t('auth.verify_bad'))], [$r['status'], $box($r['body'], 'reset-error')['text'] ?? null], 'reset token errado: 200 + auth.verify_bad');
$r = c_form($base, '/senha/redefinir', ['_csrf' => $rc, 'token' => $raw, 'password' => 'a', 'password_confirm' => 'a'], $rj);
t_eq(e((string) account_text('short')), $box($r['body'], 'reset-error')['text'] ?? null, 'reset senha curta: mensagem própria');
$r = c_form($base, '/senha/redefinir', ['_csrf' => $rc, 'token' => $raw, 'password' => $pass, 'password_confirm' => $pass . 'z'], $rj);
t_eq(e(t('err.password_mismatch')), $box($r['body'], 'reset-error')['text'] ?? null, 'reset senhas diferentes: mensagem própria');
$r = c_form($base, '/senha/redefinir', ['_csrf' => $rc, 'token' => $raw, 'password' => $pass, 'password_confirm' => $pass], $rj);
t_eq([200, null], [$r['status'], $box($r['body'], 'reset-error')], 'reset ok: página de mensagem, sem caixa de erro');

echo "esqueci a senha\n";
t_ratelimit_reset();
$fj = tempnam(sys_get_temp_dir(), 'jar');
$page = t_http('GET', $base . '/senha/esqueci', null, [], $fj)['body'];
$b = $box($page, 'forgot-error');
t_ok($b !== null && $b['text'] === '' && str_contains($b['attrs'], ' hidden') && str_contains($b['attrs'], 'role="alert"'), 'GET /senha/esqueci: #forgot-error vazio, hidden, role=alert');
t_ok(preg_match('#<p class="ok" id="forgot-ok" role="alert" hidden>#', $page) === 1, 'GET /senha/esqueci: #forgot-ok hidden');
$okBox = static function (string $html): ?string {
    return preg_match('#<p class="ok" id="forgot-ok" role="alert">(.*?)</p>#s', $html, $m) === 1 ? $m[1] : null;
};
$r1 = c_form($base, '/senha/esqueci', ['_csrf' => c_csrf($base, $fj, '/senha/esqueci'), 'email' => $owner['email']], $fj);
$r2 = c_form($base, '/senha/esqueci', ['_csrf' => c_csrf($base, $fj, '/senha/esqueci'), 'email' => 'ninguem' . bin2hex(random_bytes(3)) . '@casalivre.test'], $fj);
t_eq([200, e(t('auth.reset_missing'))], [$r1['status'], $okBox($r1['body'])], 'POST esqueci (conta existe): auth.reset_missing visível');
t_eq(e(t('auth.reset_missing')), $okBox($r2['body']), 'POST esqueci (não existe): mesmo aviso (sem enumeração)');
t_eq('', $box($r1['body'], 'forgot-error')['text'] ?? null, 'POST esqueci: sem erro');

echo "escape\n";
$evil = '<img src=x onerror=alert(1)>"&\'';
db()->prepare("UPDATE translations SET text = ? WHERE tkey = 'err.cpf'")->execute([$evil]);
I18n::refresh();
t_ratelimit_reset();
$b1 = $box(c_form($base, '/conta', ['_csrf' => $csrf, 'cpf' => '1'] + $profile, $jar)['body'], 'account-error');
$b2 = $box($reg(['cpf' => '1'] + $full)['body'], 'register-error');
t_eq([e($evil), e($evil)], [$b1['text'] ?? null, $b2['text'] ?? null], 'mensagem escapada nos dois formulários');
t_ok(!str_contains(($b1['text'] ?? '') . ($b2['text'] ?? ''), '<img'), 'sem tag crua');
db()->prepare("UPDATE translations SET text = ? WHERE tkey = 'auth.verify_bad'")->execute([$evil]);
db()->prepare("UPDATE translations SET text = ? WHERE tkey = 'auth.session_expired'")->execute([$evil]);
I18n::refresh();
$r = c_form($base, '/senha/redefinir', ['_csrf' => $rc, 'token' => $bad, 'password' => $pass, 'password_confirm' => $pass], $rj);
t_eq(e($evil), $box($r['body'], 'reset-error')['text'] ?? null, 'reset: mensagem escapada');
$lj2 = tempnam(sys_get_temp_dir(), 'jar');
t_ratelimit_reset();
c_form($base, '/entrar', ['_csrf' => c_csrf($base, $lj2), 'email' => $u['email'], 'password' => $pass], $lj2);
db()->prepare('UPDATE users SET session_version = session_version + 1 WHERE id = ?')->execute([$u['id']]);
t_http('GET', $base . '/painel', null, [], $lj2);
t_eq(e($evil), $box(t_http('GET', $base . '/entrar', null, [], $lj2)['body'], 'login-error')['text'] ?? null, 'login: mensagem escapada');


db()->prepare("UPDATE translations SET text = ? WHERE tkey = 'auth.reset_missing'")->execute([$evil]);
I18n::refresh();
t_ratelimit_reset();
$r = c_form($base, '/senha/esqueci', ['_csrf' => c_csrf($base, $fj, '/senha/esqueci'), 'email' => 'x@casalivre.test'], $fj);
t_eq(e($evil), $okBox($r['body']), 'esqueci: aviso escapado');

t_done();
