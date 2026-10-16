<?php
// Caracterização (PR 0 do refactor): POST do calendário, import iCal e exclusão de feed, web x API.
// Paridade (PR B): mesma regra, mesmos logs e mesmas mensagens nos dois canais; a web mostra o erro na tela e a
// API responde 422/409 (502 na falha de download) com {error, erros: {campo: mensagem}}.
// Sem rede externa: o import "OK" usa a URL do próprio site (/ical/{ical_hash}, lida em processo por
// exported_calendar_body) e o "NOK" usa https://127.0.0.1:9 (recusado pelo fetch).
require __DIR__ . '/lib.php';
require __DIR__ . '/char_helpers.php';
t_temp_db();
$owner = t_seed_owner();
$srcWeb = t_seed_owner();
$srcApi = t_seed_owner();
$act = c_activity_file();
$phpLog = tempnam(sys_get_temp_dir(), 'phplog');
putenv('T_PHP_ERROR_LOG=' . $phpLog);
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
t_ok(str_contains($r['body'], 'class="alert error"') && str_contains($r['body'], e(t('cal.range'))), 'web: intervalo inválido mostra o erro na tela');
t_eq([], c_codes($act, $m), 'web: intervalo inválido não grava');
$r = c_form($base, $cal, ['_csrf' => $csrf, 'acao' => 'preco', 'inicio' => $day(8), 'fim' => $day(8), 'price' => ''], $jar);
t_ok(str_contains($r['body'], 'class="alert error"') && str_contains($r['body'], e(t('cal.need_price'))), 'web: preço vazio mostra o erro na tela');
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
// Paridade (PR B): erro de validação do calendário na API é 422 com o erro por campo (mesma mensagem da web).
t_eq(422, $r['status'], 'API: intervalo inválido 422');
t_eq(null, $r['json']['ok'] ?? null, 'API: intervalo inválido sem ok:true');
t_ok(str_starts_with((string) ($r['json']['erros']['inicio'] ?? ''), t('cal.range')), 'API: erros.inicio = mensagem cal.range (a da web)');
t_eq([], c_codes($act, $m), 'API: intervalo inválido não grava');
$r = c_api('POST', $base, '/api' . $cal, ['acao' => 'preco', 'inicio' => $day(8), 'fim' => $day(8), 'price' => ''], $csrf, $jar);
t_eq([422, ['price' => t('cal.need_price')]], [$r['status'], $r['json']['erros'] ?? null], 'API: preço vazio 422 erros.price');
$r = c_api('POST', $base, '/api' . $cal, ['acao' => 'preco', 'inicio' => $day(8), 'fim' => $day(8), 'price' => '0'], $csrf, $jar);
t_eq([422, ['price' => t('cal.price_max')]], [$r['status'], $r['json']['erros'] ?? null], 'API: preço fora da faixa 422 erros.price');
$r = c_api('POST', $base, '/api/imoveis/' . $srcWeb['hash'] . '/calendario', ['acao' => 'bloquear', 'inicio' => $day(5), 'fim' => $day(5)], $csrf, $jar);
t_eq(404, $r['status'], 'API: imóvel de outro dono 404');
$r = c_form($base, '/imoveis/' . $srcWeb['hash'] . '/calendario', ['_csrf' => $csrf, 'acao' => 'bloquear', 'inicio' => $day(5), 'fim' => $day(5)], $jar);
t_eq(404, $r['status'], 'web: imóvel de outro dono 404');

echo "iCal import: web\n";
HouseRepository::upsertDay($srcWeb['house_id'], $day(20), 1, null);
HouseRepository::upsertDay($srcWeb['house_id'], $day(21), 1, null);
HouseRepository::upsertDay($srcApi['house_id'], $day(30), 1, null);
$urlWeb = 'https://casalivre.test/ical/' . $srcWeb['ical_hash'];
$urlApi = 'https://casalivre.test/ical/' . $srcApi['ical_hash'];
$imp = '/imoveis/' . $owner['hash'] . '/ical/importar';
$m = c_mark($act);
$r = c_form($base, $imp, ['_csrf' => $csrf, 'external_ical_url' => $urlWeb, 'feed_title' => 'Web'], $jar);
t_eq(200, $r['status'], 'web: import OK 200 (tela do calendário)');
$logs = c_logs($act, $m);
t_eq([ActivityLog::CALENDAR_FEED_ADD, ActivityLog::CALENDAR_SYNC], array_column($logs, 'code'), 'web: import OK grava CALENDAR_FEED_ADD + CALENDAR_SYNC');
t_eq('ok', $logs[1]['params']['resultado'] ?? null, 'web: CALENDAR_SYNC resultado=ok');
t_ok(($logs[1]['params']['novas'] ?? 0) >= 2, 'web: CALENDAR_SYNC novas >= 2');
t_ok(isset($blocked($hid)[$day(20)]) && isset($blocked($hid)[$day(21)]), 'DB: noites importadas bloqueadas');
t_eq('OK', $feeds()[0]['last_status'] ?? null, 'DB: feed web last_status=OK');

$m = c_mark($act);
$r = c_form($base, $imp, ['_csrf' => $csrf, 'external_ical_url' => $urlWeb, 'feed_title' => 'Web'], $jar);
t_eq(200, $r['status'], 'web: feed duplicado 200 (mensagem)');
t_ok(str_contains($r['body'], 'class="alert error"') && str_contains($r['body'], e(t('cal.feed_duplicate'))), 'web: duplicado mostra o erro');
// Paridade (PR B): recusa não grava CALENDAR_FEED_ADD (o feed não foi salvo), nos dois canais.
t_eq([], c_codes($act, $m), 'web: duplicado não grava');
$m = c_mark($act);
$r = c_form($base, $imp, ['_csrf' => $csrf, 'external_ical_url' => 'https://127.0.0.1:9/x.ics', 'feed_title' => ''], $jar);
t_eq(200, $r['status'], 'web: sem título 200 (mensagem)');
t_ok(str_contains($r['body'], e(t('cal.feed_title_required'))), 'web: sem título mostra o erro');
t_eq([], c_codes($act, $m), 'web: sem título não grava');
$m = c_mark($act);
$r = c_form($base, $imp, ['_csrf' => $csrf, 'external_ical_url' => '', 'feed_title' => 'Vazio'], $jar);
t_eq(200, $r['status'], 'web: URL vazia 200 (tela com erro)');
t_ok(str_contains($r['body'], 'class="alert error"') && str_contains($r['body'], e(t('cal.url_required'))) && !str_contains($r['body'], e(t('cal.link_saved'))), 'web: URL vazia recusada com erro na tela (sem "link salvo")');
t_eq([], c_codes($act, $m), 'web: URL vazia não grava');
t_eq(1, count($feeds()), 'DB: recusas não criam feed');
$m = c_mark($act);
$r = c_form($base, $imp, ['_csrf' => $csrf, 'external_ical_url' => 'https://127.0.0.1:9/web.ics', 'feed_title' => 'NokWeb'], $jar);
t_eq(200, $r['status'], 'web: download falho 200 (mensagem de erro)');
t_ok(str_contains($r['body'], 'class="alert error"') && str_contains($r['body'], e(t('cal.import_fail'))), 'web: download falho mostra o erro');
$logs = c_logs($act, $m);
t_eq([ActivityLog::CALENDAR_FEED_ADD, ActivityLog::CALENDAR_SYNC], array_column($logs, 'code'), 'web: NOK grava CALENDAR_FEED_ADD (feed salvo) + CALENDAR_SYNC');
t_eq('nok', $logs[1]['params']['resultado'] ?? null, 'web: CALENDAR_SYNC resultado=nok');
$nokWeb = array_values(array_filter($feeds(), static fn ($f) => $f['url'] === 'https://127.0.0.1:9/web.ics'))[0] ?? [];
t_eq('NOK', $nokWeb['last_status'] ?? null, 'DB: feed web NOK gravado com last_status=NOK');

echo "iCal import: API\n";
$api = '/api/imoveis/' . $owner['hash'] . '/ical/importar';
$m = c_mark($act);
$r = c_api('POST', $base, $api, ['url' => $urlApi, 'title' => 'Api'], $csrf, $jar);
t_eq([422, ['external_ical_url' => t('cal.url_required')]], [$r['status'], $r['json']['erros'] ?? null], 'API: nomes antigos url/title não são mais aceitos (422 URL obrigatória)');
t_eq([], c_codes($act, $m), 'API: url/title antigos não gravam');
$r = c_api('POST', $base, $api, ['external_ical_url' => $urlApi, 'feed_title' => 'Api'], $csrf, $jar);
t_eq(200, $r['status'], 'API: import OK 200');
t_ok(($r['json']['importadas'] ?? 0) >= 1 && !isset($r['json']['status']), 'API: {importadas} sem campo status');
$logs = c_logs($act, $m);
t_eq([ActivityLog::CALENDAR_FEED_ADD, ActivityLog::CALENDAR_SYNC], array_column($logs, 'code'), 'API: import OK grava CALENDAR_FEED_ADD + CALENDAR_SYNC (como a web)');
t_eq('ok', $logs[1]['params']['resultado'] ?? null, 'API: CALENDAR_SYNC resultado=ok');
t_ok(isset($blocked($hid)[$day(30)]), 'DB: noite importada pela API bloqueada');
$m = c_mark($act);
$r = c_api('POST', $base, $api, ['external_ical_url' => $urlApi, 'feed_title' => 'Api'], $csrf, $jar);
t_eq([409, ['external_ical_url' => t('cal.feed_duplicate')]], [$r['status'], $r['json']['erros'] ?? null], 'API: duplicado 409 com a mensagem da web');
$r = c_api('POST', $base, $api, ['external_ical_url' => 'https://127.0.0.1:9/y.ics', 'feed_title' => ''], $csrf, $jar);
t_eq([422, ['feed_title' => t('cal.feed_title_required')]], [$r['status'], $r['json']['erros'] ?? null], 'API: sem título 422 erros.feed_title');
$r = c_api('POST', $base, $api, ['external_ical_url' => '', 'feed_title' => 'Vazio'], $csrf, $jar);
t_eq([422, ['external_ical_url' => t('cal.url_required')]], [$r['status'], $r['json']['erros'] ?? null], 'API: URL vazia 422 erros.external_ical_url');
t_eq([], c_codes($act, $m), 'API: recusas não gravam activity_log');
$m = c_mark($act);
$r = c_api('POST', $base, $api, ['external_ical_url' => 'https://127.0.0.1:9/api.ics', 'feed_title' => 'NokApi'], $csrf, $jar);
t_eq(502, $r['status'], 'API: download falho 502 (não 200)');
t_eq(['NOK', 0, null, t('cal.import_fail')], [$r['json']['status'] ?? null, $r['json']['importadas'] ?? null, $r['json']['ok'] ?? null, $r['json']['error'] ?? null], 'API: NOK -> {error, status:NOK, importadas:0}, sem ok:true');
$logs = c_logs($act, $m);
t_eq([ActivityLog::CALENDAR_FEED_ADD, ActivityLog::CALENDAR_SYNC], array_column($logs, 'code'), 'API: NOK grava CALENDAR_FEED_ADD + CALENDAR_SYNC (como a web)');
t_eq('nok', $logs[1]['params']['resultado'] ?? null, 'API: CALENDAR_SYNC resultado=nok');
t_eq(4, count($feeds()), 'DB: 4 feeds (web OK, web NOK, API OK, API NOK)');

echo "calendário: noites travadas pela sincronização (conflito)\n";
$m = c_mark($act);
$r = c_api('POST', $base, '/api' . $cal, ['acao' => 'abrir', 'inicio' => $day(20), 'fim' => $day(21)], $csrf, $jar);
t_eq([409, ['inicio' => t('cal.sync_locked')]], [$r['status'], $r['json']['erros'] ?? null], 'API: abrir noites importadas 409 erros.inicio');
$w = c_form($base, $cal, ['_csrf' => $csrf, 'acao' => 'abrir', 'inicio' => $day(20), 'fim' => $day(21)], $jar);
t_ok(str_contains($w['body'], 'class="alert error"') && str_contains($w['body'], e(t('cal.sync_locked'))), 'web: mesmo conflito mostra o erro na tela');
t_eq([], c_codes($act, $m), 'conflito não grava (web e API)');

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
// Paridade (PR B): feed inexistente não muda nada; a web redireciona e a tela mostra o erro (a API: 404).
t_eq([302, $cal], [$r['status'], c_location($r)], 'web: feed inexistente 302 para o calendário');
t_eq([], c_codes($act, $m), 'web: feed inexistente não grava nada (nem ressincroniza)');
$g = t_http('GET', $base . $cal, null, [], $jar);
t_ok(str_contains($g['body'], 'class="alert error"') && str_contains($g['body'], e(t('cal.feed_missing'))), 'web: a tela do calendário mostra o erro do feed inexistente');
$g = t_http('GET', $base . $cal, null, [], $jar);
t_ok(!str_contains($g['body'], e(t('cal.feed_missing'))), 'web: a mensagem aparece uma vez só');
$m = c_mark($act);
$r = c_api('DELETE', $base, '/api/imoveis/' . $owner['hash'] . '/ical/999999', null, $csrf, $jar);
t_eq([404, t('cal.feed_missing')], [$r['status'], $r['json']['error'] ?? null], 'API: feed inexistente 404 com a mesma mensagem da web');
t_eq([], c_codes($act, $m), 'API: feed inexistente não grava');
$m = c_mark($act);
$r = c_api('DELETE', $base, '/api/imoveis/' . $owner['hash'] . '/ical/' . $byUrl($urlApi), null, $csrf, $jar);
t_eq(200, $r['status'], 'API: excluir 200');
t_ok(isset($r['json']['ressincronizados']) && is_array($r['json']['feeds'] ?? null), 'API: devolve ressincronizados + feeds');
t_eq(ActivityLog::CALENDAR_FEED_REMOVE, c_logs($act, $m)[0]['code'] ?? null, 'API: excluir grava CALENDAR_FEED_REMOVE');
t_ok(!isset($blocked($hid)[$day(30)]), 'DB: noite importada pela API liberada');
t_eq(2, count($feeds()), 'DB: sobram os 2 feeds NOK');

echo "calendar-3.js: tela usa o contrato da API (#86 D1 do Teste)\n";
$js = (string) file_get_contents(CL_ROOT . '/public/assets/pages/calendar-3.js');
t_ok(str_contains($js, 'external_ical_url: data.external_ical_url') && str_contains($js, 'feed_title: data.feed_title'), 'JS: importar envia external_ical_url/feed_title');
t_ok(!preg_match('/\\b(url|title)\\s*:\\s*data\\./', $js), 'JS: não envia mais url/title (a API recusa)');
t_ok(!preg_match('/\\balert\\(/', $js), 'JS: erros na tela, sem alert()');
t_ok(str_contains($js, 'e.data.erros') && str_contains($js, 'e.status === 502'), 'JS: mostra erros por campo (422/409) e trata o 502 (feed NOK)');
$r = c_api('POST', $base, $api, ['url' => 'https://x.test/a.ics', 'title' => 'Velho'], $csrf, $jar);
t_eq(422, $r['status'], 'API: corpo antigo {url, title} continua recusado (422)');

echo "ajustes: GET sem aviso não tem tom de erro; exclusão com aviso de sucesso; flash em vez de ?msg= (itens 1, 2, 13)\n";
$g = t_http('GET', $base . $cal, null, [], $jar); // consome o flash do DELETE da API acima
$g = t_http('GET', $base . $cal, null, [], $jar);
t_ok(!str_contains($g['body'], 'class="alert error"') && !str_contains($g['body'], 'class="alert ok"'), 'web GET sem flash: nenhum aviso (nem tom de erro vazio)');
$r = c_api('POST', $base, $api, ['external_ical_url' => 'https://127.0.0.1:9/flash.ics', 'feed_title' => 'Flash'], $csrf, $jar);
t_eq(502, $r['status'], 'API: feed NOK 502 (para excluir em seguida)');
$g = t_http('GET', $base . $cal, null, [], $jar);
t_ok(str_contains($g['body'], 'class="alert error"') && str_contains($g['body'], e(t('cal.import_fail'))), 'flash: o 502 da API aparece como erro no GET seguinte (sem ?msg=)');
$r = c_form($base, '/imoveis/' . $owner['hash'] . '/ical/' . $byUrl('https://127.0.0.1:9/flash.ics') . '/excluir', ['_csrf' => $csrf], $jar);
t_eq([302, $cal], [$r['status'], c_location($r)], 'web: excluir 302 para o calendário, sem ?msg= na URL');
$g = t_http('GET', $base . $cal, null, [], $jar);
t_ok(str_contains($g['body'], 'class="alert ok"') && str_contains($g['body'], e(t('cal.feed_removed'))), 'web: depois de excluir, aviso de sucesso (tom ok)');
$g = t_http('GET', $base . $cal, null, [], $jar);
t_ok(!str_contains($g['body'], e(t('cal.feed_removed'))), 'flash: o aviso aparece uma vez só');
$r = c_api('POST', $base, '/api' . $cal, ['acao' => 'bloquear', 'inicio' => $day(40), 'fim' => $day(40)], $csrf, $jar);
t_eq(200, $r['status'], 'API: calendário salvo 200');
$g = t_http('GET', $base . $cal, null, [], $jar);
t_ok(str_contains($g['body'], 'class="alert ok"') && str_contains($g['body'], e((string) ($r['json']['message'] ?? '-'))), 'flash: a mensagem do POST pela API aparece no GET seguinte');
$g = t_http('GET', $base . $cal . '?msg=' . rawurlencode('Mensagem forjada'), null, [], $jar);
t_ok(!str_contains($g['body'], 'Mensagem forjada'), 'web: ?msg= na URL não vira aviso');
$js = (string) file_get_contents(CL_ROOT . '/public/assets/pages/calendar-3.js');
t_ok(!str_contains($js, "searchParams.set('msg'") && !str_contains($js, "params.get('msg')"), 'JS: não usa mais ?msg= (recarrega e o aviso vem do flash)');

echo "ajustes: URL do feed só https; array recusado (itens 9, 10)\n";
$antes = count($feeds());
$m = c_mark($act);
$bad = ['javascript:alert(1)', 'ftp://x.test/a.ics', 'file:///etc/passwd', 'http://x.test/a.ics', 'https:///a.ics', 'https://x.test/a b.ics', 'data:text/calendar,BEGIN', '//x.test/a.ics'];
foreach ($bad as $u) {
    $r = c_api('POST', $base, $api, ['external_ical_url' => $u, 'feed_title' => 'Ruim'], $csrf, $jar);
    t_eq([422, ['external_ical_url' => t('cal.url_https')]], [$r['status'], $r['json']['erros'] ?? null], 'API: ' . $u . ' -> 422 erros.external_ical_url');
    $w = c_form($base, $imp, ['_csrf' => $csrf, 'external_ical_url' => $u, 'feed_title' => 'Ruim'], $jar);
    t_ok(str_contains($w['body'], 'class="alert error"') && str_contains($w['body'], e(t('cal.url_https'))), 'web: ' . $u . ' -> erro na tela');
}
foreach ([['https://x.test/a.ics'], ['u' => 'https://x.test/a.ics'], 123, true] as $u) {
    $r = c_api('POST', $base, $api, ['external_ical_url' => $u, 'feed_title' => 'Ruim'], $csrf, $jar);
    t_eq([422, ['external_ical_url' => t('err.field_type')]], [$r['status'], $r['json']['erros'] ?? null], 'API: external_ical_url ' . json_encode($u) . ' -> 422 campo inválido (não vira "Array", não é a mensagem de https)');
}
$w = c_form($base, $imp, ['_csrf' => $csrf, 'external_ical_url' => ['https://x.test/a.ics'], 'feed_title' => 'Ruim'], $jar);
t_ok(str_contains($w['body'], e(t('err.field_type'))), 'web: external_ical_url[] (array) -> erro de campo inválido na tela');
$r = c_api('POST', $base, $api, ['external_ical_url' => 'https://x.test/t.ics', 'feed_title' => ['x']], $csrf, $jar);
t_eq([422, ['feed_title' => t('err.field_type')]], [$r['status'], $r['json']['erros'] ?? null], 'API: feed_title array -> 422 erros.feed_title (campo inválido)');
t_eq([], c_codes($act, $m), 'URLs recusadas (web e API): nada no activity_log');
t_eq($antes, count($feeds()), 'DB: nenhum feed criado (nem "Array")');
t_eq(0, $byUrl('Array'), 'DB: nenhum feed com URL "Array"');

echo "ajustes: excluir feed inexistente não grava em properties (item 11)\n";
$prop = static fn (): array => db()->query('SELECT external_ical_url, audit_hold FROM properties WHERE id = ' . $hid)->fetch(PDO::FETCH_ASSOC);
db()->prepare('UPDATE properties SET external_ical_url = NULL, audit_hold = NULL WHERE id = ?')->execute([$hid]);
$antesProp = $prop();
c_form($base, '/imoveis/' . $owner['hash'] . '/ical/999999/excluir', ['_csrf' => $csrf], $jar);
t_eq($antesProp, $prop(), 'web: feed inexistente não muda external_ical_url (NULL fica NULL) nem audit_hold');
c_api('DELETE', $base, '/api/imoveis/' . $owner['hash'] . '/ical/999999', null, $csrf, $jar);
t_eq($antesProp, $prop(), 'API: idem');

echo "ajustes 2: CalendarSave valida o tipo bruto (b, e)\n";
$m = c_mark($act);
$apiCal = '/api' . $cal;
foreach (['inicio' => [$day(5)], 'fim' => ['x' => 1], 'acao' => ['bloquear'], 'price' => [300], 'min_nights' => [2], 'day' => [$day(5)]] as $campo => $v) {
    $body = ['acao' => 'bloquear', 'inicio' => $day(5), 'fim' => $day(5)];
    $body[$campo] = $v;
    $r = c_api('POST', $base, $apiCal, $body, $csrf, $jar);
    t_eq([422, [$campo => t('err.field_type')]], [$r['status'], $r['json']['erros'] ?? null], "API calendário: $campo array -> 422 erros.$campo");
}
foreach ([true, false] as $v) {
    $r = c_api('POST', $base, $apiCal, ['acao' => $v, 'inicio' => $day(5), 'fim' => $day(5)], $csrf, $jar);
    t_eq(422, $r['status'], 'API calendário: acao ' . json_encode($v) . ' -> 422');
}
foreach (['inicio', 'fim', 'acao', 'price'] as $campo) {
    $form = ['_csrf' => $csrf, 'acao' => 'bloquear', 'inicio' => $day(5), 'fim' => $day(5)];
    $form[$campo] = ['x'];
    $w = c_form($base, $cal, $form, $jar);
    t_ok(str_contains($w['body'], 'class="alert error"') && str_contains($w['body'], e(t('err.field_type'))), "web calendário: {$campo}[] -> erro de campo inválido na tela");
}
t_eq([], c_codes($act, $m), 'tipo inválido (web e API): nada gravado no activity_log');
$r = c_api('POST', $base, $apiCal, ['acao' => 'preco', 'inicio' => $day(45), 'fim' => $day(45), 'price' => 300], $csrf, $jar);
t_eq(200, $r['status'], 'API calendário: price numérico (int) continua aceito');

echo "ajustes 2: setExternalCalendar removido (f)\n";
t_ok(!method_exists(HouseRepository::class, 'setExternalCalendar'), 'HouseRepository::setExternalCalendar não existe mais');

echo "D1 #91: web com acao[] / inicio[] só mostra o erro (sem aviso do PHP, nada gravado)\n";
$m = c_mark($act);
file_put_contents($phpLog, '');
$casos = [
    'acao[]' => ['_csrf' => $csrf, 'acao' => ['bloquear'], 'inicio' => $day(50), 'fim' => $day(50)],
    'acao=salvar + inicio[]' => ['_csrf' => $csrf, 'acao' => 'salvar', 'inicio' => [$day(50)], 'fim' => $day(50), 'price' => '300'],
    'acao=salvar + fim[]' => ['_csrf' => $csrf, 'acao' => 'salvar', 'inicio' => $day(50), 'fim' => [$day(50)]],
    'cal_view[]' => ['_csrf' => $csrf, 'acao' => 'salvar', 'inicio' => $day(50), 'fim' => $day(50), 'cal_view' => ['day']],
];
foreach ($casos as $label => $form) {
    $w = c_form($base, $cal, $form, $jar);
    t_ok($w['status'] === 200 && str_contains($w['body'], 'class="alert error"') && str_contains($w['body'], e(t('err.field_type'))), "web $label: erro de campo inválido na tela");
    t_ok(!str_contains($w['body'], 'Array to string') && !str_contains($w['body'], 'data-cal-restore'), "web $label: sem aviso no corpo e sem restaurar o formulário");
}
$log = (string) file_get_contents($phpLog);
t_ok(!str_contains($log, 'Array to string conversion') && !preg_match('/PHP (Warning|Notice|Deprecated)/', $log), 'log do PHP sem avisos nos casos com array' . ($log !== '' ? ': ' . substr($log, 0, 300) : ''));
t_eq([], c_codes($act, $m), 'nada gravado no activity_log');
t_ok(!isset($blocked($hid)[$day(50)]), 'DB: noite não bloqueada');

t_done();
