<?php
// Upgrade de schema: banco existente em v2 (antes do #21), v3 (#21) e v4 (#23) sobe para v10 com os seeds
// (v3: form.* do resumo de camas; v4: err.field_type e js.* do fix/xss-views) a tabela mail_queue (v5) e tokens sha256 (v6) e fotos assíncronas (v7) e users.is_admin + settings.theme (v8), seed sem err.email_taken (v9) e err.profile_required (v10).
require __DIR__ . '/lib.php';
require_once CL_ROOT . '/src/Db.php';
t_eq(10, Db::SCHEMA_VERSION, 'SCHEMA_VERSION = 10');
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
    $pdo->exec('ALTER TABLE users DROP COLUMN is_admin');
    $pdo->exec("DELETE FROM settings WHERE skey = 'theme'");
    $pdo->exec('PRAGMA user_version = ' . $from);
    $pdo = null;
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(CL_ROOT . '/scripts/migrate.php') . ' 2>&1', $out, $code);
    $text = implode("\n", $out);
    $out = [];
    t_eq(0, $code, "v$from -> v10: migrate.php sai com 0 ($text)");
    $pdo = new PDO('sqlite:' . $file, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    t_eq(10, (int) $pdo->query('PRAGMA user_version')->fetchColumn(), "v$from -> v10: user_version = 10");
    t_eq(1, (int) $pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'mail_queue'")->fetchColumn(), "v$from -> v10: tabela mail_queue criada");
    $cols = array_column($pdo->query('PRAGMA table_info(users)')->fetchAll(PDO::FETCH_ASSOC), 'dflt_value', 'name');
    t_eq('0', $cols['is_admin'] ?? null, "v$from -> v10: users.is_admin criada com padrão 0");
    t_eq('padrao', $pdo->query("SELECT value FROM settings WHERE skey = 'theme'")->fetchColumn(), "v$from -> v10: settings.theme semeado com padrao");
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
    t_eq([], $absent, "v$from -> v10: seeds de v3 e v4 presentes nos 3 idiomas");
}

t_done();
