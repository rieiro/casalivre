<?php
// Contract test for deploy/incus (3-tier Incus IaC: networks, ACLs, volumes, profiles, Void containers).
// Static checks only; it never creates networks, volumes or containers.
require __DIR__ . '/lib.php';

$root = CL_ROOT . '/deploy/incus';
function incus_read(string $rel): string {
    $f = CL_ROOT . '/deploy/incus/' . $rel;
    return is_file($f) ? (string) file_get_contents($f) : '';
}
function incus_has(string $rel, string $needle, string $msg): void {
    t_ok(str_contains(incus_read($rel), $needle), $msg);
}

echo "incus stack: tree\n";
t_ok(is_dir($root), 'deploy/incus exists');
$required = [
    '.gitignore', 'README.md',
    'networks/incusbr-dmz.yaml', 'networks/incusbr-internal.yaml',
    'acls/acl-internal.yaml',
    'profiles/base-void-hardened.yaml', 'profiles/app-php.yaml', 'profiles/db-pg.yaml',
    'containers/db-pg/provision.sh', 'containers/db-pg/config/postgresql.conf', 'containers/db-pg/config/pg_hba.conf',
    'containers/app-php/provision.sh', 'containers/app-php/config/php.ini', 'containers/app-php/config/php-fpm.d/www.conf',
    'scripts/00-init-networks.sh', 'scripts/01-deploy-db.sh', 'scripts/02-deploy-app.sh',
    'scripts/03-upgrade-pg-major.sh', 'scripts/stack-up.sh', 'scripts/verify-stack.sh',
    'scripts/04-jumpstart-app.sh',
];
foreach ($required as $rel) {
    t_ok(is_file("$root/$rel"), "exists: $rel");
}

echo "incus stack: gitignore\n";
foreach (['*.key', '*.crt', '.env*', 'secrets/'] as $pat) {
    incus_has('.gitignore', $pat, ".gitignore ignores $pat");
}

echo "incus stack: shell scripts\n";
$scripts = [
    'scripts/00-init-networks.sh', 'scripts/01-deploy-db.sh', 'scripts/02-deploy-app.sh',
    'scripts/03-upgrade-pg-major.sh', 'scripts/stack-up.sh', 'scripts/verify-stack.sh',
    'scripts/04-jumpstart-app.sh',
    'containers/db-pg/provision.sh', 'containers/app-php/provision.sh',
];
foreach ($scripts as $rel) {
    $src = incus_read($rel);
    t_ok(str_starts_with($src, "#!/usr/bin/env bash\n"), "$rel uses /usr/bin/env bash");
    t_ok(str_contains($src, 'set -euo pipefail'), "$rel sets -euo pipefail");
    t_ok(str_contains($src, 'SCRIPT_DIR'), "$rel resolves paths via SCRIPT_DIR");
    t_ok(is_executable("$root/$rel"), "$rel is executable");
    $out = [];
    $rc = 0;
    exec('bash -n ' . escapeshellarg("$root/$rel") . ' 2>&1', $out, $rc);
    t_ok($rc === 0, "$rel passes bash -n" . ($rc === 0 ? '' : ': ' . implode(' ', $out)));
}

echo "incus stack: networks\n";
// Updated: 2026-11 - Linux interface names are limited to 15 chars; the air-gapped network is incusbr-int.
$dmz = incus_read('networks/incusbr-dmz.yaml');
foreach (['10.10.10.1/24', 'ipv4.nat: "true"', 'ipv6.address: none'] as $n) {
    t_ok(str_contains($dmz, $n), "incusbr-dmz has $n");
}
$internal = incus_read('networks/incusbr-internal.yaml');
foreach (['10.10.20.1/24', 'ipv4.nat: "false"', 'ipv6.address: none', 'security.acls: acl-internal'] as $n) {
    t_ok(str_contains($internal, $n), "incusbr-int has $n");
}

echo "incus stack: internal ACL\n";
$acl = incus_read('acls/acl-internal.yaml');
foreach (['10.10.20.11/32', '10.10.20.10/32', '"5432"', 'action: allow', 'action: drop'] as $n) {
    t_ok(str_contains($acl, $n), "acl-internal has $n");
}

echo "incus stack: profiles\n";
$base = incus_read('profiles/base-void-hardened.yaml');
foreach (['security.privileged: "false"', 'security.idmap.isolated: "true"', 'security.nesting: "false"'] as $n) {
    t_ok(str_contains($base, $n), "base-void-hardened has $n");
}
$dbp = incus_read('profiles/db-pg.yaml');
foreach (['security.protection.delete: "true"', 'limits.cpu', 'limits.memory', 'limits.memory.enforce: "hard"',
    'limits.processes', 'casalivre-db', '/var/lib/postgresql', '10.10.20.10', 'incusbr-int',
    'ipv4.gateway: "none"', 'security.mac_filtering: "true"'] as $n) {
    t_ok(str_contains($dbp, $n), "db-pg profile has $n");
}
t_ok(!str_contains($dbp, 'incusbr-dmz'), 'db-pg profile has no DMZ NIC');
$app = incus_read('profiles/app-php.yaml');
foreach (['boot.autostart.delay: "5"', 'casalivre-media', '/var/lib/casalivre/media',
    '10.10.10.20', '10.10.20.11', 'incusbr-dmz', 'incusbr-int',
    'limits.cpu', 'limits.memory', 'limits.processes', 'security.mac_filtering: "true"'] as $n) {
    t_ok(str_contains($app, $n), "app-php profile has $n");
}

echo "incus stack: postgres config\n";
$pg = incus_read('containers/db-pg/config/postgresql.conf');
foreach (["listen_addresses = '10.10.20.10'", 'scram-sha-256', 'ssl = on', 'ssl_cert_file', 'ssl_key_file'] as $n) {
    t_ok(str_contains($pg, $n), "postgresql.conf has $n");
}
$hba = incus_read('containers/db-pg/config/pg_hba.conf');
foreach (['hostssl', '10.10.20.11/32', 'scram-sha-256'] as $n) {
    t_ok(str_contains($hba, $n), "pg_hba.conf has $n");
}
t_ok(preg_match('/\b(trust|md5)\b/i', $hba) === 0, 'pg_hba.conf has no trust/md5');

echo "incus stack: php config\n";
$php = incus_read('containers/app-php/config/php.ini');
foreach (['expose_php = Off', 'display_errors = Off', 'opcache.enable=1', 'disable_functions',
    'session.cookie_httponly', 'upload_max_filesize'] as $n) {
    t_ok(str_contains($php, $n), "php.ini has $n");
}
$www = incus_read('containers/app-php/config/php-fpm.d/www.conf');
foreach (['user = appuser', 'listen = 10.10.10.20:9000', 'listen.allowed_clients',
    '10.10.10.10', '10.10.10.11'] as $n) {
    t_ok(str_contains($www, $n), "www.conf has $n");
}

echo "incus stack: script wiring\n";
$db = incus_read('scripts/01-deploy-db.sh');
// Updated: 2026-11 - xbps-install lives in containers/db-pg/provision.sh (called by 01).
foreach (['maint-nic', 'incusbr-dmz', 'postgresql${PG_VERSION}',
    'incus config device remove', 'scram-sha-256', 'chown -R postgres:postgres',
    'postgresql.conf', 'pg_hba.conf'] as $n) {
    t_ok(str_contains($db, $n), "01-deploy-db.sh has $n");
}
$appd = incus_read('scripts/02-deploy-app.sh');
// Updated: 2026-11 - Void ships pdo_pgsql/opcache/mbstring/xml/curl in php8.4 + php8.4-pgsql.
foreach (['php8.4', 'php8.4-fpm', 'php8.4-pgsql', 'php8.4-gd', 'php8.4-intl',
    'useradd', 'appuser', '/srv/app', '/var/lib/casalivre/media', 'php-fpm8.4'] as $n) {
    t_ok(str_contains($appd, $n), "02-deploy-app.sh has $n");
}
$up = incus_read('scripts/03-upgrade-pg-major.sh');
foreach (['snapshot', 'db-pg-new', 'NEW_PG_VERSION', 'pg_dumpall'] as $n) {
    t_ok(str_contains($up, $n), "03-upgrade-pg-major.sh has $n");
}
$stack = incus_read('scripts/stack-up.sh');
foreach (['00-init-networks.sh', '01-deploy-db.sh', '02-deploy-app.sh',
    'infra-nginx-hardened/scripts/00-init-host.sh'] as $n) {
    t_ok(str_contains($stack, $n), "stack-up.sh calls $n");
}
$verify = incus_read('scripts/verify-stack.sh');
foreach (['10.10.20.10', '5432', '10.10.10.20', '9000', 'ping', 'sv status', 'PASS', 'FAIL'] as $n) {
    t_ok(str_contains($verify, $n), "verify-stack.sh has $n");
}
$dbp_cfg = incus_read('containers/db-pg/provision.sh');
foreach (['xbps-install', 'postgresql${PG_VERSION}', 'postgresql${PG_VERSION}-contrib', 'xbps-remove', 'openssh', 'postgres'] as $n) {
    t_ok(str_contains($dbp_cfg, $n), "db-pg provision.sh has $n");
}
$app_cfg = incus_read('containers/app-php/provision.sh');
foreach (['php8.4-fpm', 'php8.4-pgsql', 'php8.4-gd', 'appuser', 'xbps-remove'] as $n) {
    t_ok(str_contains($app_cfg, $n), "app-php provision.sh has $n");
}

echo "incus stack: jumpstart script\n";
$jumpstart = incus_read('scripts/04-jumpstart-app.sh');
foreach (['APP_ENV_FILE', 'ops/dev-env/pg-roles.sql', 'database/schema.pgsql.sql', 'migrate.php',
    'load-ceps.php', 'seed.php', 'storage/tiles/brasil.pmtiles', 'casalivre-mail-worker',
    'SEED_DEMO', 'FORCE_TILES'] as $n) {
    t_ok(str_contains($jumpstart, $n), "04-jumpstart-app.sh has $n");
}
t_ok(str_contains($jumpstart, 'chmod 0640 /srv/app/.env'), '04-jumpstart-app.sh protects .env with 0640');

t_done();
