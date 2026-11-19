<?php
// Contract test for deploy/infra-nginx-hardened (hardened nginx edge, 3-tier Incus integration).
// Static checks (tree, pins, directives, shell syntax) plus a real `nginx -t` render when nginx/openssl exist.
require __DIR__ . '/lib.php';

$root = CL_ROOT . '/deploy/infra-nginx-hardened';

function infra_read(string $rel): string {
    $f = CL_ROOT . '/deploy/infra-nginx-hardened/' . $rel;
    return is_file($f) ? (string) file_get_contents($f) : '';
}
function infra_has(string $rel, string $needle, string $msg): void {
    t_ok(str_contains(infra_read($rel), $needle), $msg);
}

echo "infra nginx hardened: tree\n";
t_ok(is_dir($root), 'deploy/infra-nginx-hardened exists');
$required = [
    '.gitignore', 'README.md',
    'build/versions.env', 'build/configure.flags', 'build/build.sh',
    'incus/profile-prod.yaml', 'incus/profile-builder.yaml', 'incus/acl-dmz.yaml',
    'host/nftables-nginx.conf',
    'config/nginx.conf',
    'config/snippets/ssl-params.conf', 'config/snippets/headers.conf', 'config/snippets/rate-limit.conf',
    'config/sites-enabled/default.conf',
    'scripts/00-init-host.sh', 'scripts/01-build-artifact.sh', 'scripts/02-deploy-green.sh',
    'scripts/03-cutover.sh', 'scripts/04-rollback.sh',
];
foreach ($required as $rel) {
    t_ok(is_file("$root/$rel"), "exists: $rel");
}

echo "infra nginx hardened: gitignore\n";
foreach (['artifacts/', '*.tar.gz', '*.sha256', 'certs/', '*.key'] as $pat) {
    infra_has('.gitignore', $pat, ".gitignore ignores $pat");
}

echo "infra nginx hardened: pinned sources\n";
$versions = infra_read('build/versions.env');
t_ok(preg_match('/^NGINX_VERSION="[0-9]+\.[0-9]+\.[0-9]+"/m', $versions) === 1, 'NGINX_VERSION pinned');
t_ok(preg_match('/^NGINX_SHA256="[0-9a-f]{64}"/m', $versions) === 1, 'NGINX_SHA256 is 64 hex');
t_ok(preg_match('/^HEADERS_MORE_VERSION="v[0-9]+\.[0-9]+"/m', $versions) === 1, 'HEADERS_MORE_VERSION pinned');
t_ok(preg_match('/^HEADERS_MORE_COMMIT="[0-9a-f]{40}"/m', $versions) === 1, 'HEADERS_MORE_COMMIT is 40 hex');

echo "infra nginx hardened: shell scripts\n";
$scripts = ['build/build.sh', 'scripts/00-init-host.sh', 'scripts/01-build-artifact.sh',
    'scripts/02-deploy-green.sh', 'scripts/03-cutover.sh', 'scripts/04-rollback.sh'];
foreach ($scripts as $rel) {
    $src = infra_read($rel);
    t_ok(str_starts_with($src, "#!/usr/bin/env bash\n"), "$rel uses /usr/bin/env bash");
    t_ok(str_contains($src, 'set -euo pipefail'), "$rel sets -euo pipefail");
    t_ok(is_executable("$root/$rel"), "$rel is executable");
    $out = [];
    $rc = 0;
    exec('bash -n ' . escapeshellarg("$root/$rel") . ' 2>&1', $out, $rc);
    t_ok($rc === 0, "$rel passes bash -n" . ($rc === 0 ? '' : ': ' . implode(' ', $out)));
}

echo "infra nginx hardened: nginx config\n";
infra_has('config/nginx.conf', 'server_tokens off;', 'server_tokens off');
infra_has('config/nginx.conf', 'sites-enabled/*.conf', 'includes sites-enabled');
infra_has('config/nginx.conf', 'worker_processes', 'worker_processes set');
infra_has('config/nginx.conf', 'client_max_body_size', 'body limit set');
infra_has('config/snippets/headers.conf', 'more_clear_headers Server;', 'strips Server header');
foreach (['Content-Security-Policy', 'X-Frame-Options', 'X-Content-Type-Options', 'Referrer-Policy'] as $h) {
    infra_has('config/snippets/headers.conf', $h, "header $h");
}
$ssl = infra_read('config/snippets/ssl-params.conf');
foreach (['TLSv1.2', 'TLSv1.3', 'ssl_session_tickets off;', 'Strict-Transport-Security', 'ssl_ciphers'] as $n) {
    t_ok(str_contains($ssl, $n), "ssl-params contains $n");
}
t_ok(!str_contains($ssl, 'TLSv1.1'), 'ssl-params does not allow TLS 1.1');
$rl = infra_read('config/snippets/rate-limit.conf');
t_ok(str_contains($rl, 'limit_req_zone') && str_contains($rl, 'limit_conn_zone'), 'rate-limit defines req + conn zones');

echo "infra nginx hardened: FastCGI front controller\n";
$site = infra_read('config/sites-enabled/default.conf');
foreach ([
    'listen 8080', 'listen 8443', '__PRIMARY_DOMAIN__', '__TLS_CERT__', '__TLS_KEY__',
    'fastcgi_pass 10.10.10.20:9000;', 'include fastcgi_params;',
    'SCRIPT_FILENAME /srv/app/public/index.php;', 'DOCUMENT_ROOT /srv/app/public;',
    'try_files $uri /index.php$is_args$args;', 'internal;',
    'location ~ \\.php$ { return 404; }', 'location ~ /\\.(?!well-known)',
    '/srv/static',
    'HTTP_ACCEPT', 'HTTP_X_REAL_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_FORWARDED_PROTO',
    'OAI-SearchBot', 'ClaudeBot', 'Google-Extended', 'PerplexityBot',
] as $n) {
    t_ok(str_contains($site, $n), "default.conf contains $n");
}
t_ok(!str_contains($site, 'proxy_pass'), 'no generic proxy_pass left');
t_ok(!str_contains($site, '__BACKEND_UPSTREAM__'), 'no generic upstream token left');

echo "infra nginx hardened: incus profiles and strict DMZ ACL\n";
$prod = infra_read('incus/profile-prod.yaml');
foreach (['security.privileged: "false"', 'security.idmap.isolated: "true"', 'security.nesting: "false"',
    'security.protection.delete: "true"', 'security.mac_filtering: "true"',
    'security.ipv4_filtering: "true"', 'security.ipv6_filtering: "true"',
    'limits.cpu', 'limits.memory', 'limits.processes'] as $n) {
    t_ok(str_contains($prod, $n), "profile-prod contains $n");
}
$acl = infra_read('incus/acl-dmz.yaml');
foreach (['ingress:', 'egress:', 'destination_port: "8080"', 'destination_port: "8443"',
    'destination: "10.10.10.20/32"', 'destination_port: "9000"',
    'destination: "10.10.10.0/24"', 'destination: "10.10.20.0/24"',
    'destination_port: "53"', 'destination_port: "123"', 'action: drop'] as $n) {
    t_ok(str_contains($acl, $n), "acl-dmz contains $n");
}
t_ok(!str_contains($acl, 'destination_port: "80"'), 'no unused :80 ingress');
t_ok(!str_contains($acl, 'destination_port: "443"'), 'no unused :443 ingress');
t_ok(!str_contains($acl, '__BACKEND_IP__'), 'backend tier IP is fixed');
infra_has('host/nftables-nginx.conf', 'dnat ip to __ACTIVE_NGINX_IP__:8080', 'host DNAT 80->8080');
infra_has('host/nftables-nginx.conf', 'dnat ip to __ACTIVE_NGINX_IP__:8443', 'host DNAT 443->8443');

echo "infra nginx hardened: script wiring\n";
infra_has('scripts/00-init-host.sh', 'incusbr-dmz', 'init defaults to incusbr-dmz');
infra_has('scripts/00-init-host.sh', 'reusing', 'init reuses an existing DMZ bridge');
infra_has('scripts/01-build-artifact.sh', 'sha256sum -c', 'builder verifies artifact hash');
infra_has('scripts/01-build-artifact.sh', '--no-profiles', 'builder uses only the hardened profile');
infra_has('scripts/02-deploy-green.sh', '--no-profiles', 'edge uses only the hardened profile');
infra_has('scripts/02-deploy-green.sh', '10.10.10.10', 'blue static IP');
infra_has('scripts/02-deploy-green.sh', '10.10.10.11', 'green static IP');
infra_has('scripts/02-deploy-green.sh', 'nginx-prod-blue', 'blue container name');
infra_has('scripts/02-deploy-green.sh', 'nginx-prod-green', 'green container name');
infra_has('scripts/02-deploy-green.sh', 'nginx -t', 'green deploy validates with nginx -t');
infra_has('scripts/02-deploy-green.sh', '__PRIMARY_DOMAIN__', 'green deploy renders domain');
infra_has('scripts/03-cutover.sh', 'nft -f -', 'cutover applies nftables atomically');
infra_has('scripts/03-cutover.sh', 'incus stop', 'cutover stops blue');
infra_has('scripts/04-rollback.sh', 'incus start', 'rollback starts blue');

// Real syntax validation. The stock nginx lacks headers-more, so render a harness that
// drops more_* directives, points paths at a temp dir, and uses unprivileged test ports.
echo "infra nginx hardened: nginx -t render\n";
$nginx = trim((string) shell_exec('command -v nginx 2>/dev/null'));
$openssl = trim((string) shell_exec('command -v openssl 2>/dev/null'));
if ($nginx === '' || $openssl === '') {
    echo "  skip real nginx -t (nginx/openssl not installed)\n";
} else {
    $tmp = sys_get_temp_dir() . '/cl-nginx-' . bin2hex(random_bytes(4));
    mkdir($tmp . '/snippets', 0777, true);
    mkdir($tmp . '/sites-enabled', 0777, true);
    mkdir($tmp . '/logs', 0777, true);
    foreach (['client_body', 'proxy', 'fastcgi', 'uwsgi', 'scgi'] as $d) {
        mkdir($tmp . '/cache/' . $d, 0777, true);
    }
    file_put_contents($tmp . '/mime.types', "types { text/html html; }\n");
    $fastcgiParams = <<<'FP'
fastcgi_param QUERY_STRING $query_string;
fastcgi_param REQUEST_METHOD $request_method;
fastcgi_param CONTENT_TYPE $content_type;
fastcgi_param CONTENT_LENGTH $content_length;
fastcgi_param SCRIPT_NAME $fastcgi_script_name;
fastcgi_param REQUEST_URI $request_uri;
fastcgi_param DOCUMENT_URI $document_uri;
fastcgi_param DOCUMENT_ROOT $document_root;
fastcgi_param SERVER_PROTOCOL $server_protocol;
fastcgi_param REQUEST_SCHEME $scheme;
fastcgi_param GATEWAY_INTERFACE CGI/1.1;
fastcgi_param SERVER_SOFTWARE nginx;
fastcgi_param REMOTE_ADDR $remote_addr;
fastcgi_param REMOTE_PORT $remote_port;
fastcgi_param SERVER_ADDR $server_addr;
fastcgi_param SERVER_PORT $server_port;
fastcgi_param SERVER_NAME $server_name;
fastcgi_param REDIRECT_STATUS 200;
FP;
    file_put_contents($tmp . '/fastcgi_params', $fastcgiParams);
    copy($root . '/config/nginx.conf', $tmp . '/nginx.conf');
    foreach (glob($root . '/config/snippets/*.conf') ?: [] as $f) {
        copy($f, $tmp . '/snippets/' . basename($f));
    }
    copy($root . '/config/sites-enabled/default.conf', $tmp . '/sites-enabled/default.conf');

    exec('openssl req -x509 -newkey rsa:2048 -nodes -keyout ' . escapeshellarg($tmp . '/key.pem')
        . ' -out ' . escapeshellarg($tmp . '/cert.pem') . ' -days 1 -subj /CN=localhost 2>/dev/null', $o, $rc);
    t_ok($rc === 0, 'generated self-signed test certificate');

    $rewrite = static function (string $file) use ($tmp): void {
        $c = (string) file_get_contents($file);
        $c = str_replace(
            ['/usr/local/nginx/conf', '/var/log/nginx', '/var/cache/nginx', '/run/nginx'],
            [$tmp, $tmp . '/logs', $tmp . '/cache', $tmp],
            $c
        );
        $c = preg_replace('/^\s*more_(clear|set)_headers\b.*$/m', '', $c) ?? $c;
        $c = str_replace('listen 8080', 'listen 18080', $c);
        $c = str_replace('listen 8443 ssl', 'listen 18443 ssl', $c);
        $c = str_replace(['__PRIMARY_DOMAIN__', '__TLS_CERT__', '__TLS_KEY__'],
            ['localhost', $tmp . '/cert.pem', $tmp . '/key.pem'], $c);
        $c = preg_replace('/^\s*user\s+nginx;.*$/m', '', $c) ?? $c;
        file_put_contents($file, $c);
    };
    $rewrite($tmp . '/nginx.conf');
    foreach (glob($tmp . '/snippets/*.conf') ?: [] as $f) { $rewrite($f); }
    $rewrite($tmp . '/sites-enabled/default.conf');

    exec(escapeshellarg($nginx) . ' -t -p ' . escapeshellarg($tmp) . ' -c ' . escapeshellarg($tmp . '/nginx.conf')
        . ' -e ' . escapeshellarg($tmp . '/logs/error.log') . ' 2>&1', $ngout, $ngrc);
    t_ok($ngrc === 0, 'rendered config passes nginx -t' . ($ngrc === 0 ? '' : ': ' . implode(' ', $ngout)));

    exec('rm -rf ' . escapeshellarg($tmp));
}

t_done();
