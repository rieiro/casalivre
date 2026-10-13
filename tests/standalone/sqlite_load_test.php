<?php
// DEF-008: carga paralela nos .ics não pode gerar 500 "database is locked".
// Usa php -S com PHP_CLI_SERVER_WORKERS=8 (processos paralelos) e 200 requisições simultâneas.
require __DIR__ . '/lib.php';
putenv('PHP_CLI_SERVER_WORKERS=8');
t_temp_db();
$owner = t_seed_owner();
$base = t_server();
$errLog = CL_ROOT . '/storage/error.log';
clearstatcache();
$before = is_file($errLog) ? filesize($errLog) : 0;

$urls = [];
for ($i = 0; $i < 160; $i++) {
    $urls[] = $base . '/ical/' . $owner['ical_hash'];
}
for ($i = 0; $i < 40; $i++) {
    $urls[] = $base . '/imoveis/' . $owner['hash'];
}
$mh = curl_multi_init();
$handles = [];
foreach ($urls as $url) {
    $h = curl_init($url);
    curl_setopt_array($h, [CURLOPT_RETURNTRANSFER => true, CURLOPT_NOPROXY => '*', CURLOPT_TIMEOUT => 60]);
    curl_multi_add_handle($mh, $h);
    $handles[] = $h;
}
do {
    curl_multi_exec($mh, $running);
    curl_multi_select($mh);
} while ($running > 0);
$codes = [];
$ics = 0;
foreach ($handles as $i => $h) {
    $code = (int) curl_getinfo($h, CURLINFO_RESPONSE_CODE);
    $codes[$code] = ($codes[$code] ?? 0) + 1;
    if ($i < 160 && str_contains((string) curl_multi_getcontent($h), 'BEGIN:VCALENDAR')) {
        $ics++;
    }
}
ksort($codes);
echo '  códigos: ' . json_encode($codes) . "\n";
t_eq(0, $codes[500] ?? 0, 'nenhuma resposta 500 sob 200 requisições paralelas');
t_eq(160, $ics, 'todos os 160 .ics devolveram VCALENDAR');
clearstatcache();
$new = is_file($errLog) ? (string) substr((string) file_get_contents($errLog), $before) : '';
t_ok(!str_contains($new, 'database is locked'), 'error.log sem "database is locked"');

$mode = db()->query('PRAGMA journal_mode')->fetchColumn();
t_eq('wal', strtolower((string) $mode), 'banco em journal_mode=WAL');
t_ok((int) db()->query('PRAGMA busy_timeout')->fetchColumn() >= 5000, 'busy_timeout >= 5000 ms');
t_done();
