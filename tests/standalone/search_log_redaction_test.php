<?php
// ADR 001 §11.3 condition 6: a search must not store free-text localidade/q.
require __DIR__ . '/lib.php';
t_temp_db();
$base = t_server();
$logDir = CL_ROOT . '/storage/logs/api';
$placeId = (int) db()->query("SELECT id FROM places WHERE type = 'city' AND name_fold = 'armacao dos buzios' AND rtrim(uf) = 'RJ'")->fetchColumn();
t_ok($placeId > 0, 'Armacao dos Buzios, RJ is seeded');

$email = 'fulano@x.com';
$digits = '918273645';
$city = 'Armacao dos Buzios, RJ';
$mark = t_log_mark();
$get = static function (string $query) use ($base): array {
    $r = t_http('GET', $base . '/api/busca?' . $query);
    t_eq(200, $r['status'], 'GET /api/busca');
    return $r;
};
$requests = [
    'email' => $get('localidade=' . rawurlencode($email) . '&q=' . rawurlencode($email)),
    'digits' => $get('localidade=' . $digits),
    'city' => $get('localidade=' . rawurlencode($city) . '&hospedes=2'),
];
$cityBody = json_decode($requests['city']['body'], true);
t_eq($city, $cityBody['filters']['localidade'] ?? null, 'response still returns localidade');

$ids = [];
foreach ($requests as $name => $r) {
    preg_match('/^X-Request-Id:\s*(\S+)/mi', $r['headers'], $m);
    $ids[$name] = $m[1] ?? '';
    t_ok($ids[$name] !== '', "$name: X-Request-Id");
}

$activity = [];
$api = [];
for ($i = 0; $i < 30 && (count($activity) < 3 || count($api) < 3); $i++) {
    foreach (t_log_rows($mark) as $row) {
        if ($row['code'] === ActivityLog::SEARCH && in_array($row['access_event_id'], $ids, true)) {
            $activity[$row['access_event_id']] = $row;
        }
    }
    foreach (glob($logDir . '/api-*') ?: [] as $file) {
        foreach (file($file, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            $row = json_decode($line, true);
            if (is_array($row) && in_array($row['request_id'] ?? null, $ids, true)) {
                $api[$row['request_id']] = $row;
            }
        }
    }
    if (count($activity) < 3 || count($api) < 3) {
        usleep(100000);
    }
}

foreach (['email' => $email, 'digits' => $digits] as $name => $secret) {
    $id = $ids[$name];
    $act = $activity[$id] ?? null;
    $req = $api[$id] ?? null;
    t_ok(is_array($act) && is_array($req), "$name: activity_log and request log");
    $blob = json_encode([$act['params'] ?? null, $req['query'] ?? null], JSON_UNESCAPED_UNICODE);
    t_ok($blob !== false && !str_contains($blob, $secret), "$name: '$secret' is in neither log");
    t_ok(!array_key_exists('localidade', $act['params'] ?? []), "$name: activity_log has no localidade");
    t_ok(is_array($req['query'] ?? null) && array_key_exists('localidade', $req['query']) && $req['query']['localidade'] === null, "$name: request log stores localidade as null");
}
$emailQuery = $api[$ids['email']]['query'] ?? [];
t_ok(is_array($emailQuery) && array_key_exists('q', $emailQuery) && $emailQuery['q'] === null, 'request log stores q as null');

$logged = $activity[$ids['city']]['params'] ?? [];
t_eq($placeId, $logged['place_id'] ?? null, 'place_id of the resolved city');
t_eq(2, $logged['hospedes'] ?? null, 'structured hospedes filter stays');
t_ok(is_int($logged['resultados'] ?? null), 'resultados stays');
t_ok(!array_key_exists('localidade', $logged) && !array_key_exists('q', $logged), 'resolved city: no localidade or q in activity_log');
$cityLogs = json_encode([$logged, $api[$ids['city']]['query'] ?? null], JSON_UNESCAPED_UNICODE);
t_ok($cityLogs !== false && !str_contains($cityLogs, $city) && !str_contains($cityLogs, 'armacao dos buzios'), 'city text is not in the logs');
$cityQuery = $api[$ids['city']]['query'] ?? [];
t_ok(is_array($cityQuery) && array_key_exists('localidade', $cityQuery) && $cityQuery['localidade'] === null, 'city: request log stores localidade as null');
t_eq('2', $cityQuery['hospedes'] ?? null, 'city: request log stores hospedes');

t_done();
