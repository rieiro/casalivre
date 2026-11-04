<?php
// O worker (--once) grava CALENDAR_SYNC sem a URL crua. trimHorizon continua auditando CALENDAR_UPDATE.
require __DIR__ . '/lib.php';
t_temp_db();
$owner = t_seed_owner();
require_once CL_ROOT . '/src/ActivityLog.php';
require_once CL_ROOT . '/src/CalendarSync.php';
$pid = (int) $owner['house_id'];
$pdo = db();
$pdo->prepare("UPDATE properties SET status = 'active' WHERE id = ?")->execute([$pid]);
ListingSearch::sync($pid);
$pdo->prepare('INSERT INTO calendar_feeds (property_id, slot, url, title, next_sync_at) VALUES (?, 1, ?, ?, now())')
    ->execute([$pid, 'http://127.0.0.1:1/teste-sync.ics', 'Teste']);
$pdo->prepare("INSERT INTO calendar_days (property_id, day, blocked, origin) VALUES (?, ?, 1, 'sync')")
    ->execute([$pid, date('Y-m-d', strtotime('+6 years'))]);

$before = t_log_mark();
$trimmed = CalendarSync::trimHorizon();
t_eq(1, $trimmed, 'trimHorizon removeu a noite');

echo "executa o worker uma vez\n";
exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(CL_ROOT . '/scripts/calendar-worker.php') . ' --once 2>&1', $out, $code);
$text = implode("\n", $out);
if (getenv('T_DEBUG')) {
    echo $text, "\n";
}
t_eq(0, $code, 'worker --once sai 0 (falha do feed não derruba o processo)');
t_ok(!str_contains($text, 'teste-sync.ics') && !str_contains($text, 'Call to undefined function'), 'saída sem a URL e sem função indefinida');

$mine = array_values(array_filter(t_log_rows($before), static fn ($r) => (int) ($r['params']['imovel_id'] ?? 0) === $pid));
$codes = array_column($mine, 'code');
t_ok(in_array(ActivityLog::CALENDAR_SYNC, $codes, true), 'auditoria CALENDAR_SYNC gravada');
t_ok(in_array(ActivityLog::CALENDAR_UPDATE, $codes, true), 'auditoria CALENDAR_UPDATE (limite) gravada');
t_ok(in_array(ActivityLog::CALENDAR_FEED_FAIL, $codes, true), 'URL http é falha permanente (CALENDAR_FEED_FAIL)');
$sync = array_values(array_filter($mine, static fn ($r) => $r['code'] === ActivityLog::CALENDAR_SYNC));
t_ok($sync !== [] && !str_contains(json_encode($sync[0]['params']), 'teste-sync.ics'), 'URL do feed não aparece crua na auditoria');
t_eq(['other', 'system'], [$sync[0]['class'] ?? null, $sync[0]['actor_kind'] ?? null], 'CALENDAR_SYNC: classe other, actor_kind system');
$fail = array_values(array_filter($mine, static fn ($r) => $r['code'] === ActivityLog::CALENDAR_FEED_FAIL));
t_eq('admin', $fail[0]['class'] ?? null, 'CALENDAR_FEED_FAIL é admin');
$row = $pdo->query('SELECT last_error, last_status, next_sync_at = \'infinity\' AS stopped FROM calendar_feeds')->fetch();
t_eq(['bad_url', 'NOK', true], [$row['last_error'], $row['last_status'], $row['stopped'] === true || $row['stopped'] === 't'], 'feed parado com bad_url');
t_done();
