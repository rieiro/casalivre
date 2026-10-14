<?php
// Move 12 imóveis ativos dos seeds para Florianópolis (dentro do recorte PMTiles de dev), com fotos
// prontas não exigidas. 8 ficam a < 300 m uns dos outros (viram cluster em zoom baixo), 4 espalhados.
// Uso: php seed-mapa-floripa.php <raiz do app>   -> imprime JSON {cidade, hashes}
declare(strict_types=1);
$root = $argv[1] ?? getcwd();
$db = new PDO('sqlite:' . $root . '/storage/db/casalivre.sqlite');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec("INSERT OR IGNORE INTO ceps (cep, street, neighborhood, city, uf) VALUES ('88015200', 'Rua Bocaiúva', 'Centro', 'Florianópolis', 'SC')");
$ids = $db->query("SELECT id FROM properties WHERE status = 'active' ORDER BY id LIMIT 12")->fetchAll(PDO::FETCH_COLUMN);
$pts = [];
for ($i = 0; $i < 8; $i++) { $pts[] = [-27.5960 + $i * 0.0004, -48.5480 + $i * 0.0003]; }   // centro
$pts[] = [-27.6800, -48.4950]; $pts[] = [-27.4600, -48.4400]; $pts[] = [-27.5300, -48.6200]; $pts[] = [-27.7300, -48.5100];
$up = $db->prepare("UPDATE properties SET city = 'Florianópolis', cep = '88015200', lat = ?, lng = ? WHERE id = ?");
$hashes = [];
foreach ($ids as $k => $id) {
    $up->execute([$pts[$k][0], $pts[$k][1], $id]);
    $hashes[] = (string) $db->query('SELECT public_hash FROM properties WHERE id = ' . (int) $id)->fetchColumn();
}
echo json_encode(['cidade' => 'Florianópolis, SC', 'hashes' => $hashes]), "\n";
