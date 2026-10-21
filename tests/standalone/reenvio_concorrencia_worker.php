<?php
// Processo filho do reenvio_concorrencia_test.php (não é teste: run.php só roda *_test.php).
// Espera o arquivo de largada existir (todos os filhos partem juntos) e então:
//   reserve <ns> <saida>        -> IpRateLimiter::reserve (namespace <ns> de chaves no Valkey) com ação lenta; grava "1" em <saida> a cada ação feita
//   login <email> <senha>       -> LoginAttempt::run (conta pendente com a senha certa), como o login web/API
declare(strict_types=1);

const CL_ROOT = __DIR__ . '/../..';
[$_, $mode, $go] = $argv;
require_once CL_ROOT . '/src/IpRateLimiter.php';
if ($mode === 'reserve') {
    IpRateLimiter::$namespace = $argv[3];
} else {
    require_once CL_ROOT . '/src/Db.php';
    require_once CL_ROOT . '/src/Settings.php';
    require_once CL_ROOT . '/src/I18n.php';
    require_once CL_ROOT . '/src/UserRepository.php';
    require_once CL_ROOT . '/src/Mailer.php';
    require_once CL_ROOT . '/src/helpers.php';
    require_once CL_ROOT . '/src/ActivityLog.php';
    require_once CL_ROOT . '/src/Acoes/LoginAttempt.php';
    IpRateLimiter::$namespace = (string) @file_get_contents((string) getenv('T_RATELIMIT_NS_FILE'));
    AccountFlow::$enqueue = static fn (string $type, string $email): bool => Mailer::enqueueAccountMail($type, $email);
}
while (!is_file($go)) {
    usleep(1000);
}
if ($mode === 'reserve') {
    $out = $argv[4];
    IpRateLimiter::reserve('conc', 3, 3600, 'conta:x', static function (bool $cabe) use ($out): bool {
        usleep(20000); // alarga a janela da corrida: sem a vaga atômica (INCR), todos veriam "cabe"
        if ($cabe) {
            file_put_contents($out, "1\n", FILE_APPEND | LOCK_EX); // a "ação" (ex.: e-mail enfileirado)
        }
        return $cabe;
    });
    exit(0);
}
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$res = LoginAttempt::run($argv[3], $argv[4]);
echo $res->tipo, "\n";
