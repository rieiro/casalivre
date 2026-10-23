#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/AppEnv.php';

/** PR #32: o link de ativação não volta mais na API; roda o worker (APP_ENV=dev) e lê o último link do e-mail no mail-dev.log. */
if (!function_exists('link_ativacao')) {
    function link_ativacao(string $email): string
    {
        $root = dirname(__DIR__, 1);
        $cmd = 'APP_ENV=dev ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/scripts/mail-worker.php') . ' 2>&1';
        $arq = $root . '/storage/logs/mail-dev.log';
        for ($i = 0; $i < 5; $i++) {
            shell_exec($cmd);
            $link = '';
            foreach (is_file($arq) ? (file($arq, FILE_IGNORE_NEW_LINES) ?: []) : [] as $l) {
                if (str_contains($l, $email) && str_contains($l, '/verificar')) {
                    $link = (string) ((json_decode($l, true) ?: [])['link'] ?? $link);
                }
            }
            if ($link !== '') {
                return $link;
            }
            usleep(300000);
        }
        return '';
    }
}

// Exige APP_ENV=dev (ou local): os casos leem o link de verificação do mail-dev.log (worker da fila),
// que só é gravado nesses ambientes. O servidor de teste também deve subir com APP_ENV=dev.
if (!AppEnv::isDev()) {
    fwrite(STDERR, "ERRO: rode com APP_ENV=dev (ex.: APP_ENV=dev CASOS=todos php scripts/run-casos-de-teste.php) e suba o servidor de teste com APP_ENV=dev.\n");
    exit(2);
}

$base = getenv('APP_URL') ?: 'http://127.0.0.1:8080';
$cookie = sys_get_temp_dir() . '/casalivre-test.cookie';
@unlink($cookie);
$failures = [];

function req(string $method, string $url, array $options = []): array
{
    global $cookie;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_COOKIEJAR => $cookie,
        CURLOPT_COOKIEFILE => $cookie,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HEADER => true,
    ] + $options);
    $raw = curl_exec($ch);
    if ($raw === false) {
        throw new RuntimeException(curl_error($ch));
    }
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    return [$status, substr($raw, 0, $headerSize), substr($raw, $headerSize)];
}

function csrf_from(string $html): string
{
    if (!preg_match('/name="_csrf" value="([^"]+)"/', $html, $m)) {
        throw new RuntimeException('CSRF ausente');
    }
    return $m[1];
}

function assert_true(bool $ok, string $message): void
{
    global $failures;
    if ($ok) {
        echo "OK  $message\n";
        return;
    }
    $failures[] = $message;
    echo "FALHOU  $message\n";
}

/**
 * Limpeza de sobras de rodadas anteriores, para o CASOS rodar duas vezes seguidas no mesmo banco:
 * - contas criadas pelo Caso 1 (user<10 dígitos de time()>@casalivre.test; os seeds usam 6 hex) e, em cascata,
 *   os imóveis/fotos delas (Caso 3), com a fila de imagens e os arquivos das fotos;
 * - trava de login (tabela login_rate_limit; o Caso RL-1 a dispara de propósito) e limites por IP
 *   (IpRateLimiter no Valkey, cliente de loopback em todos os baldes de seguranca/api_limites.php; ex.: 30 envios
 *   de foto por 10 min, #39).
 */
function limpar_rodada_anterior(): void
{
    $root = dirname(__DIR__);
    require_once $root . '/src/Db.php';
    require_once $root . '/src/helpers.php';
    require_once $root . '/src/PhotoStorage.php';
    $pdo = db();
    $users = "SELECT id FROM users WHERE email ~ '^user[0-9]{10}@casalivre\\.test$'";
    $photos = $pdo->query("SELECT p.* FROM photos p JOIN properties pr ON pr.id = p.property_id WHERE pr.user_id IN ($users)")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($photos as $photo) {
        PhotoStorage::deletePhotoFiles($photo);
    }
    $pdo->beginTransaction();
    try {
        $pdo->exec("DELETE FROM image_queue WHERE photo_id IN (SELECT p.id FROM photos p JOIN properties pr ON pr.id = p.property_id WHERE pr.user_id IN ($users))");
        // properties.user_id é ON DELETE RESTRICT: apaga os imóveis antes (fotos, calendário, feeds, avaliações e convites em cascata).
        $pdo->exec("DELETE FROM properties WHERE user_id IN ($users)");
        $contas = $pdo->exec("DELETE FROM users WHERE id IN ($users)");
        $pdo->exec('DELETE FROM login_rate_limit');
        $pdo->commit();
    } catch (PDOException $e) {
        $pdo->rollBack();
        // FK: 23001 (restrict_violation, ON DELETE RESTRICT) ou 23503.
        $fk = in_array((string) $e->getCode(), ['23001', '23503'], true) ? ' (violação de FK)' : '';
        fwrite(STDERR, "Limpeza da massa falhou$fk: " . $e->getMessage() . "\n");
        exit(1);
    }
    require_once $root . '/src/IpRateLimiter.php';
    $baldes = array_unique(array_column(require $root . '/seguranca/api_limites.php', 4));
    foreach (['127.0.0.1', '::1'] as $ip) {
        try {
            IpRateLimiter::resetClient($ip, $baldes);
        } catch (Throwable $e) {
            fwrite(STDERR, 'Limpeza dos limites por IP falhou (' . $e::class . '); seguindo.' . "\n");
        }
    }
    echo "Limpeza: $contas conta(s) de rodadas anteriores, " . count($photos) . " foto(s); trava de login e limites por IP zerados\n";
}

/** Fotos são assíncronas (#39): processa a fila de imagens (o servidor de teste não roda o worker). */
function processar_fotos(int $rodadas = 3): void
{
    $cmd = 'APP_ENV=dev ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__) . '/scripts/image-worker.php') . ' 2>&1';
    for ($i = 0; $i < $rodadas; $i++) {
        shell_exec($cmd);
    }
}

limpar_rodada_anterior();

/**
 * Pré-requisito: fila de imagens vazia. Os seeds só enfileiram as capas (fotos assíncronas, #39); sem o
 * worker, imóveis dos seeds sem foto pronta podem cair na busca do Caso 4 ("cada item tem foto principal").
 * Roda o image-worker em laço até a fila esvaziar, com teto de CASOS_FILA_FOTOS_SEGUNDOS (padrão 300 s;
 * ~10 mil capas dos seeds levam ~4-5 min). No teto, avisa e segue.
 */
function esvaziar_fila_de_fotos(): void
{
    $teto = max(1, (int) (getenv('CASOS_FILA_FOTOS_SEGUNDOS') ?: 300));
    $sql = "SELECT COUNT(*) FROM image_queue WHERE status IN ('pending', 'processing')";
    $inicio = microtime(true);
    $antes = (int) db()->query($sql)->fetchColumn();
    $resta = $antes;
    while ($resta > 0 && microtime(true) - $inicio < $teto) {
        processar_fotos(1);
        $resta = (int) db()->query($sql)->fetchColumn();
    }
    $seg = round(microtime(true) - $inicio, 1);
    if ($resta > 0) {
        echo "AVISO: fila de fotos não esvaziou em {$teto} s ($resta de $antes pendentes); seguindo. Casos com fotos podem falhar; aumente CASOS_FILA_FOTOS_SEGUNDOS.\n";
        return;
    }
    echo "Fila de fotos: $antes pendente(s) processada(s) em {$seg} s\n";
}

esvaziar_fila_de_fotos();

$suite = getenv('CASOS') ?: 'todos'; // todos | legado | novos
if ($suite !== 'novos') {
echo "Caso 1: cadastro (via API) pede senha duas vezes e só ativa pelo link gravado\n";
[$status, , $html] = req('GET', $base . '/cadastro');
assert_true($status === 200, 'formulário de cadastro abre');
$csrf = csrf_from($html);
$email = 'user' . time() . '@casalivre.test';
$cadastro = [
    'name' => 'Marina Costa',
    'cpf' => '12345678901',
    'cep' => '88010000',
    'address_number' => '120',
    'phone' => '48999990000',
    'email' => $email,
    'password' => 'Senha-segura1',
];
$json = static fn (array $data): array => [
    CURLOPT_POSTFIELDS => json_encode($data),
    CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-CSRF-Token: ' . $csrf],
];
[$status, , $body] = req('POST', $base . '/api/users', $json($cadastro + ['password_confirm' => 'outra-senha']));
$resp = json_decode($body, true) ?: [];
assert_true($status === 422 && str_contains((string) ($resp['error'] ?? ''), 'não conferem'), 'senhas diferentes não criam a conta (422)');
[$status, , $body] = req('POST', $base . '/api/users', $json($cadastro + ['password_confirm' => 'Senha-segura1']));
$resp = json_decode($body, true) ?: [];
$link = $status === 201 ? link_ativacao($email) : '';
assert_true($status === 201 && ($resp['status'] ?? '') === 'pending' && preg_match('#/verificar\?token=([a-f0-9]+)#', $link, $token), 'cadastro grava o link de verificação e fica pendente (201)');
// O link do e-mail (GET) só mostra o botão "Ativar conta"; a ativação é o POST com CSRF (scanners não ativam).
[$status, , $html] = req('GET', $base . '/verificar?token=' . ($token[1] ?? ''));
assert_true($status === 200 && str_contains($html, 'action="/verificar"') && !str_contains($html, 'Conta ativada'), 'o link abre a tela de confirmação sem ativar a conta');
[$status, , $html] = req('POST', $base . '/verificar', [
    CURLOPT_POSTFIELDS => http_build_query(['_csrf' => csrf_from($html), 'token' => $token[1] ?? '']),
    CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
]);
assert_true($status === 200 && str_contains($html, 'Conta ativada'), 'o botão "Ativar conta" ativa a conta dentro do prazo');

echo "Caso 2: depois da ativação o login abre o painel\n";
[$status, , $html] = req('GET', $base . '/entrar');
$csrf = csrf_from($html);
[$status, $headers] = req('POST', $base . '/entrar', [
    CURLOPT_POSTFIELDS => http_build_query([
        '_csrf' => $csrf,
        'email' => $email,
        'password' => 'Senha-segura1',
    ]),
]);
assert_true(in_array($status, [302, 303], true) && str_contains($headers, '/painel'), 'login da conta ativa vai para o painel');
[$status, , $body] = req('GET', $base . '/painel');
assert_true($status === 200 && str_contains($body, 'Meus imóveis'), 'painel lista os imóveis do usuário');

echo "Caso 3: 20 imóveis na mesma cidade, cada um com 10 fotos\n";
$photoDir = sys_get_temp_dir() . '/casalivre-fotos';
@mkdir($photoDir);
$photoFiles = [];
for ($i = 1; $i <= 10; $i++) {
    $path = $photoDir . "/foto-$i.png";
    $img = imagecreatetruecolor(80, 60);
    $color = imagecolorallocate($img, 20 + $i * 15, 80, 160);
    imagefilledrectangle($img, 0, 0, 80, 60, $color);
    imagepng($img, $path);
    imagedestroy($img);
    $photoFiles[] = $path;
}
$checkin = (new DateTimeImmutable('+10 days'))->format('Y-m-d');
$checkout = (new DateTimeImmutable('+13 days'))->format('Y-m-d');
$created = 0;
for ($n = 1; $n <= 20; $n++) {
    $id = null;
    for ($attempt = 1; $attempt <= 3 && $id === null; $attempt++) {
        [$status, , $html] = req('GET', $base . '/imoveis/novo');
        $csrf = csrf_from($html);
        $bedrooms = random_int(1, 5);
        $beds = random_int($bedrooms, $bedrooms + 3);
        $guests = random_int($beds, $beds + 4);
        [$status, $headers, $body] = req('POST', $base . '/imoveis', [
            CURLOPT_POSTFIELDS => http_build_query([
                '_csrf' => $csrf,
                'title' => "Casa $n na Lagoa",
                'description' => "Casa ampla número $n com varanda, cozinha equipada e vista para o mar na Lagoa da Conceição. Espaço silencioso para famílias, com ventilação cruzada e área externa. Ideal para quem busca uma estadia curta com conforto e acesso fácil à praia.",
                'city' => 'Florianópolis',
                'cep' => '88010000',
                'address_number' => (string) $n,
                'bedrooms' => $bedrooms,
                'beds' => $beds,
                'max_guests' => $guests,
                'nightly_price' => (string) (200 + $n * 15),
                'checkin_time' => '15:00',
                'checkout_time' => '11:00',
                'min_nights' => 1,
                'pool' => $n % 2,
                'bbq' => 1,
                'garage' => $n % 3 === 0 ? 1 : 0,
                'lat_offset' => 0.002 * (($n % 5) - 2) / 100,
                'lng_offset' => 0.002 * (($n % 4) - 1) / 100,
            ]),
        ]);
        if (preg_match('#/imoveis/([a-f0-9]{16})/editar#', $headers, $m)) {
            $id = $m[1];
        }
    }
    if ($id === null) {
        assert_true(false, "imóvel $n criado");
        continue;
    }
    $created++;
    $photoCount = 0;
    $rounds = 0;
    // Limite #39: 30 envios de foto por 10 min por IP. Um POST por imóvel com as 10 fotos (máx. 10 por POST)
    // = 20 POSTs no caso inteiro; depois o image-worker processa a fila (fotos assíncronas).
    while ($photoCount < 10 && $rounds < 2) {
        $rounds++;
        [$status, , $html] = req('GET', $base . "/imoveis/$id/editar");
        if (preg_match('/name="_csrf" value="([^"]+)"/', $html, $csrfMatch)) {
            $fields = ['_csrf' => $csrfMatch[1]];
            foreach (array_slice(array_values($photoFiles), 0, 10) as $k => $file) {
                $fields["fotos[$k]"] = new CURLFile($file, 'image/png', basename($file));
            }
            req('POST', $base . "/imoveis/$id/fotos", [CURLOPT_POSTFIELDS => $fields]);
        }
        processar_fotos();
        [$status, , $html] = req('GET', $base . "/imoveis/$id/editar");
        preg_match('/(\d+) fotos neste anúncio/', $html, $count);
        $photoCount = (int) ($count[1] ?? 0);
    }
    assert_true($status === 200 && $photoCount >= 10, "imóvel $id com pelo menos 10 fotos (quartos/camas/hóspedes aleatórios), tem $photoCount");
}
assert_true($created === 20, "20 imóveis persistidos ($created)");

echo "Caso 4: cliente sem login busca a localidade (via /api/busca) e vê os 20 imóveis\n";
@unlink($cookie);
$query = 'localidade=' . rawurlencode('Florianópolis') . '&checkin=' . $checkin . '&checkout=' . $checkout . '&raio=25';
[$status, , $html] = req('GET', $base . '/?' . $query);
assert_true(in_array($status, [200, 302], true), "página de busca responde ($status; resultados vêm da API)");
[$status, $headers, $body] = req('GET', $base . '/api/busca?' . $query);
$busca = json_decode($body, true) ?: [];
$items = $busca['items'] ?? [];
assert_true($status === 200 && stripos($headers, 'application/json') !== false, 'GET /api/busca responde JSON sem login');
assert_true(count($items) >= 20, 'lista ao menos os 20 imóveis cadastrados, sem login (' . count($items) . ')');
$count = static fn (callable $ok): int => count(array_filter($items, $ok));
assert_true($count(static fn ($i) => trim((string) ($i['title'] ?? '')) !== '') === count($items), 'cada item tem título');
assert_true($count(static fn ($i) => !empty($i['photo'])) === count($items), 'cada item tem foto principal');
assert_true($count(static fn ($i) => (int) ($i['nightly_price'] ?? 0) > 0) === count($items), 'cada item tem diária');
assert_true($count(static fn ($i) => preg_match('/^[a-f0-9]{16}$/', (string) ($i['id'] ?? '')) === 1) === count($items), 'cada item tem id público (16 hex)');
echo "    (info) /api/busca não devolve resumo nem máximo de hóspedes por item; esses critérios do caso antigo ficam fora do contrato atual\n";

echo "Filtros extras do cliente: hóspedes, raio, diária e nota\n";
[$status, , $body] = req('GET', $base . '/api/busca?' . $query . '&hospedes=20');
$few = (json_decode($body, true) ?: [])['items'] ?? [];
assert_true($status === 200 && count($few) < count($items), 'filtro de hóspedes reduz a lista (' . count($few) . ')');
}
if ($suite !== 'legado') {
    require __DIR__ . '/casos/api-ical-log.php';
}

if ($failures) {
    echo "\n" . count($failures) . " falha(s)\n";
    exit(1);
}
echo "\nTodos os casos de teste passaram.\n";
