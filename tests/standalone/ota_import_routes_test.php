<?php
// Schema 44: OTA listing URL content import — HTTP/route contracts (PG + php -S).
// Covers casa_privado guards, CSRF, campos_puros, rate limits, draft visibility, discard and publish.
declare(strict_types=1);
require __DIR__ . '/lib.php';
require_once CL_ROOT . '/src/OtaImportService.php';
require_once CL_ROOT . '/src/CalendarSync.php';
require_once CL_ROOT . '/src/HouseRepository.php';
require_once CL_ROOT . '/src/ListingSearch.php';

t_temp_db();
$owner = t_seed_owner();
$otaFixture = tempnam(sys_get_temp_dir(), 'otafix');
file_put_contents($otaFixture, json_encode([]));
putenv('T_OTA_HTML_FIXTURE=' . $otaFixture);
register_shutdown_function(static fn () => @unlink($otaFixture));
$base = t_server();
$jar = tempnam(sys_get_temp_dir(), 'jar');

echo "guards: casa_privado e autenticação\n";
$noCsrfApi = t_http('POST', $base . '/api/imoveis/' . $owner['hash'] . '/importar', '{}', ['Content-Type: application/json']);
t_eq(419, $noCsrfApi['status'], 'CSRF é checado antes da sessão na API (419)');
$guestJar = tempnam(sys_get_temp_dir(), 'jar');
$guestPage = t_http('GET', $base . '/entrar', null, [], $guestJar);
preg_match('/name="_csrf" value="([^"]+)"/', $guestPage['body'], $gm);
$guestCsrf = $gm[1] ?? '';
$guestApi = t_http('POST', $base . '/api/imoveis/' . $owner['hash'] . '/importar', '{}', ['Content-Type: application/json', 'X-CSRF-Token: ' . $guestCsrf], $guestJar);
t_eq(401, $guestApi['status'], 'API de importação exige sessão (401)');
@unlink($guestJar);
$guestWeb = t_http('GET', $base . '/imoveis/' . $owner['hash'] . '/editar', null, [], $jar);
t_eq(302, $guestWeb['status'], 'edição de imóvel exige login (302)');
t_ok(str_contains($guestWeb['headers'], 'Location: /entrar'), 'redireciona para /entrar');

echo "campos_puros e casa_privado declarados no front controller\n";
$index = (string) file_get_contents(CL_ROOT . '/public/index.php');
preg_match("/'campos_puros'\s*=>\s*\[([^\]]*)\]/", $index, $m);
$puros = $m[1] ?? '';
t_ok(str_contains($puros, "'property_url'"), 'property_url está em campos_puros');
t_ok(str_contains($puros, "'platform'"), 'platform está em campos_puros');
t_ok(str_contains($puros, "'consent'"), 'consent está em campos_puros');
t_ok(str_contains($index, 'importar|publicar'), 'casa_privado cobre importar/publicar');
t_ok(str_contains($index, '/imoveis/novo/importar'), 'casa_privado cobre /imoveis/novo/importar');

echo "CSRF e validação dos links\n";
$csrf = t_login($base, $jar, $owner['email'], $owner['password']);
$webNoCsrf = t_http('POST', $base . '/imoveis/' . $owner['hash'] . '/importar', 'platform=airbnb&property_url=x', ['Content-Type: application/x-www-form-urlencoded'], $jar);
t_eq(419, $webNoCsrf['status'], 'POST web sem CSRF -> 419');
$apiNoCsrf = t_http('POST', $base . '/api/imoveis/' . $owner['hash'] . '/importar', json_encode(['platform' => 'airbnb', 'property_url' => 'x']), ['Content-Type: application/json'], $jar);
t_eq(419, $apiNoCsrf['status'], 'POST API sem CSRF -> 419');

$apiHeaders = ['Content-Type: application/json', 'X-CSRF-Token: ' . $csrf];
$foreign = t_http('POST', $base . '/api/imoveis/' . $owner['hash'] . '/importar', json_encode([
    'platform' => 'airbnb', 'property_url' => 'https://evil.example/rooms/1', 'consent' => '1',
]), $apiHeaders, $jar);
t_eq(422, $foreign['status'], 'domínio estrangeiro -> 422');
$fj = json_decode($foreign['body'], true) ?: [];
t_ok(isset($fj['erros']['property_url']), 'erro aponta property_url');
$noConsent = t_http('POST', $base . '/api/imoveis/' . $owner['hash'] . '/importar', json_encode([
    'platform' => 'airbnb', 'property_url' => 'https://www.airbnb.com/rooms/12345678', 'consent' => '',
]), $apiHeaders, $jar);
t_eq(422, $noConsent['status'], 'sem consentimento -> 422');
$nj = json_decode($noConsent['body'], true) ?: [];
t_ok(isset($nj['erros']['consent']), 'erro aponta consent');

echo "limite por IP (10/10 min, web e API no mesmo balde)\n";
t_ratelimit_reset();
$codes = [];
for ($i = 0; $i < 10; $i++) {
    $codes[] = t_http('POST', $base . '/api/imoveis/' . $owner['hash'] . '/importar', json_encode([
        'platform' => 'airbnb', 'property_url' => 'https://evil.example/rooms/1', 'consent' => '1',
    ]), $apiHeaders, $jar)['status'];
}
t_ok(!in_array(429, $codes, true), '10 tentativas sem 429');
$limited = t_http('POST', $base . '/api/imoveis/' . $owner['hash'] . '/importar', json_encode([
    'platform' => 'airbnb', 'property_url' => 'https://evil.example/rooms/1', 'consent' => '1',
]), $apiHeaders, $jar);
t_eq(429, $limited['status'], '11ª tentativa -> 429');
t_ok(str_contains($limited['headers'], 'application/json'), '429 em JSON na API');
t_ratelimit_reset();

echo "rascunho privado, descarte e publicação\n";
CalendarSync::$httpTransport = static fn (): array => ['body' => '<html><head><script type="application/ld+json">'
    . json_encode([
        '@type' => 'VacationRental',
        'name' => 'Casa Route Import',
        'description' => 'Conteúdo importado pela rota.',
        'image' => ['https://a0.muscache.com/im/pictures/route.jpg'],
        'amenityFeature' => [['name' => 'Wifi']],
    ]) . '</script></head></html>', 'http' => 200];
$draft = HouseRepository::insertDraft((int) $owner['user_id']);
$draftId = (int) $draft['id'];
$started = OtaImportService::startExisting(HouseRepository::find($draftId), (int) $owner['user_id'], 'airbnb', 'https://www.airbnb.com/rooms/7654321', true);
t_eq('ok', $started->tipo, 'import via serviço cria o rascunho');
$draftRow = HouseRepository::find($draftId);
$draftHash = HouseRepository::ensurePublicHash($draftRow);
t_eq('draft', (string) $draftRow['status'], 'imóvel permanece draft');

$publicDraft = t_http('GET', $base . '/imoveis/' . $draftHash);
t_eq(404, $publicDraft['status'], 'rascunho não aparece no link público');
$editDraft = t_http('GET', $base . '/imoveis/' . $draftHash . '/editar', null, [], $jar);
t_eq(200, $editDraft['status'], 'anfitrião edita o rascunho');
t_ok(str_contains($editDraft['body'], 'Importar dados via URL'), 'painel de importação renderiza');
t_ok(str_contains($editDraft['body'], 'rascunho privado'), 'banner de rascunho renderiza');

$publishBlocked = t_http('POST', $base . '/imoveis/' . $draftHash . '/publicar', '_csrf=' . urlencode($csrf), ['Content-Type: application/x-www-form-urlencoded'], $jar);
t_eq(302, $publishBlocked['status'], 'publicar com importação não verificada redireciona');
$stillDraft = (string) db()->query('SELECT status FROM properties WHERE id = ' . $draftId)->fetchColumn();
t_eq('draft', $stillDraft, 'publicação bloqueada enquanto a importação não é verificada');

$discard = t_http('POST', $base . '/imoveis/' . $draftHash . '/importar/descartar', '_csrf=' . urlencode($csrf) . '&platform=airbnb', ['Content-Type: application/x-www-form-urlencoded'], $jar);
t_eq(302, $discard['status'], 'descartar importação redireciona');
$importStatus = (string) db()->query('SELECT status FROM property_ota_imports WHERE property_id = ' . $draftId . ' ORDER BY id DESC LIMIT 1')->fetchColumn();
t_eq('discarded', $importStatus, 'importação marcada como descartada');

$publish = t_http('POST', $base . '/imoveis/' . $draftHash . '/publicar', '_csrf=' . urlencode($csrf), ['Content-Type: application/x-www-form-urlencoded'], $jar);
t_eq(302, $publish['status'], 'publicar redireciona');
t_eq('active', (string) db()->query('SELECT status FROM properties WHERE id = ' . $draftId)->fetchColumn(), 'draft -> active');
t_eq('active', (string) db()->query('SELECT status FROM listing_search WHERE id = ' . $draftId)->fetchColumn(), 'listing_search sincronizado');
$publicNow = t_http('GET', $base . '/imoveis/' . $draftHash);
t_eq(200, $publicNow['status'], 'imóvel publicado aparece no link público');

echo "raw_html em campos_puros\n";
t_ok(str_contains($puros, "'raw_html'"), 'raw_html está em campos_puros');

echo "colagem de HTML: >1 MiB e <5 MiB passa pelo BodyLimit/CSRF\n";
$pasteHtml = static function (string $canonical, string $title, string $padding = ''): string {
    return '<html><head><link rel="canonical" href="' . $canonical . '">'
        . '<meta property="og:url" content="' . $canonical . '">'
        . '<script type="application/ld+json">' . json_encode([
            '@type' => 'VacationRental',
            'name' => $title,
            'description' => 'Conteúdo colado pela rota.',
            'image' => ['https://a0.muscache.com/im/pictures/paste.jpg'],
        ]) . '</script></head><body>' . $padding . '</body></html>';
};
t_ratelimit_reset();
$bigPaste = $pasteHtml('https://www.airbnb.com/rooms/999111', 'Casa Colada Rota', str_repeat('<p>preenchimento</p>', 60000));
t_ok(strlen($bigPaste) > 1048576 && strlen($bigPaste) < 5 * 1048576, 'fixture entre 1 MiB e 5 MiB (' . strlen($bigPaste) . ' bytes)');
$pasteRes = t_http('POST', $base . '/api/imoveis/novo/importar', json_encode([
    'platform' => 'airbnb',
    'property_url' => 'https://www.airbnb.com/rooms/999111',
    'consent' => '1',
    'raw_html' => $bigPaste,
]), $apiHeaders, $jar);
t_eq(200, $pasteRes['status'], 'POST /api/imoveis/novo/importar com raw_html -> 200');
$pasteJson = json_decode($pasteRes['body'], true) ?: [];
t_ok(str_starts_with((string) ($pasteJson['redirect'] ?? ''), '/imoveis/') && str_ends_with((string) ($pasteJson['redirect'] ?? ''), '/editar'), 'resposta traz redirect para a edição');
$pastedPropertyId = (int) db()->query("SELECT id FROM properties WHERE title = 'Casa Colada Rota' ORDER BY id DESC LIMIT 1")->fetchColumn();
t_ok($pastedPropertyId > 0, 'rascunho criado pela colagem');
$pastedImport = db()->prepare('SELECT status, sanitization_flags FROM property_ota_imports WHERE property_id = ? ORDER BY id DESC LIMIT 1');
$pastedImport->execute([$pastedPropertyId]);
$pastedRow = $pastedImport->fetch(PDO::FETCH_ASSOC) ?: [];
t_eq('draft_ready', (string) ($pastedRow['status'] ?? ''), 'importação colada fica draft_ready');
$pastedFlags = json_decode((string) ($pastedRow['sanitization_flags'] ?? ''), true) ?: [];
t_eq('html_paste', (string) ($pastedFlags['fetch_mode'] ?? ''), 'sanitization_flags registra fetch_mode html_paste');

echo "colagem acima de 5 MiB é recusada\n";
t_ratelimit_reset();
$tooBigPaste = $pasteHtml('https://www.airbnb.com/rooms/999222', 'Casa Grande', str_repeat('x', 5 * 1048576 + 1));
$tooBigRes = t_http('POST', $base . '/api/imoveis/novo/importar', json_encode([
    'platform' => 'airbnb',
    'property_url' => 'https://www.airbnb.com/rooms/999222',
    'consent' => '1',
    'raw_html' => $tooBigPaste,
]), $apiHeaders, $jar);
t_eq(413, $tooBigRes['status'], 'raw_html > 5 MiB -> 413');
$tooBigJson = json_decode($tooBigRes['body'], true) ?: [];
t_eq('payload_too_large', (string) ($tooBigJson['error_code'] ?? ''), 'error_code payload_too_large');

echo "WAF da OTA -> blocked_by_ota sem criar rascunho\n";
t_ratelimit_reset();
file_put_contents($otaFixture, json_encode([
    'https://www.booking.com/hotel/br/casa-bloqueada.html' => [
        'http' => 202,
        'headers' => ['x-page-id' => 'wildcard-challenge-handler'],
        'body' => '<html><head><title></title></head><body><script>reportChallengeError()</script></body></html>',
    ],
], JSON_UNESCAPED_SLASHES));
$blockedBefore = (int) db()->query('SELECT COUNT(*) FROM property_ota_imports')->fetchColumn();
$blockedRes = t_http('POST', $base . '/api/imoveis/novo/importar', json_encode([
    'platform' => 'booking',
    'property_url' => 'https://www.booking.com/hotel/br/casa-bloqueada.html',
    'consent' => '1',
]), $apiHeaders, $jar);
t_eq(422, $blockedRes['status'], 'HTTP 202 da OTA -> 422');
$blockedJson = json_decode($blockedRes['body'], true) ?: [];
t_eq('blocked_by_ota', (string) ($blockedJson['error_code'] ?? ''), 'error_code blocked_by_ota');
t_eq($blockedBefore, (int) db()->query('SELECT COUNT(*) FROM property_ota_imports')->fetchColumn(), 'nenhuma importação criada no bloqueio');

echo "colagem com canonical de outro anúncio -> html_url_mismatch\n";
t_ratelimit_reset();
file_put_contents($otaFixture, json_encode([]));
$spoofPaste = $pasteHtml('https://www.airbnb.com/rooms/1111111', 'Casa Spoof');
$spoofRes = t_http('POST', $base . '/api/imoveis/novo/importar', json_encode([
    'platform' => 'airbnb',
    'property_url' => 'https://www.airbnb.com/rooms/2222222',
    'consent' => '1',
    'raw_html' => $spoofPaste,
]), $apiHeaders, $jar);
t_eq(422, $spoofRes['status'], 'canonical de outro anúncio -> 422');
$spoofJson = json_decode($spoofRes['body'], true) ?: [];
t_eq('html_url_mismatch', (string) ($spoofJson['error_code'] ?? ''), 'error_code html_url_mismatch');

@unlink($jar);
t_done();
