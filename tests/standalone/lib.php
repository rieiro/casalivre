<?php

declare(strict_types=1);

/**
 * Mini harness de testes sem dependências (sem PHPUnit/Composer).
 * Uso: php tests/standalone/<arquivo>_test.php  (sai com código != 0 se algo falhar)
 *      ou: php tests/standalone/run.php          (roda todos)
 */

const CL_ROOT = __DIR__ . '/../..';

$GLOBALS['__t_fail'] = 0;
$GLOBALS['__t_pass'] = 0;

function t_ok(bool $cond, string $msg): void
{
    if ($cond) {
        $GLOBALS['__t_pass']++;
        echo "  ok   $msg\n";
        return;
    }
    $GLOBALS['__t_fail']++;
    echo "  FAIL $msg\n";
}

function t_eq(mixed $expected, mixed $actual, string $msg): void
{
    t_ok($expected === $actual, $msg . ($expected === $actual ? '' : ' (esperado ' . var_export($expected, true) . ', obtido ' . var_export($actual, true) . ')'));
}

function t_done(): never
{
    $f = $GLOBALS['__t_fail'];
    echo ($f === 0 ? 'PASSOU' : 'FALHOU') . ": {$GLOBALS['__t_pass']} ok, $f falha(s)\n";
    exit($f === 0 ? 0 : 1);
}

/**
 * Prepara um banco SQLite temporário (DB_DSN) com o schema do projeto.
 * Deve ser chamado antes de qualquer acesso a db().
 */
function t_temp_db(): string
{
    $dir = sys_get_temp_dir() . '/casalivre-test-' . bin2hex(random_bytes(4));
    mkdir($dir, 0700, true);
    $file = $dir . '/test.sqlite';
    $pdo = new PDO('sqlite:' . $file);
    $pdo->exec((string) file_get_contents(CL_ROOT . '/database/schema.sql'));
    putenv('DB_DRIVER=sqlite');
    putenv('DB_DSN=sqlite:' . $file);
    register_shutdown_function(static function () use ($dir): void {
        foreach (glob($dir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($dir);
    });
    return $file;
}

/** Sobe `php -S` com o front controller usando o banco temporário. Retorna a URL base. */
function t_server(): string
{
    foreach (['storage/db', 'storage/logs', 'storage/cache', 'storage/sessions', 'storage/mail'] as $d) {
        @mkdir(CL_ROOT . '/' . $d, 0700, true);
    }
    // Porta livre escolhida pelo SO (porta 0); fecha o socket e entrega a porta ao php -S.
    $sock = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    if ($sock === false) {
        throw new RuntimeException("sem porta livre: $errstr");
    }
    $port = (int) substr(strrchr((string) stream_socket_get_name($sock, false), ':'), 1);
    fclose($sock);
    $nonce = bin2hex(random_bytes(16));
    $env = array_merge(getenv(), ['DB_DRIVER' => 'sqlite', 'DB_DSN' => (string) getenv('DB_DSN'), 'T_SERVER_NONCE' => $nonce]);
    // APP_URL fixa (obrigatória fora de dev/local); o teste pode definir outra antes de subir o servidor.
    $env['APP_URL'] = (string) (getenv('APP_URL') ?: 'http://127.0.0.1:' . $port);
    // GEO_SALT é obrigatório fora de dev/local (GeoSalt::enforce); o teste pode definir outro (inclusive vazio).
    if (getenv('GEO_SALT') === false) {
        $env['GEO_SALT'] = str_repeat('t', 64);
    }
    $stderr = tempnam(sys_get_temp_dir(), 'tsrv');
    $cmd = [PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', CL_ROOT . '/public', __DIR__ . '/router.php'];
    $proc = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', $stderr, 'w']], $pipes, CL_ROOT, $env);
    $fail = static function (string $why) use ($stderr, $port): never {
        $log = trim((string) @file_get_contents($stderr));
        throw new RuntimeException("Servidor de teste não subiu na porta $port: $why" . ($log !== '' ? "\nstderr:\n" . $log : ''));
    };
    if (!is_resource($proc)) {
        $fail('proc_open falhou');
    }
    register_shutdown_function(static function () use ($proc, $stderr): void {
        proc_terminate($proc);
        @unlink($stderr);
    });
    $base = 'http://127.0.0.1:' . $port;
    for ($i = 0; $i < 100; $i++) {
        if (!proc_get_status($proc)['running']) {
            $fail('processo terminou');
        }
        $ctx = stream_context_create(['http' => ['timeout' => 1, 'ignore_errors' => true]]);
        $body = @file_get_contents($base . '/__t_server_nonce', false, $ctx);
        if ($body === $nonce) {
            return $base;
        }
        usleep(50000);
    }
    $fail('nonce não confirmado (outra resposta na porta ou timeout)');
}

/** @return array{status: int, body: string, headers: string} */
function t_http(string $method, string $url, ?string $body = null, array $headers = [], ?string $jar = null): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_NOPROXY => '*',
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }
    if ($jar !== null) {
        curl_setopt($ch, CURLOPT_COOKIEJAR, $jar);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $jar);
    }
    $raw = (string) curl_exec($ch);
    $size = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    return ['status' => $status, 'headers' => substr($raw, 0, $size), 'body' => substr($raw, $size)];
}

/** Cria usuário ativo + imóvel no banco temporário (in-process). */
function t_seed_owner(): array
{
    require_once CL_ROOT . '/src/Db.php';
    require_once CL_ROOT . '/src/UserRepository.php';
    require_once CL_ROOT . '/src/HouseRepository.php';
    require_once CL_ROOT . '/src/helpers.php';
    $email = 'dono' . bin2hex(random_bytes(3)) . '@casalivre.test';
    $password = 'Casa#' . random_int(100000, 999999);
    $userId = UserRepository::insert([
        'name' => 'Dono Teste', 'cpf' => '52998224725', 'address' => 'Rua A, 1', 'cep' => '88000000',
        'street' => 'Rua A', 'address_number' => '1', 'address_complement' => '', 'phone' => '48999999999',
        'email' => $email, 'password_hash' => password_make($password), 'status' => 'active',
        'activated_at' => date('Y-m-d H:i:s'),
    ]);
    $houseId = HouseRepository::insert([
        'user_id' => $userId, 'title' => 'Casa Teste', 'description' => 'Descrição de teste do imóvel.',
        'summary' => 'Resumo', 'city' => 'Florianópolis, SC', 'address' => 'Rua A, 1', 'cep' => '88000000',
        'street' => 'Rua A', 'address_number' => '1', 'address_complement' => '', 'lat' => -27.59, 'lng' => -48.54,
        'bedrooms' => 1, 'beds' => 1, 'max_guests' => 2, 'nightly_price' => 200, 'checkin_time' => '15:00',
        'checkout_time' => '11:00', 'min_nights' => 1, 'pool' => 0, 'bbq' => 0, 'garage' => 0,
    ]);
    $house = HouseRepository::find($houseId);
    return [
        'user_id' => $userId, 'email' => $email, 'password' => $password, 'house_id' => $houseId,
        'hash' => HouseRepository::ensurePublicHash($house), 'ical_hash' => HouseRepository::ensureIcalHash($house),
    ];
}

/** Faz login via /api/sessao e devolve o token CSRF da sessão. */
function t_login(string $base, string $jar, string $email, string $password): string
{
    $page = t_http('GET', $base . '/entrar', null, [], $jar);
    preg_match('/name="_csrf" value="([^"]+)"/', $page['body'], $m);
    $csrf = $m[1] ?? '';
    $r = t_http('POST', $base . '/api/sessao', json_encode(['email' => $email, 'password' => $password]), ['Content-Type: application/json', 'X-CSRF-Token: ' . $csrf], $jar);
    if ($r['status'] !== 200) {
        throw new RuntimeException('login falhou: ' . $r['status'] . ' ' . $r['body']);
    }
    $page = t_http('GET', $base . '/painel', null, [], $jar);
    preg_match('/name="_csrf" value="([^"]+)"/', $page['body'], $m);
    return $m[1] ?? $csrf;
}
