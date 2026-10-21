<?php

declare(strict_types=1);

require_once __DIR__ . '/LogPrivacy.php';
require_once __DIR__ . '/Redact.php';
require_once __DIR__ . '/Uuid.php';

/**
 * Activity log on PostgreSQL (spec-activity-log-pg.md, final; §13 wins over §4-§11). Three routes by class
 * (activity_actions.retention_class, mirrored in CLASSES):
 * - admin    -> audit.audit_log on the main connection, synchronously (5 years, yearly partitions);
 * - security -> audit.activity_log on a SEPARATE connection (LOG_DB_USER, production cl_logw), statement_timeout
 *               500 ms, own transaction (90 days, daily partitions dropped by audit.activity_retention_step);
 * - other    -> buffered and flushed once at shutdown (after fastcgi_finish_request) as one multi-row INSERT on the
 *               main connection (14 days). This path never carries security codes (deferOther() refuses them).
 * The app only INSERTs (no SELECT); reads go through the DEFINER functions audit.activity_recent/activity_by_actor.
 * actor_ref is the full 32-byte HMAC (LogPrivacy::userRef) with actor_key_version; no IP and no user agent.
 * access_event_id is one server-made UUIDv7 per request (beginRequest()), never taken from X-Request-Id.
 */

final class ActivityLog
{
    public const LOGIN_OK = 1;
    public const LOGIN_FAIL = 2;
    public const LOGOUT = 3;
    public const REGISTER = 4;
    public const SEARCH = 5;
    public const ACCOUNT_UPDATE = 6;
    public const ACCOUNT_DELETE = 7;
    public const PROPERTY_CREATE = 8;
    public const PROPERTY_UPDATE = 9;
    public const PROPERTY_DELETE = 10;
    public const PHOTO_ADD = 11;
    public const PHOTO_DELETE = 12;
    public const CALENDAR_UPDATE = 13;
    public const CALENDAR_IMPORT = 14;
    public const CALENDAR_EXPORT = 15;
    public const REVIEW = 16;
    public const PROPERTY_VIEW = 17;
    public const EMAIL_VERIFY = 18;
    public const PASSWORD_RESET_ASK = 19;
    public const PASSWORD_RESET = 20;
    public const LOGIN_LOCKED = 21;
    public const LOCALE = 22;
    public const CALENDAR_PRICE = 23;
    public const CALENDAR_BLOCK = 24;
    public const CALENDAR_UNBLOCK = 25;
    public const CALENDAR_FEED_ADD = 26;
    public const CALENDAR_FEED_REMOVE = 27;
    public const CALENDAR_MIN_NIGHTS = 28;
    public const CALENDAR_SYNC = 29;
    public const INTERNAL_ERROR = 30;
    public const PHOTO_COVER = 31;
    public const THEME_CHANGE = 32;
    public const GROUP_CREATE = 33;
    public const GROUP_UPDATE = 34;
    public const GROUP_DOMAIN_ADD = 35;
    public const GROUP_DOMAIN_REMOVE = 36;
    public const GROUP_MEMBER_SET = 37;
    public const GROUP_MEMBER_REMOVE = 38;
    public const GROUP_INVITE = 39;
    public const GROUP_INVITE_CANCEL = 40;
    public const GROUP_INVITE_ACCEPT = 41;
    public const GROUP_INVITE_REJECT = 42;
    public const GROUP_PROPERTY_REMOVE = 43;
    public const GROUP_DOMAIN_VERIFY_START = 44;
    public const GROUP_DOMAIN_VERIFIED = 45;
    public const GROUP_DOMAIN_UNVERIFIED = 46;
    /** Written only by the DEFINER read functions (audit.activity_recent/activity_by_actor), never by PHP. */
    public const ACTIVITY_LOG_READ = 47;

    public const CATALOG = [
        self::LOGIN_OK => 'Login com sucesso',
        self::LOGIN_FAIL => 'Tentativa de login sem sucesso',
        self::LOGOUT => 'Saída da conta',
        self::REGISTER => 'Cadastro de proprietário',
        self::SEARCH => 'Busca de imóveis',
        self::ACCOUNT_UPDATE => 'Alteração de dados da conta',
        self::ACCOUNT_DELETE => 'Conta marcada como apagada',
        self::PROPERTY_CREATE => 'Inclusão de imóvel',
        self::PROPERTY_UPDATE => 'Alteração de imóvel',
        self::PROPERTY_DELETE => 'Inativação de imóvel',
        self::PHOTO_ADD => 'Inclusão de fotos',
        self::PHOTO_DELETE => 'Remoção de foto',
        self::CALENDAR_UPDATE => 'Alteração genérica de calendário',
        self::CALENDAR_IMPORT => 'Importação de calendário',
        self::CALENDAR_EXPORT => 'Exportação de calendário iCal',
        self::REVIEW => 'Avaliação de estadia',
        self::PROPERTY_VIEW => 'Visualização de imóvel',
        self::EMAIL_VERIFY => 'Ativação da conta pelo link',
        self::PASSWORD_RESET_ASK => 'Pedido de redefinição de senha',
        self::PASSWORD_RESET => 'Senha redefinida',
        self::LOGIN_LOCKED => 'Login bloqueado por tentativas incorretas',
        self::LOCALE => 'Troca de idioma da interface',
        self::CALENDAR_PRICE => 'Mudança de preço de diária',
        self::CALENDAR_BLOCK => 'Bloqueio de noites',
        self::CALENDAR_UNBLOCK => 'Desbloqueio de noites',
        self::CALENDAR_FEED_ADD => 'Inclusão de calendário para sincronismo',
        self::CALENDAR_FEED_REMOVE => 'Remoção de calendário para sincronismo',
        self::CALENDAR_MIN_NIGHTS => 'Mudança do mínimo de noites',
        self::CALENDAR_SYNC => 'Sincronização de calendário externo',
        self::INTERNAL_ERROR => 'Erro interno do sistema',
        self::PHOTO_COVER => 'Definição da foto de capa',
        self::THEME_CHANGE => 'Troca do tema da plataforma pelo admin',
        self::GROUP_CREATE => 'Criação de grupo pelo admin',
        self::GROUP_UPDATE => 'Alteração de nome ou tema do grupo pelo admin',
        self::GROUP_DOMAIN_ADD => 'Inclusão de domínio do grupo pelo admin',
        self::GROUP_DOMAIN_REMOVE => 'Remoção de domínio do grupo pelo admin',
        self::GROUP_MEMBER_SET => 'Inclusão ou troca de papel de membro do grupo pelo admin',
        self::GROUP_MEMBER_REMOVE => 'Remoção de membro do grupo pelo admin',
        self::GROUP_INVITE => 'Convite de imóvel para o grupo',
        self::GROUP_INVITE_CANCEL => 'Cancelamento de convite de imóvel para o grupo',
        self::GROUP_INVITE_ACCEPT => 'Aceite do dono: imóvel entra no grupo',
        self::GROUP_INVITE_REJECT => 'Recusa do dono ao convite do grupo',
        self::GROUP_PROPERTY_REMOVE => 'Imóvel sai do grupo',
        self::GROUP_DOMAIN_VERIFY_START => 'Novo token de verificação DNS do domínio do grupo pelo admin',
        self::GROUP_DOMAIN_VERIFIED => 'Domínio do grupo verificado por DNS TXT',
        self::GROUP_DOMAIN_UNVERIFIED => 'Domínio do grupo perdeu a verificação (reverificação DNS TXT)',
        self::ACTIVITY_LOG_READ => 'Leitura do log de atividades (ops, função DEFINER)',
    ];

    public const ADMIN = 'admin';
    public const SECURITY = 'security';
    public const OTHER = 'other';

    /**
     * Retention class of every CATALOG code (SVU §1.5; spec §13 Q1: LOGIN_OK/LOGOUT/LOGIN_FAIL are security until
     * PR-LOG-2 moves them to access_log). Seeded into activity_actions.retention_class; a test fails if a code is
     * missing. A code without a class would be admin (classOf()).
     */
    public const CLASSES = [
        self::LOGIN_OK => self::SECURITY, self::LOGIN_FAIL => self::SECURITY, self::LOGOUT => self::SECURITY,
        self::REGISTER => self::ADMIN, self::SEARCH => self::OTHER, self::ACCOUNT_UPDATE => self::ADMIN,
        self::ACCOUNT_DELETE => self::ADMIN, self::PROPERTY_CREATE => self::ADMIN, self::PROPERTY_UPDATE => self::ADMIN,
        self::PROPERTY_DELETE => self::ADMIN, self::PHOTO_ADD => self::ADMIN, self::PHOTO_DELETE => self::ADMIN,
        self::CALENDAR_UPDATE => self::ADMIN, self::CALENDAR_IMPORT => self::OTHER, self::CALENDAR_EXPORT => self::OTHER,
        self::REVIEW => self::ADMIN, self::PROPERTY_VIEW => self::OTHER, self::EMAIL_VERIFY => self::SECURITY,
        self::PASSWORD_RESET_ASK => self::SECURITY, self::PASSWORD_RESET => self::SECURITY,
        self::LOGIN_LOCKED => self::SECURITY, self::LOCALE => self::OTHER, self::CALENDAR_PRICE => self::ADMIN,
        self::CALENDAR_BLOCK => self::ADMIN, self::CALENDAR_UNBLOCK => self::ADMIN, self::CALENDAR_FEED_ADD => self::ADMIN,
        self::CALENDAR_FEED_REMOVE => self::ADMIN, self::CALENDAR_MIN_NIGHTS => self::ADMIN, self::CALENDAR_SYNC => self::OTHER,
        self::INTERNAL_ERROR => self::SECURITY, self::PHOTO_COVER => self::ADMIN, self::THEME_CHANGE => self::ADMIN,
        self::GROUP_CREATE => self::ADMIN, self::GROUP_UPDATE => self::ADMIN, self::GROUP_DOMAIN_ADD => self::ADMIN,
        self::GROUP_DOMAIN_REMOVE => self::ADMIN, self::GROUP_MEMBER_SET => self::ADMIN, self::GROUP_MEMBER_REMOVE => self::ADMIN,
        self::GROUP_INVITE => self::ADMIN, self::GROUP_INVITE_CANCEL => self::ADMIN, self::GROUP_INVITE_ACCEPT => self::ADMIN,
        self::GROUP_INVITE_REJECT => self::ADMIN, self::GROUP_PROPERTY_REMOVE => self::ADMIN,
        self::GROUP_DOMAIN_VERIFY_START => self::ADMIN, self::GROUP_DOMAIN_VERIFIED => self::SECURITY,
        self::GROUP_DOMAIN_UNVERIFIED => self::SECURITY, self::ACTIVITY_LOG_READ => self::ADMIN,
    ];

    /** payload + before_value + after_value, as PG prints jsonb (CHECK on both tables). */
    public const MAX_ROW_BYTES = 4096;

    private static ?string $accessEventId = null;
    /** @var list<array<string, mixed>> other rows waiting for the shutdown INSERT */
    private static array $otherBuffer = [];
    private static bool $flushRegistered = false;
    private static ?PDO $securityPdo = null;
    private static string $securityDsn = '';
    /** null: defer "other" rows to shutdown except in the CLI (cron/workers write at once). Tests may force it. */
    public static ?bool $deferOther = null;

    public static function classOf(int $code): string
    {
        return self::CLASSES[$code] ?? self::ADMIN;
    }

    /** Request start (public/index.php): one server-made UUIDv7, used only as access_event_id (spec §13 Q2). */
    public static function beginRequest(): string
    {
        return self::$accessEventId = Uuid::v7();
    }

    /** access_event_id of this request/process (created on first use when beginRequest() did not run, e.g. CLI). */
    public static function accessEventId(): string
    {
        return self::$accessEventId ??= Uuid::v7();
    }

    /** Seed of activity_actions (Db::migrate): code, Portuguese label and retention class. Idempotent. */
    public static function seedActions(PDO $pdo): void
    {
        $st = $pdo->prepare('INSERT INTO activity_actions (code, description, retention_class) VALUES (?, ?, ?)
            ON CONFLICT (code) DO UPDATE SET description = excluded.description, retention_class = excluded.retention_class
            WHERE activity_actions.description IS DISTINCT FROM excluded.description
               OR activity_actions.retention_class IS DISTINCT FROM excluded.retention_class');
        foreach (self::CATALOG as $code => $description) {
            $st->execute([$code, $description, self::classOf($code)]);
        }
    }

    public static function record(int $code, array $params = [], mixed $valorAnterior = null, mixed $valorAtual = null): void
    {
        // HEAD nunca grava auditoria (index.php trata HEAD da lista como GET; o método original fica em REQUEST_METHOD).
        if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '')) === 'HEAD') {
            return;
        }
        $class = self::classOf($code);
        try {
            $row = self::buildRow($code, $params, $valorAnterior, $valorAtual);
            if ($class === self::OTHER) {
                self::deferOther($row);
            } elseif ($class === self::SECURITY) {
                self::insertActivity(self::securityPdo(), [$row]);
            } else {
                self::insertAudit(db(), $row);
            }
        } catch (Throwable $e) {
            // Never silent, never with data (the message may carry parameters).
            error_log('CasaLivre ALERT: activity log write failed (class ' . $class . ', code ' . $code . ', ' . $e::class . ').');
        }
    }

    /**
     * Row for audit.activity_log / audit.audit_log (allowlisted payload, before/after as JSON text, no IP/UA).
     * @return array<string, mixed>
     */
    public static function buildRow(int $code, array $params = [], mixed $valorAnterior = null, mixed $valorAtual = null): array
    {
        $params = self::safeParams($params, $code);
        if (in_array($code, self::FIELD_NAMES_ONLY, true)) {
            $fields = self::changedFieldNames($valorAnterior, $valorAtual);
            $valorAnterior = null;
            $valorAtual = $fields === [] ? null : ['campos_alterados' => $fields];
        }
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
        $params['canal'] = str_starts_with(parse_url($uri, PHP_URL_PATH) ?: '', '/api') ? 'api' : 'web';
        $ref = isset($_SESSION['user_id']) ? LogPrivacy::userRef((int) $_SESSION['user_id']) : null;
        $kind = $ref !== null ? 'user' : (PHP_SAPI === 'cli' ? 'system' : 'anon');
        $payload = self::json($params === [] ? new stdClass() : $params);
        $before = self::jsonValue($valorAnterior);
        $after = self::jsonValue($valorAtual);
        if (self::pgJsonBytes($payload) + self::pgJsonBytes($before) + self::pgJsonBytes($after) > self::MAX_ROW_BYTES) {
            // The CHECK must never abort a transaction (spec §8.6).
            [$payload, $before, $after] = ['{"truncated": true}', null, null];
        }
        $now = DateTimeImmutable::createFromFormat('U.u', sprintf('%.6F', microtime(true)));
        return [
            'id' => Uuid::v7(),
            'created_at' => ($now ?: new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.uP'),
            'code' => $code,
            'retention_class' => self::classOf($code),
            'actor_kind' => $kind,
            'actor_ref' => $ref,
            'actor_key_version' => $ref === null ? null : LogPrivacy::keyVersion(),
            'access_event_id' => self::accessEventId(),
            'payload' => $payload,
            'before_value' => $before,
            'after_value' => $after,
        ];
    }

    /**
     * Queues an "other" row for the shutdown INSERT (or writes it at once in the CLI). Refuses any other class:
     * the shutdown path must never carry security (or admin) codes (spec §13 Q3).
     */
    public static function deferOther(array $row): void
    {
        if (($row['retention_class'] ?? null) !== self::OTHER) {
            throw new LogicException('only "other" rows may use the shutdown INSERT');
        }
        if (!(self::$deferOther ?? PHP_SAPI !== 'cli')) {
            self::insertActivity(db(), [$row]);
            return;
        }
        self::$otherBuffer[] = $row;
        if (!self::$flushRegistered) {
            self::$flushRegistered = true;
            register_shutdown_function(static function (): void {
                if (function_exists('fastcgi_finish_request')) {
                    fastcgi_finish_request();
                }
                self::flushOther();
            });
        }
    }

    /** @return list<array<string, mixed>> rows waiting for the shutdown INSERT (tests). */
    public static function pendingOther(): array
    {
        return self::$otherBuffer;
    }

    /** Shutdown: one multi-row INSERT of the buffered "other" rows on the main connection. */
    public static function flushOther(): void
    {
        $rows = array_values(array_filter(self::$otherBuffer, static fn (array $r): bool => ($r['retention_class'] ?? null) === self::OTHER));
        self::$otherBuffer = [];
        if ($rows === []) {
            return;
        }
        try {
            $pdo = db();
            if ($pdo->inTransaction()) {
                $pdo->rollBack(); // an unfinished business transaction is lost anyway; the BI rows are not
            }
            self::insertActivity($pdo, $rows);
        } catch (Throwable $e) {
            error_log('CasaLivre: ' . count($rows) . ' "other" activity row(s) lost at shutdown (' . $e::class . ').');
        }
    }

    /** INSERT of security/other rows into audit.activity_log (one statement, one or many rows). */
    public static function insertActivity(PDO $pdo, array $rows): void
    {
        $values = [];
        foreach ($rows as $row) {
            if (!in_array($row['retention_class'] ?? null, [self::SECURITY, self::OTHER], true)) {
                throw new LogicException('audit.activity_log takes only security/other rows');
            }
            $values[] = '(?::uuid, ?::timestamptz, ?, ?, ?, ?, ?, ?::uuid, ?::jsonb, ?::jsonb, ?::jsonb)';
        }
        $st = $pdo->prepare('INSERT INTO audit.activity_log (id, created_at, code, retention_class, actor_kind, actor_ref,
            actor_key_version, access_event_id, payload, before_value, after_value) VALUES ' . implode(', ', $values));
        $i = 0;
        foreach ($rows as $row) {
            self::bindCommon($st, $i, $row);
            $st->bindValue(++$i, $row['access_event_id']);
            $st->bindValue(++$i, $row['payload']);
            $st->bindValue(++$i, $row['before_value'], $row['before_value'] === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
            $st->bindValue(++$i, $row['after_value'], $row['after_value'] === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        }
        $st->execute();
    }

    /** INSERT of one admin row into audit.audit_log. */
    public static function insertAudit(PDO $pdo, array $row): void
    {
        $st = $pdo->prepare('INSERT INTO audit.audit_log (id, created_at, code, retention_class, actor_kind, actor_ref,
            actor_key_version, payload, before_value, after_value) VALUES (?::uuid, ?::timestamptz, ?, ?, ?, ?, ?, ?::jsonb, ?::jsonb, ?::jsonb)');
        $i = 0;
        self::bindCommon($st, $i, $row);
        $st->bindValue(++$i, $row['payload']);
        $st->bindValue(++$i, $row['before_value'], $row['before_value'] === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $st->bindValue(++$i, $row['after_value'], $row['after_value'] === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $st->execute();
    }

    private static function bindCommon(PDOStatement $st, int &$i, array $row): void
    {
        $st->bindValue(++$i, $row['id']);
        $st->bindValue(++$i, $row['created_at']);
        $st->bindValue(++$i, (int) $row['code'], PDO::PARAM_INT);
        $st->bindValue(++$i, $row['retention_class']);
        $st->bindValue(++$i, $row['actor_kind']);
        $st->bindValue(++$i, $row['actor_ref'], $row['actor_ref'] === null ? PDO::PARAM_NULL : PDO::PARAM_LOB);
        $st->bindValue(++$i, $row['actor_key_version'], $row['actor_key_version'] === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
    }

    /**
     * Separate connection for security rows (own transaction: a business rollback never removes them). Same DB_DSN,
     * as LOG_DB_USER/LOG_DB_PASSWORD (production: cl_logw, INSERT only); empty LOG_DB_USER = the DB_USER credentials.
     */
    private static function securityPdo(): PDO
    {
        $dsn = Db::dsn();
        if (self::$securityPdo instanceof PDO && self::$securityDsn === $dsn) {
            return self::$securityPdo;
        }
        $pdo = Db::connectAs('LOG_DB_USER', 'LOG_DB_PASSWORD');
        $pdo->exec("SET statement_timeout = '500ms'");
        self::$securityDsn = $dsn;
        return self::$securityPdo = $pdo;
    }

    private static function json(mixed $value): string
    {
        return (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    /** Upper bound of octet_length(jsonb::text): PG prints a space after every ':' and ','. */
    private static function pgJsonBytes(?string $json): int
    {
        return $json === null ? 0 : strlen($json) + substr_count($json, ':') + substr_count($json, ',');
    }

    /** before/after value as JSON text (allowlisted map, or a scalar of at most 200 chars); null when empty. */
    public static function jsonValue(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_array($value)) {
            $safe = self::cleanList($value, 0);
            return $safe === [] ? null : self::json($safe);
        }
        if (is_scalar($value)) {
            return self::json(is_string($value) ? mb_substr($value, 0, 200) : $value);
        }
        return null;
    }

    /** Chaves de identidade: nunca gravadas em claro (HMAC com LOG_SALT ou IP truncado). */
    public const IDENTITY_KEYS = ['email', 'ip', 'cpf', 'phone', 'telefone', 'user_id', 'autor'];

    /** Parâmetros aceitos em qualquer ação. */
    public const COMMON_PARAMS = ['imovel_id', 'canal', 'resultado', 'motivo'];

    /**
     * Parâmetros permitidos por ação (allowlist). Chaves fora da lista são OMITIDAS.
     * Chaves de identidade (IDENTITY_KEYS) só entram se listadas aqui e sempre pseudonimizadas.
     */
    public const ALLOWED_PARAMS = [
        self::LOGIN_OK => ['email'],
        self::LOGIN_FAIL => ['email'],
        self::LOGIN_LOCKED => ['email', 'tentativas'],
        self::LOGOUT => [],
        self::REGISTER => ['email'],
        self::SEARCH => ['localidade', 'place_id', 'checkin', 'checkout', 'hospedes', 'raio', 'preco_min', 'preco_max', 'score_min', 'amenidades', 'resultados'],
        self::ACCOUNT_UPDATE => ['email'],
        self::ACCOUNT_DELETE => ['email', 'ip'],
        self::PROPERTY_CREATE => ['titulo'],
        self::PROPERTY_UPDATE => ['titulo', 'cidade'],
        self::PROPERTY_DELETE => ['titulo', 'estado', 'inativado_em'],
        self::PHOTO_ADD => ['enviadas', 'salvas'],
        self::PHOTO_DELETE => ['foto_id', 'quantidade'],
        self::PHOTO_COVER => ['foto_id'],
        self::THEME_CHANGE => ['tema', 'tema_anterior'],
        self::GROUP_CREATE => ['grupo', 'tema'],
        self::GROUP_UPDATE => ['grupo'],
        self::GROUP_DOMAIN_ADD => ['grupo', 'dominio'],
        self::GROUP_DOMAIN_REMOVE => ['grupo', 'dominio'],
        self::GROUP_MEMBER_SET => ['grupo', 'user_id', 'papel'],
        self::GROUP_MEMBER_REMOVE => ['grupo', 'user_id'],
        self::GROUP_INVITE => ['grupo'],
        self::GROUP_INVITE_CANCEL => ['grupo'],
        self::GROUP_INVITE_ACCEPT => ['grupo'],
        self::GROUP_INVITE_REJECT => ['grupo'],
        self::GROUP_PROPERTY_REMOVE => ['grupo', 'por'],
        self::GROUP_DOMAIN_VERIFY_START => ['grupo', 'dominio'],
        self::GROUP_DOMAIN_VERIFIED => ['grupo', 'dominio'],
        self::GROUP_DOMAIN_UNVERIFIED => ['grupo', 'dominio', 'falhas', 'motivo'],
        self::CALENDAR_UPDATE => ['inicio', 'fim'],
        self::CALENDAR_IMPORT => ['inicio', 'fim', 'novas'],
        self::CALENDAR_EXPORT => [],
        self::REVIEW => ['autor', 'nota'],
        self::PROPERTY_VIEW => ['checkin', 'checkout', 'cidade'],
        self::EMAIL_VERIFY => ['email'],
        self::PASSWORD_RESET_ASK => ['email'],
        self::PASSWORD_RESET => ['email'],
        self::LOCALE => ['idioma'],
        self::CALENDAR_PRICE => ['inicio', 'fim', 'diaria'],
        self::CALENDAR_BLOCK => ['inicio', 'fim'],
        self::CALENDAR_UNBLOCK => ['inicio', 'fim'],
        self::CALENDAR_FEED_ADD => ['titulo', 'url'],
        self::CALENDAR_FEED_REMOVE => ['feed_id'],
        self::CALENDAR_MIN_NIGHTS => ['inicio', 'fim', 'minimo_noites'],
        self::CALENDAR_SYNC => ['url', 'novas'],
        self::INTERNAL_ERROR => ['arquivo', 'linha', 'mensagem', 'metodo', 'quando', 'rastreio', 'referencia', 'rota', 'tipo'],
    ];

    /**
     * Chaves permitidas em valor_anterior/valor_atual (imóvel, calendário, fotos, feeds).
     * Fora da lista: omitidas (ex.: description, address, cep, street, lat, lng, hashes, audit_hold).
     */
    public const VALUE_KEYS = [
        'id', 'imovel_id', 'property_id', 'foto_id', 'feed_id', 'day', 'dia', 'blocked', 'bloqueado', 'price', 'preco',
        'diaria', 'min_nights', 'minimo_noites', 'origin', 'origem', 'estado', 'status', 'inactivated_at', 'inativado_em',
        'title', 'titulo', 'city', 'cidade', 'bedrooms', 'beds', 'rooms', 'quartos', 'camas', 'quartos_camas', 'max_guests',
        'nightly_price', 'cleaning_fee', 'taxa_limpeza', 'checkin_time', 'checkout_time', 'calendar_months', 'pool', 'bbq',
        'garage', 'amenities', 'comodidades', 'comodidades_incluidas', 'comodidades_removidas', 'path', 'caminho',
        'is_primary', 'sort_order', 'capa', 'fotos', 'feed', 'link', 'url', 'external_ical_url', 'last_status',
        'last_synced_at', 'senha', 'quantidade', 'created_at', 'old', 'new', 'campos_alterados', 'tema',
        'grupo', 'nome', 'papel', 'dominio',
    ];

    /** Ações de conta: só os NOMES dos campos alterados são gravados (nunca os valores). */
    public const FIELD_NAMES_ONLY = [self::ACCOUNT_UPDATE, self::ACCOUNT_DELETE];

    /** Parâmetros da ação, por allowlist. */
    public static function safeParams(array $params, ?int $code = null): array
    {
        $allowed = array_merge(self::COMMON_PARAMS, $code === null ? [] : (self::ALLOWED_PARAMS[$code] ?? []));
        $clean = [];
        foreach ($params as $key => $value) {
            $key = (string) $key;
            if ($code !== null && !in_array($key, $allowed, true)) {
                continue;
            }
            if ($code === null && !in_array($key, $allowed, true) && !in_array($key, self::IDENTITY_KEYS, true)) {
                // Sem ação informada: só comuns + identidade; o resto é omitido.
                continue;
            }
            $clean[$key] = self::cleanValue($key, $value, 0);
        }
        if ($code === self::SEARCH && array_key_exists('localidade', $clean)) {
            // Demand-log redaction (spec §13 Q8; docs/lgpd/lia-demand-log.md item 1).
            $term = is_string($clean['localidade']) ? Redact::searchTerm($clean['localidade']) : null;
            if ($term === null) {
                unset($clean['localidade']);
            } else {
                $clean['localidade'] = $term;
            }
        }
        return array_filter($clean, static fn ($v) => $v !== self::DROP);
    }

    private const DROP = "\0drop";

    private static function cleanValue(string $key, mixed $value, int $depth): mixed
    {
        $lk = strtolower($key);
        if (in_array($lk, self::IDENTITY_KEYS, true)) {
            if (!is_scalar($value)) {
                return self::DROP;
            }
            return match ($lk) {
                'email' => LogPrivacy::email((string) $value),
                'ip' => LogPrivacy::ip((string) $value),
                'user_id' => LogPrivacy::user((int) $value),
                default => LogPrivacy::hmac((string) $value, $lk),
            };
        }
        if (in_array($lk, ['url', 'external_ical_url'], true) && is_string($value) && preg_match('#^[a-z][a-z0-9+.-]*://#i', $value) === 1) {
            return LogPrivacy::url($value);
        }
        if (is_array($value)) {
            return $depth >= 4 ? self::DROP : self::cleanList($value, $depth + 1);
        }
        return is_scalar($value) || $value === null ? $value : self::DROP;
    }

    /** Lista numérica: recursa nos itens; mapa: só VALUE_KEYS (+ identidade pseudonimizada). */
    private static function cleanList(array $value, int $depth): array
    {
        $out = [];
        foreach ($value as $k => $v) {
            if (!is_int($k) && !in_array((string) $k, self::VALUE_KEYS, true) && !in_array(strtolower((string) $k), self::IDENTITY_KEYS, true)) {
                continue;
            }
            $c = is_int($k) && is_array($v) ? self::cleanList($v, $depth + 1) : self::cleanValue((string) $k, $v, $depth);
            if ($c !== self::DROP) {
                $out[$k] = $c;
            }
        }
        return $out;
    }

    /** Nomes dos campos que mudaram entre $old e $new (valores nunca são gravados). */
    public static function changedFieldNames(mixed $old, mixed $new): array
    {
        $old = is_array($old) ? $old : [];
        $new = is_array($new) ? $new : [];
        $names = [];
        foreach (array_unique(array_merge(array_keys($old), array_keys($new))) as $k) {
            if (!is_string($k) || preg_match('/^[a-z0-9_]{1,40}$/i', $k) !== 1) {
                continue;
            }
            if (($old[$k] ?? null) !== ($new[$k] ?? null)) {
                $names[] = $k === 'password_hash' ? 'senha' : $k;
            }
        }
        sort($names);
        return array_values(array_unique($names));
    }

    /** Old name kept for callers/tests: JSON text of the allowlisted value (see jsonValue()). */
    public static function encodePrevious(mixed $value): ?string
    {
        return self::jsonValue($value);
    }
}

function activity(int $code, array $params = [], mixed $valorAnterior = null, mixed $valorAtual = null): void
{
    ActivityLog::record($code, $params, $valorAnterior, $valorAtual);
}
