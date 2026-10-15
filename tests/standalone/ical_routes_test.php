<?php
// Testes HTTP (php -S + SQLite temporário): rota antiga .ics -> 404, export por ical_hash, ics_body ignorado.
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

echo "import ignora ics_body\n";
$csrf = t_login($base, $jar, $owner['email'], $owner['password']);
$day = date('Ymd', strtotime('+10 days'));
$ics = "BEGIN:VCALENDAR\r\nBEGIN:VEVENT\r\nDTSTART;VALUE=DATE:$day\r\nDTEND;VALUE=DATE:" . date('Ymd', strtotime('+12 days')) . "\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
$r = t_http('POST', $base . '/api/imoveis/' . $owner['hash'] . '/ical/importar', json_encode([
    'external_ical_url' => 'http://127.0.0.1:9/bloqueado.ics?t=TOKENFEED', 'feed_title' => 'Teste', 'ics_body' => $ics,
]), ['Content-Type: application/json', 'X-CSRF-Token: ' . $csrf], $jar);
$json = json_decode($r['body'], true) ?: [];
t_eq(502, $r['status'], 'import com download falho responde 502 (feed salvo como NOK)');
t_eq('NOK', $json['status'] ?? null, 'falha no download marca status NOK');
t_eq(0, $json['importadas'] ?? null, 'nenhuma noite importada do ics_body');
$feed = $json['feeds'][0] ?? [];
t_eq('NOK', $feed['last_status'] ?? null, 'feed gravado com last_status NOK');
$blocked = HouseRepository::blockedDays($owner['house_id']);
t_eq(0, count($blocked), 'nenhum dia bloqueado no banco');

echo "URL do feed mascarada\n";
t_eq('http://127.0.0.1/••••', $feed['url'] ?? null, 'import: feeds[].url mascarada');
t_ok(!str_contains($r['body'], 'TOKENFEED') && !str_contains($r['body'], 'bloqueado.ics'), 'import: resposta sem token/caminho');
t_eq(['id', 'property_id', 'title', 'url', 'last_status', 'last_synced_at'], array_keys($feed), 'import: só campos seguros');
$g = t_http('GET', $base . '/api/imoveis/' . $owner['hash'] . '/calendario', null, [], $jar);
t_eq(200, $g['status'], 'GET calendario API 200');
t_ok(!str_contains($g['body'], 'TOKENFEED') && (json_decode($g['body'], true)['feeds'][0]['url'] ?? null) === 'http://127.0.0.1/••••', 'GET calendario API: URL mascarada');
$w = t_http('GET', $base . '/imoveis/' . $owner['hash'] . '/calendario', null, [], $jar);
t_eq(200, $w['status'], 'tela web do calendário continua 200');
t_ok(!str_contains($w['body'], 'TOKENFEED') && str_contains($w['body'], 'Teste'), 'tela web lista o feed sem token');
t_eq('http://127.0.0.1:9/bloqueado.ics?t=TOKENFEED', HouseRepository::feedsWithUrl($owner['house_id'])[0]['url'] ?? null, 'feedsWithUrl (interno) mantém URL real; banco sem criptografia');

echo "DELETE do feed\n";
$r = t_http('DELETE', $base . '/api/imoveis/' . $owner['hash'] . '/ical/' . (int) ($feed['id'] ?? 0), null, ['X-CSRF-Token: ' . $csrf], $jar);
$json = json_decode($r['body'], true) ?: [];
t_eq(200, $r['status'], 'DELETE responde 200');
t_ok(isset($json['ressincronizados']), 'DELETE devolve resultado da ressincronização');

echo "orçamento de tempo do resync\n";
require_once CL_ROOT . '/src/CalendarSync.php';
HouseRepository::addFeed($owner['house_id'], $owner['user_id'], 'http://127.0.0.1:9/a.ics', 'A');
HouseRepository::addFeed($owner['house_id'], $owner['user_id'], 'http://127.0.0.1:9/b.ics', 'B');
$res = CalendarSync::resyncProperty($owner['house_id'], 0.0);
t_eq(2, $res['pendentes'], 'sem orçamento: todos os feeds ficam PENDENTE para o cron');
$statuses = array_column(HouseRepository::feeds($owner['house_id']), 'last_status');
t_eq(['PENDENTE', 'PENDENTE'], $statuses, 'feeds marcados como PENDENTE');
$t0 = microtime(true);
$res = CalendarSync::resyncProperty($owner['house_id']);
t_eq(2, $res['nok'], 'com orçamento: URLs internas viram NOK');
t_ok(microtime(true) - $t0 < CalendarSync::RESYNC_BUDGET_SECONDS + 1, 'resync respeita o orçamento total');

@unlink($jar);
t_done();
