<?php

declare(strict_types=1);

// ADR 001 PR2: place_search_keys autocomplete, submitted-text resolution, local_id 301.
require __DIR__ . '/lib.php';
require_once CL_ROOT . '/src/SearchService.php';
require_once CL_ROOT . '/src/Redirect.php';
require_once CL_ROOT . '/src/HouseRepository.php';
require_once CL_ROOT . '/src/HouseService.php';
require_once CL_ROOT . '/src/CepRepository.php';

t_temp_db();

echo "suggest SQL\n";
$sql = (new ReflectionClass(CityRepository::class))->getReflectionConstant('PREFIX_SQL')->getValue();
t_ok(is_string($sql) && str_contains($sql, 'MAX(popularity)') && str_contains($sql, 'GROUP BY place_id') && str_contains($sql, 'LIMIT 9'), 'suggest SQL is the §11.2 prefix scan');
t_ok(str_contains($sql, "key LIKE ? ESCAPE '\\'"), 'prefix LIKE is escaped');
t_ok(!str_contains($sql, 'kind'), 'suggest SQL does not read kind');

echo "aliases\n";
$official = CityRepository::resolve('Armação dos Búzios, RJ')['place'];
t_ok(is_array($official), 'nome oficial resolve');
foreach (['buzios', 'Búzios', 'buzios, rj', 'Búzios, RJ', 'BUZIOS,rj'] as $alias) {
    t_eq((int) $official['id'], (int) (CityRepository::resolve($alias)['place']['id'] ?? 0), "alias \"$alias\"");
}
t_eq(null, CityRepository::resolve('Búzios, SP')['place'], 'Búzios, SP continua null');
t_eq('alias', (string) db()->query("SELECT kind FROM place_search_keys WHERE \"key\" = 'buzios' AND place_id = " . (int) $official['id'])->fetchColumn(), 'seed grava kind alias');

echo "autocomplete\n";
t_eq(CityRepository::suggest('sao'), CityRepository::suggest('São'), 'sao e São são a mesma lista');
t_eq([], CityRepository::suggest('f'), 'uma letra não sugere');
t_eq([], CityRepository::suggest('%a'), 'curinga % não varre a tabela');
t_eq([], CityRepository::suggest('__'), 'curinga _ não varre a tabela');
$forte = array_column(CityRepository::suggest('forte'), 'name');
$geriba = array_column(CityRepository::suggest('geriba'), 'name');
t_ok(count($forte) <= 8 && in_array('Praia do Forte', $forte, true), 'forte traz Praia do Forte no top 8');
t_ok(count($geriba) <= 8 && in_array('Praia de Geribá', $geriba, true), 'geriba traz Praia de Geribá no top 8');

echo "resolução\n";
$rio = CityRepository::resolve('Rio de Janeiro, RJ')['place'];
t_eq((int) $rio['id'], (int) CityRepository::resolve('Rio de Jan')['place']['id'], 'Rio de Jan é um match');
$one = SearchService::search(SearchQuery::fromRequest(['localidade' => 'Rio de Jan']), null);
t_eq(false, $one['unresolved'], 'Rio de Jan não fica unresolved');
t_eq(null, $one['resolution'], 'um hit não tem lista');
t_ok($one['iteracoes'] >= 1 && $one['iteracoes'] <= SearchService::MAX_QUERIES, 'um hit lista em até 4 queries');

$sao = SearchService::search(SearchQuery::fromRequest(['localidade' => 'São']), null);
t_eq(true, $sao['unresolved'], 'São não busca');
t_eq('ambiguous', $sao['resolution'], 'São tem 9+ hits: lista ambígua, sem escolher');
t_eq(8, count($sao['options']), 'São mostra 8 opções');
t_eq(0, $sao['iteracoes'], 'São não roda query de listagem');
t_eq([], $sao['items'], 'São não devolve imóveis');

$mean = SearchService::search(SearchQuery::fromRequest(['localidade' => 'praia do f']), null);
t_eq(true, $mean['unresolved'], 'praia do f não escolhe sozinho');
t_eq('did_you_mean', $mean['resolution'], 'praia do f tem 2 a 8 hits');
t_ok(in_array('Praia do Forte', array_column($mean['options'], 'name'), true), 'did you mean inclui Praia do Forte');
t_eq(0, $mean['iteracoes'], 'did you mean não lista imóveis');

$geriba = CityRepository::resolve('geriba');
t_eq(null, $geriba['place'], 'geriba não escolhe um lugar');
t_eq('did_you_mean', $geriba['resolution'], 'geriba é did_you_mean');
$geribaNames = array_column($geriba['options'], 'name');
t_ok(in_array('Geribá', $geribaNames, true) && in_array('Praia de Geribá', $geribaNames, true), 'geriba lista o bairro e a praia');
$geribaTypes = array_values(array_unique(array_column($geriba['options'], 'type')));
sort($geribaTypes);
t_eq(['neighborhood', 'poi'], $geribaTypes, 'geriba mistura bairro e poi');

$forteRes = CityRepository::resolve('forte');
t_eq(null, $forteRes['place'], 'forte não escolhe um lugar');
t_eq('did_you_mean', $forteRes['resolution'], 'forte é did_you_mean');
$forteNames = array_column($forteRes['options'], 'name');
t_ok(in_array('Forte', $forteNames, true) && in_array('Praia do Forte', $forteNames, true), 'forte lista o bairro e a praia');

$centro = CityRepository::resolve('Centro');
t_eq(null, $centro['place'], 'Centro não escolhe um lugar');
t_eq('ambiguous', $centro['resolution'], 'Centro tem 9+ hits');
t_eq(8, count($centro['options']), 'Centro mostra 8 opções');
t_ok(in_array('Centro', array_column($centro['options'], 'name'), true), 'Centro lista o bairro');

$miss = SearchService::search(SearchQuery::fromRequest(['localidade' => 'Xyzabc']), null);
t_eq(true, $miss['unresolved'], 'Xyzabc é unresolved');
t_eq('not_found', $miss['resolution'], 'Xyzabc é not_found');
t_eq([], $miss['options'], 'not_found não traz opções');
t_eq(0, $miss['iteracoes'], 'not_found não lista');
t_eq(0, $miss['linhas_lidas'], 'not_found não lê linhas');

$byId = SearchService::search(SearchQuery::fromRequest(['local_id' => (string) $rio['id']]), null);
t_eq(false, $byId['unresolved'], 'local_id da cidade busca');
t_eq($one['iteracoes'], $byId['iteracoes'], 'local_id e o texto único leem o mesmo número de queries');

echo "HTTP\n";
$base = t_server();
$id = (int) $rio['id'];
$page = t_http('GET', $base . '/busca?localidade=' . rawurlencode('Rio de Jan') . '&checkin=2030-01-10');
t_eq(301, $page['status'], 'localidade com um hit redireciona 301');
t_ok(preg_match('/^Location: \/busca\?local_id=' . $id . '&checkin=2030-01-10\s*$/mi', $page['headers']) === 1, 'Location é /busca?local_id e preserva o resto');
t_ok(!str_contains($page['headers'], 'localidade='), 'Location não leva o texto');
$stay = t_http('GET', $base . '/busca?localidade=' . rawurlencode('São'));
t_eq(200, $stay['status'], 'texto ambíguo não redireciona');
$unknown = t_http('GET', $base . '/busca?localidade=' . rawurlencode('Xyzabc'));
t_eq(200, $unknown['status'], 'texto sem hit não redireciona');

echo "Location segura\n";
$locationOf = static function (array $r): ?string {
    return preg_match('/^Location:\s*([^\r\n]*)/mi', $r['headers'], $m) === 1 ? $m[1] : null;
};
$assertInternal = static function (array $r, string $label) use ($locationOf): void {
    $loc = $locationOf($r);
    t_eq(301, $r['status'], "$label redireciona");
    t_ok(is_string($loc) && str_starts_with($loc, '/busca?'), "$label Location começa em /busca");
    t_ok(is_string($loc) && preg_match('#^(?:[a-z][a-z0-9+.-]*:)?//#i', $loc) !== 1, "$label não é URL absoluta nem protocol-relative");
    t_ok(is_string($loc) && !str_contains($loc, "\r") && !str_contains($loc, "\n"), "$label Location sem CR/LF");
    $path = is_string($loc) ? (string) (parse_url($loc, PHP_URL_PATH) ?? '') : '';
    t_eq('/busca', $path, "$label o caminho é /busca");
    t_eq(1, preg_match_all('/^Location:/mi', $r['headers']), "$label um só Location");
    t_ok(!preg_match('/^Set-Cookie:/mi', $r['headers']), "$label sem injeção de header");
};
$placeQ = rawurlencode('Rio de Jan');
$payloads = [
    '//evil.com',
    "\r\nSet-Cookie: x=1",
    '\\evil.com',
    '\\\\evil.com',
    '/\\evil.com',
];
foreach ($payloads as $payload) {
    $encoded = rawurlencode($payload);
    $withOther = t_http('GET', $base . '/busca?localidade=' . $placeQ . '&outro=' . $encoded);
    $assertInternal($withOther, 'outro=' . json_encode($payload));
    $asLocalidade = t_http('GET', $base . '/busca?localidade=' . $encoded . '&outro=' . $encoded);
    $loc = $locationOf($asLocalidade);
    t_ok($loc === null || (str_starts_with($loc, '/busca') && preg_match('#^(?:[a-z][a-z0-9+.-]*:)?//#i', $loc) !== 1), 'localidade=' . json_encode($payload) . ' não abre redirect externo');
    t_ok(!preg_match('/^Set-Cookie:\s*x=1/mi', $asLocalidade['headers']), 'localidade=' . json_encode($payload) . ' sem header injetado');
}
$canon = t_http('GET', $base . '/busca?local_id=' . $id);
t_eq(200, $canon['status'], 'local_id canônico responde 200');
t_ok(str_contains($canon['body'], 'name="local_id" value="' . $id . '"'), 'o formulário guarda local_id');
$api = json_decode(t_http('GET', $base . '/api/busca?localidade=' . rawurlencode('São'))['body'], true);
t_eq(200, t_http('GET', $base . '/api/busca?localidade=' . rawurlencode('São'))['status'], 'a API não redireciona');
t_eq(true, $api['unresolved'] ?? false, 'API São unresolved');
t_eq('ambiguous', $api['resolution'] ?? null, 'API São ambiguous');
t_eq([], $api['items'] ?? null, 'API São sem itens');

echo "cidade exata\n";
$owner = t_seed_owner();
$spId = (int) db()->query("SELECT id FROM places WHERE type = 'city' AND uf = 'SP' AND name_fold = 'sao paulo'")->fetchColumn();
t_ok($spId > 0, 'São Paulo/SP existe como cidade');
t_eq(null, CityRepository::resolve('São Paulo, SP')['place'], 'resolve não escolhe São Paulo quando há um bairro homônimo');
$exact = CityRepository::exactCity('São Paulo', 'SP');
t_eq($spId, (int) ($exact['id'] ?? 0), 'exactCity acha a cidade');
t_eq('city', (string) ($exact['type'] ?? ''), 'exactCity devolve type city');
$house = [
    'user_id' => $owner['user_id'], 'title' => 'Casa SP', 'description' => 'Descrição de teste do imóvel.',
    'summary' => 'Resumo', 'address' => 'Rua B, 2', 'cep' => '01001000',
    'street' => 'Rua B', 'address_number' => '2', 'address_complement' => '', 'lat' => -23.55, 'lng' => -46.63,
    'bedrooms' => 1, 'beds' => 1, 'max_guests' => 2, 'nightly_price' => 200, 'checkin_time' => '15:00',
    'checkout_time' => '11:00', 'min_nights' => 1, 'pool' => 0, 'bbq' => 0, 'garage' => 0,
];
$spHouse = HouseRepository::insert($house + ['city_place_id' => t_city_place_id('São Paulo, SP')]);
t_eq($spId, (int) HouseRepository::find($spHouse)['city_place_id'], 'insert com homônimo grava a cidade');
t_eq('city', (string) db()->query('SELECT type FROM places WHERE id = ' . (int) HouseRepository::find($spHouse)['city_place_id'])->fetchColumn(), 'o id gravado é cidade');
t_eq(null, t_city_place_id('Geribá, RJ'), 'Geribá, RJ não vira city_place_id');
t_eq(null, CityRepository::exactCity('Geribá', 'RJ'), 'exactCity não devolve o bairro Geribá');
db()->prepare('INSERT INTO ceps (cep, street, neighborhood, city, uf) VALUES (?, ?, ?, ?, ?)')->execute(['01001000', 'Praca da Se', 'Se', 'São Paulo', 'SP']);
db()->prepare('INSERT INTO ceps (cep, street, neighborhood, city, uf) VALUES (?, ?, ?, ?, ?)')->execute(['28950000', 'Rua da Praia', 'Geriba', 'Geribá', 'RJ']);
$built = HouseService::fromInput((int) $owner['user_id'], [
    'title' => 'Casa SP', 'description' => 'Descrição de teste do imóvel.', 'cep' => '01001000',
    'address_number' => '10', 'max_guests' => '2', 'nightly_price' => '200', 'bedrooms' => '1', 'beds' => '1',
]);
t_eq($spId, (int) ($built['city_place_id'] ?? 0), 'HouseService resolve o CEP da cidade homônima');
db()->prepare('UPDATE properties SET lat = ?, lng = ? WHERE id = ?')->execute([-23.5, -46.5, $spHouse]);
$edited = HouseService::update((int) $owner['user_id'], $spHouse, [
    'title' => 'Casa SP', 'description' => 'Descrição de teste do imóvel.', 'cep' => '01001000',
    'address_number' => '10', 'max_guests' => '2', 'nightly_price' => '200', 'bedrooms' => '1', 'beds' => '1',
]);
t_ok(!isset($edited['error']), 'editar a casa na mesma cidade');
$kept = HouseRepository::find($spHouse);
t_eq(-23.5, (float) ($kept['lat'] ?? 0), 'editar a casa na mesma cidade mantém lat');
t_eq(-46.5, (float) ($kept['lng'] ?? 0), 'editar a casa na mesma cidade mantém lng');
$rejected = HouseService::fromInput((int) $owner['user_id'], [
    'title' => 'Casa G', 'description' => 'Descrição de teste do imóvel.', 'cep' => '28950000',
    'address_number' => '10', 'max_guests' => '2', 'nightly_price' => '200', 'bedrooms' => '1', 'beds' => '1',
]);
t_ok(isset($rejected['error']), 'HouseService não aceita um CEP cujo nome é só bairro');
t_eq((string) $exact['label'], city_label_for_cep('01001000'), 'rótulo do CEP homônimo é o da cidade');

echo "alias sem cidade\n";
$aliasPdo = db();
$aliasPdo->beginTransaction();
try {
    $aliasPdo->exec("UPDATE places SET name_fold = name_fold || ' x' WHERE type = 'city' AND name_fold = 'armacao dos buzios' AND uf = 'RJ'");
    $aliasError = null;
    try {
        (new ReflectionMethod(CityRepository::class, 'seedAliases'))->invoke(null, $aliasPdo);
    } catch (RuntimeException $e) {
        $aliasError = $e;
    }
    t_ok($aliasError instanceof RuntimeException, 'alias sem cidade lança RuntimeException');
    t_ok($aliasError instanceof RuntimeException && str_contains($aliasError->getMessage(), 'Armação dos Búzios') && str_contains($aliasError->getMessage(), 'RJ'), 'a mensagem traz o nome e a uf do alias');
} finally {
    if ($aliasPdo->inTransaction()) {
        $aliasPdo->rollBack();
    }
}

t_done();
