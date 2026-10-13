<?php
// pruneTextLog com escritas concorrentes: nenhuma linha nova pode ser perdida.
require __DIR__ . '/lib.php';
require CL_ROOT . '/src/LogRetention.php';

$dir = sys_get_temp_dir() . '/retc-' . bin2hex(random_bytes(3));
mkdir($dir, 0700);
foreach (['error.log' => 'fpc', 'php.log' => 'error_log'] as $name => $mode) {
    $file = "$dir/$name";
    $old = time() - 30 * 86400;
    $seed = '';
    for ($i = 0; $i < 200; $i++) {
        $seed .= date('c', $old) . " velho $i\n#0 trace velho\n";
    }
    file_put_contents($file, $seed);
    $n = 6000;
    $writer = $mode === 'fpc'
        ? 'for($i=0;$i<' . $n . ';$i++){file_put_contents($argv[1], date("c")." novo $i\n", FILE_APPEND); usleep(200);}'
        : 'ini_set("error_log",$argv[1]); for($i=0;$i<' . $n . ';$i++){error_log("novo $i"); usleep(200);}';
    $procs = [];
    for ($w = 0; $w < 2; $w++) {
        $procs[] = proc_open([PHP_BINARY, '-r', $writer, $file], [], $pipes);
    }
    // Escritor de entradas antigas: garante que cada poda tenha o que remover (é quando o arquivo é substituído).
    $procs[] = proc_open([PHP_BINARY, '-r', 'for($i=0;$i<400;$i++){file_put_contents($argv[1], date("c", time()-30*86400)." velho-c $i\n", FILE_APPEND); usleep(3000);}', $file], [], $pipes);
    $runs = 0;
    $removed = 0;
    $deadline = microtime(true) + 20;
    do {
        clearstatcache();
        if (is_file($file)) {
            $removed += LogRetention::pruneTextLog($file, time() - 14 * 86400);
            $runs++;
        }
        usleep(5000);
        $alive = false;
        foreach ($procs as $p) {
            $alive = $alive || proc_get_status($p)['running'];
        }
    } while ($alive && microtime(true) < $deadline);
    foreach ($procs as $p) {
        proc_close($p);
    }
    LogRetention::pruneTextLog($file, time() - 14 * 86400);
    $content = (string) file_get_contents($file);
    $novos = preg_match_all('/novo \d+/', $content);
    t_ok($runs >= 3, "$name: retenção rodou $runs vezes durante as escritas");
    t_eq(2 * $n, $novos, "$name ($mode): nenhuma linha nova perdida");
    t_eq(0, preg_match_all('/velho/', $content), "$name: linhas antigas removidas");
    t_ok($removed >= 400, "$name: linhas antigas removidas durante a carga ($removed)");
    t_eq([], glob("$dir/$name.*") ?: [], "$name: nenhum arquivo temporário sobrando");
}
exec('rm -rf ' . escapeshellarg($dir));
t_done();
