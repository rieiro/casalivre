<?php

declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/src/Db.php';
require_once $root . '/src/UserRepository.php';
require_once $root . '/src/HouseRepository.php';
require_once $root . '/src/PhotoRepository.php';
require_once $root . '/src/PhotoStorage.php';
require_once $root . '/src/helpers.php';
require_once $root . '/src/CepRepository.php';
require_once $root . '/src/CityRepository.php';

Db::loadEnv();
if (!getenv('DB_DSN')) {
    fwrite(STDERR, "DB_DSN não definido (só PostgreSQL). Rode ops/dev-env/db-setup.sh ou configure o .env antes.\n");
    exit(1);
}
if (CepRepository::count() < 1) {
    fwrite(STDERR, "Base de CEP vazia. Rode scripts/load-ceps.php antes.\n");
    exit(1);
}

$first = ['Ana', 'Bruno', 'Carla', 'Diego', 'Elena', 'Felipe', 'Gabriela', 'Heitor', 'Irene', 'João', 'Larissa', 'Marina', 'Nicolas', 'Olivia', 'Paulo'];
$last = ['Almeida', 'Costa', 'Ferreira', 'Gomes', 'Lima', 'Martins', 'Nunes', 'Oliveira', 'Ribeiro', 'Souza'];
$ddds = ['11', '21', '31', '41', '48', '51', '61', '71', '81', '85'];
$houseWords = ['Casa', 'Apto', 'Chalé', 'Loft', 'Studio'];

$lines = ['Dados gerados em ' . date('d/m/Y H:i'), ''];

for ($i = 1; $i <= 5; $i++) {
    $name = $first[random_int(0, count($first) - 1)] . ' ' . $last[random_int(0, count($last) - 1)];
    $email = 'user' . bin2hex(random_bytes(3)) . '@casalivre.test';
    $password = 'Casa#' . random_int(100000, 999999);
    $userPlace = CepRepository::randomWithStreet();
    if (!$userPlace) {
        fwrite(STDERR, "Nenhum CEP com rua disponível.\n");
        exit(1);
    }
    $userNumber = (string) random_int(10, 900);
    $phone = $ddds[array_rand($ddds)] . '9' . str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT);
    $userId = UserRepository::insert([
        'name' => $name,
        'cpf' => (string) random_int(10000000000, 99999999999),
        'address' => compose_address((string) $userPlace['street'], $userNumber, ''),
        'cep' => $userPlace['cep'],
        'street' => $userPlace['street'],
        'address_number' => $userNumber,
        'address_complement' => '',
        'phone' => $phone,
        'email' => $email,
        'password_hash' => password_make($password),
        'status' => 'active',
        'activated_at' => date('Y-m-d H:i:s'),
    ]);
    $lines[] = "$i. $name";
    $lines[] = "   email: $email";
    $lines[] = "   senha: $password";
    $lines[] = "   celular: $phone";
    $houses = random_int(2, 3);
    $tries = 0;
    for ($h = 1; $h <= $houses; $h++) {
        $place = CepRepository::randomWithStreet();
        if (!$place) {
            break;
        }
        $known = CityRepository::match($place['city'] . ', ' . $place['uf']);
        if (!$known) {
            if (++$tries > 30) {
                break;
            }
            $h--;
            continue;
        }
        $bedrooms = random_int(1, 4);
        $beds = random_int($bedrooms, $bedrooms + 2);
        $guests = random_int($beds, $beds + 3);
        $number = (string) random_int(10, 400);
        $title = $houseWords[array_rand($houseWords)] . ' ' . $h;
        $description = "Imóvel $h em {$known['label']}, com varanda, cozinha equipada e espaço para até $guests hóspedes. Cadastro de teste para navegar na lista, editar dados e gerenciar fotos.";
        $houseId = HouseRepository::insert([
            'user_id' => $userId,
            'title' => $title,
            'description' => $description,
            'summary' => summary_from($description, 45),
            'city_place_id' => (int) $known['id'],
            'address' => compose_address((string) $place['street'], $number, ''),
            'cep' => $place['cep'],
            'street' => $place['street'],
            'address_number' => $number,
            'address_complement' => '',
            'lat' => (float) $known['lat'],
            'lng' => (float) $known['lng'],
            'bedrooms' => $bedrooms,
            'beds' => $beds,
            'max_guests' => $guests,
            'nightly_price' => random_int(180, 750),
            'checkin_time' => '15:00',
            'checkout_time' => '11:00',
            'min_nights' => 1,
            'pool' => random_int(0, 1),
            'bbq' => 1,
            'garage' => random_int(0, 1),
        ]);
        $binary = solid_png(random_int(20, 80), random_int(70, 140), random_int(140, 210));
        // Capa pela fila de imagens (o image-worker gera os WebP).
        $tmpCover = tempnam(sys_get_temp_dir(), 'seed');
        file_put_contents($tmpCover, $binary);
        PhotoStorage::enqueueOriginal($houseId, $tmpCover, 1, 0);
        @unlink($tmpCover);
        seed_reviews($houseId);
        $lines[] = "   imóvel: $title ({$known['label']})";
    }
    $lines[] = '';
}

$report = implode("\n", $lines);
file_put_contents($root . '/storage/seed-users.txt', $report);
echo $report;
echo "Salvo em storage/seed-users.txt\n";
echo "Entre em /entrar. Depois do login a página principal lista os imóveis.\n";

function seed_reviews(int $houseId): void
{
    $authors = ['Ana', 'Bruno', 'Carla', 'Diego', 'Elena', 'Felipe', 'Gabriela', 'Heitor', 'Irene', 'João', 'Larissa', 'Marina', 'Nicolas', 'Olivia', 'Paulo'];
    $notes = [
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
    for ($n = 0; $n < 9; $n++) {
        HouseRepository::addReview(
            $houseId,
            $authors[random_int(0, count($authors) - 1)] . ' ' . chr(65 + random_int(0, 25)) . '.',
            random_int(1, 10),
            $notes[random_int(0, count($notes) - 1)]
        );
    }
}

function solid_png(int $r, int $g, int $b): string
{
    if (!function_exists('gzcompress')) {
        return base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
    }
    $w = 320;
    $h = 200;
    $raw = str_repeat(chr(0) . str_repeat(chr($r) . chr($g) . chr($b), $w), $h);
    $ihdr = pack('NNCCCCC', $w, $h, 8, 2, 0, 0, 0);
    return "\x89PNG\r\n\x1a\n"
        . png_chunk('IHDR', $ihdr)
        . png_chunk('IDAT', gzcompress($raw, 9))
        . png_chunk('IEND', '');
}

function png_chunk(string $type, string $data): string
{
    return pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data) & 0xffffffff);
}
