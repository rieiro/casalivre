<?php
// Login sem enumeração por tempo: e-mail inexistente e conta bloqueada fazem um password_verify fictício
// (mesmo algoritmo/opções dos hashes reais, hash guardado em cache). err.deleted/err.inactive só com a senha
// certa. Contagem de falhas (conta e IP) inalterada.
require __DIR__ . '/lib.php';
require __DIR__ . '/char_helpers.php';
require_once CL_ROOT . '/src/Api.php';
require_once CL_ROOT . '/src/LoginRateLimiter.php';
t_temp_db();
t_seed_owner();
$base = t_server();
$jar = tempnam(sys_get_temp_dir(), 'jar');
$csrf = c_csrf($base, $jar);
$reset = static function (): void {
    t_ratelimit_reset();
    db()->exec('DELETE FROM login_rate_limit');
};
$api = static function (string $email, string $password) use ($base, $csrf, $jar): array {
    return c_api('POST', $base, '/api/sessao', ['email' => $email, 'password' => $password], $csrf, $jar);
};
$userRow = static function (int $id): array {
    $st = db()->prepare('SELECT * FROM users WHERE id = ?');
    $st->execute([$id]);
    $r = $st->fetch(PDO::FETCH_ASSOC) ?: [];
    $st->closeCursor();
    return $r;
};
$median = static function (array $xs): float {
    sort($xs);
    return $xs[intdiv(count($xs), 2)];
};

echo "hash fictício\n";
$u = c_user('active');
$reset();
$api('ninguem' . bin2hex(random_bytes(3)) . '@casalivre.test', 'x'); // aquece: gera o hash fictício
$cached = JsonCache::read('login_dummy_hash.json');
$dummy = is_array($cached) ? (string) ($cached['hash'] ?? '') : '';
t_ok($dummy !== '', 'hash fictício guardado em cache');
t_eq(password_get_info($userRow($u['id'])['password_hash'])['algo'], password_get_info($dummy)['algo'], 'mesmo algoritmo dos hashes reais');
t_eq(false, password_needs_rehash($dummy, PasswordPolicy::algo()), 'mesmas opções (password_needs_rehash = false)');

echo "tempo: inexistente x existente x bloqueada\n";
$locked = c_user('active');
db()->prepare('UPDATE users SET failed_logins = 1000 WHERE id = ?')->execute([$locked['id']]);
$t = ['real' => [], 'inexistente' => [], 'bloqueada' => []];
for ($i = 0; $i < 11; $i++) {
    // Intercalado: carga da máquina afeta os três igualmente.
    foreach (['real' => $u['email'], 'inexistente' => 'n' . $i . bin2hex(random_bytes(3)) . '@casalivre.test', 'bloqueada' => $locked['email']] as $k => $email) {
        $reset();
        db()->prepare('UPDATE users SET failed_logins = 0 WHERE id = ?')->execute([$u['id']]);
        $s = microtime(true);
        $api($email, 'errada' . $i);
        $t[$k][] = microtime(true) - $s;
    }
}
$mReal = $median($t['real']);
$mNo = $median($t['inexistente']);
$mLock = $median($t['bloqueada']);
printf("  medianas: real %.4fs, inexistente %.4fs, bloqueada %.4fs\n", $mReal, $mNo, $mLock);
t_ok($mNo >= 0.5 * $mReal, sprintf('inexistente >= 50%% do real (%.4f / %.4f)', $mNo, $mReal));
t_ok($mLock >= 0.5 * $mReal, sprintf('bloqueada >= 50%% do real (%.4f / %.4f)', $mLock, $mReal));
t_ok($mNo <= 2.0 * $mReal, 'inexistente não fica muito mais lento que o real (hash fictício não é gerado a cada requisição)');

echo "contagem de tentativas inalterada\n";
$reset();
db()->prepare('UPDATE users SET failed_logins = 0 WHERE id = ?')->execute([$u['id']]);
$r = $api('ninguem@casalivre.test', 'x');
t_eq(401, $r['status'], 'inexistente: 401 genérico');
t_eq(1, (int) db()->query('SELECT COALESCE(SUM(failures), 0) FROM login_rate_limit')->fetchColumn(), 'inexistente: conta 1 falha no limite por IP');
$api($u['email'], 'errada');
t_eq(2, (int) db()->query('SELECT COALESCE(SUM(failures), 0) FROM login_rate_limit')->fetchColumn(), 'senha errada: +1 no limite por IP');
t_eq(1, (int) $userRow($u['id'])['failed_logins'], 'senha errada: failed_logins +1 só na conta existente');
$before = (int) $userRow($locked['id'])['failed_logins'];
$r = $api($locked['email'], $locked['password']);
t_ok($r['status'] >= 400, 'bloqueada continua recusada (' . $r['status'] . ')');
t_eq(t('err.locked'), $r['json']['error'] ?? null, 'bloqueada: err.locked mesmo com a senha certa (como antes)');
t_eq($before, (int) $userRow($locked['id'])['failed_logins'], 'bloqueada: failed_logins não muda');
$reset();
for ($i = 0; $i < LoginRateLimiter::maxAttempts(); $i++) {
    $r = $api('x' . $i . '@casalivre.test', 'x');
}
t_eq(429, $r['status'], 'limite por IP com e-mails inexistentes: 429 na última tentativa, como antes');
$reset();

echo "apagada/inativa: mensagem específica só com a senha certa\n";
foreach (['apagado' => 'err.deleted', 'inactive' => 'err.inactive'] as $status => $key) {
    $x = c_user($status);
    $reset();
    $r = $api($x['email'], 'errada');
    t_eq([401, t('err.bad_login')], [$r['status'], $r['json']['error'] ?? null], "API $status + senha errada: genérico 401");
    $r = $api($x['email'], $x['password']);
    t_eq([403, account_text($status === 'apagado' ? 'deleted' : 'inactive')], [$r['status'], $r['json']['error'] ?? null], "API $status + senha certa: $key");
    $wj = tempnam(sys_get_temp_dir(), 'jar');
    $r = c_form($base, '/entrar', ['_csrf' => c_csrf($base, $wj), 'email' => $x['email'], 'password' => 'errada'], $wj);
    t_ok(str_contains($r['body'], e(t('err.bad_login'))) && !str_contains($r['body'], e(t($key))), "web $status + senha errada: genérico");
    $r = c_form($base, '/entrar', ['_csrf' => c_csrf($base, $wj), 'email' => $x['email'], 'password' => $x['password']], $wj);
    t_ok(str_contains($r['body'], e(t($key))), "web $status + senha certa: $key");
}

echo "cache sem escrita: hash de reserva, sem password_hash e sem warning\n";
$ro = sys_get_temp_dir() . '/cl-dummy-ro-' . bin2hex(random_bytes(3));
mkdir($ro, 0700);
chmod($ro, 0500);
$logFile = tempnam(sys_get_temp_dir(), 'cllog');
$prevLog = ini_set('error_log', $logFile);
$warnings = [];
set_error_handler(static function (int $no, string $msg) use (&$warnings): bool {
    if (!(error_reporting() & $no)) {
        return true; // suprimido com @: o PHP não registraria
    }
    $warnings[] = $msg;
    return true;
});
PasswordPolicy::$dummyCachePath = $ro . '/login_dummy_hash.json';
$marker = sys_get_temp_dir() . '/casalivre-dummy-hash-' . md5(PasswordPolicy::$dummyCachePath) . '.warned';
@unlink($marker);
PasswordPolicy::$dummyGenerated = 0;
$realHash = (string) $userRow($u['id'])['password_hash'];
$td = [];
$tr = [];
for ($i = 0; $i < 9; $i++) {
    PasswordPolicy::resetDummy(); // cada "requisição" começa sem o estático
    $s = microtime(true);
    PasswordPolicy::dummyVerify('x' . $i);
    $td[] = microtime(true) - $s;
    $s = microtime(true);
    password_verify('x' . $i, $realHash);
    $tr[] = microtime(true) - $s;
}
t_ok(!is_writable($ro) || getmyuid() === 0, 'diretório do cache sem escrita');
t_eq(0, PasswordPolicy::$dummyGenerated, 'sem escrita e opções atuais: nenhum password_hash (usa DUMMY_HASH)');
t_eq(false, password_needs_rehash(PasswordPolicy::DUMMY_HASH, PasswordPolicy::algo()), 'DUMMY_HASH vale para as opções atuais');
t_eq(password_get_info($realHash)['algo'], password_get_info(PasswordPolicy::DUMMY_HASH)['algo'], 'DUMMY_HASH com o algoritmo dos hashes reais');
$ratio = $median($td) / $median($tr);
printf("  sem escrita: fictício %.4fs, real %.4fs (x%.2f)\n", $median($td), $median($tr), $ratio);
t_ok($ratio >= 0.5 && $ratio <= 1.5, sprintf('tempo do fictício ~1x o real (x%.2f)', $ratio));
t_eq([], $warnings, 'nenhum warning do PHP');
t_eq(false, is_file(PasswordPolicy::$dummyCachePath), 'nada gravado');

echo "opções mudaram: gera um, grava no cache e o cache passa a valer\n";
$rw = sys_get_temp_dir() . '/cl-dummy-rw-' . bin2hex(random_bytes(3));
mkdir($rw, 0700);
PasswordPolicy::$options = ['memory_cost' => 32768, 'time_cost' => 3];
PasswordPolicy::$dummyCachePath = $rw . '/login_dummy_hash.json';
PasswordPolicy::$dummyGenerated = 0;
PasswordPolicy::resetDummy();
PasswordPolicy::dummyVerify('x');
t_eq(1, PasswordPolicy::$dummyGenerated, 'DUMMY_HASH obsoleto: gera 1');
$gen = json_decode((string) file_get_contents(PasswordPolicy::$dummyCachePath), true)['hash'] ?? '';
t_ok(str_contains($gen, 'm=32768,t=3'), 'gerado com as opções novas e gravado');
PasswordPolicy::resetDummy();
PasswordPolicy::dummyVerify('y');
t_eq(1, PasswordPolicy::$dummyGenerated, 'próxima requisição usa o cache (não gera de novo)');

echo "opções mudaram e sem escrita: aviso no log uma vez só\n";
PasswordPolicy::$dummyCachePath = $ro . '/login_dummy_hash.json';
@unlink(sys_get_temp_dir() . '/casalivre-dummy-hash-' . md5(PasswordPolicy::$dummyCachePath) . '.warned');
PasswordPolicy::$dummyGenerated = 0;
for ($i = 0; $i < 3; $i++) {
    PasswordPolicy::resetDummy();
    PasswordPolicy::dummyVerify('z');
}
t_eq(3, PasswordPolicy::$dummyGenerated, 'sem cache possível: gera por requisição (caso raro, registrado)');
t_eq(1, substr_count((string) file_get_contents($logFile), 'hash fictício do login'), 'problema registrado uma vez só');
t_eq([], $warnings, 'nenhum warning do PHP');

restore_error_handler();
ini_set('error_log', (string) $prevLog);
PasswordPolicy::$options = [];
PasswordPolicy::$dummyCachePath = null;
PasswordPolicy::resetDummy();
chmod($ro, 0700);
@exec('rm -rf ' . escapeshellarg($ro) . ' ' . escapeshellarg($rw));
@unlink($logFile);
@unlink(sys_get_temp_dir() . '/casalivre-dummy-hash-' . md5($ro . '/login_dummy_hash.json') . '.warned');

t_done();
