<?php
// Testes HTTP (php -S + banco PG temporário): rota antiga .ics -> 404, export por ical_hash, ics_body ignorado.
require __DIR__ . '/lib.php';
t_temp_db();
$owner = t_seed_owner();
$base = t_server();
$jar = tempnam(sys_get_temp_dir(), 'jar');

echo "export .ics\n";
$r = t_http('GET', $base . '/api/imoveis/' . $owner['hash'] . '/calendario.ics');
t_eq(404, $r['status'], 'rota antiga /api/imoveis/{hash_publico}/calendario.ics responde 404');
t_ok(!str_contains($r['body'], 'BEGIN:VCALENDAR'), 'rota antiga não devolve calendário');
$r = t_http('GET', $base . '/imoveis/' . $owner['hash'] . '/calendario.ics');
t_eq(404, $r['status'], 'rota web antiga /imoveis/{hash}/calendario.ics responde 404');
$r = t_http('GET', $base . '/api/ical/' . $owner['ical_hash'] . '.ics');
t_eq(200, $r['status'], 'GET /api/ical/{ical_hash}.ics responde 200');
t_ok(str_contains($r['body'], 'BEGIN:VCALENDAR'), 'export por ical_hash devolve VCALENDAR');
$r = t_http('GET', $base . '/api/ical/' . str_repeat('0', 32) . '.ics');
t_eq(404, $r['status'], 'ical_hash inexistente responde 404');

echo "import baixa o feed na hora e ignora ics_body\n";
$csrf = t_login($base, $jar, $owner['email'], $owner['password']);
$day = date('Ymd', strtotime('+10 days'));
$ics = "BEGIN:VCALENDAR\r\nBEGIN:VEVENT\r\nDTSTART;VALUE=DATE:$day\r\nDTEND;VALUE=DATE:" . date('Ymd', strtotime('+12 days')) . "\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
// Updated: 2026-11 - add-feed now fetches the feed, picks the challenge and imports immediately.
$feedDay = date('Y-m-d', strtotime('+5 days'));
$feedEnd = date('Ymd', strtotime('+6 days'));
file_put_contents((string) getenv('T_ICS_BODY_FILE'),
    "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\nUID:feed\r\nDTSTART;VALUE=DATE:" . str_replace('-', '', $feedDay)
    . "\r\nDTEND;VALUE=DATE:$feedEnd\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n");
// Updated: 2026-11 - exercise the platform-bound OTA verification path.
$feedUrl = 'https://www.airbnb.com/calendar/ical/12345678.ics?t=TOKENFEED';
$r = t_http('POST', $base . '/api/imoveis/' . $owner['hash'] . '/ical/importar', json_encode([
    'external_ical_url' => $feedUrl, 'feed_title' => 'Teste', 'ics_body' => $ics,
    'mode' => 'ota', 'platform' => 'airbnb',
    'property_url' => 'https://www.airbnb.com/rooms/12345678', 'consent' => '1',
]), ['Content-Type: application/json', 'X-CSRF-Token: ' . $csrf], $jar);
$json = json_decode($r['body'], true) ?: [];
t_eq(200, $r['status'], 'import grava o feed e sincroniza no pedido');
t_eq(true, $json['ok'] ?? null, 'import ok');
t_eq(1, $json['importadas'] ?? null, 'a noite do próprio feed é importada');
$feed = $json['feeds'][0] ?? [];
t_eq('OK', $feed['last_status'], 'feed já sincronizado');
$blocked = db()->prepare('SELECT COUNT(*) FROM calendar_days WHERE property_id = ? AND blocked = 1 AND day = ?');
$blocked->execute([$owner['house_id'], $feedDay]);
t_eq(1, (int) $blocked->fetchColumn(), 'o dia do feed foi bloqueado');
$clientDay = db()->prepare('SELECT COUNT(*) FROM calendar_days WHERE property_id = ? AND day = ?');
$clientDay->execute([$owner['house_id'], date('Y-m-d', strtotime('+10 days'))]);
t_eq(0, (int) $clientDay->fetchColumn(), 'nenhuma noite veio do ics_body do cliente');
$long = t_http('POST', $base . '/api/imoveis/' . $owner['hash'] . '/ical/importar', json_encode([
    'external_ical_url' => 'https://feeds.example/' . str_repeat('a', 8000), 'feed_title' => 'Longa',
]), ['Content-Type: application/json', 'X-CSRF-Token: ' . $csrf], $jar);
t_eq(422, $long['status'], 'URL de 8 KB é 422, não 500');
$badPort = t_http('POST', $base . '/api/imoveis/' . $owner['hash'] . '/ical/importar', json_encode([
    'external_ical_url' => 'https://127.0.0.1:9/bloqueado.ics', 'feed_title' => 'Porta',
]), ['Content-Type: application/json', 'X-CSRF-Token: ' . $csrf], $jar);
t_eq(422, $badPort['status'], 'porta diferente de 443 é recusada');

echo "URL do feed mascarada\n";
t_eq('https://www.airbnb.com/••••', $feed['url'] ?? null, 'import: feeds[].url mascarada');
t_ok(!str_contains($r['body'], 'TOKENFEED') && !str_contains($r['body'], 'bloqueado.ics'), 'import: resposta sem token/caminho');
// Updated: 2026-11 - feeds() now exposes the unified challenge/verification lifecycle.
t_eq(['id', 'property_id', 'title', 'url', 'last_status', 'last_synced_at', 'last_error', 'fail_count',
    'status', 'challenge_date', 'challenge_expires_at', 'verified_at', 'last_checked_at', 'attempts',
    'passive_fail_count', 'credits_awarded', 'source_platform', 'verification_mode', 'property_url',
    'canonical_listing_id', 'ics_metadata', 'review_status', 'reviewed_by', 'reviewed_at', 'review_note',
    'consent_at', 'stopped'],
    array_keys($feed), 'import: só campos seguros');
$g = t_http('GET', $base . '/api/imoveis/' . $owner['hash'] . '/calendario', null, [], $jar);
t_eq(200, $g['status'], 'GET calendario API 200');
t_ok(!str_contains($g['body'], 'TOKENFEED') && (json_decode($g['body'], true)['feeds'][0]['url'] ?? null) === 'https://www.airbnb.com/••••', 'GET calendario API: URL mascarada');
$w = t_http('GET', $base . '/imoveis/' . $owner['hash'] . '/calendario', null, [], $jar);
t_eq(200, $w['status'], 'tela web do calendário continua 200');
t_ok(!str_contains($w['body'], 'TOKENFEED') && str_contains($w['body'], 'Teste'), 'tela web lista o feed sem token');
$stored = db()->prepare('SELECT url FROM calendar_feeds WHERE property_id = ? ORDER BY id');
$stored->execute([$owner['house_id']]);
// Updated: 2026-11 - schema 37 stores the feed URL encrypted; the API still returns only the mask.
$storedUrl = (string) $stored->fetchColumn();
t_ok(str_starts_with($storedUrl, 'enc:v1:'), 'a URL real fica cifrada no banco');
t_ok(!str_contains($storedUrl, 'TOKENFEED'), 'o token privado não fica em claro no banco');

echo "verificar titularidade pelo feed\n";
$challengeStmt = db()->prepare('SELECT challenge_date FROM calendar_feeds WHERE id = ?');
$challengeStmt->execute([(int) ($feed['id'] ?? 0)]);
$challenge = (string) $challengeStmt->fetchColumn();
t_ok($challenge !== '', 'o desafio tem data futura');
$challengeEnd = date('Ymd', strtotime($challenge . ' +1 day'));
file_put_contents((string) getenv('T_ICS_BODY_FILE'),
    "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\nUID:challenge\r\nDTSTART;VALUE=DATE:" . str_replace('-', '', $challenge)
    . "\r\nDTEND;VALUE=DATE:$challengeEnd\r\nSUMMARY:Blocked\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n");
$v = t_http('POST', $base . '/api/imoveis/' . $owner['hash'] . '/ical/' . (int) $feed['id'] . '/verificar', '', ['X-CSRF-Token: ' . $csrf], $jar);
$vj = json_decode($v['body'], true) ?: [];
t_eq(200, $v['status'], 'verificar responde 200');
t_eq(true, $vj['verified'] ?? null, 'verificar conclui a titularidade');
t_ok(!isset($vj['feed']['url_hash']) && str_contains((string) ($vj['feed']['url'] ?? ''), '••••'), 'verificar devolve o feed mascarado');
$verifiedRow = db()->prepare('SELECT status, verified_at IS NOT NULL AS verified, credits_awarded FROM calendar_feeds WHERE id = ?');
$verifiedRow->execute([(int) $feed['id']]);
$verifiedState = $verifiedRow->fetch(PDO::FETCH_ASSOC) ?: [];
t_eq(['active', 1, 1], [($verifiedState['status'] ?? ''), (int) ($verifiedState['verified'] ?? 0), (int) ($verifiedState['credits_awarded'] ?? 0)], 'feed fica ativo e verificado');
$ledger = db()->prepare('SELECT count(*) FROM ota_credit_ledger WHERE property_id = ?');
$ledger->execute([$owner['house_id']]);
t_eq(1, (int) $ledger->fetchColumn(), 'crédito de onboarding entra uma única vez');

echo "Booking.com pending_review responde 200\n";
$bookingUrl = 'https://ical.booking.com/v1/export?t=ROUTEBOOK';
$bookingPublic = 'https://www.booking.com/hotel/br/casa-route.html';
$r = t_http('POST', $base . '/api/imoveis/' . $owner['hash'] . '/ical/importar', json_encode([
    'external_ical_url' => $bookingUrl,
    'feed_title' => 'Booking Route',
    'mode' => 'ota',
    'platform' => 'booking',
    'property_url' => $bookingPublic,
    'consent' => '1',
]), ['Content-Type: application/json', 'X-CSRF-Token: ' . $csrf], $jar);
$bj = json_decode($r['body'], true) ?: [];
t_eq(200, $r['status'], 'Booking import responde 200');
$bookingFeed = null;
foreach (($bj['feeds'] ?? []) as $candidate) {
    if (($candidate['source_platform'] ?? '') === 'booking') {
        $bookingFeed = $candidate;
        break;
    }
}
t_ok(is_array($bookingFeed), 'o feed Booking foi criado');
$bookingId = (int) ($bookingFeed['id'] ?? 0);
$bookingChallengeStmt = db()->prepare('SELECT challenge_date FROM calendar_feeds WHERE id = ?');
$bookingChallengeStmt->execute([$bookingId]);
$bookingChallenge = (string) $bookingChallengeStmt->fetchColumn();
t_ok($bookingChallenge !== '', 'o desafio Booking tem data futura');
$bookingEnd = date('Ymd', strtotime($bookingChallenge . ' +1 day'));
file_put_contents((string) getenv('T_ICS_BODY_FILE'),
    "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\nUID:booking-challenge\r\nDTSTART;VALUE=DATE:" . str_replace('-', '', $bookingChallenge)
    . "\r\nDTEND;VALUE=DATE:$bookingEnd\r\nSUMMARY:Blocked\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n");
$bv = t_http('POST', $base . '/api/imoveis/' . $owner['hash'] . '/ical/' . $bookingId . '/verificar', '', ['X-CSRF-Token: ' . $csrf], $jar);
$bvj = json_decode($bv['body'], true) ?: [];
t_eq(200, $bv['status'], 'Booking verificar responde 200 (não 422)');
t_eq(false, $bvj['verified'] ?? null, 'Booking não libera o selo antes da revisão');
t_eq('pending_review', $bvj['review_status'] ?? null, 'Booking retorna review_status pending_review');
$bookingDateBr = date('d/m/Y', strtotime($bookingChallenge));
t_eq($bookingDateBr, $bvj['challenge_date_br'] ?? null, 'Booking devolve challenge_date_br');
t_eq(
    t('cal.review_pending_detail', ['platform' => 'Booking.com', 'date' => $bookingDateBr]),
    $bvj['message'] ?? null,
    'Booking devolve a instrução de revisão com a data'
);

echo "Admin JSON de revisão OTA\n";
$adminBase = $base . '/api/admin/verifications';
$r = t_http('GET', $adminBase . '/pending', null, [], $jar);
t_eq(403, $r['status'], 'não-admin recebe 403 no JSON de revisão');
db()->prepare('UPDATE users SET is_admin = 1 WHERE id = ?')->execute([$owner['user_id']]);
$r = t_http('GET', $adminBase . '/pending', null, [], $jar);
$aj = json_decode($r['body'], true) ?: [];
t_eq(200, $r['status'], 'admin lista pendências com 200');
$pendingIds = array_map(static fn (array $row): int => (int) $row['id'], $aj['pending'] ?? []);
t_ok(in_array($bookingId, $pendingIds, true), 'a pendência Booking aparece na fila');
$pendingBooking = null;
foreach (($aj['pending'] ?? []) as $row) {
    if ((int) $row['id'] === $bookingId) {
        $pendingBooking = $row;
        break;
    }
}
t_ok(is_array($pendingBooking), 'payload da pendência Booking presente');
t_eq('Booking.com', $pendingBooking['platform_label'] ?? null, 'platform_label no JSON admin');
t_ok(($pendingBooking['challenge_date_br'] ?? '') !== '', 'challenge_date_br no JSON admin');
t_ok(array_key_exists('property_city', $pendingBooking ?? []), 'property_city no JSON admin');

$r = t_http('POST', $adminBase . '/' . $bookingId . '/approve', '{}', ['Content-Type: application/json', 'X-CSRF-Token: ' . $csrf], $jar);
$ap = json_decode($r['body'], true) ?: [];
t_eq(200, $r['status'], 'admin aprova com 200');
t_eq('approved', $ap['status'] ?? null, 'admin approve devolve approved');
$r = t_http('POST', $adminBase . '/' . $bookingId . '/approve', '{}', ['Content-Type: application/json', 'X-CSRF-Token: ' . $csrf], $jar);
$ap2 = json_decode($r['body'], true) ?: [];
t_eq(200, $r['status'], 'segunda aprovação é idempotente');
t_eq('already_approved', $ap2['status'] ?? null, 'segunda aprovação devolve already_approved');

$vrboUrl = 'https://www.vrbo.com/icalendar/555001.ics';
$vrboPublic = 'https://www.vrbo.com/555001';
$r = t_http('POST', $base . '/api/imoveis/' . $owner['hash'] . '/ical/importar', json_encode([
    'external_ical_url' => $vrboUrl,
    'feed_title' => 'Vrbo Route',
    'mode' => 'ota',
    'platform' => 'vrbo',
    'property_url' => $vrboPublic,
    'consent' => '1',
]), ['Content-Type: application/json', 'X-CSRF-Token: ' . $csrf], $jar);
$vj = json_decode($r['body'], true) ?: [];
t_eq(200, $r['status'], 'Vrbo import responde 200');
$vrboFeed = null;
foreach (($vj['feeds'] ?? []) as $candidate) {
    if (($candidate['source_platform'] ?? '') === 'vrbo') {
        $vrboFeed = $candidate;
        break;
    }
}
t_ok(is_array($vrboFeed), 'o feed Vrbo foi criado');
$vrboId = (int) ($vrboFeed['id'] ?? 0);
$vrboChallengeStmt = db()->prepare('SELECT challenge_date FROM calendar_feeds WHERE id = ?');
$vrboChallengeStmt->execute([$vrboId]);
$vrboChallenge = (string) $vrboChallengeStmt->fetchColumn();
$vrboEnd = date('Ymd', strtotime($vrboChallenge . ' +1 day'));
file_put_contents((string) getenv('T_ICS_BODY_FILE'),
    "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\nUID:vrbo-challenge\r\nDTSTART;VALUE=DATE:" . str_replace('-', '', $vrboChallenge)
    . "\r\nDTEND;VALUE=DATE:$vrboEnd\r\nSUMMARY:Blocked\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n");
$vv = t_http('POST', $base . '/api/imoveis/' . $owner['hash'] . '/ical/' . $vrboId . '/verificar', '', ['X-CSRF-Token: ' . $csrf], $jar);
t_eq(200, $vv['status'], 'Vrbo verificar responde 200');
$r = t_http('POST', $adminBase . '/' . $vrboId . '/reject', '{"rejection_reason":""}', ['Content-Type: application/json', 'X-CSRF-Token: ' . $csrf], $jar);
t_eq(422, $r['status'], 'rejeição sem motivo responde 422');
$r = t_http('POST', $adminBase . '/' . $vrboId . '/reject', '{"rejection_reason":"Anúncio público não corresponde ao imóvel"}', ['Content-Type: application/json', 'X-CSRF-Token: ' . $csrf], $jar);
$rj = json_decode($r['body'], true) ?: [];
t_eq(200, $r['status'], 'admin rejeita com 200');
t_eq('rejected', $rj['status'] ?? null, 'admin reject devolve rejected');
$r = t_http('POST', $adminBase . '/' . $vrboId . '/reject', '{"rejection_reason":"Again"}', ['Content-Type: application/json', 'X-CSRF-Token: ' . $csrf], $jar);
t_eq(409, $r['status'], 'rejeição repetida responde 409');
$r = t_http('POST', $adminBase . '/' . $vrboId . '/approve', '{}', ['Content-Type: application/json', 'X-CSRF-Token: ' . $csrf], $jar);
t_eq(409, $r['status'], 'aprovação de rejeitada responde 409');

echo "DELETE do feed\n";
$r = t_http('DELETE', $base . '/api/imoveis/' . $owner['hash'] . '/ical/' . (int) ($feed['id'] ?? 0), null, ['X-CSRF-Token: ' . $csrf], $jar);
$json = json_decode($r['body'], true) ?: [];
t_eq(200, $r['status'], 'DELETE responde 200');
t_ok(!isset($json['ressincronizados']) && is_array($json['feeds'] ?? null), 'DELETE não baixa os outros feeds');

echo "retry marca o feed como devido\n";
require_once CL_ROOT . '/src/CalendarSync.php';
$a = HouseRepository::addFeed($owner['house_id'], $owner['user_id'], 'https://feeds.example/a.ics', 'A');
HouseRepository::addFeed($owner['house_id'], $owner['user_id'], 'https://feeds.example/b.ics', 'B');
HouseRepository::markFeedPending($owner['house_id'], (int) $a['feed']['id']);
$pending = array_values(array_filter(HouseRepository::feeds($owner['house_id']), static fn ($f) => ($f['title'] ?? '') === 'A'))[0] ?? [];
t_eq('PENDENTE', $pending['last_status'] ?? null, 'markFeedPending grava PENDENTE');

@unlink($jar);
t_done();
