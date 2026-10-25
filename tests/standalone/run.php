<?php
// Roda todos os testes standalone: php tests/standalone/run.php
require_once __DIR__ . '/php_cmd.php';
$fail = 0;
foreach (glob(__DIR__ . '/*_test.php') ?: [] as $file) {
    echo '== ' . basename($file) . "\n";
    passthru(t_php_shell() . ' ' . escapeshellarg($file), $code);
    $fail += $code !== 0 ? 1 : 0;
}
// PG (TEST_PG_DSN): drop test databases left over by processes that have finished.
if ((string) getenv('TEST_PG_DSN') !== '') {
    require_once __DIR__ . '/lib.php';
    foreach (t_pg_sweep((string) getenv('TEST_PG_DSN')) as $db) {
        echo "leftover dropped: $db\n";
    }
}
echo $fail === 0 ? "TODOS PASSARAM\n" : "$fail arquivo(s) com falha\n";
exit($fail === 0 ? 0 : 1);
