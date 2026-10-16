<?php
// P0-5: ops/dev-env/up-incus.sh against a stub `incus` (records every call; no real Incus is touched),
// plus the up.sh dispatch and down.sh on the Incus path. Checks: image packed from $CL_ROOTFS (same
// rootfs, no build), mounts = repo + CL_HOME/{run,etc,log,sv,data} only (never admin/ or all of CL_HOME),
// one proxy device on 127.0.0.1:CL_PORT_HTTP and no other port, unprivileged, 1:1 idmap, runsvdir init,
// PG/Valkey socket-only configs, idempotency.
declare(strict_types=1);
require __DIR__ . '/lib.php';

$dev = realpath(CL_ROOT . '/ops/dev-env');
$has = static fn (string $bin): bool => trim((string) shell_exec('command -v ' . $bin . ' 2>/dev/null')) !== '';
if (!is_executable('/bin/bash') || !$has('sha256sum') || !$has('tar') || (function_exists('posix_getuid') && posix_getuid() === 0)) {
    echo "  SKIPPED (needs bash, sha256sum, tar and a non-root uid)\n";
    t_done();
}
$base = sys_get_temp_dir() . '/cl-p05-' . bin2hex(random_bytes(4));
register_shutdown_function(static fn () => @exec('chmod -R u+w ' . escapeshellarg($base) . ' 2>/dev/null; rm -rf ' . escapeshellarg($base)));
$home = "$base/home";
$bin = "$base/bin";
$log = "$base/incus.log";
$state = "$base/state";
mkdir("$home/rootfs/usr/bin", 0700, true);
mkdir($bin, 0700, true);
file_put_contents("$home/rootfs/usr/bin/marker", 'rootfs');
touch("$home/.provisioned");
mkdir("$home/data/pg18", 0700, true);
file_put_contents("$home/data/pg18/postmaster.pid", "999999\n");
// Stub incus: logs argv (one line, args separated by " | "); `list` prints the state file (status).
file_put_contents("$bin/incus", <<<SH
#!/bin/bash
printf '%s' "\$1" >>'$log'; shift; for a in "\$@"; do printf ' | %s' "\$a" >>'$log'; done; echo >>'$log'
case "\$(sed -n '\$p' '$log' | cut -d' ' -f1)" in
list) cat '$state' 2>/dev/null ;;
image) [ "\$1" != info ] || [ -f '$state.img' ] || exit 1; [ "\$1" != import ] || { tar -tzf "\$3" | grep -q marker && touch '$state.img'; } ;;
start) echo RUNNING >'$state' ;;
exec) c="\${@: -1}"; case \$c in *rolsuper*) echo falsetrue ;; *"FROM users"*) echo 1 ;; esac ;;
stop) echo STOPPED >'$state' ;;
esac
exit 0
SH);
// Stub envsubst (the box may not have gettext): only the three listed variables.
file_put_contents("$bin/envsubst", "#!/bin/bash\nsed -e \"s|\\\${CL_HOME}|\$CL_HOME|g\" -e \"s|\\\${CL_REPO}|\$CL_REPO|g\" -e \"s|\\\${CL_PORT_HTTP}|\$CL_PORT_HTTP|g\"\n");
chmod("$bin/incus", 0700);
chmod("$bin/envsubst", 0700);
$run = static function (string $cmd, array $env = []) use ($bin, $home, $base): array {
    $e = 'env -i PATH=' . escapeshellarg("$bin:/usr/bin:/bin") . ' HOME=' . escapeshellarg($base) . ' CL_HOME=' . escapeshellarg($home) . ' CL_PORT_HTTP=34567'
        . ' CL_SUBUID_FILE=' . escapeshellarg("$base/subuid") . ' CL_SUBGID_FILE=' . escapeshellarg("$base/subgid");
    foreach ($env as $k => $v) {
        $e .= ' ' . $k . '=' . escapeshellarg($v);
    }
    exec("$e $cmd 2>&1", $out, $code);
    return [$code, implode("\n", $out)];
};
file_put_contents("$base/subuid", "root:100000:65536\nroot:" . posix_getuid() . ":1\n");
file_put_contents("$base/subgid", "root:100000:65536\nroot:" . posix_getgid() . ":1\n");
$calls = static fn (): array => file_exists($log) ? file($log, FILE_IGNORE_NEW_LINES) : [];

echo "subuid/subgid preflight\n";
file_put_contents("$base/subuid", "root:100000:65536\n");
[$code, $out] = $run("/bin/bash $dev/up-incus.sh");
t_ok($code !== 0 && str_contains($out, 'root:' . posix_getuid() . ':1') && str_contains($out, 'Fix (as root)'), 'missing root:<uid>:1 in subuid -> dies with the fix');
t_eq([], $calls(), 'nothing sent to incus before the preflight passes');
file_put_contents("$base/subuid", "root:100000:65536\nroot:" . posix_getuid() . ":1\n");
file_put_contents("$base/subgid", "root:100000:65536\n");
[$code, $out] = $run("/bin/bash $dev/up-incus.sh");
t_ok($code !== 0 && str_contains($out, 'root:' . posix_getgid() . ':1'), 'missing root:<gid>:1 in subgid -> dies');
file_put_contents("$base/subgid", "root:100000:65536\nroot:" . posix_getgid() . ":1\n");

echo "up-incus.sh\n";
[$code, $out] = $run("/bin/bash $dev/up-incus.sh");
t_eq(0, $code, 'up-incus.sh ok' . ($code ? ": $out" : ''));
t_ok(!file_exists("$home/data/pg18/postmaster.pid"), 'stale postmaster.pid removed (shared prestart)');
t_ok(str_contains($out, 'removing') && str_contains($out, 'postmaster.pid'), 'removal is logged');
$c = $calls();
$all = implode("\n", $c);
$import = array_values(array_filter($c, static fn ($l) => str_starts_with($l, 'image | import')));
t_eq(1, count($import), 'one image import');
t_ok(is_file("$state.img"), 'imported rootfs tarball is $CL_ROOTFS (marker file inside)');
t_ok(!str_contains($all, 'launch') && !str_contains($all, 'remote') && !str_contains($all, 'images:'), 'no remote image, no separate build');
$init = (string) (array_values(array_filter($c, static fn ($l) => str_starts_with($l, 'init | ')))[0] ?? '');
t_ok(str_contains($init, 'security.privileged=false'), 'unprivileged container');
t_ok(str_contains($init, ' | -s | default | '), 'own root disk on pool "default" (init -s), not the profile');
$uid = (string) posix_getuid();
t_ok(str_contains($init, "raw.idmap=uid $uid $uid"), 'idmap 1:1 for this uid');
t_ok(str_contains($all, "lxc.init.cmd = /usr/bin/runsvdir -P $home/sv") && str_contains($all, "lxc.init.uid = $uid"), 'init: runsvdir -P CL_HOME/sv as this uid');
$disks = array_values(array_filter($c, static fn ($l) => str_contains($l, ' | disk | ')));
$paths = array_map(static fn ($l) => preg_match('/path=(\S+)/', $l, $m) ? $m[1] : '', $disks);
sort($paths);
$want = [realpath(CL_ROOT), "$home/data", "$home/etc", "$home/log", "$home/run", "$home/sv"];
sort($want);
t_eq($want, $paths, 'mounts: repo + CL_HOME/{run,etc,log,sv,data} only');
t_ok(!str_contains($all, "$home/admin") && !preg_match('#source=' . preg_quote($home, '#') . '( |$)#m', $all), 'admin/ and the whole CL_HOME never mounted');
$proxies = array_values(array_filter($c, static fn ($l) => str_contains($l, ' | proxy | ')));
t_eq(1, count($proxies), 'exactly one proxy device');
t_ok(str_contains($proxies[0] ?? '', 'listen=tcp:127.0.0.1:34567') && str_contains($proxies[0] ?? '', 'connect=tcp:127.0.0.1:34567'), 'proxy only on 127.0.0.1:CL_PORT_HTTP');
t_ok(substr_count($all, 'tcp:') === 2 && !str_contains($all, 'udp:') && substr_count($all, 'listen=') === 1, 'no other listen/connect address');
t_ok(str_contains((string) @file_get_contents("$home/etc/postgresql.conf"), "listen_addresses = ''"), "PG config socket-only (listen_addresses '')");
t_ok(str_contains((string) @file_get_contents("$home/etc/valkey.conf"), "port 0"), 'Valkey config socket-only (port 0)');
t_ok(is_file("$home/admin/valkey-admin.secret") && !file_exists("$home/etc/valkey-admin.secret"), 'admin secret only in admin/ (not mounted)');
$secrets = [trim((string) @file_get_contents("$home/admin/valkey-admin.secret")), trim((string) @file_get_contents("$home/etc/valkey-app.secret"))];
t_ok($secrets[0] !== '' && !str_contains($all, $secrets[0]) && !str_contains($all, $secrets[1]) && !str_contains($all, 'valkey-admin'), 'no secret (value or admin path) on the incus command line');
t_eq(trim((string) @file_get_contents("$home/run/incus.name")), trim(explode(' | ', $init)[2] ?? ''), 'run/incus.name records the container');

echo "idempotent\n";
$n = count($calls());
[$code] = $run("/bin/bash $dev/up-incus.sh");
t_eq(0, $code, 'second run ok');
t_eq(0, count(array_filter(array_slice($calls(), $n), static fn ($l) => preg_match('/^(init|image \| import|delete)/', $l))), 'running container left alone');

echo "down.sh (incus path)\n";
[$code, $out] = $run("/bin/bash $dev/down.sh");
t_eq(0, $code, 'down.sh ok' . ($code ? ": $out" : ''));
$tail = implode("\n", array_slice($calls(), $n));
t_ok(str_contains($tail, "sv | -w | 30 | stop | $home/sv/postgresql") && str_contains($tail, 'stop | --timeout | 30'), 'PG stopped cleanly, then incus stop');
t_ok(!file_exists("$home/run/incus.name"), 'incus.name removed');

echo "stopped container is recreated, image reused\n";
$n = count($calls());
[$code] = $run("/bin/bash $dev/up-incus.sh", ['CL_INCUS_POOL' => 'fastpool']);
$tail = implode("\n", array_slice($calls(), $n));
t_ok($code === 0 && str_contains($tail, 'delete | --force') && str_contains($tail, "\ninit | ") && !str_contains($tail, 'image | import'), 'delete + init, no re-import');
t_ok(str_contains($tail, ' | -s | fastpool | '), 'CL_INCUS_POOL picks the root disk pool');

echo "db-setup.sh (incus path)\n";
$n = count($calls());
[$code, $out] = $run("/bin/bash $dev/db-setup.sh");
$tail = array_slice($calls(), $n);
$execs = array_values(array_filter($tail, static fn ($l) => str_starts_with($l, 'exec | ')));
$cname = trim((string) @file_get_contents("$home/run/incus.name"));
t_eq(0, $code, 'db-setup.sh ok on incus (detected: container RUNNING)' . ($code ? ": $out" : ''));
t_ok(count($execs) > 5 && $execs === array_values(array_filter($execs, static fn ($l) => str_starts_with($l, "exec | $cname | --user | " . posix_getuid() . ' | --group | ' . posix_getgid()))), 'every step runs via incus exec as this uid/gid');
$joined = implode("\n", $execs);
t_ok(str_contains($joined, "$home/etc/dev-env.env") && str_contains($joined, 'pg-roles.sql') && str_contains($joined, 'scripts/migrate.php'), 'same steps as bwrap (env file, roles, migrate)');
t_ok(!str_contains(implode("\n", $tail), 'nsenter'), 'no nsenter on the incus path');
$run("/bin/bash $dev/down.sh");
[$code, $out] = $run("/bin/bash $dev/db-setup.sh", ['CL_RUNTIME' => 'incus']);
t_ok($code !== 0 && str_contains($out, 'incus env is down'), 'CL_RUNTIME=incus with the container down -> clear error');

echo "shared prestart\n";
foreach (['up-bwrap.sh', 'up-incus.sh'] as $f) {
    $src = (string) file_get_contents("$dev/$f");
    t_eq(1, substr_count($src, 'prestart '), "$f calls prestart once");
    t_ok(!str_contains($src, 'postmaster.pid') && !str_contains($src, 'ensure_dirs') && !str_contains($src, 'render_templates'), "$f has no copy of the pre-start steps");
}

echo "one runtime per CL_HOME (refuse_running)\n";
$check = static fn (string $mode) => $run('/bin/bash -c ' . escapeshellarg(". $dev/lib.sh; cl_defaults; refuse_running $mode"));
@unlink("$home/run/env.pid");
file_put_contents($state, "RUNNING\n");
[$code, $out] = $run("/bin/bash $dev/up-bwrap.sh -d");
t_ok($code !== 0 && str_contains($out, 'incus env') && str_contains($out, 'already up'), 'incus running -> up-bwrap.sh refuses with a clear message');
t_ok(!file_exists("$home/run/env.pid"), 'up-bwrap.sh started nothing');
file_put_contents($state, "STOPPED\n");
t_eq(0, $check('bwrap')[0], 'incus stopped -> bwrap may start');
// Live fake bwrap: a process whose cmdline is "bwrap ... --ro-bind $CL_ROOTFS / ..." (what pid_is_ours checks).
$fake = proc_open(['/bin/bash', '-c', 'exec -a bwrap python3 -c "import time; time.sleep(60)" --ro-bind ' . "$home/rootfs" . ' / x'], [], $pipes);
$pid = (int) proc_get_status($fake)['pid'];
usleep(300000);
file_put_contents("$home/run/env.pid", "$pid 1 $pid\n");
[$code, $out] = $check('incus');
t_ok($code !== 0 && str_contains($out, 'bwrap env is already up'), 'live env.pid -> refused with a clear message');
[$code, $out] = $run("/bin/bash $dev/up-incus.sh");
t_ok($code !== 0 && str_contains($out, 'bwrap env is already up'), 'live env.pid -> up-incus.sh refuses');
proc_terminate($fake, 9);
proc_close($fake);
file_put_contents("$home/run/env.pid", "999999 999998 999997\n");
t_eq(0, $check('incus')[0], 'stale env.pid -> incus may start');
@unlink("$home/run/env.pid");
@unlink($state);
t_eq(0, $check('incus')[0], 'nothing up -> proceeds');

echo "up.sh dispatch\n";
$stubDev = "$base/dev";
mkdir($stubDev);
copy("$dev/up.sh", "$stubDev/up.sh");
foreach (['bwrap', 'incus'] as $rt) {
    file_put_contents("$stubDev/up-$rt.sh", "#!/bin/bash\necho $rt \"\$@\"\n");
    chmod("$stubDev/up-$rt.sh", 0700);
}
t_eq([0, 'bwrap -d'], $run("/bin/bash $stubDev/up.sh -d"), 'default: bwrap, args passed through');
t_eq([0, 'incus'], $run("/bin/bash $stubDev/up.sh --incus"), '--incus');
t_eq([0, 'incus x'], $run("/bin/bash $stubDev/up.sh x", ['CL_RUNTIME' => 'incus']), 'CL_RUNTIME=incus');
t_eq([0, 'bwrap -d'], $run("/bin/bash $stubDev/up.sh --bwrap -d", ['CL_RUNTIME' => 'incus']), 'flag wins over CL_RUNTIME');
t_eq(2, $run("/bin/bash $stubDev/up.sh", ['CL_RUNTIME' => 'docker'])[0], 'unknown runtime: exit 2');
t_done();
