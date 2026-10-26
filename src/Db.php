<?php

declare(strict_types=1);

require_once __DIR__ . '/SchemaDesatualizado.php';

final class Db
{
    /** Conexão compartilhada do processo (antes era um static local de pdo()). */
    private static ?PDO $conn = null;

    /** true só dentro de migrateNow(): pdo() não confere a versão (quem migra é o próprio script). */
    private static bool $migrating = false;

    /** Chave do pg_advisory_lock da migração (fixa; qualquer bigint serve, só precisa ser única no banco). */
    public const PG_MIGRATE_LOCK = 718331201;

    public static function pdo(): PDO
    {
        if (self::$conn instanceof PDO) {
            return self::$conn;
        }
        $pdo = self::connectAs('DB_USER', 'DB_PASSWORD');
        if (!self::$migrating) {
            // A web request or worker only CHECKS the version (one query); it never migrates. See ensureSchemaCurrent().
            self::ensureSchemaCurrent($pdo);
        }
        return self::$conn = $pdo;
    }

    /**
     * New PDO on DB_DSN as the login in env $userEnv with the password in env $passwordEnv. Empty/unset $userEnv
     * falls back to the main credentials (DB_USER/DB_PASSWORD); an empty or unset password is null (peer/trust).
     * Same options for every connection: exceptions and FETCH_ASSOC. Used by pdo() and the role logins
     * (LOG_DB_USER, LOG_JOB_DB_USER, OPS_DB_USER).
     */
    public static function connectAs(string $userEnv, string $passwordEnv): PDO
    {
        $dsn = self::dsn();
        $user = (string) (getenv($userEnv) ?: '');
        if ($user === '') {
            $user = (string) (getenv('DB_USER') ?: '');
            $passwordEnv = 'DB_PASSWORD';
        }
        $password = getenv($passwordEnv);
        if (!in_array('pgsql', PDO::getAvailableDrivers(), true)) {
            throw new RuntimeException("PHP has no PDO 'pgsql' driver. On Void Linux: xbps-install -S php8.4-pgsql");
        }
        return new PDO($dsn, $user === '' ? null : $user, ($password === false || $password === '') ? null : $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }

    /**
     * PostgreSQL bytea[] literal ({"\\x..",...}) for binding a list of binary strings as one ?::bytea[] parameter.
     * @param list<string> $values
     */
    public static function byteaArrayLiteral(array $values): string
    {
        return '{' . implode(',', array_map(static fn (string $v): string => '"\\\\x' . bin2hex($v) . '"', $values)) . '}';
    }

    /**
     * Web request/worker: one query on schema_meta. A DB behind SCHEMA_VERSION (or without schema_meta)
     * => SchemaDesatualizado (503 in ExceptionShield). scripts/migrate.php is the only migrator.
     */
    public static function ensureSchemaCurrent(PDO $pdo): void
    {
        $version = self::pgSchemaVersion($pdo);
        if ($version < self::SCHEMA_VERSION) {
            throw new SchemaDesatualizado(self::staleSchemaMessage($version));
        }
    }

    /** DB_DSN (required, PostgreSQL only: pgsql:host=...;dbname=...). */
    public static function dsn(): string
    {
        self::loadEnv();
        $dsn = (string) (getenv('DB_DSN') ?: '');
        if ($dsn === '') {
            throw new RuntimeException('DB_DSN is not set. CasaLivre is PostgreSQL only: DB_DSN=pgsql:host=...;dbname=...');
        }
        if (!str_starts_with($dsn, 'pgsql:')) {
            throw new RuntimeException('DB_DSN must be a PostgreSQL DSN (pgsql:...); CasaLivre is PostgreSQL only.');
        }
        return $dsn;
    }

    /** Pastas de runtime que o deploy precisa (usado por scripts/migrate.php e scripts/setup.sh). storage/db guarda o cep.version. */
    public const STORAGE_DIRS = ['storage/db', 'storage/logs', 'storage/mail', 'storage/sessions', 'public/uploads/users'];

    /** Cria as pastas de STORAGE_DIRS que faltarem. */
    public static function ensureStorageDirs(): void
    {
        foreach (self::STORAGE_DIRS as $dir) {
            $path = dirname(__DIR__) . '/' . $dir;
            if (!is_dir($path) && !mkdir($path, 0775, true) && !is_dir($path)) {
                throw new RuntimeException("Não foi possível criar $path");
            }
        }
    }

    public static function loadEnv(): void
    {
        static $loaded = false;
        if ($loaded) {
            return;
        }
        $loaded = true;
        // CASALIVRE_ENV_FILE (opcional): outro arquivo .env; usado pelos testes para não ler o .env real.
        // Sem ela (produção), o padrão é o .env da raiz, como antes.
        $custom = getenv('CASALIVRE_ENV_FILE');
        $file = is_string($custom) && $custom !== '' ? $custom : dirname(__DIR__) . '/.env';
        if (!is_file($file)) {
            return;
        }
        // O ambiente tem precedência: o .env só preenche chaves ainda não definidas (getenv, $_ENV ou $_SERVER),
        // igual a scripts/env-lib.sh (start.sh). Ex.: APP_ENV=dev na linha de comando vence APP_ENV=production.
        foreach (file($file, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            $line = trim($line);
            if (str_starts_with($line, 'export ')) {
                $line = ltrim(substr($line, 7));
            }
            if ($line === '' || str_starts_with($line, '#') || preg_match('/^([A-Za-z_][A-Za-z0-9_]*)\s*=(.*)$/', $line, $m) !== 1) {
                continue;
            }
            $key = $m[1];
            $value = trim($m[2]);
            if (strlen($value) >= 2 && ($value[0] === '"' || $value[0] === "'") && $value[-1] === $value[0]) {
                $value = substr($value, 1, -1);
            }
            if (getenv($key) !== false || array_key_exists($key, $_ENV) || (array_key_exists($key, $_SERVER) && is_string($_SERVER[$key]))) {
                continue;
            }
            putenv($key . '=' . $value);
        }
    }

    /**
     * Versão do schema e dos dados semeados (settings, traduções de database/messages.php,
     * places (cidades), catálogo de ações). AUMENTE este número sempre que mudar o schema ou os seeds.
     * Sem migração de dados (decisão 2026-10-21): o banco é recriado do zero por database/schema.pgsql.sql;
     * migrate() só roda os seeds idempotentes e grava a versão.
     */
    public const SCHEMA_VERSION = 24; // 2: auth.pending_email; 3: resumo de camas por quarto (form.*, #21); 4: err.field_type + js.* (#23); 5: mail_queue; 6: tokens sha256 (sem activation_link/reset_link, #35); 7: fotos assíncronas (photos.status/uuid/original, image_queue); 8: users.is_admin + settings.theme (temas, Etapa 2); 9: seed sem err.email_taken (e-mail não é editável); 10: err.profile_required; 11: grupos (groups, group_members, group_domains, group_property_invites, properties.group_id); 12: group_domains.verify_token + verified_at (verificação DNS TXT); 13: group_domains.last_check_at + fail_count (reverificação pelo cron); 14: auth.verify_title/hint/button (ativação por POST); 15: cal.url_required + cal.feed_missing (paridade calendário/iCal); 16: photo.cover_one/none_arrived/partial + review.score_range/not_published (paridade capa/avaliação/fotos); 17: cal.feed_removed + cal.url_https (ajustes do calendário); 18: schema_meta (versão no pgsql) e fim da conversão money_unit (bancos recriados do zero, já em reais); 19: paridade dos schemas (7 tabelas no schema, properties.user_id RESTRICT, sem idx_ceps_cep/idx_photos_property, tokens parciais). 20: PostgreSQL only (#137): activity_actions.retention_class, schema audit (activity_log/audit_log particionados, funções DEFINER), legal_holds, legal_hold_access_events, purge_runs; sem caminho incremental no migrate(). 21: places + place_search_keys (ADR 001 §11/§11.2; o texto do ADR ainda dizia SCHEMA 20) e properties.city_place_id / neighborhood_place_id no lugar de properties.city (tabela cities removida). 22: bairros OpenCEP (type=neighborhood, database/places_neighborhoods.json.bz2, opencep-2.0.1 / database/cep.csv.bz2) e UNIQUE places_city_uf_uk (uf, name_fold) WHERE type = 'city' — substitui o places_root_uk do ADR, que rejeitava homônimos entre UFs. 23: idx_prop_latlng_active (lat, lng) WHERE status = 'active' replaces unused gist idx_prop_geo_active. 24: idx_prop_nb_active (neighborhood_place_id, id) INCLUDE (max_guests) WHERE active and neighborhood_place_id IS NOT NULL; drops idx_properties_status

    /** The one message for a DB behind SCHEMA_VERSION (requests and migrate.php): no data migration, recreate it. */
    private static function staleSchemaMessage(int $version): string
    {
        return "Banco na versão $version, esperada " . self::SCHEMA_VERSION . ': não há migração de dados. '
            . 'Recrie o banco do zero (database/schema.pgsql.sql) e rode php scripts/migrate.php antes de liberar o tráfego.';
    }

    /** Força/aplica a migração (usado por scripts/migrate.php no deploy). Retorna a versão final. */
    public static function migrateNow(bool $force = false): int
    {
        self::$migrating = true;
        try {
            $pdo = self::pdo();
        } finally {
            self::$migrating = false;
        }
        return self::migrateStep($pdo, $force);
    }

    /**
     * pg_advisory_lock serializa migrate.php concorrentes (o segundo espera o primeiro e, ao
     * rechecar a versão, não refaz nada). Tudo numa transação; a versão é gravada em schema_meta no mesmo COMMIT.
     */
    private static function migrateStep(PDO $pdo, bool $force): int
    {
        if ($pdo->query("SELECT to_regclass('public.users') IS NULL")->fetchColumn()) {
            throw new RuntimeException('Schema ausente: carregue database/schema.pgsql.sql num banco novo (psql -f) e rode o migrate.php de novo.');
        }
        $pdo->query('SELECT pg_advisory_lock(' . self::PG_MIGRATE_LOCK . ')');
        try {
            $pdo->beginTransaction();
            try {
                $pdo->exec(self::SCHEMA_META_DDL);
                $current = self::pgSchemaVersion($pdo);
                if ($current >= 1 && $current < self::SCHEMA_VERSION) { // 0 = new DB fresh from the schema file
                    throw new SchemaDesatualizado(self::staleSchemaMessage($current));
                }
                if ($force || $current < self::SCHEMA_VERSION) {
                    self::migrate($pdo);
                    $pdo->prepare("INSERT INTO schema_meta (skey, value) VALUES ('schema_version', ?) ON CONFLICT(skey) DO UPDATE SET value = excluded.value")
                        ->execute([(string) self::SCHEMA_VERSION]);
                }
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $e;
            }
        } finally {
            $pdo->query('SELECT pg_advisory_unlock(' . self::PG_MIGRATE_LOCK . ')');
        }
        return self::pgSchemaVersion($pdo);
    }

    /**
     * Versão gravada em schema_meta. 0 se a tabela ou a linha não existem.
     * Atenção: a consulta que falha (tabela ausente) aborta a transação aberta; dentro de
     * migrateStep a tabela já foi criada antes, então isso não acontece lá.
     */
    private static function pgSchemaVersion(PDO $pdo): int
    {
        try {
            $value = $pdo->query("SELECT value FROM schema_meta WHERE skey = 'schema_version'")->fetchColumn();
        } catch (PDOException $e) {
            // Só "tabela não existe" (42P01) vale como versão 0. Rede, permissão, timeout: relança.
            if (self::isUndefinedTable($e)) {
                return 0;
            }
            throw $e;
        }
        return $value === false ? 0 : (int) $value;
    }

    /** SQLSTATE from errorInfo[0] when that is a 5-character code, else from getCode(); null if neither is. */
    public static function sqlState(PDOException $e): ?string
    {
        $candidates = [isset($e->errorInfo[0]) ? $e->errorInfo[0] : null, $e->getCode()];
        foreach ($candidates as $candidate) {
            if (is_string($candidate) && preg_match('/\A[0-9A-Z]{5}\z/', $candidate) === 1) {
                return $candidate;
            }
        }
        return null;
    }

    public static function isUndefinedTable(PDOException $e): bool
    {
        return self::sqlState($e) === '42P01';
    }

    /**
     * Long-running worker liveness (QueueWorkerLoop::tick): one SELECT 1 on the shared connection. Throws when the
     * connection is gone (e.g. after a PG restart); the shared connection is never reopened in-process, the
     * worker exits and runit restarts it with a fresh one.
     */
    public static function ping(): void
    {
        self::pdo()->query('SELECT 1');
    }

    /**
     * Lost or unreachable DB connection (not a query/data error), anywhere in the exception chain: SQLSTATE class 08
     * (connection exception), 57P01-57P03 (admin/crash shutdown, cannot connect now), or the pgsql driver's
     * HY000 for a dropped session ("server closed the connection unexpectedly", "no connection to the server",
     * "terminating connection due to administrator command").
     */
    public static function isConnectionError(Throwable $e): bool
    {
        for (; $e !== null; $e = $e->getPrevious()) {
            if (!$e instanceof PDOException) {
                continue;
            }
            $state = self::sqlState($e) ?? '';
            if (str_starts_with($state, '08') || in_array($state, ['57P01', '57P02', '57P03'], true)) {
                return true;
            }
            $msg = $e->getMessage();
            // pdo_pgsql often reports a dropped session as HY000, including an administrator terminate
            // (pg_terminate_backend / server shutdown) whose SQLSTATE would otherwise be 57P01.
            if ($state === 'HY000' && (str_contains($msg, 'server closed the connection')
                || str_contains($msg, 'no connection to the server')
                || str_contains($msg, 'terminating connection due to administrator command'))) {
                return true;
            }
        }
        return false;
    }

    /** Metadados internos da migração (versão do schema). */
    private const SCHEMA_META_DDL = 'CREATE TABLE IF NOT EXISTS schema_meta (skey TEXT PRIMARY KEY, value TEXT NOT NULL)';

    private static int $savepoint = 0;

    /** Há transação aberta nesta conexão? */
    public static function inTransaction(PDO $pdo): bool
    {
        return $pdo->inTransaction();
    }

    /**
     * Executa $fn numa transação. Se já houver uma aberta, usa SAVEPOINT/RELEASE em vez de
     * beginTransaction(), que falharia. Concorrência: quem lê e depois escreve usa SELECT ... FOR UPDATE.
     *
     * @template T
     * @param callable(PDO): T $fn
     * @return T
     */
    public static function transactional(PDO $pdo, callable $fn): mixed
    {
        if (!$pdo->inTransaction()) {
            $pdo->beginTransaction();
            try {
                $result = $fn($pdo);
                $pdo->commit();
                return $result;
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $e;
            }
        }
        $name = 'cl_sp_' . (++self::$savepoint);
        $pdo->exec('SAVEPOINT ' . $name);
        try {
            $result = $fn($pdo);
            $pdo->exec('RELEASE SAVEPOINT ' . $name);
            return $result;
        } catch (Throwable $e) {
            try {
                $pdo->exec('ROLLBACK TO SAVEPOINT ' . $name);
                $pdo->exec('RELEASE SAVEPOINT ' . $name);
            } catch (Throwable) {
            }
            throw $e;
        }
    }

    /**
     * Seeds idempotentes only (no ALTER, no backfill, no legacy conversion): the tables come from
     * database/schema.pgsql.sql. A schema change goes to that file and to the seeds, never here.
     */
    private static function migrate(PDO $pdo): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        require_once __DIR__ . '/Settings.php';
        require_once __DIR__ . '/I18n.php';
        require_once __DIR__ . '/CityRepository.php';
        require_once __DIR__ . '/ActivityLog.php';
        Settings::seed($pdo);
        I18n::seed($pdo);
        CityRepository::ensure($pdo);
        ActivityLog::seedActions($pdo);
        $done = true;
    }
}

function db(): PDO
{
    return Db::pdo();
}
