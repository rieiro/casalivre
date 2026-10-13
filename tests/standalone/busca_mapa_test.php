<?php
// /busca/mapa: o mapa recebe os imóveis da busca em data-points (antes a rota passava results = [] e o mapa
// não desenhava nada). Mesmo conjunto de /api/busca; contagem de count($results); sem busca -> lista vazia;
// a busca é registrada uma vez no servidor e a página não chama /api/busca.
require __DIR__ . '/lib.php';
t_temp_db();
$owner = t_seed_owner();
db()->prepare("UPDATE properties SET status = 'active' WHERE id = ?")->execute([$owner['house_id']]);
$actFile = tempnam(sys_get_temp_dir(), 'act') . '.sqlite';
putenv('ACTIVITY_DB_PATH=' . $actFile);
$base = t_server();
$points = static function (string $body): ?array {
    if (!preg_match('/<div id="result-map" data-points="([^"]*)"/', $body, $m)) {
        return null;
    }
    return json_decode(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5), true);
};
$loc = urlencode('Florianópolis, SC');

echo "mapa com busca\n";
$r = t_http('GET', $base . '/busca/mapa?localidade=' . $loc);
t_eq(200, $r['status'], 'GET /busca/mapa responde 200');
$p = $points($r['body']) ?? [];
$api = json_decode(t_http('GET', $base . '/api/busca?localidade=' . $loc)['body'], true)['items'] ?? [];
t_ok(count($api) >= 1, 'API encontra o imóvel semeado (' . count($api) . ')');
t_eq(count($api), count($p), 'data-points tem o mesmo número de imóveis da API');
t_eq('Casa Teste', $p[0]['title'] ?? null, 'ponto com o título do imóvel');
t_ok(abs(($p[0]['lat'] ?? 0) - (-27.59)) < 0.5 && abs(($p[0]['lng'] ?? 0) - (-48.54)) < 0.5, 'ponto com lat/lng do imóvel');
t_ok(str_starts_with($p[0]['href'] ?? '', '/imoveis/' . $owner['hash'] . '?'), 'ponto aponta para /imoveis/{hash}');
t_ok(!str_contains($r['body'], 'data-points="[]"'), 'data-points não vem vazio');
t_ok(preg_match('/<p class="muted" data-map-count>(\d+) /', $r['body'], $cm) === 1, 'página mostra a contagem');
t_eq(count($p), (int) ($cm[1] ?? -1), 'contagem = count($results) = número de pontos');
t_ok(!str_contains($r['body'], '/api/busca') && !str_contains($r['body'], 'use_api') && !str_contains($r['body'], 'via API'), 'página não chama /api/busca (sem busca duplicada)');

echo "sem busca / sem resultado\n";
t_eq([], $points(t_http('GET', $base . '/busca/mapa')['body']), 'sem filtros -> data-points vazio');
t_eq([], $points(t_http('GET', $base . '/busca/mapa?localidade=' . urlencode('Cidade Inexistente XYZ'))['body']), 'localidade sem imóveis -> vazio');

echo "imóvel inativo fica fora\n";
db()->prepare("UPDATE properties SET status = 'paused' WHERE id = ?")->execute([$owner['house_id']]);
$apiOff = json_decode(t_http('GET', $base . '/api/busca?localidade=' . $loc)['body'], true)['items'] ?? [];
t_eq(0, count($apiOff), 'API não lista imóvel pausado');
t_eq([], $points(t_http('GET', $base . '/busca/mapa?localidade=' . $loc)['body']), 'mapa também não mostra imóvel pausado');

echo "log: a página do mapa registra exatamente uma busca\n";
$count = static function () use ($actFile): int {
    $pdo = new PDO('sqlite:' . $actFile, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    return (int) $pdo->query('SELECT COUNT(*) FROM activity_log WHERE action_code = ' . ActivityLog::SEARCH)->fetchColumn();
};
$before = $count();
t_http('GET', $base . '/busca/mapa?localidade=' . $loc);
t_eq($before + 1, $count(), '/busca/mapa grava exatamente 1 SEARCH');
t_http('GET', $base . '/api/busca?localidade=' . $loc);
t_eq($before + 2, $count(), 'controle: /api/busca grava mais 1 SEARCH');

t_done();
