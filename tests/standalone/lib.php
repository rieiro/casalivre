<?php

declare(strict_types=1);

/**
 * Mini harness de testes sem dependências (sem PHPUnit/Composer).
 * Uso: php tests/standalone/<arquivo>_test.php  (sai com código != 0 se algo falhar)
 *      ou: php tests/standalone/run.php          (roda todos)
 */

const CL_ROOT = __DIR__ . '/../..';
require_once __DIR__ . '/php_cmd.php';

// Isola os testes do .env real da raiz: Db::loadEnv lê CASALIVRE_ENV_FILE (herdado por php -S e
// subprocessos). Padrão: um .env vazio temporário; um teste pode apontar para o seu antes de subir o servidor.
if (getenv('CASALIVRE_ENV_FILE') === false) {
    $__tEnv = tempnam(sys_get_temp_dir(), 'clenv');
    putenv('CASALIVRE_ENV_FILE=' . $__tEnv);
    register_shutdown_function(static fn () => @unlink($__tEnv));
}

// Limite por IP (IpRateLimiter, Valkey): cada execução usa um namespace próprio de chaves (cl:rl:t<hex>:...),
// gravado em T_RATELIMIT_NS_FILE (herdado por php -S e subprocessos; tests/standalone/router.php relê a cada
// requisição). t_ratelimit_reset() troca o namespace: rodadas seguidas não acumulam 429 e as chaves antigas
// expiram pelo TTL da janela. Sem Valkey (VALKEY_SOCKET) o limitador cai no modo de falha de cada balde.
// LOG_SALT de teste quando não vier do ambiente (a CI define o seu): a chave do limitador é HMAC com ele.
if (getenv('LOG_SALT') === false) {
    putenv('LOG_SALT=salt-de-teste-standalone-0123456789abcdef');
}
require_once CL_ROOT . '/src/IpRateLimiter.php';
if (getenv('T_RATELIMIT_NS_FILE') === false) {
    $__tRl = (string) tempnam(sys_get_temp_dir(), 'clrl');
    putenv('T_RATELIMIT_NS_FILE=' . $__tRl);
    register_shutdown_function(static fn () => @unlink($__tRl));
    t_ratelimit_reset();
} else {
    IpRateLimiter::$namespace = (string) @file_get_contents((string) getenv('T_RATELIMIT_NS_FILE'));
}

/** Zera o estado do limite por IP desta execução (namespace novo para este processo e para os servidores). */
function t_ratelimit_reset(): void
{
    IpRateLimiter::$namespace = 't' . bin2hex(random_bytes(6)) . ':';
    file_put_contents((string) getenv('T_RATELIMIT_NS_FILE'), IpRateLimiter::$namespace);
}

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
 * Gives $file a new mtime one second older than the previous call's (#192): the worker file caches reload when the
 * mtime differs, and a test writes in the same second as the load, which filemtime() (1 s) cannot tell apart.
 */
function t_backdate(string $file): void
{
    static $mtime = null;
    $mtime = ($mtime ?? time() - 3600) - 1;
    touch($file, $mtime);
}

/**
 * Prepara um banco temporário (DB_DSN) com o schema do projeto (PostgreSQL only, #137). Exige TEST_PG_DSN
 * (ex.: pgsql:host=/run/pg;dbname=postgres; ops/dev-env/test-pg.sh define): cria casalivre_t_<pid>_<rand>, carrega
 * schema.pgsql.sql, roda o migrate e apaga SÓ esse banco no fim. Deve ser chamado antes de qualquer acesso a db().
 * Retorna o DSN do banco.
 */
function t_temp_db(): string
{
    $pgAdmin = (string) getenv('TEST_PG_DSN');
    if ($pgAdmin === '') {
        fwrite(STDERR, "TEST_PG_DSN is required (PostgreSQL only): run ops/dev-env/test-pg.sh or set TEST_PG_DSN/TEST_PG_USER.\n");
        exit(2);
    }
    return t_temp_pg($pgAdmin);
}

/** Banco PG temporário VAZIO (sem schema, sem DB_DSN): cria e apaga no shutdown. Retorna o DSN. */
function t_temp_pg_empty(): string
{
    $adminDsn = (string) getenv('TEST_PG_DSN');
    if ($adminDsn === '') {
        fwrite(STDERR, "TEST_PG_DSN is required (PostgreSQL only).\n");
        exit(2);
    }
    $name = 'casalivre_t_' . getmypid() . '_' . bin2hex(random_bytes(3));
    t_pg_admin($adminDsn)->exec("CREATE DATABASE $name");
    register_shutdown_function(static fn () => t_pg_drop($adminDsn, $name));
    return preg_match('/dbname=[^;]+/', $adminDsn) ? (string) preg_replace('/dbname=[^;]+/', "dbname=$name", $adminDsn) : $adminDsn . ";dbname=$name";
}

/** Banco PG temporário: cria, carrega o schema, migra (seeds + versão) e apaga no shutdown. */
function t_temp_pg(string $adminDsn): string
{
    $opts = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION];
    $user = getenv('TEST_PG_USER') ?: null;
    $pass = getenv('TEST_PG_PASSWORD') ?: null;
    $name = 'casalivre_t_' . getmypid() . '_' . bin2hex(random_bytes(3));
    $admin = t_pg_admin($adminDsn);
    $admin->exec("CREATE DATABASE $name");
    $dsn = preg_match('/dbname=[^;]+/', $adminDsn) ? (string) preg_replace('/dbname=[^;]+/', "dbname=$name", $adminDsn) : $adminDsn . ";dbname=$name";
    register_shutdown_function(static fn () => t_pg_drop($adminDsn, $name));
    (new PDO($dsn, $user, $pass, $opts))->exec((string) file_get_contents(CL_ROOT . '/database/schema.pgsql.sql'));
    putenv('DB_DSN=' . $dsn);
    $user !== null && putenv('DB_USER=' . $user);
    $pass !== null && putenv('DB_PASSWORD=' . $pass);
    // Em processo (migrate() carrega Settings, I18n, ActivityLog...).
    require_once CL_ROOT . '/src/Db.php';
    Db::migrateNow();
    return $dsn;
}

/**
 * Conexão de teste ao banco temporário (DB_DSN) como $role (SET ROLE; o login de teste é membro com SET TRUE).
 * casalivre_retention é o dono do schema audit: só os testes leem as tabelas de log assim; o app só tem INSERT.
 */
function t_pg_as(string $role, ?string $dsn = null): PDO
{
    $pdo = new PDO($dsn ?? (string) getenv('DB_DSN'), getenv('DB_USER') ?: null, getenv('DB_PASSWORD') ?: null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('SET ROLE ' . $role);
    return $pdo;
}

/**
 * Linhas dos dois logs (audit.activity_log + audit.audit_log) do banco temporário, em ordem de gravação
 * (created_at, id), lidas como o dono do schema audit.
 * @return list<array{id: string, created_at: string, code: int, class: string, actor_kind: string, actor_ref: ?string,
 *   actor_key_version: ?int, access_event_id: ?string, params: array, anterior: mixed, atual: mixed}>
 */
function t_log_rows(?string $sinceCreatedAt = null, ?string $dsn = null): array
{
    $pdo = t_pg_as('casalivre_retention', $dsn);
    $cols = "id::text AS id, to_char(created_at AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI:SS.US') AS created_at, code, retention_class,
        actor_kind, encode(actor_ref, 'hex') AS actor_ref, actor_key_version, %s AS access_event_id, payload::text AS payload,
        before_value::text AS before_value, after_value::text AS after_value";
    $where = $sinceCreatedAt === null ? '' : " WHERE created_at > ?::timestamp AT TIME ZONE 'UTC'";
    $st = $pdo->prepare('SELECT * FROM (SELECT ' . sprintf($cols, 'access_event_id::text') . ' FROM audit.activity_log' . $where
        . ' UNION ALL SELECT ' . sprintf($cols, 'NULL::text') . ' FROM audit.audit_log' . $where . ') x ORDER BY created_at, id');
    $st->execute($sinceCreatedAt === null ? [] : [$sinceCreatedAt, $sinceCreatedAt]);
    return array_map(static fn (array $r): array => [
        'id' => (string) $r['id'],
        'created_at' => (string) $r['created_at'],
        'code' => (int) $r['code'],
        'class' => (string) $r['retention_class'],
        'actor_kind' => (string) $r['actor_kind'],
        'actor_ref' => $r['actor_ref'],
        'actor_key_version' => $r['actor_key_version'] === null ? null : (int) $r['actor_key_version'],
        'access_event_id' => $r['access_event_id'],
        'params' => json_decode((string) $r['payload'], true) ?: [],
        'anterior' => $r['before_value'] === null ? null : json_decode((string) $r['before_value'], true),
        'atual' => $r['after_value'] === null ? null : json_decode((string) $r['after_value'], true),
    ], $st->fetchAll());
}

/** Quantas linhas há nos dois logs (todas ou só de $code), lidas como o dono do schema audit. */
function t_log_count(?int $code = null, ?string $dsn = null): int
{
    $q = 'SELECT count(*) FROM (SELECT code FROM audit.activity_log UNION ALL SELECT code FROM audit.audit_log) x'
        . ($code === null ? '' : ' WHERE code = ' . $code);
    return (int) t_pg_as('casalivre_retention', $dsn)->query($q)->fetchColumn();
}

/** Marca (relógio do banco agora, UTC) para t_log_rows($mark): só o que for gravado depois. */
function t_log_mark(?string $dsn = null): string
{
    usleep(1000);
    return (string) t_pg_as('casalivre_retention', $dsn)->query("SELECT to_char(clock_timestamp() AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI:SS.US')")->fetchColumn();
}

/** Admin PDO for the test PG (TEST_PG_DSN/USER/PASSWORD). */
function t_pg_admin(string $adminDsn): PDO
{
    return new PDO($adminDsn, getenv('TEST_PG_USER') ?: null, getenv('TEST_PG_PASSWORD') ?: null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
}

/** Terminates one PostgreSQL backend (pg_terminate_backend) from another connection of the same role. */
function t_pg_terminate_backend(PDO $pdo, int $pid): void
{
    $pdo->query('SELECT pg_terminate_backend(' . $pid . ')');
}

/**
 * Drops a test database. WITH (FORCE) first; if PG denies it (e.g. "permission denied to terminate process"
 * on a backend casalivre_dev may not signal), closes the test's own connection and retries without FORCE
 * for a few seconds, until the other sessions leave.
 */
function t_pg_drop(string $adminDsn, string $name): bool
{
    try {
        $admin = t_pg_admin($adminDsn);
        $admin->exec("DROP DATABASE IF EXISTS $name WITH (FORCE)");
        return true;
    } catch (Throwable $e) {
        $first = $e->getMessage();
    }
    if (class_exists('Db', false)) {
        (new ReflectionProperty('Db', 'conn'))->setValue(null, null); // test only: release the app connection
        gc_collect_cycles();
    }
    for ($i = 0; $i < 20; $i++) {
        try {
            ($admin ?? t_pg_admin($adminDsn))->exec("DROP DATABASE IF EXISTS $name");
            return true;
        } catch (Throwable $e) {
            usleep(250000);
        }
    }
    fwrite(STDERR, "t_temp_pg: could not drop $name: $first / {$e->getMessage()}\n");
    return false;
}

/**
 * run.php sweep: drops casalivre_t_<pid>_* whose test process no longer exists (leftovers of interrupted tests).
 * Databases of live processes (a parallel run) are kept. PIDs are those of the PID namespace running the suite.
 * @return list<string> dropped databases
 */
function t_pg_sweep(string $adminDsn): array
{
    $gone = [];
    $rows = t_pg_admin($adminDsn)->query("SELECT datname FROM pg_database WHERE datname LIKE 'casalivre\\_t\\_%'")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($rows as $db) {
        if (preg_match('/^casalivre_t_(\d+)_[0-9a-f]+$/', (string) $db, $m) && !file_exists('/proc/' . $m[1]) && t_pg_drop($adminDsn, (string) $db)) {
            $gone[] = (string) $db;
        }
    }
    return $gone;
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
    $env = array_merge(getenv(), ['DB_DSN' => (string) getenv('DB_DSN'), 'T_SERVER_NONCE' => $nonce]);
    // APP_URL fixa (obrigatória fora de dev/local); o teste pode definir outra antes de subir o servidor.
    $env['APP_URL'] = (string) (getenv('APP_URL') ?: 'http://127.0.0.1:' . $port);
    // GEO_SALT é obrigatório fora de dev/local (GeoSalt::enforce); o teste pode definir outro (inclusive vazio).
    // Se o .env do teste (CASALIVRE_ENV_FILE) já traz GEO_SALT, vale o do arquivo.
    $envFile = (string) getenv('CASALIVRE_ENV_FILE');
    $fileHasSalt = $envFile !== '' && is_file($envFile) && preg_match('/^GEO_SALT=/m', (string) file_get_contents($envFile)) === 1;
    if (getenv('GEO_SALT') === false && !$fileHasSalt) {
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
