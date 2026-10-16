<?php
// Upgrade de schema: banco existente em v2 (antes do #21), v3 (#21) e v4 (#23) sobe para v14 com os seeds
// (v3: form.* do resumo de camas; v4: err.field_type e js.* do fix/xss-views) a tabela mail_queue (v5) e tokens sha256 (v6) e fotos assíncronas (v7) e users.is_admin + settings.theme (v8), seed sem err.email_taken (v9) e err.profile_required (v10) e as tabelas de grupos + properties.group_id (v11) e group_domains.verify_token/verified_at (v12) e last_check_at/fail_count (v13) e auth.verify_* da ativação por POST (v14) e cal.url_required/cal.feed_missing (v15) e photo.*/review.* da paridade capa/avaliação/fotos (v16) e cal.feed_removed/cal.url_https (v17) e schema_meta (v18), na mesma lista de chaves.
require __DIR__ . '/lib.php';
require_once CL_ROOT . '/src/Db.php';
t_eq(18, Db::SCHEMA_VERSION, 'SCHEMA_VERSION = 18');
$v3 = ['form.beds_add_title', 'form.room_summary_title', 'form.bed_one', 'form.bed_many', 'form.script_error'];
$v4 = ['err.field_type', 'js.net_error', 'js.request_failed'];
$v14 = ['auth.verify_title', 'auth.verify_hint', 'auth.verify_button', 'cal.url_required', 'cal.feed_missing', 'photo.cover_one', 'photo.none_arrived', 'photo.partial', 'review.score_range', 'review.not_published', 'cal.feed_removed', 'cal.url_https'];
$langs = ['pt-BR', 'en', 'es'];

foreach ([2 => array_merge($v3, $v4, $v14), 3 => array_merge($v4, $v14), 4 => $v14] as $from => $missing) {
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
    foreach (['group_property_invites', 'group_domains', 'group_members'] as $tb) {
        $pdo->exec("DROP TABLE $tb");
    }
    $pdo->exec('DROP INDEX idx_properties_group');
    $pdo->exec('ALTER TABLE properties DROP COLUMN group_id');
    $pdo->exec('DROP TABLE groups');
    $pdo->exec('PRAGMA user_version = ' . $from);
    $pdo = null;
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(CL_ROOT . '/scripts/migrate.php') . ' 2>&1', $out, $code);
    $text = implode("\n", $out);
    $out = [];
    t_eq(0, $code, "v$from -> v13: migrate.php sai com 0 ($text)");
    $pdo = new PDO('sqlite:' . $file, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    t_eq(18, (int) $pdo->query('PRAGMA user_version')->fetchColumn(), "v$from -> v13: user_version = 11");
    t_eq(1, (int) $pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'mail_queue'")->fetchColumn(), "v$from -> v13: tabela mail_queue criada");
    $cols = array_column($pdo->query('PRAGMA table_info(users)')->fetchAll(PDO::FETCH_ASSOC), 'dflt_value', 'name');
    t_eq('0', $cols['is_admin'] ?? null, "v$from -> v13: users.is_admin criada com padrão 0");
    foreach (['groups', 'group_members', 'group_domains', 'group_property_invites'] as $tb) {
        t_eq(1, (int) $pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = '$tb'")->fetchColumn(), "v$from -> v13: tabela $tb criada");
    }
    $dcols = array_column($pdo->query('PRAGMA table_info(group_domains)')->fetchAll(PDO::FETCH_ASSOC), 'name', 'name');
    t_ok(isset($dcols['verify_token'], $dcols['verified_at'], $dcols['last_check_at'], $dcols['fail_count']), "v$from -> v13: group_domains com verify_token, verified_at, last_check_at e fail_count");
    t_ok(array_key_exists('group_id', array_column($pdo->query('PRAGMA table_info(properties)')->fetchAll(PDO::FETCH_ASSOC), 'name', 'name')), "v$from -> v13: properties.group_id criada");
    t_eq(1, (int) $pdo->query("SELECT COUNT(*) FROM activity_actions WHERE code = 43")->fetchColumn(), "v$from -> v13: catálogo do activity_log com os códigos de grupos");
    t_eq('padrao', $pdo->query("SELECT value FROM settings WHERE skey = 'theme'")->fetchColumn(), "v$from -> v13: settings.theme semeado com padrao");
    $st = $pdo->prepare('SELECT COUNT(*) FROM translations WHERE tkey = ? AND lang = ? AND text <> \'\'');
    $absent = [];
    foreach (array_merge($v3, $v4, $v14) as $key) {
        foreach ($langs as $lang) {
            $st->execute([$key, $lang]);
            if ((int) $st->fetchColumn() !== 1) {
                $absent[] = "$lang:$key";
            }
        }
    }
    t_eq([], $absent, "v$from -> v14: seeds de v3, v4 e v14 presentes nos 3 idiomas");
}

echo "upgrade de v11 (group_domains sem verificação)\n";
$file = t_temp_db();
exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(CL_ROOT . '/scripts/migrate.php') . ' 2>&1', $out, $code);
$out = [];
$pdo = new PDO('sqlite:' . $file, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec("INSERT INTO groups (slug, name, theme) VALUES ('g', 'G', 'padrao')");
$pdo->exec('DROP TABLE group_domains');
$pdo->exec("CREATE TABLE group_domains (id INTEGER PRIMARY KEY AUTOINCREMENT, group_id INTEGER NOT NULL REFERENCES groups(id) ON DELETE CASCADE, domain TEXT NOT NULL UNIQUE, created_at TEXT NOT NULL DEFAULT (datetime('now')))");
$pdo->exec("INSERT INTO group_domains (group_id, domain) VALUES (1, 'antigo.example.com')");
$pdo->exec('PRAGMA user_version = 11');
$pdo = null;
exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(CL_ROOT . '/scripts/migrate.php') . ' 2>&1', $out, $code);
t_eq(0, $code, 'v11 -> v13: migrate.php sai com 0 (' . implode("\n", $out) . ')');
$pdo = new PDO('sqlite:' . $file, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
t_eq(18, (int) $pdo->query('PRAGMA user_version')->fetchColumn(), 'v11 -> v13: user_version = 12');
$row = $pdo->query("SELECT domain, verify_token, verified_at, last_check_at, fail_count FROM group_domains")->fetch(PDO::FETCH_ASSOC);
t_eq(['domain' => 'antigo.example.com', 'verify_token' => '', 'verified_at' => null, 'last_check_at' => null, 'fail_count' => 0], $row, 'v11 -> v13: domínio antigo mantido, sem token e sem verificar (não resolve)');
t_eq(3, (int) $pdo->query("SELECT COUNT(*) FROM activity_actions WHERE code IN (44, 45, 46)")->fetchColumn(), 'v11 -> v13: catálogo com os códigos de verificação');

echo "upgrade de v12 (group_domains sem last_check_at/fail_count)\n";
$file = t_temp_db();
exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(CL_ROOT . '/scripts/migrate.php') . ' 2>&1', $out, $code);
$out = [];
$pdo = new PDO('sqlite:' . $file, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec("INSERT INTO groups (slug, name, theme) VALUES ('g', 'G', 'padrao')");
$pdo->exec('DROP TABLE group_domains');
$pdo->exec("CREATE TABLE group_domains (id INTEGER PRIMARY KEY AUTOINCREMENT, group_id INTEGER NOT NULL REFERENCES groups(id) ON DELETE CASCADE, domain TEXT NOT NULL UNIQUE, verify_token TEXT NOT NULL DEFAULT '', verified_at TEXT, created_at TEXT NOT NULL DEFAULT (datetime('now')))");
$pdo->exec("INSERT INTO group_domains (group_id, domain, verify_token, verified_at) VALUES (1, 'ok.example.com', 'abc', '2026-10-01 00:00:00')");
$pdo->exec('PRAGMA user_version = 12');
$pdo = null;
exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(CL_ROOT . '/scripts/migrate.php') . ' 2>&1', $out, $code);
t_eq(0, $code, 'v12 -> v13: migrate.php sai com 0 (' . implode("\n", $out) . ')');
$pdo = new PDO('sqlite:' . $file, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
t_eq(18, (int) $pdo->query('PRAGMA user_version')->fetchColumn(), 'v12 -> v13: user_version = 13');
$row = $pdo->query("SELECT verify_token, verified_at, last_check_at, fail_count FROM group_domains")->fetch(PDO::FETCH_ASSOC);
t_eq(['verify_token' => 'abc', 'verified_at' => '2026-10-01 00:00:00', 'last_check_at' => null, 'fail_count' => 0], $row, 'v12 -> v13: verificado mantido, sem checagem (o cron confere na próxima execução)');

t_done();
