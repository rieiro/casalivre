<?php
// Caracterização (PR 0 do refactor): foto de capa e avaliação, web x API.
// Registra o comportamento ATUAL. Linhas "SNAPSHOT" documentam diferenças web/API de hoje de propósito;
// não são o comportamento desejado. Se um refactor mudar alguma, atualize o teste junto com a decisão.
require __DIR__ . '/lib.php';
require __DIR__ . '/char_helpers.php';
t_temp_db();
$owner = t_seed_owner();
$other = t_seed_owner();
require_once CL_ROOT . '/src/PhotoRepository.php';
$hid = $owner['house_id'];
$p1 = (int) PhotoRepository::insert($hid, 'a.jpg', 1, 1)['id'];
$p2 = (int) PhotoRepository::insert($hid, 'b.jpg', 0, 2)['id'];
$p3 = (int) PhotoRepository::insert($hid, 'c.jpg', 0, 3)['id'];
$foreign = (int) PhotoRepository::insert($other['house_id'], 'x.jpg', 1, 1)['id'];
$act = c_activity_file();
$base = t_server();
$jar = tempnam(sys_get_temp_dir(), 'jar');
$csrf = t_login($base, $jar, $owner['email'], $owner['password']);
$cover = static function () use ($hid): int {
    $rows = array_values(array_filter(PhotoRepository::list($hid), static fn ($p) => (int) $p['is_primary'] === 1));
    return count($rows) === 1 ? (int) $rows[0]['id'] : -count($rows);
};
$h = '/imoveis/' . $owner['hash'];

echo "capa: web\n";
$m = c_mark($act);
$r = c_form($base, $h . '/fotos/capa', ['_csrf' => $csrf, 'fotos' => [$p2]], $jar);
t_eq(302, $r['status'], 'web /fotos/capa: 302');
t_eq($h . '/editar', c_location($r), 'web /fotos/capa: redireciona para /editar');
$logs = c_logs($act, $m);
t_eq([ActivityLog::PHOTO_COVER], array_column($logs, 'code'), 'web /fotos/capa: grava PHOTO_COVER');
t_eq($p2, $cover(), 'DB: capa = foto 2');
$m = c_mark($act);
$r = c_form($base, $h . '/fotos/capa', ['_csrf' => $csrf, 'fotos' => [$p1, $p3]], $jar);
// SNAPSHOT: com 0 ou 2+ fotos marcadas a web ignora em silêncio (302); a API exige foto_id (422).
t_eq(302, $r['status'], 'web /fotos/capa: 2 fotos AINDA 302 sem erro');
t_eq([], c_codes($act, $m), 'web /fotos/capa: 2 fotos não grava');
t_eq($p2, $cover(), 'DB: capa inalterada');
$m = c_mark($act);
$r = c_form($base, $h . '/fotos/' . $p3 . '/capa', ['_csrf' => $csrf], $jar);
t_eq(302, $r['status'], 'web /fotos/{id}/capa: 302');
t_eq($h . '/editar', c_location($r), 'web /fotos/{id}/capa: redireciona para /editar');
t_eq([ActivityLog::PHOTO_COVER], c_codes($act, $m), 'web /fotos/{id}/capa: grava PHOTO_COVER');
t_eq($p3, $cover(), 'DB: capa = foto 3');
$m = c_mark($act);
$r = c_form($base, $h . '/fotos/' . $foreign . '/capa', ['_csrf' => $csrf], $jar);
t_eq(302, $r['status'], 'web: foto de outro imóvel AINDA 302 (ignorada)');
t_eq([], c_codes($act, $m), 'web: foto de outro imóvel não grava');
t_eq($p3, $cover(), 'DB: capa inalterada (foto alheia)');

echo "capa: API\n";
$api = '/api' . $h . '/fotos/capa';
$m = c_mark($act);
$r = c_api('POST', $base, $api, ['foto_id' => $p1], $csrf, $jar);
t_eq(200, $r['status'], 'API: capa 200');
t_eq($p1, (int) ($r['json']['fotos'][0]['id'] ?? 0), 'API: devolve fotos com a capa primeiro');
t_eq([ActivityLog::PHOTO_COVER], c_codes($act, $m), 'API: grava PHOTO_COVER');
t_eq($p1, $cover(), 'DB: capa = foto 1');
$m = c_mark($act);
$r = c_api('POST', $base, $api, [], $csrf, $jar);
t_eq(422, $r['status'], 'API: sem foto_id 422');
$r = c_api('POST', $base, $api, ['foto_id' => $foreign], $csrf, $jar);
t_eq(200, $r['status'], 'API: foto de outro imóvel AINDA 200 (ignorada)');
t_eq([], c_codes($act, $m), 'API: erros/foto alheia não gravam');
t_eq($p1, $cover(), 'DB: capa inalterada');
$r = c_api('POST', $base, '/api/imoveis/' . $other['hash'] . '/fotos/capa', ['foto_id' => $foreign], $csrf, $jar);
t_eq(404, $r['status'], 'API: imóvel de outro dono 404');

echo "avaliação: web\n";
$anon = tempnam(sys_get_temp_dir(), 'jar');
$acsrf = c_csrf($base, $anon);
$count = static fn (): int => (int) db()->query('SELECT COUNT(*) FROM reviews WHERE property_id = ' . $hid)->fetchColumn();
$m = c_mark($act);
$r = c_form($base, $h . '/avaliar', ['_csrf' => $acsrf, 'score' => 8, 'author' => '  ', 'comment' => ' bom '], $anon);
t_eq(302, $r['status'], 'web avaliar: 302 (anônimo)');
t_eq($h, c_location($r), 'web avaliar: redireciona para o imóvel');
$logs = c_logs($act, $m);
t_eq([ActivityLog::REVIEW], array_column($logs, 'code'), 'web avaliar: grava REVIEW');
// SNAPSHOT: params.autor sai null no activity_log (nome não é gravado em claro); só a nota fica.
t_eq([8, null], [$logs[0]['params']['nota'] ?? null, $logs[0]['params']['autor'] ?? null], 'web avaliar: params nota=8, autor=null');
$row = db()->query('SELECT author, score, comment FROM reviews WHERE property_id = ' . $hid . ' ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
t_eq(['author' => 'Hóspede', 'score' => 8, 'comment' => 'bom'], ['author' => $row['author'], 'score' => (int) $row['score'], 'comment' => $row['comment']], 'DB: review gravada (trim)');
$r = c_form($base, $h . '/avaliar', ['_csrf' => $acsrf, 'score' => 9, 'volta' => '/busca?localidade=x'], $anon);
t_eq($h . '?volta=%2Fbusca%3Flocalidade%3Dx', c_location($r), 'web avaliar: volta=/busca... preservado no redirect');
$r = c_form($base, $h . '/avaliar', ['_csrf' => $acsrf, 'score' => 9, 'volta' => 'https://evil.test/'], $anon);
t_eq($h, c_location($r), 'web avaliar: volta externa descartada');
$before = $count();
$m = c_mark($act);
$r = c_form($base, $h . '/avaliar', ['_csrf' => $acsrf, 'score' => 11], $anon);
// SNAPSHOT: nota fora de 1..10 na web redireciona (302) sem gravar e sem erro; a API responde 422.
t_eq(302, $r['status'], 'web avaliar: nota inválida AINDA 302');
t_eq([], c_codes($act, $m), 'web avaliar: nota inválida não grava');
t_eq($before, $count(), 'DB: nota inválida não cria review');
$r = c_form($base, '/imoveis/' . str_repeat('0', 16) . '/avaliar', ['_csrf' => $acsrf, 'score' => 5], $anon);
t_eq(404, $r['status'], 'web avaliar: imóvel inexistente 404');
$r = c_form($base, $h . '/avaliar', ['score' => 5], $anon);
t_eq(419, $r['status'], 'web avaliar: sem CSRF 419');

echo "avaliação: API\n";
$m = c_mark($act);
$r = c_api('POST', $base, '/api' . $h . '/avaliar', ['score' => 7, 'author' => 'Ana', 'comment' => 'ok'], $acsrf, $anon);
t_eq(201, $r['status'], 'API avaliar: 201');
t_eq(['ok' => true], $r['json'], 'API avaliar: {ok:true}');
t_eq([ActivityLog::REVIEW], c_codes($act, $m), 'API avaliar: grava REVIEW');
$before = $count();
$m = c_mark($act);
$r = c_api('POST', $base, '/api' . $h . '/avaliar', ['score' => 0], $acsrf, $anon);
t_eq(422, $r['status'], 'API avaliar: nota inválida 422');
$r = c_api('POST', $base, '/api/imoveis/' . str_repeat('0', 16) . '/avaliar', ['score' => 5], $acsrf, $anon);
t_eq(404, $r['status'], 'API avaliar: imóvel inexistente 404');
t_eq([], c_codes($act, $m), 'API avaliar: erros não gravam');
t_eq($before, $count(), 'DB: erros não criam review');
db()->prepare("UPDATE properties SET status = 'paused' WHERE id = ?")->execute([$hid]);
// Mudança intencional (fix/avaliacao-rate-limit): web e API dividem o limite de 10 avaliações/h por IP.
// Este arquivo já fez 10 POSTs de avaliação até aqui; zera o limite para checar o 404 de imóvel não ativo.
t_ratelimit_reset();
$r = c_api('POST', $base, '/api' . $h . '/avaliar', ['score' => 5], $acsrf, $anon);
t_eq(404, $r['status'], 'API avaliar: imóvel não ativo 404');
$r = c_form($base, $h . '/avaliar', ['_csrf' => $acsrf, 'score' => 5], $anon);
t_eq(404, $r['status'], 'web avaliar: imóvel não ativo 404');

echo "capa com imóvel não ativo\n";
$m = c_mark($act);
// Web (house_id) e API (findByUser) exigem status=active: o dono recebe 404 em imóvel pausado nos dois canais.
$r = c_form($base, $h . '/fotos/' . $p2 . '/capa', ['_csrf' => $csrf], $jar);
t_eq(404, $r['status'], 'web: capa em imóvel pausado 404');
$r = c_api('POST', $base, $api, ['foto_id' => $p2], $csrf, $jar);
t_eq(404, $r['status'], 'API: capa em imóvel pausado 404');
t_eq($p1, $cover(), 'DB: capa inalterada');
t_eq([], c_codes($act, $m), 'imóvel pausado: nada gravado');

t_done();
