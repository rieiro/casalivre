<?php
// Caracterização (PR 0 do refactor): foto de capa e avaliação, web x API.
// Paridade (PR C): validação da capa e da nota nas ações comuns (CoverSet, ReviewAdd); a web mostra o erro na tela
// e a API responde 422 com {error, erros: {campo: mensagem}}. Linhas "SNAPSHOT" restantes são retratos de hoje.
require __DIR__ . '/lib.php';
require __DIR__ . '/char_helpers.php';
t_temp_db();
$owner = t_seed_owner();
$other = t_seed_owner();
require_once CL_ROOT . '/src/PhotoRepository.php';
require_once CL_ROOT . '/src/Acoes/CoverSet.php';
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
$editNotice = static function () use ($base, $h, $jar): string {
    $g = t_http('GET', $base . $h . '/editar', null, [], $jar);
    return $g['body'];
};
foreach (['2 fotos' => [$p1, $p3], '0 fotos' => []] as $label => $marked) {
    $r = c_form($base, $h . '/fotos/capa', ['_csrf' => $csrf, 'fotos' => $marked], $jar);
    t_eq([302, $h . '/editar'], [$r['status'], c_location($r)], "web /fotos/capa: $label 302 para /editar");
    t_ok(str_contains($editNotice(), e(CoverSet::ERRO)), "web /fotos/capa: $label mostra o erro na tela de edição");
}
t_ok(!str_contains($editNotice(), e(CoverSet::ERRO)), 'web: o aviso aparece uma vez só');
t_eq([], c_codes($act, $m), 'web /fotos/capa: 0/2 fotos não grava');
t_eq($p2, $cover(), 'DB: capa inalterada');
$m = c_mark($act);
$r = c_form($base, $h . '/fotos/' . $p3 . '/capa', ['_csrf' => $csrf], $jar);
t_eq(302, $r['status'], 'web /fotos/{id}/capa: 302');
t_eq($h . '/editar', c_location($r), 'web /fotos/{id}/capa: redireciona para /editar');
t_eq([ActivityLog::PHOTO_COVER], c_codes($act, $m), 'web /fotos/{id}/capa: grava PHOTO_COVER');
t_eq($p3, $cover(), 'DB: capa = foto 3');
$m = c_mark($act);
$r = c_form($base, $h . '/fotos/' . $foreign . '/capa', ['_csrf' => $csrf], $jar);
t_eq(302, $r['status'], 'web: foto de outro imóvel 302');
t_ok(str_contains($editNotice(), e(CoverSet::ERRO)), 'web: foto de outro imóvel mostra o erro (não ignora)');
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
t_eq([422, ['foto_id' => CoverSet::ERRO]], [$r['status'], $r['json']['erros'] ?? null], 'API: sem foto_id 422 com a mesma mensagem da web');
$r = c_api('POST', $base, $api, ['foto_id' => $foreign], $csrf, $jar);
t_eq([422, ['foto_id' => CoverSet::ERRO]], [$r['status'], $r['json']['erros'] ?? null], 'API: foto de outro imóvel 422 (como a web)');
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
t_eq(422, $r['status'], 'web avaliar: nota inválida 422');
t_ok(str_contains($r['body'], e('Nota deve ser entre 1 e 10.')), 'web avaliar: nota inválida mostra o erro na tela');
$r = c_form($base, $h . '/avaliar', ['_csrf' => $acsrf, 'score' => 0], $anon);
t_eq(422, $r['status'], 'web avaliar: nota 0 422');
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
t_eq([422, ['score' => 'Nota deve ser entre 1 e 10.']], [$r['status'], $r['json']['erros'] ?? null], 'API avaliar: nota inválida 422 erros.score (mesma mensagem da web)');
$r = c_api('POST', $base, '/api/imoveis/' . str_repeat('0', 16) . '/avaliar', ['score' => 5], $acsrf, $anon);
t_eq(404, $r['status'], 'API avaliar: imóvel inexistente 404');
t_eq([], c_codes($act, $m), 'API avaliar: erros não gravam');
t_eq($before, $count(), 'DB: erros não criam review');
echo "fotos: PHOTO_ADD com enviadas e salvas nos dois canais\n";
$img = imagecreatetruecolor(8, 8);
ob_start();
imagejpeg($img);
$jpeg = (string) ob_get_clean();
$multipart = static function (array $fields, string $bound) use ($jpeg): string {
    $b = '';
    foreach ($fields as $k => $v) {
        $b .= "--$bound\r\nContent-Disposition: form-data; name=\"$k\"\r\n\r\n$v\r\n";
    }
    // Uma foto válida e um arquivo que não é imagem: enviadas 2, salvas 1.
    $b .= "--$bound\r\nContent-Disposition: form-data; name=\"fotos[0]\"; filename=\"ok.jpg\"\r\nContent-Type: image/jpeg\r\n\r\n$jpeg\r\n";
    $b .= "--$bound\r\nContent-Disposition: form-data; name=\"fotos[1]\"; filename=\"x.jpg\"\r\nContent-Type: image/jpeg\r\n\r\nnao-e-imagem\r\n";
    return $b . "--$bound--\r\n";
};
foreach (['web' => [$h . '/fotos', ['_csrf' => $csrf], []], 'API' => ['/api/casas/' . $owner['hash'] . '/fotos', [], ['X-CSRF-Token: ' . $csrf]]] as $canal => [$url, $fields, $extra]) {
    $m = c_mark($act);
    $r = t_http('POST', $base . $url, $multipart($fields, 'pcbound'), array_merge(['Content-Type: multipart/form-data; boundary=pcbound'], $extra), $jar);
    t_eq($canal === 'web' ? 302 : 201, $r['status'], "$canal fotos: " . ($canal === 'web' ? '302' : '201'));
    $logs = c_logs($act, $m);
    t_eq([ActivityLog::PHOTO_ADD], array_column($logs, 'code'), "$canal fotos: grava PHOTO_ADD");
    t_eq([2, 1], [$logs[0]['params']['enviadas'] ?? null, $logs[0]['params']['salvas'] ?? null], "$canal fotos: PHOTO_ADD enviadas=2 salvas=1");
    if ($canal === 'API') {
        t_eq([1, 2], [$r['json']['salvas'] ?? (json_decode($r['body'], true)['salvas'] ?? null), json_decode($r['body'], true)['enviadas'] ?? null], 'API fotos: devolve salvas e enviadas');
    } else {
        t_ok(str_contains($editNotice(), 'Salvas 1 de 2 fotos'), 'web fotos: aviso "Salvas 1 de 2" na tela de edição');
    }
}

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
