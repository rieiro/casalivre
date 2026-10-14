<?php
// Produção (ou APP_ENV ausente) sem SMTP_HOST: cadastro, reenvio e "esqueci a senha" não quebram,
// o link não vai para lugar nenhum (mail-dev.log, activity_log, log da API, resposta) e o error_log
// recebe só o marcador 'mail_not_configured'.
require __DIR__ . '/lib.php';
t_temp_db();
$owner = t_seed_owner();
db()->exec("INSERT OR IGNORE INTO ceps (cep, street, neighborhood, city, uf) VALUES ('88015200', 'Rua Teste', 'Centro', 'Florianópolis', 'SC')");
$hideInResponse = function_exists('expose_account_links'); // PR #15 (link fora da resposta) já mesclado?

foreach (['ausente' => null, 'production' => 'production'] as $label => $env) {
    echo "APP_ENV $label, sem SMTP\n";
    $start = microtime(true);
    $errLog = tempnam(sys_get_temp_dir(), 'phperr');
    $port = random_int(20000, 40000);
    $envv = getenv();
    foreach (['APP_ENV', 'SMTP_HOST', 'SMTP_PORT', 'SMTP_USER', 'SMTP_PASS', 'SMTP_FROM', 'SMTP_SECURE'] as $k) {
        unset($envv[$k]);
    }
    if ($env !== null) {
        $envv['APP_ENV'] = $env;
    }
    $envv['APP_URL'] = 'https://casalivre.example';
    $envv['LOG_SALT'] = 'sal-de-teste-0123456789abcdef0123456789'; // evita o aviso de LOG_SALT no error_log
    $envv['GEO_SALT'] = str_repeat('g', 64); // obrigatório fora de dev/local (GeoSalt::enforce)
    $proc = proc_open([PHP_BINARY, '-d', 'error_log=' . $errLog, '-d', 'log_errors=1', '-S', '127.0.0.1:' . $port, '-t', CL_ROOT . '/public', CL_ROOT . '/public/index.php'],
        [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, CL_ROOT, $envv);
    $base = 'http://127.0.0.1:' . $port;
    for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $port); $i++) {
        usleep(100000);
    }
    $csrf = static function (string $jar, string $page = '/entrar') use ($base): string {
        $r = t_http('GET', $base . $page, null, [], $jar);
        preg_match('/name="_csrf" value="([^"]+)"/', $r['body'], $m);
        return $m[1] ?? '';
    };
    $bodies = [];
    $pass = 'Casa#' . random_int(100000, 999999) . 'xY';
    $user = ['name' => 'Sem Smtp', 'cpf' => '52998224725', 'cep' => '88015-200', 'address_number' => '1',
        'address_complement' => '', 'phone' => '(48) 99999-0000', 'password' => $pass, 'password_confirm' => $pass];

    // 1) cadastro pela API
    $jar = tempnam(sys_get_temp_dir(), 'jar');
    $e1 = 'api' . bin2hex(random_bytes(3)) . '@casalivre.test';
    $r = t_http('POST', $base . '/api/users', json_encode($user + ['email' => $e1]), ['Content-Type: application/json', 'X-CSRF-Token: ' . $csrf($jar)], $jar);
    t_eq(201, $r['status'], "$label: POST /api/users -> 201");
    $bodies[] = $r['body'];

    // 2) cadastro pela web
    $wjar = tempnam(sys_get_temp_dir(), 'jar');
    $e2 = 'web' . bin2hex(random_bytes(3)) . '@casalivre.test';
    $r = t_http('POST', $base . '/cadastro', http_build_query(['_csrf' => $csrf($wjar, '/cadastro'), 'email' => $e2] + $user), ['Content-Type: application/x-www-form-urlencoded'], $wjar);
    t_ok($r['status'] < 500, "$label: POST /cadastro sem erro ({$r['status']})");
    $bodies[] = $r['body'];

    // 3) reenvio: login com conta pendente e link expirado gera e "envia" um novo
    db()->prepare("UPDATE users SET activation_expires_at = '2000-01-01 00:00:00' WHERE email = ?")->execute([$e2]);
    $r = t_http('POST', $base . '/entrar', http_build_query(['_csrf' => $csrf($wjar), 'email' => $e2, 'password' => $pass]), ['Content-Type: application/x-www-form-urlencoded'], $wjar);
    t_ok($r['status'] < 500, "$label: login pendente (reenvio) sem erro ({$r['status']})");
    $bodies[] = $r['body'];

    // 4) esqueci a senha (web e API) para conta ativa
    $r = t_http('POST', $base . '/senha/esqueci', http_build_query(['_csrf' => $csrf($wjar, '/senha/esqueci'), 'email' => $owner['email']]), ['Content-Type: application/x-www-form-urlencoded'], $wjar);
    t_ok($r['status'] < 500, "$label: POST /senha/esqueci sem erro ({$r['status']})");
    $bodies[] = $r['body'];
    $r = t_http('POST', $base . '/api/auth/senha/esqueci', json_encode(['email' => $owner['email']]), ['Content-Type: application/json', 'X-CSRF-Token: ' . $csrf($jar)], $jar);
    t_eq(200, $r['status'], "$label: POST /api/auth/senha/esqueci -> 200");
    t_eq(['ok' => true], json_decode($r['body'], true), "$label: resposta genérica no esqueci a senha (API)");
    $bodies[] = $r['body'];
    usleep(300000);
    proc_terminate($proc);

    // tokens gerados nesta rodada
    $st = db()->prepare('SELECT activation_token, reset_token FROM users WHERE email IN (?, ?, ?)');
    $st->execute([$e1, $e2, $owner['email']]);
    $tokens = array_values(array_filter(array_merge(...array_map('array_values', $st->fetchAll(PDO::FETCH_ASSOC)))));
    t_eq([], $tokens, "$label: nenhum token gerado (fila não recebe nada sem SMTP)");
    t_eq(0, (int) db()->query('SELECT COUNT(*) FROM mail_queue')->fetchColumn(), "$label: mail_queue vazia");

    // nenhum arquivo em storage/ alterado nesta rodada contém token ou link
    $hits = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(CL_ROOT . '/storage', FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if (!$f->isFile() || $f->getMTime() < (int) $start - 1) {
            continue;
        }
        $data = (string) @file_get_contents($f->getPathname());
        foreach ($tokens as $t) {
            if (str_contains($data, $t)) {
                $hits[] = substr($f->getPathname(), strlen(CL_ROOT) + 1);
            }
        }
        if (str_contains($data, '/verificar?token=') || str_contains($data, '/senha/redefinir?token=')) {
            $hits[] = substr($f->getPathname(), strlen(CL_ROOT) + 1) . ' (link)';
        }
    }
    t_eq([], array_values(array_unique($hits)), "$label: token/link ausente de storage/ (activity_log, logs da API, error.log)");
    t_ok(!is_file(CL_ROOT . '/storage/logs/mail-dev.log') || filemtime(CL_ROOT . '/storage/logs/mail-dev.log') < (int) $start - 1, "$label: mail-dev.log não escrito");

    // error_log: só o marcador, uma vez por tentativa de envio
    $lines = array_values(array_filter(array_map('trim', file($errLog) ?: [])));
    $marks = array_filter($lines, static fn ($l) => preg_match('/^(\[[^\]]+\] )?mail_not_configured$/', $l) === 1);
    t_ok(count($marks) >= 4, "$label: 'mail_not_configured' no error_log (" . count($marks) . 'x)');
    $txt = implode("\n", $lines);
    t_ok(!str_contains($txt, '@') && !str_contains($txt, 'token') && !array_filter($tokens, static fn ($t) => str_contains($txt, $t)), "$label: error_log sem e-mail, link ou token");
    t_eq(count($lines), count($marks), "$label: nada além do marcador no error_log");
    @unlink($errLog);

    // resposta ao usuário
    $all = implode("\n", $bodies);
    if ($hideInResponse) {
        t_ok(!array_filter($tokens, static fn ($t) => str_contains($all, $t)), "$label: token/link fora das respostas");
    } else {
        echo "  (link na resposta: coberto pelo PR #15, ainda não mesclado nesta base)\n";
    }
}
t_done();
