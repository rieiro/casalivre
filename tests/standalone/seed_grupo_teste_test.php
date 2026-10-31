<?php
// Seed dev do grupo `teste`: só APP_ENV=dev/local, idempotente, não cria admin, admin existente vira gestor,
// 3 imóveis ativos de seed já aceitos, imóvel de outro grupo intocado, vitrine /g/teste/ responde.
require __DIR__ . '/lib.php';
require_once CL_ROOT . '/src/Seed/SeedGrupoTeste.php';
require_once CL_ROOT . '/src/GrupoResolvedor.php';
t_temp_db();
$conta = static fn (string $sql, array $p = []): int => (static function () use ($sql, $p): int {
    $st = db()->prepare($sql);
    $st->execute($p);
    return (int) $st->fetchColumn();
})();
$donos = [];
for ($i = 0; $i < 5; $i++) {
    $donos[] = t_seed_owner();
}
db()->exec("UPDATE properties SET status = 'active'");
foreach (db()->query('SELECT id FROM properties')->fetchAll(PDO::FETCH_COLUMN) as $id) {
    ListingSearch::sync((int) $id);
}
db()->prepare("UPDATE properties SET status = 'inactive' WHERE id = ?")->execute([$donos[0]['house_id']]);
ListingSearch::sync((int) $donos[0]['house_id']);
db()->exec("INSERT INTO groups (slug, name, theme) VALUES ('outro', 'Outro', 'padrao')");
db()->prepare("UPDATE properties SET group_id = (SELECT id FROM groups WHERE slug = 'outro') WHERE id = ?")->execute([$donos[1]['house_id']]);
ListingSearch::sync((int) $donos[1]['house_id']);
$admins = static fn (): int => $conta('SELECT COUNT(*) FROM users WHERE is_admin = 1');

echo "recusa fora de dev/local\n";
foreach ([false, 'production', 'prod', 'staging', 'test', '', 'development'] as $env) {
    putenv($env === false ? 'APP_ENV' : 'APP_ENV=' . $env);
    $r = SeedGrupoTeste::run();
    t_ok(!$r['ok'] && str_contains($r['linhas'][0], 'APP_ENV=dev ou local'), 'recusa APP_ENV=' . json_encode($env));
}
t_eq(0, $conta("SELECT COUNT(*) FROM groups WHERE slug = 'teste'"), 'recusado: nada criado');
putenv('APP_ENV=production');
exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(CL_ROOT . '/scripts/seed-grupo-teste.php') . ' 2>&1', $out, $code);
t_eq(1, $code, 'script CLI sai com 1 fora de dev');

echo "dev sem admin: grupo sem gestor, com aviso, ninguém vira admin\n";
putenv('APP_ENV= Local ');
$r = SeedGrupoTeste::run();
t_ok($r['ok'], 'APP_ENV=local (com espaço/maiúscula) roda');
$g = GroupRepository::findBySlug('teste');
t_eq(['teste', 'contraste'], [$g['slug'] ?? null, $g['theme'] ?? null], 'grupo teste, tema contraste');
t_eq(0, $conta('SELECT COUNT(*) FROM group_members WHERE group_id = ?', [$g['id']]), 'sem gestor');
t_ok((bool) array_filter($r['linhas'], static fn ($l) => str_contains($l, 'AVISO: nenhum admin')), 'aviso de grupo sem gestor');
t_eq(0, $admins(), 'ninguém virou admin');
$ids = static fn (): array => array_map('intval', db()->query('SELECT id FROM properties WHERE group_id = ' . (int) $g['id'] . ' ORDER BY id')->fetchAll(PDO::FETCH_COLUMN));
t_eq([$donos[2]['house_id'], $donos[3]['house_id'], $donos[4]['house_id']], $ids(), '3 imóveis ativos de seed aceitos (inativo e de outro grupo ficam fora)');
t_eq('outro', GroupRepository::slugById((int) db()->query('SELECT group_id FROM properties WHERE id = ' . (int) $donos[1]['house_id'])->fetchColumn()), 'imóvel de outro grupo intocado');

echo "idempotente e admin existente vira gestor\n";
putenv('APP_ENV=dev');
db()->prepare('UPDATE users SET is_admin = 1 WHERE id = ?')->execute([$donos[3]['user_id']]);
db()->prepare('UPDATE users SET is_admin = 1 WHERE id = ?')->execute([$donos[4]['user_id']]);
SeedGrupoTeste::run();
$r = SeedGrupoTeste::run();
t_ok($r['ok'], 'segunda rodada ok');
t_eq(1, $conta("SELECT COUNT(*) FROM groups WHERE slug = 'teste'"), 'grupo não duplica');
t_eq([[(int) $donos[3]['user_id'], 'gestor']], array_map(static fn ($m) => [(int) $m['user_id'], $m['role']], db()->query('SELECT user_id, role FROM group_members WHERE group_id = ' . (int) $g['id'])->fetchAll()), 'primeiro admin (menor id) é o único gestor');
t_eq(2, $admins(), 'seed não muda is_admin');
t_eq(3, count($ids()), 'imóveis não duplicam (3)');
t_ok((bool) array_filter($r['linhas'], static fn ($l) => str_contains($l, 'já é gestor')), 'segunda rodada: já é gestor');

echo "completa se faltar\n";
db()->prepare('UPDATE properties SET group_id = NULL WHERE id = ?')->execute([$donos[2]['house_id']]);
ListingSearch::sync((int) $donos[2]['house_id']);
SeedGrupoTeste::run();
t_eq(3, count($ids()), 'volta a 3 imóveis');

echo "vitrine /g/teste/\n";
t_eq(['slug', 'teste'], (static function (): array { $r = GrupoResolvedor::resolver(null, '/g/teste/'); return [$r['origem'], $r['grupo']['slug'] ?? null]; })(), '/g/teste/ resolve para o grupo');
putenv('APP_ENV');
t_done();
