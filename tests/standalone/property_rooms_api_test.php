<?php
// Issue #20: PUT /api/casas/{hash} com quartos/camas e comodidades aninhados (formato novo do front)
// e com chaves planas "rooms[0][beds][0][type]" (formato antigo; defesa no api_body()).
require __DIR__ . '/lib.php';
t_temp_db();
$owner = t_seed_owner();
db()->exec("INSERT OR IGNORE INTO ceps (cep, street, neighborhood, city, uf) VALUES ('88000000', 'Rua A', 'Centro', 'Florianópolis', 'SC')");
$base = t_server();
$jar = tempnam(sys_get_temp_dir(), 'jar');
$csrf = t_login($base, $jar, $owner['email'], $owner['password']);
$common = ['title' => 'Casa Teste', 'description' => 'Descrição de teste do imóvel.', 'cep' => '88000-000', 'street' => 'Rua A',
    'city' => 'Florianópolis, SC', 'address_number' => '1', 'address_complement' => '', 'max_guests' => '4',
    'nightly_price' => 'R$ 200,00', 'cleaning_fee' => 'R$ 0,00', 'checkin_time' => '15:00', 'checkout_time' => '11:00',
    'min_nights' => '1', 'calendar_months' => '6', 'noise_from' => '08:00', 'noise_until' => '22:00'];
$put = static fn (array $data) => t_http('PUT', $base . '/api/casas/' . $owner['hash'], json_encode($data), ['Content-Type: application/json', 'X-CSRF-Token: ' . $csrf], $jar);
$row = static function () use ($owner): array {
    $st = db()->prepare('SELECT rooms, bedrooms, beds, amenities FROM properties WHERE id = ?');
    $st->execute([$owner['house_id']]);
    return $st->fetch(PDO::FETCH_ASSOC);
};

echo "JSON aninhado (front corrigido)\n";
$r = $put($common + [
    'rooms' => [['beds' => [['type' => 'double', 'variant' => 'queen'], ['type' => 'single', 'variant' => 'common']]], ['beds' => [['type' => 'bunk', 'variant' => '']]]],
    'amenity' => ['pool' => '1', 'wifi' => '1'],
]);
t_eq(200, $r['status'], 'PUT com rooms aninhado -> 200 (' . substr($r['body'], 0, 120) . ')');
$p = $row();
t_eq([2, 3], [(int) $p['bedrooms'], (int) $p['beds']], '2 quartos, 3 camas salvos');
$rooms = json_decode((string) $p['rooms'], true);
t_eq('queen', $rooms[0]['beds'][0]['variant'] ?? null, 'variante da cama salva');
$am = json_decode((string) $p['amenities'], true) ?: [];
t_ok(!empty($am['pool']) && !empty($am['wifi']) && empty($am['bbq']), 'comodidades salvas');

echo "chaves planas (formato antigo) -> 422 normal, nada salvo\n";
$before = $row();
$r = $put($common + [
    'rooms[0][beds][0][type]' => 'single', 'rooms[0][beds][0][variant]' => 'widow',
    'amenity[workspace]' => '1',
]);
t_eq(422, $r['status'], 'PUT com chaves planas -> 422 (sem conversão no servidor)');
t_eq($before, $row(), 'imóvel não alterado');

echo "validação continua\n";
$r = $put($common + ['rooms' => [['beds' => []]]]);
t_eq(422, $r['status'], 'quarto sem cama -> 422');
$r = $put($common + ['rooms' => ['0' => ['beds' => ['0' => ['type' => 'single', 'variant' => 'common']]]]]);
t_eq(200, $r['status'], 'índices como chaves de objeto (formato do conversor) -> 200');

echo "conversor do front (Node, via vm)\n";
$node = trim((string) shell_exec('command -v node'));
if ($node === '') {
    echo "  (node ausente: pulado)\n";
} else {
    $js = <<<'JS'
const vm = require('vm');
const fs = require('fs');
const entries = [['title','Casa'],['rooms[0][beds][0][type]','double'],['rooms[0][beds][0][variant]','queen'],
  ['rooms[0][beds][1][type]','single'],['rooms[1][beds][0][type]','bunk'],['amenity[pool]','1'],['amenity[wifi]','1'],
  ['__proto__[polluted]','1'],['a[constructor][prototype][p]','1'],['b[__proto__][q]','1']];
const ctx = { window: {}, FormData: function () { return entries; } };
vm.createContext(ctx);
vm.runInContext(fs.readFileSync(process.argv[2], 'utf8'), ctx);
const out = ctx.window.CasaLivreForm.toObject({});
console.log(JSON.stringify({ out, clean: ({}).polluted === undefined && ({}).p === undefined && ({}).q === undefined }));
JS;
    $f = tempnam(sys_get_temp_dir(), 'js') . '.js';
    file_put_contents($f, $js);
    $res = json_decode((string) shell_exec(escapeshellarg($node) . ' ' . escapeshellarg($f) . ' ' . escapeshellarg(CL_ROOT . '/public/assets/form-data.js')), true);
    @unlink($f);
    $o = $res['out'] ?? [];
    t_eq(['0' => ['beds' => ['0' => ['type' => 'double', 'variant' => 'queen'], '1' => ['type' => 'single']]], '1' => ['beds' => ['0' => ['type' => 'bunk']]]], $o['rooms'] ?? null, 'rooms[i][beds][j][campo] vira objeto aninhado');
    t_eq(['pool' => '1', 'wifi' => '1'], $o['amenity'] ?? null, 'amenity[chave] vira objeto');
    t_eq('Casa', $o['title'] ?? null, 'campo simples mantido');
    t_ok(($res['clean'] ?? false) === true && !isset($o['a']) && !isset($o['b']) && !isset($o['__proto__']), 'sem poluição de protótipo');
}
t_done();
