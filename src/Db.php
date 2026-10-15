<?php

declare(strict_types=1);

final class Db
{
    public static function pdo(): PDO
    {
        static $pdo = null;
        if ($pdo instanceof PDO) {
            return $pdo;
        }
        self::loadEnv();
        $driver = getenv('DB_DRIVER') ?: 'sqlite';
        $dsn = getenv('DB_DSN') ?: '';
        if ($dsn === '' && $driver === 'sqlite') {
            $dsn = 'sqlite:' . dirname(__DIR__) . '/storage/db/casalivre.sqlite';
        }
        $user = getenv('DB_USER') ?: null;
        $password = getenv('DB_PASSWORD');
        $password = ($password === false || $password === '') ? null : $password;
        $pdoDriver = $driver === 'pgsql' ? 'pgsql' : 'sqlite';
        if (!in_array($pdoDriver, PDO::getAvailableDrivers(), true)) {
            $pkg = $pdoDriver === 'pgsql' ? 'php-pgsql' : 'php-sqlite';
            throw new RuntimeException(
                "O PHP não tem o driver PDO '$pdoDriver'. No Void Linux: sudo xbps-install -S $pkg"
            );
        }
        $pdo = new PDO($dsn, $user, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $pdo->exec('PRAGMA foreign_keys = ON');
            // busy_timeout antes de journal_mode: trocar o modo também pode esperar lock.
            // (>= 5000 ms exigido pelo DEF-008; mantido 8000 ms, que já era o valor do projeto.)
            $pdo->exec('PRAGMA busy_timeout = 8000');
            $pdo->exec('PRAGMA journal_mode = WAL');
            $pdo->exec('PRAGMA synchronous = NORMAL');
            $pdo->exec('PRAGMA temp_store = MEMORY');
            $pdo->exec('PRAGMA cache_size = -8000');
        }
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            // DEF-008: só migra quando o banco está atrás de SCHEMA_VERSION. A checagem
            // (PRAGMA user_version) é uma leitura barata, sem lock de escrita.
            if ((int) $pdo->query('PRAGMA user_version')->fetchColumn() < self::SCHEMA_VERSION) {
                try {
                    self::migrateSqlite($pdo, false);
                } catch (Throwable $e) {
                    $pdo = null;
                    throw $e;
                }
            }
        } else {
            self::migrate($pdo);
        }
        return $pdo;
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
     * cidades, catálogo de ações). AUMENTE este número sempre que mudar migrate(), o schema
     * ou os seeds; senão os bancos existentes não recebem a mudança.
     */
    public const SCHEMA_VERSION = 13; // 2: auth.pending_email; 3: resumo de camas por quarto (form.*, #21); 4: err.field_type + js.* (#23); 5: mail_queue; 6: tokens sha256 (sem activation_link/reset_link, #35); 7: fotos assíncronas (photos.status/uuid/original, image_queue); 8: users.is_admin + settings.theme (temas, Etapa 2); 9: seed sem err.email_taken (e-mail não é editável); 10: err.profile_required; 11: grupos (groups, group_members, group_domains, group_property_invites, properties.group_id); 12: group_domains.verify_token + verified_at (verificação DNS TXT); 13: group_domains.last_check_at + fail_count (reverificação pelo cron)

    /** Força/aplica a migração (usado por scripts/migrate.php no deploy). Retorna a versão final. */
    public static function migrateNow(bool $force = false): int
    {
        $pdo = self::pdo();
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            self::migrateSqlite($pdo, $force);
            return (int) $pdo->query('PRAGMA user_version')->fetchColumn();
        }
        self::migrate($pdo);
        return self::SCHEMA_VERSION;
    }

    /**
     * Em WAL, promover uma transação de leitura para escrita falha na hora com
     * "database is locked" (o busy_timeout não se aplica). BEGIN IMMEDIATE pega o lock de
     * escrita no início (respeitando o busy_timeout); a versão é rechecada dentro do lock
     * porque outro processo pode ter migrado enquanto esperávamos.
     */
    /**
     * Transação aberta com SQL cru (BEGIN IMMEDIATE em migrateSqlite). No PHP 8.2/8.3,
     * PDO::inTransaction() não enxerga transações abertas via exec(), então o Db controla isso.
     */
    private static bool $rawTransaction = false;
    private static int $savepoint = 0;

    /** Há transação aberta nesta conexão (via PDO ou via SQL cru do Db)? */
    public static function inTransaction(PDO $pdo): bool
    {
        return self::$rawTransaction || $pdo->inTransaction();
    }

    /**
     * Executa $fn numa transação. Se já houver uma aberta (inclusive o BEGIN IMMEDIATE da
     * migração), usa SAVEPOINT/RELEASE em vez de beginTransaction(), que falharia.
     *
     * @template T
     * @param callable(PDO): T $fn
     * @return T
     */
    public static function transactional(PDO $pdo, callable $fn, bool $immediate = false): mixed
    {
        // $immediate: no SQLite abre com BEGIN IMMEDIATE (lock de escrita já no início, respeitando o busy_timeout),
        // para leitura + escrita sem o deadlock do upgrade de lock da transação deferred. No pgsql não muda nada.
        if ($immediate && !self::inTransaction($pdo) && (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $pdo->exec('BEGIN IMMEDIATE');
            self::$rawTransaction = true;
            try {
                $result = $fn($pdo);
                $pdo->exec('COMMIT');
                return $result;
            } catch (Throwable $e) {
                try {
                    $pdo->exec('ROLLBACK');
                } catch (Throwable) {
                }
                throw $e;
            } finally {
                self::$rawTransaction = false;
            }
        }
        $own = false;
        if (!self::inTransaction($pdo)) {
            try {
                $pdo->beginTransaction();
                $own = true;
            } catch (PDOException $e) {
                // BEGIN cru aberto fora do Db (PHP 8.2/8.3 não o enxerga): cai no SAVEPOINT.
                if (!str_contains($e->getMessage(), 'within a transaction')) {
                    throw $e;
                }
            }
        }
        if ($own) {
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

    private static function migrateSqlite(PDO $pdo, bool $force): void
    {
        $pdo->exec('BEGIN IMMEDIATE');
        self::$rawTransaction = true;
        try {
            if ($force || (int) $pdo->query('PRAGMA user_version')->fetchColumn() < self::SCHEMA_VERSION) {
                self::migrate($pdo);
                $pdo->exec('PRAGMA user_version = ' . (int) self::SCHEMA_VERSION);
            }
            $pdo->exec('COMMIT');
        } catch (Throwable $e) {
            try {
                $pdo->exec('ROLLBACK');
            } catch (Throwable) {
            }
            throw $e;
        } finally {
            self::$rawTransaction = false;
        }
    }

    private static function migrate(PDO $pdo): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        foreach ([
            'CREATE TABLE IF NOT EXISTS settings (skey TEXT PRIMARY KEY, value TEXT NOT NULL, description TEXT NOT NULL)',
            'CREATE TABLE IF NOT EXISTS languages (code TEXT PRIMARY KEY, label TEXT NOT NULL)',
            'CREATE TABLE IF NOT EXISTS translations (lang TEXT NOT NULL, tkey TEXT NOT NULL, text TEXT NOT NULL, PRIMARY KEY (lang, tkey))',
            'CREATE TABLE IF NOT EXISTS cities (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, uf TEXT NOT NULL, label TEXT NOT NULL, lat REAL NOT NULL, lng REAL NOT NULL, name_fold TEXT NOT NULL, label_fold TEXT NOT NULL)',
            'CREATE INDEX IF NOT EXISTS idx_cities_name_fold ON cities(name_fold)',
            'CREATE INDEX IF NOT EXISTS idx_cities_label_fold ON cities(label_fold)',
            'CREATE TABLE IF NOT EXISTS activity_actions (code INTEGER PRIMARY KEY, description TEXT NOT NULL)',
            'CREATE TABLE IF NOT EXISTS login_rate_limit (ip TEXT PRIMARY KEY, failures INTEGER NOT NULL DEFAULT 0, locked_until TEXT)',
        ] as $sql) {
            if ($driver === 'pgsql' && str_contains($sql, 'AUTOINCREMENT')) {
                $sql = 'CREATE TABLE IF NOT EXISTS cities (id INTEGER GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY, name TEXT NOT NULL, uf TEXT NOT NULL, label TEXT NOT NULL, lat DOUBLE PRECISION NOT NULL, lng DOUBLE PRECISION NOT NULL, name_fold TEXT NOT NULL, label_fold TEXT NOT NULL)';
            }
            $pdo->exec($sql);
        }
        self::renameUsers($pdo, $driver);
        $userColumns = [
            'status' => "TEXT NOT NULL DEFAULT 'active'",
            'activation_expires_at' => 'TEXT',
            'reset_token' => 'TEXT',
            'reset_expires_at' => 'TEXT',
            'inactivated_at' => 'TEXT',
            'inactivated_ip' => 'TEXT',
            'deleted_at' => 'TEXT',
            'failed_logins' => 'INTEGER NOT NULL DEFAULT 0',
            'session_version' => 'INTEGER NOT NULL DEFAULT 0',
            'cep' => "TEXT NOT NULL DEFAULT ''",
            'street' => "TEXT NOT NULL DEFAULT ''",
            'address_number' => "TEXT NOT NULL DEFAULT ''",
            'address_complement' => "TEXT NOT NULL DEFAULT ''",
            'audit_hold' => 'TEXT',
            'is_admin' => 'INTEGER NOT NULL DEFAULT 0',
        ];
        if ($driver === 'sqlite') {
            $have = [];
            foreach ($pdo->query('PRAGMA table_info(users)') as $col) {
                $have[$col['name']] = true;
            }
            foreach ($userColumns as $name => $type) {
                if (!isset($have[$name])) {
                    $pdo->exec('ALTER TABLE users ADD COLUMN ' . $name . ' ' . $type);
                }
            }
        } else {
            foreach ($userColumns as $name => $type) {
                $pdo->exec('ALTER TABLE users ADD COLUMN IF NOT EXISTS ' . $name . ' ' . $type);
            }
        }
        if ($driver === 'sqlite') {
            $haveProperty = [];
            foreach ($pdo->query('PRAGMA table_info(properties)') as $col) {
                $haveProperty[$col['name']] = true;
            }
            if ($haveProperty !== [] && !isset($haveProperty['amenities'])) {
                $pdo->exec("ALTER TABLE properties ADD COLUMN amenities TEXT NOT NULL DEFAULT '{}'");
            }
            $haveDays = [];
            foreach ($pdo->query('PRAGMA table_info(calendar_days)') as $col) {
                $haveDays[$col['name']] = true;
            }
            if ($haveDays !== [] && !isset($haveDays['min_nights'])) {
                $pdo->exec('ALTER TABLE calendar_days ADD COLUMN min_nights INTEGER');
            }
            if ($haveDays !== [] && !isset($haveDays['origin'])) {
                $pdo->exec("ALTER TABLE calendar_days ADD COLUMN origin TEXT NOT NULL DEFAULT ''");
            }
            if ($haveDays !== [] && !isset($haveDays['audit_hold'])) {
                $pdo->exec('ALTER TABLE calendar_days ADD COLUMN audit_hold TEXT');
            }
            $havePhotos = [];
            foreach ($pdo->query('PRAGMA table_info(photos)') as $col) {
                $havePhotos[$col['name']] = true;
            }
            if ($havePhotos !== [] && !isset($havePhotos['audit_hold'])) {
                $pdo->exec('ALTER TABLE photos ADD COLUMN audit_hold TEXT');
            }
            if ($haveProperty !== [] && !isset($haveProperty['ical_hash'])) {
                $pdo->exec('ALTER TABLE properties ADD COLUMN ical_hash TEXT');
            }
            if ($haveProperty !== [] && !isset($haveProperty['status'])) {
                $pdo->exec("ALTER TABLE properties ADD COLUMN status TEXT NOT NULL DEFAULT 'active'");
            }
            if ($haveProperty !== [] && !isset($haveProperty['inactivated_at'])) {
                $pdo->exec('ALTER TABLE properties ADD COLUMN inactivated_at TEXT');
            }
            if ($haveProperty !== [] && !isset($haveProperty['public_hash'])) {
                $pdo->exec('ALTER TABLE properties ADD COLUMN public_hash TEXT');
            }
            foreach (['cep' => "TEXT NOT NULL DEFAULT ''", 'street' => "TEXT NOT NULL DEFAULT ''", 'address_number' => "TEXT NOT NULL DEFAULT ''", 'address_complement' => "TEXT NOT NULL DEFAULT ''"] as $name => $type) {
                if ($haveProperty !== [] && !isset($haveProperty[$name])) {
                    $pdo->exec('ALTER TABLE properties ADD COLUMN ' . $name . ' ' . $type);
                }
            }
            if ($haveProperty !== [] && !isset($haveProperty['calendar_months'])) {
                $pdo->exec('ALTER TABLE properties ADD COLUMN calendar_months INTEGER NOT NULL DEFAULT ' . self::calendarMonthsDefault($pdo));
            }
            if ($haveProperty !== [] && !isset($haveProperty['cleaning_fee'])) {
                $pdo->exec('ALTER TABLE properties ADD COLUMN cleaning_fee INTEGER NOT NULL DEFAULT 0');
            }
            if ($haveProperty !== [] && !isset($haveProperty['audit_hold'])) {
                $pdo->exec('ALTER TABLE properties ADD COLUMN audit_hold TEXT');
            }
            if ($haveProperty !== [] && !isset($haveProperty['rooms'])) {
                $pdo->exec("ALTER TABLE properties ADD COLUMN rooms TEXT NOT NULL DEFAULT '[]'");
            }
            if ($haveProperty !== []) {
                $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS properties_ical_hash ON properties(ical_hash)');
                $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS properties_public_hash ON properties(public_hash)');
                $pdo->exec('CREATE INDEX IF NOT EXISTS idx_properties_status ON properties(status)');
                $pdo->exec('CREATE INDEX IF NOT EXISTS idx_photos_cover ON photos(property_id, is_primary, sort_order, id)');
                $pdo->exec('CREATE TABLE IF NOT EXISTS calendar_feeds (id INTEGER PRIMARY KEY AUTOINCREMENT, property_id INTEGER NOT NULL REFERENCES properties(id) ON DELETE CASCADE, url TEXT NOT NULL, last_status TEXT, last_synced_at TEXT, UNIQUE (property_id, url))');
                $pdo->exec("INSERT INTO calendar_feeds (property_id, url)
                    SELECT id, external_ical_url FROM properties
                    WHERE external_ical_url IS NOT NULL AND trim(external_ical_url) != ''
                    AND NOT EXISTS (SELECT 1 FROM calendar_feeds f WHERE f.property_id = properties.id AND f.url = properties.external_ical_url)");
                $haveFeeds = [];
                foreach ($pdo->query('PRAGMA table_info(calendar_feeds)') as $col) {
                    $haveFeeds[$col['name']] = true;
                }
                if ($haveFeeds !== [] && !isset($haveFeeds['title'])) {
                    $pdo->exec("ALTER TABLE calendar_feeds ADD COLUMN title TEXT NOT NULL DEFAULT ''");
                }
            }
        } else {
            $pdo->exec("ALTER TABLE properties ADD COLUMN IF NOT EXISTS amenities TEXT NOT NULL DEFAULT '{}'");
            $pdo->exec('ALTER TABLE properties ADD COLUMN IF NOT EXISTS ical_hash TEXT');
            $pdo->exec("ALTER TABLE properties ADD COLUMN IF NOT EXISTS status TEXT NOT NULL DEFAULT 'active'");
            $pdo->exec('ALTER TABLE properties ADD COLUMN IF NOT EXISTS inactivated_at TIMESTAMP');
            $pdo->exec('ALTER TABLE properties ADD COLUMN IF NOT EXISTS public_hash TEXT');
            $pdo->exec("ALTER TABLE properties ADD COLUMN IF NOT EXISTS cep TEXT NOT NULL DEFAULT ''");
            $pdo->exec("ALTER TABLE properties ADD COLUMN IF NOT EXISTS street TEXT NOT NULL DEFAULT ''");
            $pdo->exec("ALTER TABLE properties ADD COLUMN IF NOT EXISTS address_number TEXT NOT NULL DEFAULT ''");
            $pdo->exec("ALTER TABLE properties ADD COLUMN IF NOT EXISTS address_complement TEXT NOT NULL DEFAULT ''");
            $pdo->exec('ALTER TABLE properties ADD COLUMN IF NOT EXISTS calendar_months INTEGER NOT NULL DEFAULT ' . self::calendarMonthsDefault($pdo));
            $pdo->exec('ALTER TABLE properties ADD COLUMN IF NOT EXISTS cleaning_fee INTEGER NOT NULL DEFAULT 0');
            $pdo->exec('ALTER TABLE properties ADD COLUMN IF NOT EXISTS audit_hold TEXT');
            $pdo->exec("ALTER TABLE properties ADD COLUMN IF NOT EXISTS rooms TEXT NOT NULL DEFAULT '[]'");
            $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS properties_ical_hash ON properties (ical_hash)');
            $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS properties_public_hash ON properties (public_hash)');
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_properties_status ON properties (status)');
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_photos_cover ON photos (property_id, is_primary, sort_order, id)');
            $pdo->exec('ALTER TABLE calendar_days ADD COLUMN IF NOT EXISTS min_nights INTEGER');
            $pdo->exec("ALTER TABLE calendar_days ADD COLUMN IF NOT EXISTS origin TEXT NOT NULL DEFAULT ''");
            $pdo->exec('ALTER TABLE calendar_days ADD COLUMN IF NOT EXISTS audit_hold TEXT');
            $pdo->exec('ALTER TABLE photos ADD COLUMN IF NOT EXISTS audit_hold TEXT');
            $pdo->exec('CREATE TABLE IF NOT EXISTS calendar_feeds (id INTEGER GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY, property_id INTEGER NOT NULL REFERENCES properties(id) ON DELETE CASCADE, url TEXT NOT NULL, last_status TEXT, last_synced_at TIMESTAMP, UNIQUE (property_id, url))');
            $pdo->exec("ALTER TABLE calendar_feeds ADD COLUMN IF NOT EXISTS title TEXT NOT NULL DEFAULT ''");
            $pdo->exec("INSERT INTO calendar_feeds (property_id, url)
                SELECT id, external_ical_url FROM properties
                WHERE external_ical_url IS NOT NULL AND btrim(external_ical_url) != ''
                AND NOT EXISTS (SELECT 1 FROM calendar_feeds f WHERE f.property_id = properties.id AND f.url = properties.external_ical_url)");
        }
        if ($driver === 'pgsql') {
            $pdo->exec("CREATE TABLE IF NOT EXISTS ceps (id INTEGER GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY, cep TEXT NOT NULL UNIQUE, street TEXT NOT NULL DEFAULT '', neighborhood TEXT NOT NULL DEFAULT '', city TEXT NOT NULL DEFAULT '', uf TEXT NOT NULL DEFAULT '')");
        } else {
            $pdo->exec("CREATE TABLE IF NOT EXISTS ceps (id INTEGER PRIMARY KEY AUTOINCREMENT, cep TEXT NOT NULL UNIQUE, street TEXT NOT NULL DEFAULT '', neighborhood TEXT NOT NULL DEFAULT '', city TEXT NOT NULL DEFAULT '', uf TEXT NOT NULL DEFAULT '')");
        }
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_ceps_cep ON ceps(cep)');
        // Busca de tokens (hash) na ativação/redefinição; no pgsql, parciais (só linhas com token).
        $partial = static fn (string $c): string => $driver === 'pgsql' ? ' WHERE ' . $c . ' IS NOT NULL' : '';
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_users_activation_token ON users(activation_token)' . $partial('activation_token'));
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_users_reset_token ON users(reset_token)' . $partial('reset_token'));
        // Fila de e-mails (MailQueue): o worker lê por status/next_attempt_at.
        $pdo->exec('CREATE TABLE IF NOT EXISTS mail_queue (' . ($driver === 'pgsql' ? 'id INTEGER GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY' : 'id INTEGER PRIMARY KEY AUTOINCREMENT')
            . ", type TEXT NOT NULL, recipient TEXT NOT NULL, cooldown_key TEXT NOT NULL, status TEXT NOT NULL DEFAULT 'pending', attempts INTEGER NOT NULL DEFAULT 0, last_error TEXT NOT NULL DEFAULT '', next_attempt_at TEXT NOT NULL, created_at TEXT NOT NULL, updated_at TEXT NOT NULL)");
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_mail_queue_ready ON mail_queue(status, next_attempt_at)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_mail_queue_cooldown ON mail_queue(cooldown_key, created_at)');
        // Fotos assíncronas: status/uuid/original em photos e a fila image_queue (ImageQueue).
        $photoColumns = ['status' => "TEXT NOT NULL DEFAULT 'ready'", 'uuid' => 'TEXT', 'original' => 'TEXT'];
        if ($driver === 'sqlite') {
            $havePhoto = [];
            foreach ($pdo->query('PRAGMA table_info(photos)') as $col) {
                $havePhoto[$col['name']] = true;
            }
            foreach ($photoColumns as $name => $type) {
                if ($havePhoto !== [] && !isset($havePhoto[$name])) {
                    $pdo->exec('ALTER TABLE photos ADD COLUMN ' . $name . ' ' . $type);
                }
            }
        } else {
            foreach ($photoColumns as $name => $type) {
                $pdo->exec('ALTER TABLE photos ADD COLUMN IF NOT EXISTS ' . $name . ' ' . $type);
            }
        }
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_photos_status ON photos(property_id, status)');
        $pdo->exec('CREATE TABLE IF NOT EXISTS image_queue (' . ($driver === 'pgsql' ? 'id INTEGER GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY' : 'id INTEGER PRIMARY KEY AUTOINCREMENT')
            . ", photo_id INTEGER NOT NULL, status TEXT NOT NULL DEFAULT 'pending', attempts INTEGER NOT NULL DEFAULT 0, last_error TEXT NOT NULL DEFAULT '', next_attempt_at TEXT NOT NULL, created_at TEXT NOT NULL, updated_at TEXT NOT NULL)");
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_image_queue_ready ON image_queue(status, next_attempt_at)');
        // Grupos (Sprint 3, PR 6): tabelas e properties.group_id (antes do índice), nos dois drivers.
        $ident = $driver === 'pgsql' ? 'INTEGER GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        $now = $driver === 'pgsql' ? 'TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP' : "TEXT NOT NULL DEFAULT (datetime('now'))";
        $pdo->exec("CREATE TABLE IF NOT EXISTS groups (id $ident, slug TEXT NOT NULL UNIQUE, name TEXT NOT NULL, theme TEXT NOT NULL, created_at $now)");
        $pdo->exec("CREATE TABLE IF NOT EXISTS group_members (group_id INTEGER NOT NULL REFERENCES groups(id) ON DELETE CASCADE, user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE, role TEXT NOT NULL CHECK (role IN ('gestor', 'membro')), created_at $now, PRIMARY KEY (group_id, user_id))");
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_group_members_user ON group_members(user_id)');
        $pdo->exec("CREATE TABLE IF NOT EXISTS group_domains (id $ident, group_id INTEGER NOT NULL REFERENCES groups(id) ON DELETE CASCADE, domain TEXT NOT NULL UNIQUE, created_at $now)");
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_group_domains_group ON group_domains(group_id)');
        // v12: verificação DNS TXT; domínios já cadastrados ficam sem verificar (não resolvem até o admin verificar).
        $verifiedType = $driver === 'pgsql' ? 'TIMESTAMP' : 'TEXT';
        if ($driver === 'sqlite') {
            $haveDomain = [];
            foreach ($pdo->query('PRAGMA table_info(group_domains)') as $col) {
                $haveDomain[$col['name']] = true;
            }
            if (!isset($haveDomain['verify_token'])) {
                $pdo->exec("ALTER TABLE group_domains ADD COLUMN verify_token TEXT NOT NULL DEFAULT ''");
            }
            if (!isset($haveDomain['verified_at'])) {
                $pdo->exec("ALTER TABLE group_domains ADD COLUMN verified_at $verifiedType");
            }
            if (!isset($haveDomain['last_check_at'])) {
                $pdo->exec("ALTER TABLE group_domains ADD COLUMN last_check_at $verifiedType");
            }
            if (!isset($haveDomain['fail_count'])) {
                $pdo->exec('ALTER TABLE group_domains ADD COLUMN fail_count INTEGER NOT NULL DEFAULT 0');
            }
        } else {
            $pdo->exec("ALTER TABLE group_domains ADD COLUMN IF NOT EXISTS verify_token TEXT NOT NULL DEFAULT ''");
            $pdo->exec("ALTER TABLE group_domains ADD COLUMN IF NOT EXISTS verified_at $verifiedType");
            $pdo->exec("ALTER TABLE group_domains ADD COLUMN IF NOT EXISTS last_check_at $verifiedType");
            $pdo->exec('ALTER TABLE group_domains ADD COLUMN IF NOT EXISTS fail_count INTEGER NOT NULL DEFAULT 0');
        }
        $pdo->exec("CREATE TABLE IF NOT EXISTS group_property_invites (group_id INTEGER NOT NULL REFERENCES groups(id) ON DELETE CASCADE, property_id INTEGER NOT NULL REFERENCES properties(id) ON DELETE CASCADE, invited_by INTEGER REFERENCES users(id) ON DELETE SET NULL, created_at $now, PRIMARY KEY (group_id, property_id))");
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_group_invites_property ON group_property_invites(property_id)');
        if ($driver === 'sqlite') {
            $haveGroup = [];
            foreach ($pdo->query('PRAGMA table_info(properties)') as $col) {
                $haveGroup[$col['name']] = true;
            }
            if ($haveGroup !== [] && !isset($haveGroup['group_id'])) {
                $pdo->exec('ALTER TABLE properties ADD COLUMN group_id INTEGER REFERENCES groups(id) ON DELETE SET NULL');
            }
            if ($haveGroup !== []) {
                $pdo->exec('CREATE INDEX IF NOT EXISTS idx_properties_group ON properties(group_id)');
            }
        } else {
            $pdo->exec('ALTER TABLE properties ADD COLUMN IF NOT EXISTS group_id INTEGER REFERENCES groups(id) ON DELETE SET NULL');
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_properties_group ON properties(group_id)');
        }
        $missingHash = $pdo->query("SELECT id FROM properties WHERE public_hash IS NULL OR public_hash = ''");
        if ($missingHash) {
            $fillHash = $pdo->prepare('UPDATE properties SET public_hash = ? WHERE id = ?');
            foreach ($missingHash as $hashRow) {
                $fillHash->execute([bin2hex(random_bytes(8)), (int) $hashRow['id']]);
            }
        }
        require_once __DIR__ . '/Settings.php';
        require_once __DIR__ . '/I18n.php';
        require_once __DIR__ . '/CityRepository.php';
        require_once __DIR__ . '/ActivityLog.php';
        $unit = $pdo->prepare('SELECT value FROM settings WHERE skey = ?');
        $unit->execute(['money_unit']);
        if ($unit->fetchColumn() !== 'reais') {
            $pdo->exec('UPDATE properties SET nightly_price = CAST(ROUND(nightly_price / 100.0) AS INTEGER)');
            $pdo->exec('UPDATE calendar_days SET price = CAST(ROUND(price / 100.0) AS INTEGER) WHERE price IS NOT NULL');
            $pdo->prepare('INSERT INTO settings (skey, value, description) VALUES (?, ?, ?) ON CONFLICT(skey) DO UPDATE SET value = excluded.value')
                ->execute(['money_unit', 'reais', 'Diária gravada como número inteiro, sem centavos']);
        }
        Settings::seed($pdo);
        I18n::seed($pdo);
        CityRepository::ensure($pdo);
        $action = $pdo->prepare('INSERT INTO activity_actions (code, description) VALUES (?, ?)');
        $has = $pdo->prepare('SELECT 1 FROM activity_actions WHERE code = ?');
        $refresh = $pdo->prepare('UPDATE activity_actions SET description = ? WHERE code = ? AND description != ?');
        foreach (ActivityLog::CATALOG as $code => $description) {
            $has->execute([$code]);
            if (!$has->fetchColumn()) {
                $action->execute([$code, $description]);
            } else {
                $refresh->execute([$description, $code, $description]);
            }
        }
        $done = true;
    }

    private static function renameUsers(PDO $pdo, string $driver): void
    {
        if ($driver === 'sqlite') {
            $tables = [];
            foreach ($pdo->query("SELECT name FROM sqlite_master WHERE type = 'table'") as $row) {
                $tables[$row['name']] = true;
            }
            if (isset($tables['owners']) && !isset($tables['users'])) {
                $pdo->exec('ALTER TABLE owners RENAME TO users');
                $tables['users'] = true;
                unset($tables['owners']);
            }
            if (isset($tables['properties'])) {
                $cols = [];
                foreach ($pdo->query('PRAGMA table_info(properties)') as $col) {
                    $cols[$col['name']] = true;
                }
                if (isset($cols['owner_id']) && !isset($cols['user_id'])) {
                    $pdo->exec('ALTER TABLE properties RENAME COLUMN owner_id TO user_id');
                }
                if (isset($cols['user_id']) || isset($cols['owner_id'])) {
                    $pdo->exec('DROP INDEX IF EXISTS idx_properties_owner');
                    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_properties_user ON properties(user_id)');
                }
            }
            if (isset($tables['photos'])) {
                $pdo->exec("UPDATE photos SET path = REPLACE(path, '/uploads/proprietarios/', '/uploads/users/') WHERE path LIKE '%/uploads/proprietarios/%'");
            }
        } else {
            $owners = $pdo->query("SELECT to_regclass('public.owners')")->fetchColumn();
            $users = $pdo->query("SELECT to_regclass('public.users')")->fetchColumn();
            if ($owners && !$users) {
                $pdo->exec('ALTER TABLE owners RENAME TO users');
                $users = true;
            }
            $properties = $pdo->query("SELECT to_regclass('public.properties')")->fetchColumn();
            if ($properties) {
                $ownerCol = $pdo->query("SELECT 1 FROM information_schema.columns WHERE table_schema = 'public' AND table_name = 'properties' AND column_name = 'owner_id'")->fetchColumn();
                $userCol = $pdo->query("SELECT 1 FROM information_schema.columns WHERE table_schema = 'public' AND table_name = 'properties' AND column_name = 'user_id'")->fetchColumn();
                if ($ownerCol && !$userCol) {
                    $pdo->exec('ALTER TABLE properties RENAME COLUMN owner_id TO user_id');
                    $userCol = true;
                }
                if ($userCol) {
                    $pdo->exec('DROP INDEX IF EXISTS idx_properties_owner');
                    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_properties_user ON properties(user_id)');
                }
            }
            if ($pdo->query("SELECT to_regclass('public.photos')")->fetchColumn()) {
                $pdo->exec("UPDATE photos SET path = REPLACE(path, '/uploads/proprietarios/', '/uploads/users/') WHERE path LIKE '%/uploads/proprietarios/%'");
            }
        }
        $old = dirname(__DIR__) . '/public/uploads/proprietarios';
        $new = dirname(__DIR__) . '/public/uploads/users';
        if (is_dir($old) && !is_dir($new)) {
            rename($old, $new);
        }
    }

    private static function calendarMonthsDefault(PDO $pdo): int
    {
        return 6;
    }
}

function db(): PDO
{
    return Db::pdo();
}
