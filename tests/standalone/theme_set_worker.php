<?php
// Processo do theme_set_concurrency_test: espera a largada (arquivo) e chama ThemeSet uma vez, com um
// $atual velho (o oposto do pedido), como um POST que leu o tema antes do save concorrente.
declare(strict_types=1);
define('CL_ROOT', dirname(__DIR__, 2));
require_once CL_ROOT . '/src/Db.php';
require_once CL_ROOT . '/src/helpers.php';
require_once CL_ROOT . '/src/Settings.php';
require_once CL_ROOT . '/src/ActivityLog.php';
require_once CL_ROOT . '/src/Acoes/ThemeSet.php';
[, $tema, $start] = $argv;
$atual = $tema === 'padrao' ? 'contraste' : 'padrao';
db();
while (!is_file($start)) {
    usleep(500);
}
try {
    $r = ThemeSet::run($tema, $atual, ['contraste', 'padrao']);
    $r->registrar();
    echo json_encode(['tipo' => $r->tipo, 'eventos' => count($r->eventos)]);
} catch (Throwable $e) {
    echo json_encode(["tipo" => "erro", "erro" => $e->getMessage() . " @ " . $e->getFile() . ":" . $e->getLine() . " " . str_replace("\n", " | ", $e->getTraceAsString())]);
}
