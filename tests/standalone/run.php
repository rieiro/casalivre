<?php
// Roda todos os testes standalone: php tests/standalone/run.php
$fail = 0;
foreach (glob(__DIR__ . '/*_test.php') ?: [] as $file) {
    echo '== ' . basename($file) . "\n";
    passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($file), $code);
    $fail += $code !== 0 ? 1 : 0;
}
echo $fail === 0 ? "TODOS PASSARAM\n" : "$fail arquivo(s) com falha\n";
exit($fail === 0 ? 0 : 1);
