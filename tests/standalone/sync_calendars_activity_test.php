<?php
// scripts/sync-calendars.php (cron) precisa gravar as auditorias CALENDAR_SYNC e CALENDAR_UPDATE.
require __DIR__ . '/lib.php';
t_temp_db();
$owner = t_seed_owner();
require_once CL_ROOT . '/src/ActivityLog.php';
$pid = (int) $owner['house_id'];
$pdo = db();
$pdo->prepare("UPDATE properties SET status = 'active' WHERE id = ?")->execute([$pid]);
// Feed que o SSRF recusa (loopback): resultado NOK, sem depender de rede.
$pdo->prepare('INSERT INTO calendar_feeds (property_id, url, title) VALUES (?, ?, ?)')
    ->execute([$pid, 'http://127.0.0.1:1/teste-sync.ics', 'Teste']);
// Noite bloqueada muito além do horizonte: trimHorizon remove e audita.
$pdo->prepare("INSERT INTO calendar_days (property_id, day, blocked, origin) VALUES (?, ?, 1, 'sync')")
    ->execute([$pid, date('Y-m-d', strtotime('+6 years'))]);

$before = t_log_mark();

echo "executa o script do cron\n";
exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(CL_ROOT . '/scripts/sync-calendars.php') . ' 2>&1', $out, $code);
$text = implode("\n", $out);
if (getenv("T_DEBUG")) { echo $text, "\n"; }
t_ok(str_contains($text, 'Noites além do horizonte removidas: 1'), 'trimHorizon removeu a noite');
t_ok(str_contains($text, '1 NOK'), 'feed processado (NOK)');
t_ok(!str_contains($text, 'Call to undefined function'), 'sem erro de função indefinida');

$mine = array_values(array_filter(t_log_rows($before), static fn ($r) => (int) ($r['params']['imovel_id'] ?? 0) === $pid));
$codes = array_column($mine, 'code');
t_ok(in_array(ActivityLog::CALENDAR_SYNC, $codes, true), 'auditoria CALENDAR_SYNC gravada');
t_ok(in_array(ActivityLog::CALENDAR_UPDATE, $codes, true), 'auditoria CALENDAR_UPDATE (limite) gravada');
$sync = array_values(array_filter($mine, static fn ($r) => $r['code'] === ActivityLog::CALENDAR_SYNC));
t_ok($sync !== [] && !str_contains(json_encode($sync[0]['params']), 'teste-sync.ics'), 'URL do feed não aparece crua na auditoria');
t_eq(['other', 'system'], [$sync[0]['class'] ?? null, $sync[0]['actor_kind'] ?? null], 'CALENDAR_SYNC do cron: classe other, actor_kind system (CLI grava na hora)');
t_done();
