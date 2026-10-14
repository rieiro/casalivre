<?php
// Massa de dados determinística para os testes E2E do CasaLivre.
// Uso: php seed-e2e.php <raiz-do-app> <arquivo-json-de-saida>
declare(strict_types=1);

$root = $argv[1] ?? getcwd();
$out = $argv[2] ?? ($root . '/e2e-fixture.json');
chdir($root);
foreach (['Db', 'UserRepository', 'HouseRepository', 'PhotoRepository', 'PhotoStorage', 'helpers', 'CepRepository', 'CityRepository'] as $f) {
    require_once $root . '/src/' . $f . '.php';
}

$senha = 'Casa#E2e2026';
$cep = CepRepository::find('01001000');
$known = CityRepository::match('São Paulo, SP');
if (!$cep || !$known) {
    fwrite(STDERR, "CEP 01001000 ou cidade São Paulo ausente na base.\n");
    exit(1);
}

function e2e_user(string $email, string $senha, array $cep, string $nome): int
{
    return UserRepository::insert([
        'name' => $nome,
        'cpf' => '52998224725',
        'address' => compose_address((string) $cep['street'], '100', ''),
        'cep' => $cep['cep'],
        'street' => $cep['street'],
        'address_number' => '100',
        'address_complement' => '',
        'phone' => '11987654321',
        'email' => $email,
        'password_hash' => password_make($senha),
        'status' => 'active',
        'activated_at' => date('Y-m-d H:i:s'),
    ]);
}

$dono = e2e_user('e2e.dono@casalivre.test', $senha, $cep, 'Dona E2E');
e2e_user('e2e.reset@casalivre.test', $senha, $cep, 'Reset E2E');
e2e_user('e2e.senhaerrada@casalivre.test', $senha, $cep, 'Senha Errada E2E');
e2e_user('e2e.csrf@casalivre.test', $senha, $cep, 'CSRF E2E');

$houseId = HouseRepository::insert([
    'user_id' => $dono,
    'title' => 'Casa E2E Sé',
    'description' => 'Imóvel de teste E2E na Praça da Sé, com cozinha equipada e espaço para até 4 hóspedes.',
    'summary' => 'Imóvel de teste E2E na Praça da Sé',
    'city' => $known['label'],
    'address' => compose_address((string) $cep['street'], '10', ''),
    'cep' => $cep['cep'],
    'street' => $cep['street'],
    'address_number' => '10',
    'address_complement' => '',
    'lat' => (float) $known['lat'],
    'lng' => (float) $known['lng'],
    'bedrooms' => 2,
    'beds' => 3,
    'max_guests' => 4,
    'nightly_price' => 300,
    'checkin_time' => '15:00',
    'checkout_time' => '11:00',
    'min_nights' => 1,
    'pool' => 0,
    'bbq' => 1,
    'garage' => 0,
]);
$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
$public = PhotoStorage::writeGenerated($dono, $houseId, 'capa.png', $png);
PhotoRepository::insert($houseId, $public, 1, 0);

$house = HouseRepository::find($houseId);
file_put_contents($out, json_encode([
    'senha' => $senha,
    'dono' => 'e2e.dono@casalivre.test',
    'reset' => 'e2e.reset@casalivre.test',
    'senhaErrada' => 'e2e.senhaerrada@casalivre.test',
    'csrf' => 'e2e.csrf@casalivre.test',
    'casaHash' => property_ref($house),
    'casaTitulo' => 'Casa E2E Sé',
    'cidade' => $known['label'],
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
echo "Massa E2E criada em $out\n";
