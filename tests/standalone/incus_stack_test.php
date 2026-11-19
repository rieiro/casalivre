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
    'containers/app-php/config/valkey.conf', 'containers/app-php/config/valkey.acl.in',
    'containers/app-php/config/logrotate-casalivre',
    'containers/app-php/sv/casalivre-valkey/run', 'containers/app-php/sv/casalivre-valkey/log/run',
    'containers/app-php/sv/casalivre-logrotate/run', 'containers/app-php/sv/casalivre-logrotate/log/run',
    'containers/app-php/config/pgbouncer.ini.in',
    'containers/app-php/sv/casalivre-pgbouncer/run', 'containers/app-php/sv/casalivre-pgbouncer/log/run',
    'scripts/00-init-networks.sh', 'scripts/01-deploy-db.sh', 'scripts/02-deploy-app.sh',
    'scripts/03-upgrade-pg-major.sh', 'scripts/stack-up.sh', 'scripts/verify-stack.sh',
    'scripts/04-jumpstart-app.sh', 'scripts/05-dev-live.sh', 'scripts/valkey-setup.sh',
    'scripts/logs-setup.sh', 'scripts/06-backup-data.sh', 'scripts/pgbouncer-setup.sh',
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
    'scripts/04-jumpstart-app.sh', 'scripts/05-dev-live.sh', 'scripts/valkey-setup.sh',
    'scripts/logs-setup.sh', 'scripts/06-backup-data.sh', 'scripts/pgbouncer-setup.sh',
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

echo "incus stack: dev live overlay\n";
$dev = incus_read('scripts/05-dev-live.sh');
foreach (['on|off|status', 'setfacl', 'volatile.idmap.base', 'opcache.validate_timestamps=1',
    'dev-live-src', 'dev-live-static', 'dev-live-tiles',
    '/srv/app', '/srv/static', '/srv/tiles', 'alias /srv/tiles/brasil.pmtiles',
    'storage/tiles', 'nginx -t', 'ALLOW_LOCAL_DB', 'QUEUE_DRIVER', 'check_env', '/busca',
    'VALKEY_SOCKET', 'VALKEY_PASSWORD_FILE', 'test -S', 'deploy/incus/secrets'] as $n) {
    t_ok(str_contains($dev, $n), "05-dev-live.sh has $n");
}
t_ok(str_contains($dev, 'sv restart php-fpm'), '05-dev-live.sh restarts php-fpm after the opcache switch');
t_ok(str_contains($dev, 'set -euo pipefail'), '05-dev-live.sh is defensive');
t_ok(str_contains($jumpstart, 'ALLOW_LIVE_DEV'), '04-jumpstart-app.sh has the live-dev guard');
t_ok(str_contains($jumpstart, '05-dev-live.sh'), '04-jumpstart-app.sh points at 05-dev-live.sh');
t_ok(str_contains(incus_read('scripts/02-deploy-app.sh'), 'ALLOW_LIVE_DEV'),
    '02-deploy-app.sh has the live-dev guard');

echo "incus stack: valkey\n";
$vkconf = incus_read('containers/app-php/config/valkey.conf');
foreach (['port 0', 'unixsocket /run/casalivre-valkey/valkey.sock', 'unixsocketperm 660',
    'aclfile /etc/casalivre-valkey/valkey.acl', 'dir /var/lib/casalivre/media/valkey'] as $n) {
    t_ok(str_contains($vkconf, $n), "valkey.conf has $n");
}
$vkeacl = incus_read('containers/app-php/config/valkey.acl.in');
foreach (['user default off', 'user cl_app on #__VALKEY_APP_HASH__', '~cl:*', '&cl:*', '-@all'] as $n) {
    t_ok(str_contains($vkeacl, $n), "valkey.acl.in has $n");
}
$vksetup = incus_read('scripts/valkey-setup.sh');
foreach (['valkey', 'php8.4-redis', 'xbps-install', 'sha256sum', 'VALKEY_PASSWORD_FILE',
    'cl_app', 'valkey-cli', 'sv up', 'VALKEY_SOCKET'] as $n) {
    t_ok(str_contains($vksetup, $n), "valkey-setup.sh has $n");
}
t_ok(str_contains(incus_read('scripts/02-deploy-app.sh'), 'valkey-setup.sh'),
    '02-deploy-app.sh runs valkey-setup.sh');
t_ok(str_contains(incus_read('scripts/04-jumpstart-app.sh'), 'valkey-setup.sh'),
    '04-jumpstart-app.sh runs valkey-setup.sh');
t_ok(str_contains(incus_read('containers/app-php/provision.sh'), 'valkey'),
    'app-php provision.sh installs valkey');
t_ok(str_contains(incus_read('containers/app-php/provision.sh'), 'php8.4-redis'),
    'app-php provision.sh installs php8.4-redis');
t_ok(str_contains(incus_read('containers/app-php/config/php.ini'), 'extension=redis.so'),
    'php.ini loads redis.so');
t_ok(str_contains(incus_read('containers/app-php/config/php.ini'), '/etc/casalivre'),
    'php.ini allows /etc/casalivre in open_basedir');
$verify = incus_read('scripts/verify-stack.sh');
foreach (['casalivre-valkey', 'valkey-cli', 'redis'] as $n) {
    t_ok(str_contains($verify, $n), "verify-stack.sh has $n");
}
t_ok(str_contains((string) file_get_contents(CL_ROOT . '/.env.example'), 'VALKEY_SOCKET'),
    '.env.example documents VALKEY_SOCKET');

echo "incus stack: log services\n";
$vlog = incus_read('containers/app-php/sv/casalivre-valkey/log/run');
foreach (['vlogger', 'casalivre-valkey'] as $n) {
    t_ok(str_contains($vlog, $n), "casalivre-valkey log/run has $n");
}
$logrotate = incus_read('containers/app-php/config/logrotate-casalivre');
foreach (['/var/log/php-fpm/access.log', '/var/log/php-fpm/error.log',
    '/var/log/casalivre/alerts.log', 'copytruncate', 'rotate 14'] as $n) {
    t_ok(str_contains($logrotate, $n), "logrotate-casalivre has $n");
}
$logs = incus_read('scripts/logs-setup.sh');
foreach (['logrotate', 'xbps-install', '/etc/logrotate.d/casalivre',
    'logrotate.status', 'sv up', 'casalivre-logrotate'] as $n) {
    t_ok(str_contains($logs, $n), "logs-setup.sh has $n");
}
t_ok(str_contains(incus_read('scripts/02-deploy-app.sh'), 'logs-setup.sh'),
    '02-deploy-app.sh runs logs-setup.sh');
t_ok(str_contains(incus_read('scripts/04-jumpstart-app.sh'), 'logs-setup.sh'),
    '04-jumpstart-app.sh runs logs-setup.sh');
$edgeLogrotate = (string) file_get_contents(CL_ROOT . '/deploy/infra-nginx-hardened/system/logrotate-nginx');
foreach (['/var/log/nginx/*.log', 'kill -USR1', 'rotate 14'] as $n) {
    t_ok(str_contains($edgeLogrotate, $n), "edge logrotate-nginx has $n");
}
t_ok(str_contains((string) file_get_contents(CL_ROOT . '/deploy/infra-nginx-hardened/scripts/02-deploy-green.sh'),
    'logrotate'), 'edge 02-deploy-green.sh installs logrotate policy');
$verifyLogs = incus_read('scripts/verify-stack.sh');
foreach (['casalivre-logrotate', 'logrotate.d/nginx'] as $n) {
    t_ok(str_contains($verifyLogs, $n), "verify-stack.sh checks $n");
}

echo "incus stack: data backup\n";
$backup = incus_read('scripts/06-backup-data.sh');
foreach (['pg_dump', 'pg_dumpall', 'storage volume export', 'casalivre-media',
    'public/uploads', 'storage/fotos', 'deploy/incus/secrets', 'MAX_BACKUPS',
    '--volume-only', 'storage/tiles', 'casalivre-app', 'DB_PGHOST', 'MIN_FREE_MB'] as $n) {
    t_ok(str_contains($backup, $n), "06-backup-data.sh has $n");
}
t_ok(!str_contains($backup, 'not a git working tree'), '06-backup-data.sh does not require .git');
$readme = (string) file_get_contents(CL_ROOT . '/deploy/incus/README.md');
foreach (['06-backup-data.sh', 'pg_dump', 'storage/tiles', 'casalivre-app', 'DB_PGHOST'] as $n) {
    t_ok(str_contains($readme, $n), "README documents $n");
}

echo "incus stack: pg_stat_statements\n";
$pgconf = incus_read('containers/db-pg/config/postgresql.conf');
t_ok(str_contains($pgconf, "shared_preload_libraries = 'pg_stat_statements'"),
    "postgresql.conf preloads pg_stat_statements");
t_ok(str_contains(incus_read('scripts/01-deploy-db.sh'),
    'CREATE EXTENSION IF NOT EXISTS pg_stat_statements'),
    '01-deploy-db.sh creates the pg_stat_statements extension');
t_ok(str_contains(incus_read('scripts/04-jumpstart-app.sh'),
    'CREATE EXTENSION IF NOT EXISTS pg_stat_statements'),
    '04-jumpstart-app.sh creates the pg_stat_statements extension');
t_ok(str_contains(incus_read('scripts/verify-stack.sh'), 'pg_stat_statements'),
    'verify-stack.sh checks pg_stat_statements');
t_ok(str_contains((string) file_get_contents(CL_ROOT . '/deploy/incus/README.md'), 'pg_stat_statements'),
    'README documents pg_stat_statements');

echo "incus stack: static cache headers\n";
$edgeConf = (string) file_get_contents(CL_ROOT . '/deploy/infra-nginx-hardened/config/sites-enabled/default.conf');
t_ok(str_contains($edgeConf, 'add_header_inherit merge'),
    'edge config preserves inherited security headers on static locations');
t_ok(substr_count($edgeConf, 'public, max-age=31536000, immutable') >= 2,
    'edge config marks assets and uploads immutable for one year');
$devLive = incus_read('scripts/05-dev-live.sh');
t_ok(str_contains($devLive, 'public, max-age=31536000, immutable'),
    '05-dev-live.sh overrides the production immutable header in dev');
t_ok(str_contains($devLive, 'no-store'), '05-dev-live.sh uses no-store in dev');
t_ok(str_contains((string) file_get_contents(CL_ROOT . '/deploy/incus/README.md'), 'immutable'),
    'README documents immutable static caching');

echo "incus stack: pgbouncer\n";
$pgb = incus_read('containers/app-php/config/pgbouncer.ini.in');
foreach (['[databases]', 'casalivre = host=10.10.20.10', '[users]',
    'cl_logw = max_user_connections=8', 'cl_retention_job = max_user_connections=2',
    'listen_addr = 127.0.0.1', 'listen_port = 6432', 'auth_type = md5',
    'pool_mode = session', 'default_pool_size = 32', 'min_pool_size = 5',
    'server_idle_timeout = 600', 'max_db_connections = 60',
    'server_tls_sslmode = verify-ca', 'server_tls_ca_file = /etc/casalivre-pgbouncer/db-ca.crt'] as $n) {
    t_ok(str_contains($pgb, $n), "pgbouncer.ini.in has $n");
}
$pgbSetup = incus_read('scripts/pgbouncer-setup.sh');
foreach (['xbps-install', 'pgbouncer', 'userlist.txt', '_pgbouncer', 'sleep 6',
    'casalivre-pgbouncer'] as $n) {
    t_ok(str_contains($pgbSetup, $n), "pgbouncer-setup.sh has $n");
}
t_ok(str_contains(incus_read('scripts/02-deploy-app.sh'), 'pgbouncer-setup.sh'),
    '02-deploy-app.sh runs pgbouncer-setup.sh');
t_ok(str_contains(incus_read('scripts/04-jumpstart-app.sh'), 'pgbouncer-setup.sh'),
    '04-jumpstart-app.sh runs pgbouncer-setup.sh');
$devLive = incus_read('scripts/05-dev-live.sh');
t_ok(str_contains($devLive, 'port=6432'), '05-dev-live.sh allows the pgbouncer DSN');
t_ok(str_contains($devLive, 'casalivre-pgbouncer'), '05-dev-live.sh checks pgbouncer');
t_ok(str_contains(incus_read('scripts/verify-stack.sh'), 'casalivre-pgbouncer'),
    'verify-stack.sh checks pgbouncer');
t_ok(str_contains((string) file_get_contents(CL_ROOT . '/deploy/incus/README.md'), 'PgBouncer'),
    'README documents PgBouncer');

t_done();
