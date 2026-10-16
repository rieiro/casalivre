<?php
// P0-3b (L2 of the #111 review): on PG the app connection (db(), DB_DSN/DB_USER) must not be a superuser.
// In the dev env the app connects as casalivre_dev (CREATEDB, NOSUPERUSER). Skipped on SQLite.
declare(strict_types=1);
require __DIR__ . '/lib.php';

if (!t_is_pg()) {
    echo "  SKIPPED (PG only: set TEST_PG_DSN)\n";
    t_done();
}
t_temp_db();
require_once CL_ROOT . '/src/helpers.php';
$row = db()->query('SELECT current_user AS who, rolsuper FROM pg_roles WHERE rolname = current_user')->fetch(PDO::FETCH_ASSOC);
t_ok(is_array($row), 'app connection role found in pg_roles');
t_eq(false, (bool) ($row['rolsuper'] ?? true), 'app does not connect as superuser (' . ($row['who'] ?? '?') . ')');
t_ok(($row['who'] ?? '') !== 'postgres', 'app does not connect as postgres');
t_done();
