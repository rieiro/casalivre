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

echo "import ignora ics_body\n";
$csrf = t_login($base, $jar, $owner['email'], $owner['password']);
$day = date('Ymd', strtotime('+10 days'));
$ics = "BEGIN:VCALENDAR\r\nBEGIN:VEVENT\r\nDTSTART;VALUE=DATE:$day\r\nDTEND;VALUE=DATE:" . date('Ymd', strtotime('+12 days')) . "\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
$feedUrl = 'https://feeds.example/bloqueado.ics?t=TOKENFEED';
$r = t_http('POST', $base . '/api/imoveis/' . $owner['hash'] . '/ical/importar', json_encode([
    'external_ical_url' => $feedUrl, 'feed_title' => 'Teste', 'ics_body' => $ics,
]), ['Content-Type: application/json', 'X-CSRF-Token: ' . $csrf], $jar);
$json = json_decode($r['body'], true) ?: [];
t_eq(200, $r['status'], 'import grava o feed e não baixa no pedido');
t_eq(true, $json['ok'] ?? null, 'import ok');
t_eq(0, $json['importadas'] ?? null, 'nenhuma noite importada do ics_body');
$feed = $json['feeds'][0] ?? [];
t_eq(null, $feed['last_status'], 'feed novo ainda sem status (o worker sincroniza)');
$blocked = db()->prepare('SELECT COUNT(*) FROM calendar_days WHERE property_id = ? AND blocked = 1');
$blocked->execute([$owner['house_id']]);
t_eq(0, (int) $blocked->fetchColumn(), 'nenhum dia bloqueado no banco');
$long = t_http('POST', $base . '/api/imoveis/' . $owner['hash'] . '/ical/importar', json_encode([
    'external_ical_url' => 'https://feeds.example/' . str_repeat('a', 8000), 'feed_title' => 'Longa',
]), ['Content-Type: application/json', 'X-CSRF-Token: ' . $csrf], $jar);
t_eq(422, $long['status'], 'URL de 8 KB é 422, não 500');
$badPort = t_http('POST', $base . '/api/imoveis/' . $owner['hash'] . '/ical/importar', json_encode([
    'external_ical_url' => 'https://127.0.0.1:9/bloqueado.ics', 'feed_title' => 'Porta',
]), ['Content-Type: application/json', 'X-CSRF-Token: ' . $csrf], $jar);
t_eq(422, $badPort['status'], 'porta diferente de 443 é recusada');

echo "URL do feed mascarada\n";
t_eq('https://feeds.example/••••', $feed['url'] ?? null, 'import: feeds[].url mascarada');
t_ok(!str_contains($r['body'], 'TOKENFEED') && !str_contains($r['body'], 'bloqueado.ics'), 'import: resposta sem token/caminho');
t_eq(['id', 'property_id', 'title', 'url', 'last_status', 'last_synced_at', 'last_error', 'fail_count', 'stopped'], array_keys($feed), 'import: só campos seguros');
$g = t_http('GET', $base . '/api/imoveis/' . $owner['hash'] . '/calendario', null, [], $jar);
t_eq(200, $g['status'], 'GET calendario API 200');
t_ok(!str_contains($g['body'], 'TOKENFEED') && (json_decode($g['body'], true)['feeds'][0]['url'] ?? null) === 'https://feeds.example/••••', 'GET calendario API: URL mascarada');
$w = t_http('GET', $base . '/imoveis/' . $owner['hash'] . '/calendario', null, [], $jar);
t_eq(200, $w['status'], 'tela web do calendário continua 200');
t_ok(!str_contains($w['body'], 'TOKENFEED') && str_contains($w['body'], 'Teste'), 'tela web lista o feed sem token');
$stored = db()->prepare('SELECT url FROM calendar_feeds WHERE property_id = ? ORDER BY id');
$stored->execute([$owner['house_id']]);
t_eq($feedUrl, $stored->fetchColumn(), 'a URL real fica no banco; a API devolve a mascarada');

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
