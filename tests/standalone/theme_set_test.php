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
t_eq([[ActivityLog::THEME_CHANGE, ['tema' => 'contraste', 'tema_anterior' => 'padrao']]], $r->eventos, 'troca: um THEME_CHANGE com tema e tema_anterior');
ThemeSet::run('padrao', 'contraste', $lista);
t_eq('padrao', $db(), 'volta ao padrão');
t_done();
