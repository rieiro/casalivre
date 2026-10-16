<?php
// Testes de CalendarSync::isPublicIp / resolveTarget (sem rede externa).
require __DIR__ . '/lib.php';
require CL_ROOT . '/src/CalendarSync.php';

echo "isPublicIp\n";
$blocked = ['127.0.0.1', '10.0.0.1', '172.16.5.4', '192.168.1.1', '169.254.169.254', '100.64.0.1', '0.0.0.0',
    '224.0.0.1', '255.255.255.255', '198.18.0.1', '::1', '::', '::ffff:127.0.0.1', '::ffff:10.0.0.1',
    '::127.0.0.1', '::8.8.8.8', '64:ff9b::7f00:1', '64:ff9b::808:808', '64:ff9b:1::1', '2002:7f00:1::1',
    '2002:808:808::1', '2001:0:4136:e378::1', '2001:db8::1', 'fe80::1', 'fc00::1', 'fd12:3456::1', 'ff02::1', '100::1', '::ffff:8.8.8.8', '::ffff:1.1.1.1', '::FFFF:8.8.8.8', '0:0:0:0:0:ffff:808:808', '::ffff:0:8.8.8.8'];  // IPv4 mapeado: recusado sempre (conservador)
foreach ($blocked as $ip) {
    t_ok(!CalendarSync::isPublicIp($ip), "bloqueia $ip");
}
foreach (['8.8.8.8', '1.1.1.1', '2606:4700:4700::1111', '2a00:1450:4001::1'] as $ip) {
    t_ok(CalendarSync::isPublicIp($ip), "aceita $ip");
}

echo "resolveTarget\n";
foreach (['http://127.0.0.1/a.ics', 'http://[::1]/a.ics', 'http://localhost/a.ics', 'http://x.localhost/a.ics',
    'file:///etc/passwd', 'gopher://8.8.8.8/', 'ftp://8.8.8.8/a', 'http://user:pw@8.8.8.8/a.ics',
    'http://169.254.169.254/latest/meta-data', 'http://[64:ff9b::a9fe:a9fe]/', 'http://2130706433/', 'nada',
    'http://8.8.8.8/cal.ics', 'HTTP://8.8.8.8/cal.ics', 'http://[2606:4700:4700::1111]/x'] as $u) { // http público também: só https
    t_eq(null, CalendarSync::resolveTarget($u), "recusa $u");
}
$t = CalendarSync::resolveTarget('https://8.8.8.8/cal.ics');
t_eq(['host' => '8.8.8.8', 'port' => 443, 'ip' => '8.8.8.8'], $t, 'aceita https://8.8.8.8 (porta 443)');
$t = CalendarSync::resolveTarget('https://[2606:4700:4700::1111]:8080/x');
t_eq(8080, $t['port'] ?? null, 'aceita IPv6 público com porta');

echo "fetch só https\n";
foreach (['http://8.8.8.8/cal.ics', 'ftp://8.8.8.8/a', 'javascript:alert(1)', ''] as $u) {
    t_eq(null, CalendarSync::fetch($u, 1), "fetch recusa '$u' antes de qualquer rede (feed antigo http não baixa)");
}
$src = (string) file_get_contents(CL_ROOT . '/src/CalendarSync.php');
t_ok(str_contains($src, "PROTOCOLS_STR] = 'https';") && !str_contains($src, 'CURLPROTO_HTTP |'), 'curl restrito a https');

echo "logRef\n";
$ref = CalendarSync::logRef('https://www.airbnb.com/calendar/ical/123.ics?s=SEGREDO');
t_ok(str_starts_with((string) $ref, 'www.airbnb.com#') && !str_contains((string) $ref, 'SEGREDO'), 'logRef guarda só host + hash');

t_done();
