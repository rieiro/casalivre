#!/usr/bin/env php
<?php

declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/src/Db.php';
require_once $root . '/src/Settings.php';
require_once $root . '/src/UserRepository.php';
require_once $root . '/src/HouseRepository.php';
require_once $root . '/src/PhotoRepository.php';
require_once $root . '/src/PhotoStorage.php';
require_once $root . '/src/helpers.php';
require_once $root . '/src/CepRepository.php';
require_once $root . '/src/CityRepository.php';

const MASSIVE_CITIES = 100;
const MASSIVE_HOUSES = 100;

Db::loadEnv();
if (!getenv('DB_DSN')) {
    fwrite(STDERR, "DB_DSN não definido (só PostgreSQL). Rode ops/dev-env/db-setup.sh ou configure o .env antes.\n");
    exit(1);
}
if (CepRepository::count() < 1) {
    fwrite(STDERR, "Base de CEP vazia. Rode scripts/load-ceps.php antes.\n");
    exit(1);
}

$already = (int) db()->query("SELECT COUNT(*) FROM users WHERE email LIKE 'massa%@casalivre.test'")->fetchColumn();
if ($already > 0) {
    fwrite(STDERR, "Já existem contas massaNNN@casalivre.test. Apague essas contas antes de rodar de novo.\n");
    exit(1);
}

$cities = massive_cities(MASSIVE_CITIES);
if (count($cities) < MASSIVE_CITIES) {
    fwrite(STDERR, 'Só há ' . count($cities) . " cidades com CEP e rua que também existem na lista de cidades. São necessárias " . MASSIVE_CITIES . ".\n");
    exit(1);
}

$today = new DateTimeImmutable('today');
$last = $today->modify('+6 months');
$evenDays = [];
for ($day = $today; $day <= $last; $day = $day->modify('+1 day')) {
    if (((int) $day->format('j')) % 2 === 0) {
        $evenDays[] = $day->format('Y-m-d');
    }
}

$cover = massive_cover($root);
$places = db()->prepare("SELECT cep, street, neighborhood FROM ceps WHERE city = ? AND uf = ? AND street != '' LIMIT " . MASSIVE_HOUSES);
$block = db()->prepare("INSERT INTO calendar_days (property_id, day, blocked, price, min_nights, origin) VALUES (?, ?, 1, NULL, NULL, '')");
$review = db()->prepare('INSERT INTO reviews (property_id, author, score, comment, created_at) VALUES (?, ?, ?, ?, ?)');
$ddds = ['11', '21', '31', '41', '48', '51', '61', '71', '81', '85'];
$lines = [
    'Carga massiva em ' . date('d/m/Y H:i'),
    MASSIVE_CITIES . ' cidades, ' . MASSIVE_HOUSES . ' imóveis por usuário.',
    'Datas pares bloqueadas de ' . $today->format('d/m/Y') . ' até ' . $last->format('d/m/Y') . ' (' . count($evenDays) . ' dias).',
    '9 avaliações por imóvel.',
    '',
];

foreach ($cities as $index => $city) {
    $n = $index + 1;
    $email = sprintf('massa%03d@casalivre.test', $n);
    $password = 'Casa#' . str_pad((string) $n, 3, '0', STR_PAD_LEFT) . 'x9';
    $places->execute([$city['cep_city'], $city['uf']]);
    $streets = $places->fetchAll();
    if (!$streets) {
        fwrite(STDERR, "Sem rua para {$city['label']}.\n");
        exit(1);
    }
    $first = $streets[0];
    $number = (string) (100 + $n);
    $phone = $ddds[$index % count($ddds)] . '9' . str_pad((string) $n, 8, '0', STR_PAD_LEFT);
    $pdo = db();
    $pdo->beginTransaction();
    $userId = UserRepository::insert([
        'name' => 'Usuário ' . $city['label'],
        'cpf' => str_pad((string) (10000000000 + $n), 11, '0', STR_PAD_LEFT),
        'address' => compose_address((string) $first['street'], $number, ''),
        'cep' => $first['cep'],
        'street' => $first['street'],
        'address_number' => $number,
        'address_complement' => '',
        'phone' => $phone,
        'email' => $email,
        'password_hash' => password_make($password),
        'status' => 'active',
        'activated_at' => date('Y-m-d H:i:s'),
    ]);
    for ($h = 1; $h <= MASSIVE_HOUSES; $h++) {
        $place = $streets[($h - 1) % count($streets)];
        $houseNumber = (string) $h;
        $title = 'Casa ' . $h;
        $description = 'Imóvel ' . $h . ' em ' . $city['label'] . '. Cadastro da carga massiva, com as datas pares bloqueadas no calendário.';
        $houseId = HouseRepository::insert([
            'user_id' => $userId,
            'title' => $title,
            'description' => $description,
            'summary' => summary_from($description, 45),
            'city_place_id' => (int) $city['id'],
            'neighborhood_place_id' => CityRepository::neighborhoodId((int) $city['id'], (string) $place['neighborhood']),
            'address' => compose_address((string) $place['street'], $houseNumber, ''),
            'cep' => $place['cep'],
            'street' => $place['street'],
            'address_number' => $houseNumber,
            'address_complement' => '',
            'lat' => (float) $city['lat'],
            'lng' => (float) $city['lng'],
            'bedrooms' => 2,
            'beds' => 3,
            'max_guests' => 4,
            'nightly_price' => 200 + ($h % 50),
            'checkin_time' => '15:00',
            'checkout_time' => '11:00',
            'min_nights' => 1,
            'pool' => 0,
            'bbq' => 0,
            'garage' => 1,
        ]);
        // Capa pela fila de imagens (o image-worker gera os WebP).
        if (PhotoStorage::enqueueOriginal($houseId, $cover, 1, 0) === null) {
            throw new RuntimeException('Não foi possível enfileirar a foto de capa.');
        }
        massive_reviews($review, $houseId);
        foreach ($evenDays as $iso) {
            $block->execute([$houseId, $iso]);
        }
    }
    $pdo->commit();
    $lines[] = sprintf('%03d. %s', $n, $city['label']);
    $lines[] = "   email: $email";
    $lines[] = "   senha: $password";
    $lines[] = '   imóveis: ' . MASSIVE_HOUSES;
    $lines[] = '';
    echo $n . '/' . MASSIVE_CITIES . ' ' . $city['label'] . "\n";
}

db()->exec('UPDATE properties SET rating_sum = COALESCE((SELECT SUM(score) FROM reviews r WHERE r.property_id = properties.id), 0), rating_count = (SELECT COUNT(*) FROM reviews r WHERE r.property_id = properties.id)');
require_once $root . '/src/ListingSearch.php';
ListingSearch::rewriteAll();

$report = implode("\n", $lines);
file_put_contents($root . '/storage/seed-massive.txt', $report);
echo "Salvo em storage/seed-massive.txt\n";
echo "Total: " . (MASSIVE_CITIES * MASSIVE_HOUSES) . " imóveis. Entre com massa001@casalivre.test e a senha do relatório.\n";

function massive_reviews(PDOStatement $review, int $houseId): void
{
    static $authors = ['Ana', 'Bruno', 'Carla', 'Diego', 'Elena', 'Felipe', 'Gabriela', 'Heitor', 'Irene', 'João', 'Larissa', 'Marina', 'Nicolas', 'Olivia', 'Paulo'];
    static $notes = [
        'Estadia tranquila, casa limpa e bem localizada.',
        'Gostamos da cozinha e do quarto. Voltaria.',
        'Bom custo-benefício. O check-in foi simples.',
        'Local silencioso e anfitrião atencioso.',
        'Ficou aquém do anúncio, mas a cama era confortável.',
        'Perfeito para família. Recomendo.',
        'Ótima estadia, tudo funcionando.',
        'Banheiro espaçoso e bairro agradável.',
        'Experiência ok, algumas coisas simples para melhorar.',
        'Adoramos a varanda e a vista.',
        'Limpeza caprichada e comunicação rápida.',
        'Confortável para o fim de semana.',
    ];
    $at = date('Y-m-d H:i:s');
    for ($n = 0; $n < 9; $n++) {
        $review->execute([
            $houseId,
            $authors[random_int(0, 14)] . ' ' . chr(65 + random_int(0, 25)) . '.',
            random_int(1, 10),
            $notes[random_int(0, 11)],
            $at,
        ]);
    }
}

function massive_cities(int $needed): array
{
    $known = [];
    foreach (db()->query("SELECT id, name, uf, label, lat, lng, name_fold FROM places WHERE type = 'city' ORDER BY id") as $row) {
        $key = $row['name_fold'] . '|' . strtolower(rtrim((string) $row['uf']));
        if (!isset($known[$key])) {
            $row['uf'] = rtrim((string) $row['uf']);
            $known[$key] = $row;
        }
    }
    $picked = [];
    $seen = [];
    $rows = db()->query("SELECT city, uf FROM ceps WHERE street != '' GROUP BY city, uf ORDER BY city, uf");
    foreach ($rows as $row) {
        $key = fold((string) $row['city']) . '|' . strtolower((string) $row['uf']);
        if (!isset($known[$key]) || isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $city = $known[$key];
        $picked[] = [
            'id' => (int) $city['id'],
            'cep_city' => (string) $row['city'],
            'uf' => (string) $row['uf'],
            'label' => (string) $city['label'],
            'lat' => $city['lat'],
            'lng' => $city['lng'],
        ];
        if (count($picked) >= $needed) {
            break;
        }
    }
    return $picked;
}

function massive_cover(string $root): string
{
    $dir = $root . '/public/uploads/seed';
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Não foi possível criar a pasta da capa.');
    }
    $path = $dir . '/capa.png';
    if (!is_file($path)) {
        file_put_contents($path, massive_png());
    }
    return $path;
}

function massive_png(): string
{
    if (!function_exists('gzcompress')) {
        return base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
    }
    $w = 320;
    $h = 200;
    $raw = str_repeat(chr(0) . str_repeat(chr(40) . chr(90) . chr(160), $w), $h);
    $ihdr = pack('NNCCCCC', $w, $h, 8, 2, 0, 0, 0);
    return "\x89PNG\r\n\x1a\n"
        . massive_chunk('IHDR', $ihdr)
        . massive_chunk('IDAT', gzcompress($raw, 9))
        . massive_chunk('IEND', '');
}

function massive_chunk(string $type, string $data): string
{
    return pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data) & 0xffffffff);
}
