<?php
// Unidade de src/Acoes/ThemeSet.php (troca de tema): allowlist, no-op, gravação, cache e evento THEME_CHANGE.
require __DIR__ . '/lib.php';
t_temp_db();
require_once CL_ROOT . '/src/Db.php';
require_once CL_ROOT . '/src/helpers.php';
require_once CL_ROOT . '/src/Settings.php';
require_once CL_ROOT . '/src/ActivityLog.php';
require_once CL_ROOT . '/src/Acoes/ThemeSet.php';
register_shutdown_function(static function (): void {
    $c = JsonCache::read('settings.json');
    if (is_array($c) && isset($c['theme'])) {
        $c['theme']['value'] = Theme::DEFAULT;
        JsonCache::write('settings.json', $c);
    }
});
Settings::refresh();
$db = static fn (): string => (string) db()->query("SELECT value FROM settings WHERE skey = 'theme'")->fetchColumn();
$cache = static fn (): ?string => JsonCache::read('settings.json')['theme']['value'] ?? null;
$lista = ['contraste', 'padrao'];

echo "inválido\n";
foreach (['../padrao', '../../etc/passwd', 'nao-existe', 'CONTRASTE', '', ['contraste'], null, 1] as $bad) {
    $r = ThemeSet::run($bad, 'padrao', $lista);
    t_eq('invalido', $r->tipo, 'invalido: ' . json_encode($bad));
    t_eq([], $r->eventos, 'invalido sem evento: ' . json_encode($bad));
}
t_eq('Tema inválido.', ThemeSet::run('x', 'padrao', $lista)->mensagem, 'mensagem do inválido');
t_eq('invalido', ThemeSet::run('contraste', 'padrao', ['padrao'])->tipo, 'só vale a lista recebida');
t_eq('padrao', $db(), 'inválido não grava');

echo "mesmo tema\n";
$r = ThemeSet::run('padrao', 'padrao', $lista);
t_eq('ok', $r->tipo, 'mesmo tema: ok');
t_eq(false, $r->dados['mudou'], 'mesmo tema: mudou = false');
t_eq([], $r->eventos, 'mesmo tema: sem evento');

echo "troca\n";
$r = ThemeSet::run('contraste', 'padrao', $lista);
t_eq('ok', $r->tipo, 'troca: ok');
t_eq(true, $r->dados['mudou'], 'troca: mudou = true');
t_eq('contraste', $db(), 'troca: settings.theme gravado');
t_eq('contraste', $cache(), 'troca: cache de settings atualizado');
t_eq([[ActivityLog::THEME_CHANGE, ['tema' => 'contraste', 'tema_anterior' => 'padrao'], ['tema' => 'padrao'], ['tema' => 'contraste']]], $r->eventos, 'troca: um THEME_CHANGE com tema/tema_anterior e anterior/atual');
db()->prepare("UPDATE settings SET value = 'valor-cru' WHERE skey = 'theme'")->execute();
$r = ThemeSet::run('padrao', 'contraste', $lista);
t_eq('valor-cru', $r->eventos[0][2]['tema'] ?? null, 'anterior vem do banco na mesma transação (não do parâmetro)');

echo "atual velho (outro save chegou antes)\n";
db()->prepare("UPDATE settings SET value = 'contraste' WHERE skey = 'theme'")->execute();
$r = ThemeSet::run('contraste', 'padrao', $lista);
t_eq('ok', $r->tipo, 'banco já com o tema pedido: ok');
t_eq(false, $r->dados['mudou'], 'banco já com o tema pedido: mudou = false (comparação dentro da transação)');
t_eq([], $r->eventos, 'banco já com o tema pedido: sem THEME_CHANGE');
t_ok(!Db::inTransaction(db()), 'transação fechada após o no-op');
db()->prepare("UPDATE settings SET value = 'padrao' WHERE skey = 'theme'")->execute();

echo "sem linha settings.theme\n";
db()->exec("DELETE FROM settings WHERE skey = 'theme'");
$r = ThemeSet::run('contraste', 'padrao', $lista);
t_eq('conflito', $r->tipo, 'sem linha: conflito (rowCount 0)');
t_eq([], $r->eventos, 'sem linha: sem log');
t_eq(false, db()->query("SELECT value FROM settings WHERE skey = 'theme'")->fetchColumn(), 'sem linha: nada inserido');
t_ok(!Db::inTransaction(db()), 'transação fechada');
db()->prepare('INSERT INTO settings (skey, value, description) VALUES (?, ?, ?)')->execute(['theme', 'padrao', 'x']);
Settings::refresh();

echo "JsonCache atômico\n";
$antes = glob(dirname(JsonCache::path('x')) . '/*.tmp') ?: [];
JsonCache::write('theme-set-teste.json', ['a' => 1]);
t_eq(['a' => 1], JsonCache::read('theme-set-teste.json'), 'JsonCache grava e lê');
t_eq($antes, glob(dirname(JsonCache::path('x')) . '/*.tmp') ?: [], 'JsonCache: sem .tmp sobrando');
t_ok(str_contains((string) file_get_contents(CL_ROOT . '/conta/Geo/JsonCache.php'), 'rename('), 'JsonCache usa rename');
unlink(JsonCache::path('theme-set-teste.json'));
t_eq('padrao', $db(), 'volta ao padrão');
t_done();
