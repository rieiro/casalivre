<?php
// Carrega o schema PG num banco VAZIO (DSN) e cria um proprietário e um imóvel com foto com caminho malicioso (fix/xss-views).
// Uso: DB_USER=... php tests/e2e/seed_xss.php <pgsql:dsn de um banco vazio>  -> imprime JSON {email, password, hash}
declare(strict_types=1);
$root = dirname(__DIR__, 2);
$dsn = $argv[1] ?? '';
if (!str_starts_with($dsn, 'pgsql:')) {
    fwrite(STDERR, "uso: seed_xss.php <pgsql:dsn de um banco vazio> (PostgreSQL only)\n");
    exit(2);
}
(new PDO($dsn, getenv('DB_USER') ?: null, getenv('DB_PASSWORD') ?: null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]))
    ->exec((string) file_get_contents($root . '/database/schema.pgsql.sql'));
putenv('DB_DSN=' . $dsn);
require_once $root . '/src/Db.php';
Db::migrateNow();
require_once $root . '/src/Settings.php';
require_once $root . '/src/I18n.php';
require_once $root . '/src/UserRepository.php';
require_once $root . '/src/HouseRepository.php';
require_once $root . '/src/helpers.php';
require_once $root . '/src/Rooms.php';
require_once $root . '/tests/standalone/lib.php';
db()->exec("INSERT INTO ceps (cep, street, neighborhood, city, uf) VALUES ('88015200', 'Rua Bocaiúva', 'Centro', 'Florianópolis', 'SC') ON CONFLICT DO NOTHING");
$email = 'dono.xss@casalivre.test';
$password = 'Casa#Xss';
$userId = UserRepository::insert([
    'name' => 'Dono XSS', 'cpf' => '52998224725', 'address' => 'Rua Bocaiúva, 10', 'cep' => '88015200',
    'street' => 'Rua Bocaiúva', 'address_number' => '10', 'address_complement' => '', 'phone' => '48999999999',
    'email' => $email, 'password_hash' => password_make($password), 'status' => 'active', 'activated_at' => date('Y-m-d H:i:s'),
]);
$rooms = [['beds' => [['type' => 'double', 'variant' => 'queen'], ['type' => 'single', 'variant' => 'common']]], ['beds' => [['type' => 'bunk', 'variant' => '']]]];
$cityId = t_city_place_id('Florianópolis, SC');
$houseId = HouseRepository::insert([
    'user_id' => $userId, 'title' => 'Casa da Lagoa', 'description' => 'Casa ampla perto da Lagoa da Conceição.',
    'summary' => 'Casa ampla', 'city_place_id' => $cityId, 'address' => 'Rua Bocaiúva, 10', 'cep' => '88015200',
    'street' => 'Rua Bocaiúva', 'address_number' => '10', 'address_complement' => '', 'lat' => -27.59, 'lng' => -48.54,
    'bedrooms' => 2, 'beds' => 3, 'max_guests' => 5, 'nightly_price' => 350, 'checkin_time' => '15:00',
    'checkout_time' => '11:00', 'min_nights' => 2, 'pool' => 1, 'bbq' => 1, 'garage' => 0,
]);
db()->prepare('UPDATE properties SET rooms = ?, amenities = ?, status = ? WHERE id = ?')
    ->execute([Rooms::parse(["rooms" => $rooms])["json"], json_encode(['pool' => 1, 'bbq' => 1, 'wifi' => 1]), 'active', $houseId]);
ListingSearch::sync($houseId);
// Caminho de foto malicioso gravado direto no banco: o front deve trocar pelo placeholder.
db()->prepare('INSERT INTO photos (property_id, path, is_primary, sort_order) VALUES (?, ?, 1, 0)')
    ->execute([$houseId, 'javascript:alert(1)" onerror="alert(2)']);
$house = HouseRepository::find($houseId);
echo json_encode(['email' => $email, 'password' => $password, 'hash' => HouseRepository::ensurePublicHash($house), 'id' => $houseId]), "\n";
