<?php
// P0-4: dev-env Valkey files from ops/dev-env/services.sh, run on a temp CL_HOME. The cl_admin secret
// lives in $CL_HOME/admin (0700, file 0400), which up-bwrap.sh never binds into the runtime, so the app
// (same uid in dev) cannot read it (#112 L2); an old etc/valkey-admin.secret is moved there. Also: app
// secret 0440, aclfile 0600, valkey.sock 0660 in the template and the runit service, queue-worker service.
declare(strict_types=1);
require __DIR__ . '/lib.php';

$dev = CL_ROOT . '/ops/dev-env';
$has = static fn (string $bin): bool => trim((string) shell_exec('command -v ' . $bin . ' 2>/dev/null')) !== '';
if (!is_executable('/bin/bash') || !$has('sha256sum') || !$has('od')) {
    echo "  SKIPPED (needs bash, od and sha256sum)\n";
    t_done();
}
if (function_exists('posix_getuid') && posix_getuid() === 0) {
    echo "  SKIPPED (services.sh refuses root)\n";
    t_done();
}
$home = sys_get_temp_dir() . '/cl-p04-' . bin2hex(random_bytes(4));
register_shutdown_function(static fn () => @exec('chmod -R u+w ' . escapeshellarg($home) . ' 2>/dev/null; rm -rf ' . escapeshellarg($home)));
mkdir($home . '/etc', 0700, true);
file_put_contents($home . '/etc/valkey-admin.secret', 'oldadminsecret');
$run = static function () use ($dev, $home): int {
    exec('env -i PATH=/usr/bin:/bin HOME=' . escapeshellarg($home) . ' CL_HOME=' . escapeshellarg($home)
        . ' /bin/bash ' . escapeshellarg($dev . '/services.sh') . ' 2>&1', $out, $code);
    return $code;
};
$mode = static fn (string $p): string => is_file($p) || is_dir($p) ? sprintf('%o', fileperms($p) & 0777) : 'missing';

echo "services.sh\n";
t_eq(0, $run(), 'services.sh ok');
t_eq(0, $run(), 'services.sh idempotent');
$adminFile = "$home/admin/valkey-admin.secret";
t_eq('700', $mode("$home/admin"), 'admin/ is 0700');
t_eq('400', $mode($adminFile), 'admin secret is 0400');
t_ok(!file_exists("$home/etc/valkey-admin.secret"), 'no admin secret in etc/ (bound into the runtime)');
t_eq('oldadminsecret', @file_get_contents($adminFile), 'old etc/ admin secret moved, not regenerated');
t_eq('440', $mode("$home/etc/valkey-app.secret"), 'app secret 0440');
t_eq('600', $mode("$home/etc/valkey.acl"), 'aclfile 0600');
$acl = (string) @file_get_contents("$home/etc/valkey.acl");
t_ok(str_contains($acl, '#' . hash('sha256', 'oldadminsecret')), 'cl_admin ACL uses the admin secret hash');
t_ok(str_contains($acl, 'user cl_app on') && str_contains($acl, 'resetkeys ~cl:*'), 'cl_app limited to ~cl:*');
$appLine = (string) (array_values(preg_grep('/^user cl_app /', explode("\n", $acl)) ?: [])[0] ?? '');
foreach (['+rename', '+hlen', '+hexists', '+hincrby', '-@all'] as $cmd) {
    t_ok(in_array($cmd, explode(' ', $appLine), true), "cl_app ACL has $cmd (ADR 001 §11)");
}
foreach (['+eval', '+evalsha', '+script', '+keys', '+scan', '+xadd', '+@all'] as $cmd) {
    t_ok(!in_array($cmd, explode(' ', $appLine), true), "cl_app ACL has no $cmd");
}
foreach (['dev-env.env', 'php-fpm-env.conf'] as $f) {
    $c = (string) @file_get_contents("$home/etc/$f");
    t_ok(!str_contains($c, 'admin'), "$f does not mention the admin secret");
    t_ok(str_contains($c, 'valkey-app.secret') && str_contains($c, 'QUEUE_DRIVER'), "$f: app secret path and QUEUE_DRIVER");
}
t_ok(is_file("$home/sv/queue-worker/run"), 'queue-worker runit service installed');

echo "runtime binds and socket mode\n";
$up = (string) file_get_contents("$dev/up-bwrap.sh");
t_ok(preg_match('/for d in run etc log sv data; do args\+=\(--bind/', $up) === 1, 'up-bwrap.sh binds only run etc log sv data');
t_ok(!preg_match('/--(ro-)?bind[^\n]*(admin|"\$CL_HOME" )/', $up), 'up-bwrap.sh binds neither admin/ nor all of CL_HOME');
t_ok(str_contains((string) file_get_contents("$dev/templates/valkey.conf.in"), "unixsocketperm 660\n"), 'valkey.conf.in: unixsocketperm 660');
t_ok(str_contains((string) file_get_contents("$dev/sv/valkey/run"), '--unixsocketperm 660'), 'sv/valkey/run: --unixsocketperm 660');
t_ok(str_contains((string) file_get_contents("$dev/valkey-acl-check.sh"), 'env_alive "${bw:-}" "${init:-}"'), 'valkey-acl-check.sh uses env_alive');
t_done();
