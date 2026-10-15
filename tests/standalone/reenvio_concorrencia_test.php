<?php
// PR #88 (Security): conferir o limite, enfileirar e contar sob o mesmo flock (IpRateLimiter::reserve).
// N processos em paralelo partindo juntos: no máximo RESEND_MAX ações / e-mails de ativação.
require __DIR__ . '/lib.php';
require __DIR__ . '/char_helpers.php';
require_once CL_ROOT . '/src/Db.php';
require_once CL_ROOT . '/src/UserRepository.php';
require_once CL_ROOT . '/src/helpers.php';
require_once CL_ROOT . '/src/Acoes/LoginAttempt.php';

const N = 12;
$worker = __DIR__ . '/reenvio_concorrencia_worker.php';
/** Sobe N filhos que esperam $go; cria $go (largada) e espera todos. Devolve as saídas (stdout). */
$burst = static function (array $args): array {
    $go = sys_get_temp_dir() . '/cl-go-' . bin2hex(random_bytes(4));
    $procs = [];
    for ($i = 0; $i < N; $i++) {
        $p = proc_open(array_merge([PHP_BINARY, __DIR__ . '/reenvio_concorrencia_worker.php', $args[0], $go], array_slice($args, 1)), [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, CL_ROOT);
        $procs[] = [$p, $pipes];
    }
    usleep(300000);
    touch($go);
    $out = [];
    foreach ($procs as [$p, $pipes]) {
        $out[] = trim(stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]));
        proc_close($p);
    }
    @unlink($go);
    return $out;
};

echo "IpRateLimiter::reserve: " . N . " processos em paralelo, limite 3\n";
$dir = sys_get_temp_dir() . '/cl-rl-conc-' . bin2hex(random_bytes(4));
$saida = tempnam(sys_get_temp_dir(), 'conc');
$burst(['reserve', $dir, $saida]);
t_eq(3, substr_count((string) file_get_contents($saida), '1'), 'no máximo 3 ações dentro da janela (as outras ' . (N - 3) . ' viram "não cabe")');
IpRateLimiter::$dir = $dir;
t_ok(IpRateLimiter::peek('conc', 3, 3600, 'conta:x') > 0, 'balde em 3/3: peek bloqueia');
IpRateLimiter::$dir = (string) getenv('T_RATELIMIT_DIR');
@exec('rm -rf ' . escapeshellarg($dir));
@unlink($saida);

echo "login: " . N . " logins paralelos com a senha certa (conta pendente) enfileiram no máximo " . LoginAttempt::RESEND_MAX . "\n";
t_temp_db();
putenv('SMTP_HOST=smtp.invalid');
foreach (['APP_URL', 'QUEUE_DRIVER'] as $k) {
    putenv($k);
}
$activations = static function (string $email): int {
    $st = db()->prepare("SELECT COUNT(*) FROM mail_queue WHERE type = 'activation' AND recipient = ?");
    $st->execute([$email]);
    return (int) $st->fetchColumn();
};
$u = c_user('pending');
$out = $burst(['login', $u['email'], $u['password']]);
t_eq(array_fill(0, N, 'pending'), $out, 'todos os ' . N . ' logins respondem pending (mesma resposta, sem erro)');
$n = $activations($u['email']);
t_ok($n >= 1 && $n <= LoginAttempt::RESEND_MAX, "e-mails de ativação enfileirados: $n (1..RESEND_MAX)");
// Rajadas seguidas, com o cooldown da fila envelhecido entre elas (sem worker o token segue vazio): o total para em RESEND_MAX.
for ($k = 0; $k < 4; $k++) {
    db()->exec("UPDATE mail_queue SET created_at = '2000-01-01 00:00:00'");
    $burst(['login', $u['email'], $u['password']]);
}
t_eq(LoginAttempt::RESEND_MAX, $activations($u['email']), 'depois de 5 rajadas paralelas: exatamente RESEND_MAX e-mails na janela');

t_done();
