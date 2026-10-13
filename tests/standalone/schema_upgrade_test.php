<?php
// Upgrade de schema: banco existente em v2 (antes do #21) e em v3 (#21) sobe para v4 com os seeds dos dois PRs
// (v3: form.* do resumo de camas; v4: err.field_type e js.* do fix/xss-views).
require __DIR__ . '/lib.php';
require_once CL_ROOT . '/src/Db.php';
t_eq(4, Db::SCHEMA_VERSION, 'SCHEMA_VERSION = 4');
$v3 = ['form.beds_add_title', 'form.room_summary_title', 'form.bed_one', 'form.bed_many', 'form.script_error'];
$v4 = ['err.field_type', 'js.net_error', 'js.request_failed'];
$langs = ['pt-BR', 'en', 'es'];

foreach ([2 => array_merge($v3, $v4), 3 => $v4] as $from => $missing) {
    echo "upgrade de v$from\n";
    $file = t_temp_db();
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(CL_ROOT . '/scripts/migrate.php') . ' 2>&1', $out, $code);
    $out = [];
    t_eq(0, $code, "v$from: migração inicial ok");
    $pdo = new PDO('sqlite:' . $file, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    // Simula um banco antigo: sem as chaves que ainda não existiam e com user_version antigo.
    $in = implode(',', array_fill(0, count($missing), '?'));
    $pdo->prepare("DELETE FROM translations WHERE tkey IN ($in)")->execute($missing);
    $pdo->exec('PRAGMA user_version = ' . $from);
    $pdo = null;
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(CL_ROOT . '/scripts/migrate.php') . ' 2>&1', $out, $code);
    $text = implode("\n", $out);
    $out = [];
    t_eq(0, $code, "v$from -> v4: migrate.php sai com 0 ($text)");
    $pdo = new PDO('sqlite:' . $file, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    t_eq(4, (int) $pdo->query('PRAGMA user_version')->fetchColumn(), "v$from -> v4: user_version = 4");
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
    t_eq([], $absent, "v$from -> v4: seeds de v3 e v4 presentes nos 3 idiomas");
}

t_done();
