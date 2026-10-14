<?php
// Caracterização (PR 0 do refactor): POST do calendário, import iCal e exclusão de feed, web x API.
// Registra o comportamento ATUAL. Linhas "SNAPSHOT" documentam diferenças web/API de hoje de propósito;
// não são o comportamento desejado. Se um refactor mudar alguma, atualize o teste junto com a decisão.
// Sem rede externa: o import "OK" usa a URL do próprio site (/ical/{ical_hash}, lida em processo por
// exported_calendar_body) e o "NOK" usa http://127.0.0.1:9 (recusado pelo fetch).
require __DIR__ . '/lib.php';
require __DIR__ . '/char_helpers.php';
t_temp_db();
$owner = t_seed_owner();
$srcWeb = t_seed_owner();
$srcApi = t_seed_owner();
$act = c_activity_file();
$base = t_server();
$jar = tempnam(sys_get_temp_dir(), 'jar');
$csrf = t_login($base, $jar, $owner['email'], $owner['password']);
$hid = $owner['house_id'];
$cal = '/imoveis/' . $owner['hash'] . '/calendario';
$day = static fn (int $n): string => date('Y-m-d', strtotime("+$n days"));
$blocked = static fn (int $id): array => array_column(HouseRepository::blockedDays($id), 'origin', 'day');
$feeds = static fn (): array => HouseRepository::feedsWithUrl($hid);

echo "calendário: web\n";
$m = c_mark($act);
$r = c_form($base, $cal, ['_csrf' => $csrf, 'acao' => 'bloquear', 'inicio' => $day(5), 'fim' => $day(6)], $jar);
t_eq(200, $r['status'], 'web: bloquear responde 200 (re-renderiza a tela, sem redirect)');
t_eq(null, c_location($r), 'web: bloquear sem redirect');
$logs = c_logs($act, $m);
t_eq([ActivityLog::CALENDAR_BLOCK], array_column($logs, 'code'), 'web: bloquear grava CALENDAR_BLOCK');
t_eq([$day(5), $day(6)], [$logs[0]['params']['inicio'] ?? null, $logs[0]['params']['fim'] ?? null], 'web: params inicio/fim');
t_eq([$day(5) => 'sistema', $day(6) => 'sistema'], $blocked($hid), 'DB: 2 noites bloqueadas origin=sistema');
$m = c_mark($act);
$r = c_form($base, $cal, ['_csrf' => $csrf, 'acao' => 'preco', 'inicio' => $day(8), 'fim' => $day(8), 'price' => '300'], $jar);
t_eq(200, $r['status'], 'web: preço 200');
$logs = c_logs($act, $m);
t_eq([ActivityLog::CALENDAR_PRICE], array_column($logs, 'code'), 'web: preço grava CALENDAR_PRICE');
t_eq(300, $logs[0]['params']['diaria'] ?? null, 'web: CALENDAR_PRICE params.diaria=300');
$m = c_mark($act);
$r = c_form($base, $cal, ['_csrf' => $csrf, 'acao' => 'bloquear', 'inicio' => $day(-3), 'fim' => $day(-2)], $jar);
t_eq(200, $r['status'], 'web: intervalo no passado 200 com mensagem');
t_eq([], c_codes($act, $m), 'web: intervalo inválido não grava');
t_eq(2, count($blocked($hid)), 'DB: nada mudou com intervalo inválido');

echo "calendário: API\n";
$m = c_mark($act);
$r = c_api('POST', $base, '/api' . $cal, ['acao' => 'abrir', 'inicio' => $day(5), 'fim' => $day(5)], $csrf, $jar);
t_eq(200, $r['status'], 'API: abrir 200');
t_eq(true, $r['json']['ok'] ?? null, 'API: {ok:true}');
t_ok(is_string($r['json']['message'] ?? null) && is_array($r['json']['days'] ?? null), 'API: devolve message + days');
t_eq([ActivityLog::CALENDAR_UNBLOCK], c_codes($act, $m), 'API: abrir grava CALENDAR_UNBLOCK');
t_eq([$day(6) => 'sistema'], $blocked($hid), 'DB: sobra 1 noite bloqueada');
$m = c_mark($act);
$r = c_api('POST', $base, '/api' . $cal, ['acao' => 'bloquear', 'inicio' => $day(-3), 'fim' => $day(-2)], $csrf, $jar);
// SNAPSHOT: erro de validação do calendário na API ainda responde 200 {ok:true} com a mensagem de erro.
t_eq(200, $r['status'], 'API: intervalo inválido AINDA responde 200');
t_eq(true, $r['json']['ok'] ?? null, 'API: intervalo inválido AINDA devolve ok:true (só message indica o erro)');
t_eq([], c_codes($act, $m), 'API: intervalo inválido não grava');
$r = c_api('POST', $base, '/api/imoveis/' . $srcWeb['hash'] . '/calendario', ['acao' => 'bloquear', 'inicio' => $day(5), 'fim' => $day(5)], $csrf, $jar);
t_eq(404, $r['status'], 'API: imóvel de outro dono 404');
$r = c_form($base, '/imoveis/' . $srcWeb['hash'] . '/calendario', ['_csrf' => $csrf, 'acao' => 'bloquear', 'inicio' => $day(5), 'fim' => $day(5)], $jar);
t_eq(404, $r['status'], 'web: imóvel de outro dono 404');

echo "iCal import: web\n";
HouseRepository::upsertDay($srcWeb['house_id'], $day(20), 1, null);
HouseRepository::upsertDay($srcWeb['house_id'], $day(21), 1, null);
HouseRepository::upsertDay($srcApi['house_id'], $day(30), 1, null);
$urlWeb = $base . '/ical/' . $srcWeb['ical_hash'];
$urlApi = $base . '/ical/' . $srcApi['ical_hash'];
$imp = '/imoveis/' . $owner['hash'] . '/ical/importar';
$m = c_mark($act);
$r = c_form($base, $imp, ['_csrf' => $csrf, 'external_ical_url' => $urlWeb, 'feed_title' => 'Web'], $jar);
t_eq(200, $r['status'], 'web: import OK 200 (tela do calendário)');
$logs = c_logs($act, $m);
t_eq([ActivityLog::CALENDAR_SYNC, ActivityLog::CALENDAR_FEED_ADD], array_column($logs, 'code'), 'web: import OK grava CALENDAR_SYNC + CALENDAR_FEED_ADD');
t_eq('ok', $logs[0]['params']['resultado'] ?? null, 'web: CALENDAR_SYNC resultado=ok');
t_ok(($logs[0]['params']['novas'] ?? 0) >= 2, 'web: CALENDAR_SYNC novas >= 2');
t_ok(isset($blocked($hid)[$day(20)]) && isset($blocked($hid)[$day(21)]), 'DB: noites importadas bloqueadas');
t_eq('OK', $feeds()[0]['last_status'] ?? null, 'DB: feed web last_status=OK');

$m = c_mark($act);
$r = c_form($base, $imp, ['_csrf' => $csrf, 'external_ical_url' => $urlWeb, 'feed_title' => 'Web'], $jar);
t_eq(200, $r['status'], 'web: feed duplicado 200 (mensagem)');
// SNAPSHOT: a web grava CALENDAR_FEED_ADD mesmo quando o feed é recusado (duplicado/limite/título/URL vazia).
t_eq([ActivityLog::CALENDAR_FEED_ADD], c_codes($act, $m), 'web: duplicado AINDA grava CALENDAR_FEED_ADD');
$m = c_mark($act);
$r = c_form($base, $imp, ['_csrf' => $csrf, 'external_ical_url' => 'http://127.0.0.1:9/x.ics', 'feed_title' => ''], $jar);
t_eq(200, $r['status'], 'web: sem título 200 (mensagem)');
t_eq([ActivityLog::CALENDAR_FEED_ADD], c_codes($act, $m), 'web: sem título AINDA grava CALENDAR_FEED_ADD');
$m = c_mark($act);
$r = c_form($base, $imp, ['_csrf' => $csrf, 'external_ical_url' => '', 'feed_title' => 'Vazio'], $jar);
t_eq(200, $r['status'], 'web: URL vazia 200 ("link salvo")');
t_eq([ActivityLog::CALENDAR_FEED_ADD], c_codes($act, $m), 'web: URL vazia AINDA grava CALENDAR_FEED_ADD');
t_eq(1, count($feeds()), 'DB: recusas não criam feed');
$m = c_mark($act);
$r = c_form($base, $imp, ['_csrf' => $csrf, 'external_ical_url' => 'http://127.0.0.1:9/web.ics', 'feed_title' => 'NokWeb'], $jar);
t_eq(200, $r['status'], 'web: download falho 200 (mensagem de erro)');
// SNAPSHOT: na web, falha de download NÃO grava CALENDAR_SYNC (só FEED_ADD); a API grava SYNC resultado=nok.
t_eq([ActivityLog::CALENDAR_FEED_ADD], c_codes($act, $m), 'web: NOK grava só CALENDAR_FEED_ADD (sem CALENDAR_SYNC)');
$nokWeb = array_values(array_filter($feeds(), static fn ($f) => $f['url'] === 'http://127.0.0.1:9/web.ics'))[0] ?? [];
t_eq('NOK', $nokWeb['last_status'] ?? null, 'DB: feed web NOK gravado com last_status=NOK');

echo "iCal import: API\n";
$api = '/api/imoveis/' . $owner['hash'] . '/ical/importar';
$m = c_mark($act);
$r = c_api('POST', $base, $api, ['url' => $urlApi, 'title' => 'Api'], $csrf, $jar);
t_eq(200, $r['status'], 'API: import OK 200');
t_ok(($r['json']['importadas'] ?? 0) >= 1 && !isset($r['json']['status']), 'API: {importadas} sem campo status');
// SNAPSHOT: a API NÃO grava CALENDAR_FEED_ADD; só CALENDAR_SYNC.
t_eq([ActivityLog::CALENDAR_SYNC], c_codes($act, $m), 'API: import OK grava só CALENDAR_SYNC (sem CALENDAR_FEED_ADD)');
t_ok(isset($blocked($hid)[$day(30)]), 'DB: noite importada pela API bloqueada');
$m = c_mark($act);
$r = c_api('POST', $base, $api, ['url' => $urlApi, 'title' => 'Api'], $csrf, $jar);
t_eq(409, $r['status'], 'API: duplicado 409');
$r = c_api('POST', $base, $api, ['url' => 'http://127.0.0.1:9/y.ics', 'title' => ''], $csrf, $jar);
t_eq(422, $r['status'], 'API: sem título 422');
$r = c_api('POST', $base, $api, ['url' => '', 'title' => 'Vazio'], $csrf, $jar);
t_eq(422, $r['status'], 'API: URL vazia 422');
t_eq([], c_codes($act, $m), 'API: recusas não gravam activity_log');
$m = c_mark($act);
$r = c_api('POST', $base, $api, ['url' => 'http://127.0.0.1:9/api.ics', 'title' => 'NokApi'], $csrf, $jar);
t_eq(200, $r['status'], 'API: download falho AINDA responde 200');
t_eq(['NOK', 0, true], [$r['json']['status'] ?? null, $r['json']['importadas'] ?? null, $r['json']['ok'] ?? null], 'API: NOK -> {ok:true, status:NOK, importadas:0}');
$logs = c_logs($act, $m);
t_eq([ActivityLog::CALENDAR_SYNC], array_column($logs, 'code'), 'API: NOK grava CALENDAR_SYNC');
t_eq('nok', $logs[0]['params']['resultado'] ?? null, 'API: CALENDAR_SYNC resultado=nok');
t_eq(4, count($feeds()), 'DB: 4 feeds (web OK, web NOK, API OK, API NOK)');

echo "excluir feed\n";
$byUrl = static function (string $url) use ($feeds): int {
    foreach ($feeds() as $f) {
        if ($f['url'] === $url) {
            return (int) $f['id'];
        }
    }
    return 0;
};
$m = c_mark($act);
$r = c_form($base, '/imoveis/' . $owner['hash'] . '/ical/' . $byUrl($urlWeb) . '/excluir', ['_csrf' => $csrf], $jar);
t_eq(302, $r['status'], 'web: excluir 302');
t_eq($cal, c_location($r), 'web: excluir redireciona para o calendário');
$logs = c_logs($act, $m);
t_eq(ActivityLog::CALENDAR_FEED_REMOVE, $logs[0]['code'] ?? null, 'web: excluir grava CALENDAR_FEED_REMOVE primeiro');
t_ok(!isset($blocked($hid)[$day(20)]), 'DB: noites importadas do feed removido liberadas');
t_eq(0, $byUrl($urlWeb), 'DB: feed web removido');
$m = c_mark($act);
$r = c_form($base, '/imoveis/' . $owner['hash'] . '/ical/999999/excluir', ['_csrf' => $csrf], $jar);
// SNAPSHOT: na web, feed inexistente ainda redireciona (302) sem erro; a API responde 404.
t_eq(302, $r['status'], 'web: feed inexistente AINDA 302');
t_ok(!in_array(ActivityLog::CALENDAR_FEED_REMOVE, c_codes($act, $m), true), 'web: feed inexistente não grava CALENDAR_FEED_REMOVE');
$m = c_mark($act);
$r = c_api('DELETE', $base, '/api/imoveis/' . $owner['hash'] . '/ical/999999', null, $csrf, $jar);
t_eq(404, $r['status'], 'API: feed inexistente 404');
t_eq([], c_codes($act, $m), 'API: feed inexistente não grava');
$m = c_mark($act);
$r = c_api('DELETE', $base, '/api/imoveis/' . $owner['hash'] . '/ical/' . $byUrl($urlApi), null, $csrf, $jar);
t_eq(200, $r['status'], 'API: excluir 200');
t_ok(isset($r['json']['ressincronizados']) && is_array($r['json']['feeds'] ?? null), 'API: devolve ressincronizados + feeds');
t_eq(ActivityLog::CALENDAR_FEED_REMOVE, c_logs($act, $m)[0]['code'] ?? null, 'API: excluir grava CALENDAR_FEED_REMOVE');
t_ok(!isset($blocked($hid)[$day(30)]), 'DB: noite importada pela API liberada');
t_eq(2, count($feeds()), 'DB: sobram os 2 feeds NOK');

t_done();
