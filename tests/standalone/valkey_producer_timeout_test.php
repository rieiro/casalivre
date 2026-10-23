<?php
// Cleanup sprint #158 (Arquitetura #160 follow-up): a queue producer in a web request (MailQueues/ImageQueues::make
// with QUEUE_DRIVER=valkey) connected with phpredis defaults, so with Valkey frozen an FPM worker hung for over 20 s.
// Producers outside the CLI now use Valkey::PRODUCER_TIMEOUT (0.5 s, connect + read); the CLI (queue workers, cron)
// keeps the defaults. A "frozen" Valkey is a unix socket that accepts (listen backlog) but never answers.
declare(strict_types=1);
require __DIR__ . '/lib.php';
require_once CL_ROOT . '/src/Db.php';
require_once CL_ROOT . '/src/MailQueue.php';
require_once CL_ROOT . '/src/ImageQueue.php';

echo "timeout per SAPI\n";
t_eq(0.5, Valkey::PRODUCER_TIMEOUT, 'producer timeout is 0.5 s');
t_eq(0.5, Valkey::queueTimeout('fpm-fcgi'), 'FPM (web request): 0.5 s');
t_eq(0.0, Valkey::queueTimeout('cli'), 'CLI (queue worker, cron): phpredis defaults');
t_eq(0.0, Valkey::queueTimeout(), 'this process (CLI): defaults');
foreach (['src/MailQueue.php', 'src/ImageQueue.php'] as $f) {
    t_ok(str_contains((string) file_get_contents(CL_ROOT . '/' . $f), 'Valkey::connect(Valkey::queueTimeout())'), "$f: the factory connects with queueTimeout()");
}

if (!extension_loaded('redis')) {
    echo "  SKIPPED frozen-Valkey part (no phpredis)\n";
    t_done();
}
echo "frozen Valkey\n";
$secret = (string) tempnam(sys_get_temp_dir(), 'vkpw');
file_put_contents($secret, 'x');
$hung = sys_get_temp_dir() . '/frozen-valkey-' . bin2hex(random_bytes(3)) . '.sock';
$srv = stream_socket_server('unix://' . $hung, $errno, $errstr);
$saved = [getenv('VALKEY_SOCKET'), getenv('VALKEY_USER'), getenv('VALKEY_PASSWORD_FILE')];
putenv('VALKEY_SOCKET=' . $hung);
putenv('VALKEY_USER=cl_app');
putenv('VALKEY_PASSWORD_FILE=' . $secret);
$t0 = microtime(true);
$err = null;
try {
    Valkey::connect(Valkey::queueTimeout('fpm-fcgi'));
} catch (Throwable $e) {
    $err = $e;
}
$ms = (int) ((microtime(true) - $t0) * 1000);
t_ok($err instanceof RedisException, 'web producer: frozen Valkey throws (' . ($err === null ? 'nothing' : $err::class) . ')');
t_ok($ms >= 400 && $ms < 1500, "web producer: gives up after ~0.5 s ($ms ms)");
// The CLI default really is the long one: bounded here by default_socket_timeout = 2 s so the test stays short.
$prev = ini_set('default_socket_timeout', '2');
$t0 = microtime(true);
try {
    Valkey::connect(Valkey::queueTimeout('cli'));
} catch (Throwable) {
}
$ms = (int) ((microtime(true) - $t0) * 1000);
ini_set('default_socket_timeout', (string) $prev);
t_ok($ms >= 1800, "worker/CLI: keeps the long default (waited $ms ms with default_socket_timeout=2)");
fclose($srv);
@unlink($hung);
@unlink($secret);
foreach (['VALKEY_SOCKET', 'VALKEY_USER', 'VALKEY_PASSWORD_FILE'] as $i => $k) {
    $saved[$i] === false ? putenv($k) : putenv($k . '=' . $saved[$i]);
}
t_done();
