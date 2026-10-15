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

echo "escape\n";
$evil = '<img src=x onerror=alert(1)>"&\'';
db()->prepare("UPDATE translations SET text = ? WHERE tkey = 'err.cpf'")->execute([$evil]);
I18n::refresh();
t_ratelimit_reset();
$b1 = $box(c_form($base, '/conta', ['_csrf' => $csrf, 'cpf' => '1'] + $profile, $jar)['body'], 'account-error');
$b2 = $box($reg(['cpf' => '1'] + $full)['body'], 'register-error');
t_eq([e($evil), e($evil)], [$b1['text'] ?? null, $b2['text'] ?? null], 'mensagem escapada nos dois formulários');
t_ok(!str_contains(($b1['text'] ?? '') . ($b2['text'] ?? ''), '<img'), 'sem tag crua');

t_done();
