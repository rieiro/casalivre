<?php
// Concorrência da troca de tema (QA #57 D1/D2): processos paralelos chamam ThemeSet no mesmo SQLite.
// Esperado: nenhum erro (nada de "database is locked"/500), só 'ok' ou 'conflito'; nenhum THEME_CHANGE com
// tema == tema_anterior; trocas alternadas sem duplicata e saldo igual ao valor final.
require __DIR__ . '/lib.php';
require __DIR__ . '/char_helpers.php';
$dbFile = t_temp_db();
require_once CL_ROOT . '/src/Db.php';
require_once CL_ROOT . '/src/helpers.php';
require_once CL_ROOT . '/src/Settings.php';
require_once CL_ROOT . '/src/ActivityLog.php';
$act = c_activity_file();
// Cria o store de atividades (tabelas + journal_mode=WAL) aqui, antes dos workers: se os 12 processos o criam
// juntos do zero, o PRAGMA journal_mode = WAL de um esbarra no lock de outro (QA #57 D3). Só o teste muda.
new SqliteActivityStore($act);
register_shutdown_function(static function (): void {
    $c = JsonCache::read('settings.json');
    if (is_array($c) && isset($c['theme'])) {
        $c['theme']['value'] = Theme::DEFAULT;
        JsonCache::write('settings.json', $c);
    }
});
Settings::refresh();
t_eq('padrao', db()->query("SELECT value FROM settings WHERE skey = 'theme'")->fetchColumn(), 'começa no padrão');

$env = array_merge(getenv(), ['DB_DRIVER' => 'sqlite', 'DB_DSN' => 'sqlite:' . $dbFile, 'ACTIVITY_DB_PATH' => $act]);
$tipos = [];
$erros = [];
for ($rodada = 1; $rodada <= 3; $rodada++) {
    $start = sys_get_temp_dir() . '/theme-start-' . bin2hex(random_bytes(4));
    $procs = [];
    for ($i = 0; $i < 12; $i++) {
        $tema = $i % 2 === 0 ? 'contraste' : 'padrao';
        $p = proc_open([PHP_BINARY, __DIR__ . '/theme_set_worker.php', $tema, $start], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, CL_ROOT, $env);
        $procs[] = [$p, $pipes];
    }
    usleep(300000);
    touch($start);
    foreach ($procs as [$p, $pipes]) {
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        proc_close($p);
        $j = json_decode((string) $out, true);
        $tipos[] = $j['tipo'] ?? 'sem-saida';
        if (!is_array($j) || ($j['tipo'] ?? '') === 'erro' || trim($err) !== '') {
            $erros[] = trim((string) ($j['erro'] ?? '') . ' ' . $err . ' ' . $out);
        }
    }
    unlink($start);
}
t_eq([], $erros, '36 chamadas paralelas sem erro (sem database is locked)');
t_eq([], array_values(array_diff(array_unique($tipos), ['ok', 'conflito'])), 'só ok ou conflito: ' . json_encode(array_count_values($tipos)));

$logs = array_values(array_filter(c_logs($act, 0), static fn ($l) => $l['code'] === ActivityLog::THEME_CHANGE));
t_ok($logs !== [], 'houve trocas registradas (' . count($logs) . ')');
$semMudanca = array_filter($logs, static fn ($l) => ($l['params']['tema'] ?? null) === ($l['params']['tema_anterior'] ?? null));
t_eq(0, count($semMudanca), 'nenhum THEME_CHANGE com tema == tema_anterior');
// O log é gravado depois do COMMIT, então a ordem das linhas não é a ordem das trocas; com dois temas, trocas
// verdadeiras alternam: padrao->contraste e contraste->padrao diferem no máximo em 1, e o saldo dá o valor final.
$ida = count(array_filter($logs, static fn ($l) => ($l['params']['tema_anterior'] ?? '') === 'padrao' && ($l['params']['tema'] ?? '') === 'contraste'));
$volta = count(array_filter($logs, static fn ($l) => ($l['params']['tema_anterior'] ?? '') === 'contraste' && ($l['params']['tema'] ?? '') === 'padrao'));
t_eq(count($logs), $ida + $volta, 'todo log é padrao<->contraste');
t_ok($ida - $volta === 0 || $ida - $volta === 1, "trocas alternadas sem duplicata (ida $ida, volta $volta)");
t_eq($ida > $volta ? 'contraste' : 'padrao', db()->query("SELECT value FROM settings WHERE skey = 'theme'")->fetchColumn(), 'valor final bate com o saldo das trocas registradas');
t_ok(array_filter($logs, static fn ($l) => ($l['anterior']['tema'] ?? null) !== ($l['params']['tema_anterior'] ?? null)) === [], 'valor_anterior = tema_anterior em todos');
t_done();
