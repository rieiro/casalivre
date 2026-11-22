<?php

declare(strict_types=1);

// Verifies standalone template DB caching, fast cloning, and stale template cleanup.
require __DIR__ . '/lib.php';
require_once CL_ROOT . '/src/Db.php';

$h1 = t_pg_template_hash();
$h2 = t_pg_template_hash();
t_eq(64, strlen($h1), 'template hash is sha256');
t_eq($h1, $h2, 'template hash is deterministic');

$tplName = 'casalivre_t_tpl_' . substr($h1, 0, 12);

$t0 = microtime(true);
$dsn = t_temp_db();
$elapsed = microtime(true) - $t0;
t_ok(str_contains($dsn, 'casalivre_t_'), "cloned temp db dsn ready ({$elapsed}s)");

$pdo = db();
t_eq((string) Db::SCHEMA_VERSION, (string) $pdo->query("SELECT value FROM schema_meta WHERE skey = 'schema_version'")->fetchColumn(), 'cloned template has schema_version');
t_ok((int) $pdo->query("SELECT COUNT(*) FROM places WHERE type = 'poi'")->fetchColumn() >= 8029, 'cloned template has seeded places');
t_ok((int) $pdo->query('SELECT COUNT(*) FROM settings')->fetchColumn() > 0, 'cloned template has seeded settings');

// Verify stale template cleanup preserves the active template.
$adminDsn = (string) getenv('TEST_PG_DSN');
$admin = t_pg_admin($adminDsn);
$stale = 'casalivre_t_tpl_000000000000';
$admin->exec("CREATE DATABASE $stale");
$dropped = t_pg_sweep($adminDsn);
t_ok(in_array($stale, $dropped, true), 't_pg_sweep removes stale templates');
t_ok(!in_array($tplName, $dropped, true), 't_pg_sweep preserves current active template');

t_done();
