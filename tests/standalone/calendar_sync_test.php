<?php
// Claim, diff import, backoff, removal, feed slots, URL cap, ICS clamp / invalid dates / too_big.
require __DIR__ . '/lib.php';
t_temp_db();
$owner = t_seed_owner();
require_once CL_ROOT . '/src/ActivityLog.php';
require_once CL_ROOT . '/src/I18n.php';
require_once CL_ROOT . '/src/Acoes/ICalImport.php';
require_once CL_ROOT . '/src/CalendarWorker.php';
$pid = (int) $owner['house_id'];
$uid = (int) $owner['user_id'];
$day = static fn (int $n): string => date('Y-m-d', strtotime("+$n days"));
$now = date('Y-m-d H:i:s');
$add = static function (string $url, string $title) use ($pid, $uid): int {
    $created = HouseRepository::addFeed($pid, $uid, $url, $title);
    t_ok(is_array($created), "addFeed $title");
    return (int) $created['feed']['id'];
};
$night = static function (int $propertyId, string $iso) : array {
    $stmt = db()->prepare('SELECT blocked, origin, feed_id, price FROM calendar_days WHERE property_id = ? AND day = ?');
    $stmt->execute([$propertyId, $iso]);
    $row = $stmt->fetch() ?: [];
    return [
        'blocked' => (int) ($row['blocked'] ?? 0),
        'origin' => (string) ($row['origin'] ?? ''),
        'feed_id' => $row['feed_id'] === null || $row['feed_id'] === '' ? null : (int) $row['feed_id'],
        'price' => $row['price'] === null || $row['price'] === '' ? null : (int) $row['price'],
    ];
};

echo "claim\n";
$a = $add('https://feeds.example/a.ics', 'A');
$b = $add('https://feeds.example/b.ics', 'B');
db()->prepare("UPDATE calendar_feeds SET next_sync_at = now() - interval '2 minutes' WHERE id = ?")->execute([$a]);
db()->prepare("UPDATE calendar_feeds SET next_sync_at = now() - interval '1 minute' WHERE id = ?")->execute([$b]);
$first = HouseRepository::claimDueFeed();
$second = HouseRepository::claimDueFeed();
t_eq([$a, $b], [(int) ($first['id'] ?? 0), (int) ($second['id'] ?? 0)], 'dois claims, ids diferentes, o mais antigo primeiro');
t_eq(true, $first['active'] ?? false, 'conta ativa');
t_eq(null, HouseRepository::claimDueFeed(), 'nada mais devido (lease de 10 min)');

db()->exec("UPDATE calendar_feeds SET next_sync_at = 'infinity'");
db()->prepare("UPDATE calendar_feeds SET next_sync_at = now() - interval '1 minute' WHERE id = ?")->execute([$a]);
$hold = new PDO((string) getenv('DB_DSN'), getenv('DB_USER') ?: null, getenv('DB_PASSWORD') ?: null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$hold->beginTransaction();
$locked = $hold->prepare(HouseRepository::CLAIM_DUE_FEED_SQL);
$locked->execute();
$held = $locked->fetch();
t_eq($a, (int) ($held['id'] ?? 0), 'primeira conexão segurou o feed');
t_eq(null, HouseRepository::claimDueFeed(), 'SKIP LOCKED: a outra conexão não leva a mesma linha');
$hold->commit();
t_eq(null, HouseRepository::claimDueFeed(), 'lease impede um novo claim');
db()->prepare("UPDATE calendar_feeds SET next_sync_at = now() - interval '1 second' WHERE id = ?")->execute([$a]);
t_eq($a, (int) (HouseRepository::claimDueFeed()['id'] ?? 0), 'lease vencido: o feed volta');

db()->prepare("UPDATE users SET status = 'inactive' WHERE id = ?")->execute([$uid]);
db()->prepare("UPDATE calendar_feeds SET next_sync_at = now() - interval '1 minute' WHERE id = ?")->execute([$b]);
$idle = HouseRepository::claimDueFeed();
t_eq([$b, false], [(int) ($idle['id'] ?? 0), $idle['active'] ?? true], 'conta inativa: claim devolve active=false');
$gap = (float) db()->query('SELECT EXTRACT(EPOCH FROM (next_sync_at - now())) FROM calendar_feeds WHERE id = ' . $b)->fetchColumn();
t_ok($gap > 80000 && $gap < 90000, 'conta inativa empurrada ~1 dia');
db()->prepare("UPDATE users SET status = 'active' WHERE id = ?")->execute([$uid]);
db()->exec("UPDATE calendar_feeds SET next_sync_at = 'infinity'");

echo "diff\n";
$d1 = $day(2);
$d2 = $day(3);
$d3 = $day(4);
$wrote = HouseRepository::importFeed($pid, $a, [$d1, $d2], ['sync', 'reserva'], $now, 0);
t_eq(['freed' => 0, 'written' => 2], $wrote, 'primeira importação grava as duas noites');
t_eq(['blocked' => 1, 'origin' => 'sync', 'feed_id' => $a, 'price' => null], $night($pid, $d1), 'noite do feed A');
$again = HouseRepository::importFeed($pid, $a, [$d1, $d2], ['sync', 'reserva'], $now, 0);
t_eq(['freed' => 0, 'written' => 0], $again, 'sync sem mudança grava 0 linhas');
db()->prepare('UPDATE calendar_days SET price = 123 WHERE property_id = ? AND day = ?')->execute([$pid, $d1]);
$cancelled = HouseRepository::importFeed($pid, $a, [$d2], ['reserva'], $now, 0);
t_eq(1, $cancelled['freed'] ?? 0, 'noite cancelada é liberada');
t_eq(['blocked' => 0, 'origin' => '', 'feed_id' => null, 'price' => 123], $night($pid, $d1), 'preço fica, feed_id sai');
db()->prepare("INSERT INTO calendar_days (property_id, day, blocked, origin) VALUES (?, ?, 1, 'sistema')")->execute([$pid, $d3]);
$kept = HouseRepository::importFeed($pid, $a, [$d2, $d3], ['reserva', 'sync'], $now, 0);
t_eq(0, $kept['written'] ?? -1, 'bloco do dono não é escrito');
t_eq(['blocked' => 1, 'origin' => 'sistema', 'feed_id' => null, 'price' => null], $night($pid, $d3), 'origem sistema permanece');
db()->prepare("UPDATE calendar_feeds SET next_sync_at = now() + interval '2 hours' WHERE id = ?")->execute([$b]);
$stolen = HouseRepository::importFeed($pid, $b, [$d2], ['sync'], $now, 0);
t_eq(0, $stolen['written'] ?? -1, 'segundo feed não toma a noite do primeiro');
t_eq($a, $night($pid, $d2)['feed_id'], 'feed_id continua o do primeiro');
$freed = HouseRepository::importFeed($pid, $a, [], [], $now, 0);
t_ok(($freed['freed'] ?? 0) >= 1, 'o primeiro feed liberou a noite');
$reDue = (float) db()->query('SELECT EXTRACT(EPOCH FROM (next_sync_at - now())) FROM calendar_feeds WHERE id = ' . $b)->fetchColumn();
t_ok($reDue <= 2, 'o outro feed fica devido na hora');
$took = HouseRepository::importFeed($pid, $b, [$d2], ['sync'], $now, 0);
t_eq(1, $took['written'] ?? 0, 'depois de livre, o segundo feed fica com a noite');
t_eq($b, $night($pid, $d2)['feed_id'], 'feed_id passa a ser o segundo');

echo "removal\n";
db()->prepare("UPDATE calendar_feeds SET next_sync_at = now() + interval '3 hours' WHERE id = ?")->execute([$b]);
HouseRepository::importFeed($pid, $a, [$d1], ['sync'], $now, 0);
$gone = HouseRepository::removeFeed($pid, $uid, $a);
t_eq($a, (int) ($gone['feed']['id'] ?? 0), 'remove devolve o feed');
t_eq(['blocked' => 0, 'origin' => '', 'feed_id' => null, 'price' => 123], $night($pid, $d1), 'remover libera só as noites daquele feed e guarda o preço');
t_eq($b, $night($pid, $d2)['feed_id'], 'a noite do outro feed continua');
$afterRemove = (float) db()->query('SELECT EXTRACT(EPOCH FROM (next_sync_at - now())) FROM calendar_feeds WHERE id = ' . $b)->fetchColumn();
t_ok($afterRemove <= 2, 'remover o feed marca o outro como devido');
t_eq(null, HouseRepository::importFeed($pid, $a, [$d1], ['sync'], $now, 0), 'import de feed já removido não grava');

echo "backoff\n";
$back = $add('https://backoff.example/a.ics', 'Back');
$expect = [0 => 1800, 1 => 3600, 2 => 7200, 3 => 14400];
for ($i = 4; $i <= 14; $i++) {
    $expect[$i] = 21600;
}
foreach ($expect as $before => $seconds) {
    db()->prepare('UPDATE calendar_feeds SET fail_count = ?, last_error = NULL, next_sync_at = now(), last_status = NULL WHERE id = ?')->execute([$before, $back]);
    $row = HouseRepository::recordFeedFailure($back, 'timeout', $now, 0);
    $delay = (float) db()->query('SELECT EXTRACT(EPOCH FROM (next_sync_at - now())) FROM calendar_feeds WHERE id = ' . $back)->fetchColumn();
    t_eq(false, $row['stopped'], "fail_count $before ainda não para");
    t_ok(abs($delay - $seconds) < 8, "fail_count $before espera {$seconds}s (obteve $delay)");
}
db()->prepare('UPDATE calendar_feeds SET fail_count = 15, next_sync_at = now() WHERE id = ?')->execute([$back]);
$stop = HouseRepository::recordFeedFailure($back, 'dns', $now, 0);
t_eq([16, true], [$stop['fail_count'], $stop['stopped']], 'a 16ª falha temporária para o feed');
db()->prepare('UPDATE calendar_feeds SET fail_count = 0, last_error = NULL, next_sync_at = now() WHERE id = ?')->execute([$back]);
$permanent = HouseRepository::recordFeedFailure($back, 'http_4xx', $now, 0);
t_eq([1, true], [$permanent['fail_count'], $permanent['stopped']], 'http_4xx para na hora');
HouseRepository::markFeedPending($pid, $back);
$reset = db()->query('SELECT fail_count, last_error, last_status FROM calendar_feeds WHERE id = ' . $back)->fetch();
t_eq([0, null, 'PENDENTE'], [(int) $reset['fail_count'], $reset['last_error'], $reset['last_status']], 'retry zera a falha');

echo "url\n";
$long = 'https://x.test/' . str_repeat('a', 3000);
$refused = ICalImport::run(HouseRepository::find($pid), $uid, $long, 'Longa');
t_eq('url', $refused->tipo, 'URL longa é erro de validação');
t_eq(t('cal.url_https'), $refused->mensagem, 'mesma mensagem das outras URLs ruins');
t_eq('url', HouseRepository::addFeed($pid, $uid, $long, 'Longa'), 'addFeed também recusa');

echo "ics\n";
$one = date('Ymd', strtotime('+5 days'));
$oneEnd = date('Ymd', strtotime('+6 days'));
$invalid = "BEGIN:VCALENDAR\n"
    . "BEGIN:VEVENT\nDTSTART:20261301\nDTEND:20261302\nEND:VEVENT\n"
    . "BEGIN:VEVENT\nDTSTART:20260230\nEND:VEVENT\n"
    . "BEGIN:VEVENT\nDTSTART:20260132\nEND:VEVENT\n"
    . "BEGIN:VEVENT\nDTSTART:$one\nDTEND:$oneEnd\nSUMMARY:Blocked\nEND:VEVENT\n"
    . "END:VCALENDAR\n";
$parsed = import_ics($pid, $invalid);
t_eq([[$day(5)], ['sync']], [$parsed['nights'], $parsed['origins']], 'mês 13, dia 32 e 30/fev são ignorados; o evento válido entra');
$span = "BEGIN:VCALENDAR\nBEGIN:VEVENT\nDTSTART:00010101\nDTEND:99991231\nSUMMARY:Blocked\nEND:VEVENT\nEND:VCALENDAR\n";
$t0 = microtime(true);
$wide = import_ics($pid, $span);
$elapsed = microtime(true) - $t0;
t_ok($elapsed < 1, 'VEVENT de milênios não percorre ano a ano');
t_eq($day(0), $wide['nights'][0] ?? '', 'o loop começa em hoje');
t_eq(max_calendar_day(HouseRepository::find($pid)), $wide['nights'][count($wide['nights']) - 1] ?? '', 'o loop termina no horizonte');
$huge = "BEGIN:VCALENDAR\nBEGIN:VEVENT\n" . str_repeat('X', CalendarSync::MAX_EVENT_BYTES + 1) . "\nDTSTART:$one\nEND:VEVENT\nEND:VCALENDAR\n";
t_eq(['error' => 'too_big'], import_ics($pid, $huge), 'VEVENT acima de 1 MB falha o feed');
$over = "BEGIN:VCALENDAR\nBEGIN:VEVENT\n" . str_repeat('Y', CalendarSync::MAX_EVENT_BYTES + 1) . "\nDTSTART:$one\nEND:VEVENT\nEND:VCALENDAR\n";
$previousLimit = ini_set('pcre.backtrack_limit', (string) (CalendarSync::MAX_BYTES * 5));
t_eq(['error' => 'too_big'], import_ics($pid, $over), 'evento pouco acima de 1 MB continua too_big quando o match cabe');
if ($previousLimit !== false) {
    ini_set('pcre.backtrack_limit', $previousLimit);
}
$accepted = CalendarSync::acceptBody($huge, 200);
t_ok(isset($accepted['body']), '1 MB de VEVENT ainda passa do teto de 2 MB do corpo');
CalendarWorker::finish(
    ['id' => $back, 'property_id' => $pid, 'url' => 'https://backoff.example/a.ics'],
    $accepted,
    0
);
$bigRow = db()->query('SELECT last_error, next_sync_at = \'infinity\' AS stopped FROM calendar_feeds WHERE id = ' . $back)->fetch();
t_eq('too_big', $bigRow['last_error'], 'too_big fica visível em last_error');
t_ok($bigRow['stopped'] === true || $bigRow['stopped'] === 't', 'too_big para o feed');

echo "slots\n";
$other = t_seed_owner();
for ($i = 1; $i <= 5; $i++) {
    $created = HouseRepository::addFeed((int) $other['house_id'], (int) $other['user_id'], 'https://slot.example/f' . $i . '.ics', 'S' . $i);
    t_ok(is_array($created), "slot $i");
}
t_eq('limit', HouseRepository::addFeed((int) $other['house_id'], (int) $other['user_id'], 'https://slot.example/f6.ics', 'S6'), 'o sexto feed é limit');
$slots = db()->query('SELECT slot FROM calendar_feeds WHERE property_id = ' . (int) $other['house_id'] . ' ORDER BY slot')->fetchAll(PDO::FETCH_COLUMN);
t_eq(['1', '2', '3', '4', '5'], array_map('strval', $slots), 'slots 1..5');
t_eq('duplicate', HouseRepository::addFeed((int) $other['house_id'], (int) $other['user_id'], 'https://slot.example/f1.ics', 'Outro'), 'a mesma URL é duplicate');

echo "alerts\n";
db()->prepare("UPDATE calendar_feeds SET next_sync_at = now() - interval '31 minutes' WHERE id = ?")->execute([$back]);
$fh = fopen('php://memory', 'w+');
CalendarWorker::$stderr = $fh;
CalendarWorker::checkAlerts();
rewind($fh);
$alert = stream_get_contents($fh);
t_ok(str_contains($alert, 'calendar-worker: ALERT') && str_contains($alert, 'lag'), 'ALERT de atraso > 30 min');
db()->exec("UPDATE calendar_feeds SET last_synced_at = now(), last_error = 'timeout'");
ftruncate($fh, 0);
rewind($fh);
CalendarWorker::checkAlerts();
rewind($fh);
$temp = stream_get_contents($fh);
t_ok(str_contains($temp, 'temporary failure rate'), 'ALERT de falha temporária');
$syncedAt = date('Y-m-d H:i:s', time() - 2400);
$prevDue = date('Y-m-d H:i:s', time() - 2400 + 2400);
t_ok(CalendarWorker::isExpiredLease($prevDue, $syncedAt), 'intervalo de lease de 10 min depois de 30 min');
t_ok(!CalendarWorker::isExpiredLease($prevDue, null), 'primeira sincronização não é lease vencido');
CalendarWorker::observeClaim(['prev_due' => $prevDue, 'last_synced_at' => $syncedAt]);
ftruncate($fh, 0);
rewind($fh);
CalendarWorker::checkAlerts();
rewind($fh);
t_ok(str_contains(stream_get_contents($fh), 'lease expiries'), 'ALERT de lease vencido');
CalendarWorker::$stderr = null;
fclose($fh);
HouseRepository::importFeed($pid, $b, [$d2], ['sync'], $now, HouseRepository::JITTER_MAX_SECONDS);
$jitterGap = (float) db()->query('SELECT EXTRACT(EPOCH FROM (next_sync_at - now())) FROM calendar_feeds WHERE id = ' . $b)->fetchColumn();
t_ok($jitterGap >= 1790 && $jitterGap <= 2110, 'os 30 min de sucesso ganham no máximo 300 s de jitter');

t_done();
