<?php
// Upgrade de schema: banco existente em v2 (antes do #21), v3 (#21) e v4 (#23) sobe para v5 com os seeds
// (v3: form.* do resumo de camas; v4: err.field_type e js.* do fix/xss-views) e a tabela mail_queue (v5).
require __DIR__ . '/lib.php';
require_once CL_ROOT . '/src/Db.php';
t_eq(7, Db::SCHEMA_VERSION, 'SCHEMA_VERSION = 7');
$v3 = ['form.beds_add_title', 'form.room_summary_title', 'form.bed_one', 'form.bed_many', 'form.script_error'];
$v4 = ['err.field_type', 'js.net_error', 'js.request_failed'];
$langs = ['pt-BR', 'en', 'es'];

foreach ([2 => array_merge($v3, $v4), 3 => $v4, 4 => []] as $from => $missing) {
    echo "upgrade de v$from\n";
    $file = t_temp_db();
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(CL_ROOT . '/scripts/migrate.php') . ' 2>&1', $out, $code);
    $out = [];
    t_eq(0, $code, "v$from: migração inicial ok");
    $pdo = new PDO('sqlite:' . $file, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    // Simula um banco antigo: sem as chaves que ainda não existiam e com user_version antigo.
    if ($missing) {
        $in = implode(',', array_fill(0, count($missing), '?'));
        $pdo->prepare("DELETE FROM translations WHERE tkey IN ($in)")->execute($missing);
    }
    $pdo->exec('DROP TABLE mail_queue');
    $pdo->exec('PRAGMA user_version = ' . $from);
    $pdo = null;
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(CL_ROOT . '/scripts/migrate.php') . ' 2>&1', $out, $code);
    $text = implode("\n", $out);
    $out = [];
    t_eq(0, $code, "v$from -> v5: migrate.php sai com 0 ($text)");
    $pdo = new PDO('sqlite:' . $file, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    t_eq(7, (int) $pdo->query('PRAGMA user_version')->fetchColumn(), "v$from -> v7: user_version = 7");
    t_eq(1, (int) $pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'mail_queue'")->fetchColumn(), "v$from -> v5: tabela mail_queue criada");
    $st = $pdo->prepare('SELECT COUNT(*) FROM translations WHERE tkey = ? AND lang = ? AND text <> \'\'');
    $absent = [];
    foreach (array_merge($v3, $v4) as $key) {
        foreach ($langs as $lang) {
            $st->execute([$key, $lang]);
            if ((int) $st->fetchColumn() !== 1) {
                $absent[] = "$lang:$key";
            }
        }
    }
    t_eq([], $absent, "v$from -> v5: seeds de v3 e v4 presentes nos 3 idiomas");
}

t_done();
