<?php
// Caracterização (PR 3 do refactor): limite de login por IP (429) e bloqueio de conta, web x API.
// Registra o comportamento ATUAL (SNAPSHOT intencional); deve passar igual antes e depois do refactor.
// Limite por IP: LoginRateLimiter (tabela login_rate_limit, padrão 5 falhas, 15 min). Ao atingir, o pipeline
// (RateLimit) responde 429 + Retry-After em /entrar e /api/sessao ANTES da checagem de senha.
// Bloqueio de conta: AccountFlow::checkLogin (failed_logins >= login_max_failures, padrão 5).
require __DIR__ . '/lib.php';
require __DIR__ . '/char_helpers.php';
t_temp_db();
t_seed_owner();
$act = c_activity_file();
$base = t_server();
$reset = static function (): void {
    db()->exec('DELETE FROM login_rate_limit');
    t_ratelimit_reset();
};
$ipRows = static fn (): int => (int) db()->query('SELECT COUNT(*) FROM login_rate_limit')->fetchColumn();
$failed = static fn (int $id): int => (int) db()->query('SELECT failed_logins FROM users WHERE id = ' . $id)->fetchColumn();
$web = static fn (string $jar, string $csrf, string $email, string $pw): array => c_form($base, '/entrar', ['_csrf' => $csrf, 'email' => $email, 'password' => $pw], $jar);
$api = static fn (string $jar, string $csrf, string $email, string $pw): array => c_api('POST', $base, '/api/sessao', ['email' => $email, 'password' => $pw], $csrf, $jar);

foreach (['web' => $web, 'api' => $api] as $canal => $call) {
    $reset();
    $u = c_user('active');
    $jar = tempnam(sys_get_temp_dir(), 'jar');
    $csrf = c_csrf($base, $jar);
    $badStatus = $canal === 'web' ? 200 : 401;

    echo "$canal: limite por IP (e-mails inexistentes, sem bloqueio de conta)\n";
    $m = c_mark($act);
    for ($i = 1; $i <= 4; $i++) {
        $r = $call($jar, $csrf, "ninguem$i@casalivre.test", 'errada');
        t_eq($badStatus, $r['status'], "$canal: falha $i responde $badStatus");
    }
    $r = $call($jar, $csrf, 'ninguem5@casalivre.test', 'errada');
    t_eq(429, $r['status'], "$canal: 5ª falha (maxAttempts) já responde 429 pelo handler");
    t_eq(array_fill(0, 5, ActivityLog::LOGIN_FAIL), c_codes($act, $m), "$canal: 5 LOGIN_FAIL gravados");
    $m = c_mark($act);
    $r = $call($jar, $csrf, $u['email'], $u['password']);
    t_eq(429, $r['status'], "$canal: senha certa durante o limite -> 429");
    t_ok((bool) preg_match('/^Retry-After:\s*900\b/mi', $r['headers']), "$canal: 429 do pipeline com Retry-After: 900");
    t_eq([], c_codes($act, $m), "$canal: bloqueado no pipeline não grava activity_log");
    t_eq(0, $failed($u['id']), "$canal: senha nem foi checada (failed_logins = 0)");
    $r = t_http('GET', $base . '/api/users/eu', null, [], $jar);
    t_eq(401, $r['status'], "$canal: sem sessão após o 429");

    echo "$canal: depois do reset\n";
    $reset();
    $m = c_mark($act);
    $r = $call($jar, $csrf, 'ninguem@casalivre.test', 'errada');
    t_eq(1, $ipRows(), "$canal: uma falha cria a linha do IP");
    $r = $call($jar, $csrf, $u['email'], $u['password']);
    t_eq($canal === 'web' ? 302 : 200, $r['status'], "$canal: login ok após reset");
    t_eq([ActivityLog::LOGIN_FAIL, ActivityLog::LOGIN_OK], c_codes($act, $m), "$canal: LOGIN_FAIL + LOGIN_OK");
    t_eq(0, $ipRows(), "$canal: login ok limpa o contador do IP (clear)");

    echo "$canal: bloqueio de conta\n";
    $reset();
    $v = c_user('active');
    $jar = tempnam(sys_get_temp_dir(), 'jar');
    $csrf = c_csrf($base, $jar);
    $m = c_mark($act);
    for ($i = 1; $i <= 4; $i++) {
        $r = $call($jar, $csrf, $v['email'], 'errada');
        t_eq($badStatus, $r['status'], "$canal: senha errada $i responde $badStatus");
    }
    $r = $call($jar, $csrf, $v['email'], 'errada');
    t_eq($canal === 'web' ? 200 : 423, $r['status'], "$canal: 5ª senha errada bloqueia a conta (locked, não 429)");
    $logs = c_logs($act, $m);
    t_eq(array_merge(array_fill(0, 4, ActivityLog::LOGIN_FAIL), [ActivityLog::LOGIN_LOCKED]), array_column($logs, 'code'), "$canal: 4 LOGIN_FAIL + LOGIN_LOCKED");
    t_eq(5, $logs[4]['params']['tentativas'] ?? null, "$canal: LOGIN_LOCKED com tentativas=5");
    t_eq(5, $failed($v['id']), "$canal: failed_logins = 5");
    $r = $call($jar, $csrf, $v['email'], $v['password']);
    t_eq(429, $r['status'], "$canal: o bloqueio também contou no IP -> senha certa recebe 429");
    db()->exec('DELETE FROM login_rate_limit');
    $m = c_mark($act);
    $r = $call($jar, $csrf, $v['email'], $v['password']);
    t_eq($canal === 'web' ? 200 : 423, $r['status'], "$canal: sem limite de IP, conta segue bloqueada com a senha certa");
    t_eq([ActivityLog::LOGIN_LOCKED], c_codes($act, $m), "$canal: LOGIN_LOCKED (sem tentativas)");
    t_eq(0, $ipRows(), "$canal: bloqueio de conta já atingido não conta no IP");
}

// Paridade (PR A): cadastro e esqueci a senha usam um balde por IP somando web e API (como fotos e avaliação).
// O ApiRateLimit roda antes do CSRF, então o corpo não importa para a contagem.
echo "limite somado web+API: esqueci a senha (5/15 min) e cadastro (10/h)\n";
$post = static fn (string $path): int => str_starts_with($path, '/api/')
    ? t_http('POST', $base . $path, '{}', ['Content-Type: application/json'])['status']
    : t_http('POST', $base . $path, '', ['Content-Type: application/x-www-form-urlencoded'])['status'];
foreach ([
    'esqueci a senha' => ['/senha/esqueci', '/api/auth/senha/esqueci', 5],
    'cadastro' => ['/cadastro', '/api/users', 10],
] as $nome => [$webPath, $apiPath, $max]) {
    foreach (['web primeiro' => [$webPath, $apiPath], 'API primeiro' => [$apiPath, $webPath]] as $ordem => [$a, $b]) {
        $reset();
        $codes = [];
        for ($i = 0; $i < $max; $i++) {
            $codes[] = $post($i % 2 === 0 ? $a : $b);
        }
        t_ok(!in_array(429, $codes, true), "$nome ($ordem): $max pedidos alternando web/API passam (" . implode(',', $codes) . ')');
        t_eq(429, $post($a), "$nome ($ordem): o seguinte em $a dá 429 (balde somado)");
        t_eq(429, $post($b), "$nome ($ordem): e em $b também");
    }
}

t_done();
