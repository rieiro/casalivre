<?php

declare(strict_types=1);

require_once __DIR__ . '/LogPrivacy.php';

interface ActivityStore
{
    public function append(int $code, string $action, ?string $actorId, array $params, string $ip, string $userAgent, ?string $valorAnterior = null, ?string $valorAtual = null): void;

    public function recent(int $limit = 50, ?int $code = null): array;
}

final class SqliteActivityStore implements ActivityStore
{
    private PDO $pdo;

    public function __construct(?string $path = null)
    {
        $path ??= dirname(__DIR__) . '/storage/logs/activity.sqlite';
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Não foi possível criar storage/logs.');
        }
        $this->pdo = new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $this->pdo->exec('PRAGMA busy_timeout = 8000');
        $this->pdo->exec('PRAGMA journal_mode = WAL');
        $this->pdo->exec('PRAGMA synchronous = NORMAL');
        $sql = file_get_contents(dirname(__DIR__) . '/database/activity.sql') ?: '';
        foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) {
            $this->pdo->exec($statement);
        }
        $columns = [];
        foreach ($this->pdo->query('PRAGMA table_info(activity_log)') as $col) {
            $columns[$col['name']] = true;
        }
        if (!isset($columns['valor_anterior'])) {
            $this->pdo->exec('ALTER TABLE activity_log ADD COLUMN valor_anterior TEXT');
        }
        if (!isset($columns['valor_atual'])) {
            $this->pdo->exec('ALTER TABLE activity_log ADD COLUMN valor_atual TEXT');
        }
    }

    public function append(int $code, string $action, ?string $actorId, array $params, string $ip, string $userAgent, ?string $valorAnterior = null, ?string $valorAtual = null): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO activity_log (created_at, action_code, action, actor_id, ip, user_agent, params, valor_anterior, valor_atual)
             VALUES (:created_at, :code, :action, :actor_id, :ip, :user_agent, :params, :valor_anterior, :valor_atual)'
        );
        $stmt->execute([
            'created_at' => date('Y-m-d H:i:s'),
            'code' => $code,
            'action' => $action,
            'actor_id' => $actorId,
            'ip' => $ip,
            'user_agent' => $userAgent,
            'params' => json_encode($params, JSON_UNESCAPED_UNICODE) ?: '{}',
            'valor_anterior' => $valorAnterior,
            'valor_atual' => $valorAtual,
        ]);
    }

    public function recent(int $limit = 50, ?int $code = null): array
    {
        $limit = max(1, min(500, $limit));
        if ($code === null) {
            $stmt = $this->pdo->query('SELECT * FROM activity_log ORDER BY id DESC LIMIT ' . $limit);
        } else {
            $stmt = $this->pdo->prepare('SELECT * FROM activity_log WHERE action_code = :code ORDER BY id DESC LIMIT ' . $limit);
            $stmt->execute(['code' => $code]);
        }
        $rows = $stmt->fetchAll();
        foreach ($rows as &$row) {
            $decoded = json_decode((string) $row['params'], true);
            $row['params'] = is_array($decoded) ? $decoded : [];
        }
        unset($row);
        return $rows;
    }
}

final class MongoActivityStore implements ActivityStore
{
    private string $uri;
    private string $db;

    public function __construct()
    {
        if (!class_exists(\MongoDB\Driver\Manager::class)) {
            throw new RuntimeException('Extensão mongodb ausente. No Void: sudo xbps-install -S php-mongodb e habilite extension=mongodb.');
        }
        $this->uri = getenv('LOG_MONGO_URI') ?: 'mongodb://127.0.0.1:27017';
        $this->db = getenv('LOG_MONGO_DB') ?: 'casalivre';
    }

    public function append(int $code, string $action, ?string $actorId, array $params, string $ip, string $userAgent, ?string $valorAnterior = null, ?string $valorAtual = null): void
    {
        $bulk = new \MongoDB\Driver\BulkWrite();
        $bulk->insert([
            'created_at' => date('Y-m-d H:i:s'),
            'action_code' => $code,
            'action' => $action,
            'actor_id' => $actorId,
            'ip' => $ip,
            'user_agent' => $userAgent,
            'params' => $params,
            'valor_anterior' => $valorAnterior,
            'valor_atual' => $valorAtual,
        ]);
        $this->manager()->executeBulkWrite($this->db . '.activity_log', $bulk);
    }

    public function recent(int $limit = 50, ?int $code = null): array
    {
        $query = $code === null ? [] : ['action_code' => $code];
        $cursor = $this->manager()->executeQuery(
            $this->db . '.activity_log',
            new \MongoDB\Driver\Query($query, [
                'sort' => ['created_at' => -1],
                'limit' => max(1, min(500, $limit)),
            ])
        );
        $rows = [];
        foreach ($cursor as $doc) {
            $row = (array) $doc;
            $row['params'] = isset($row['params']) ? (array) $row['params'] : [];
            unset($row['_id']);
            $rows[] = $row;
        }
        return $rows;
    }

    private function manager(): \MongoDB\Driver\Manager
    {
        static $manager = null;
        if (!$manager instanceof \MongoDB\Driver\Manager) {
            $manager = new \MongoDB\Driver\Manager($this->uri);
        }
        return $manager;
    }
}

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
    // 47 is taken by ACTIVITY_LOG_READ in #148 (PG-only ActivityLog).
    /** IpRateLimiter could not reach Valkey (Segurança #160): own code, not INTERNAL_ERROR. */
    public const VALKEY_UNAVAILABLE = 48;

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
        self::VALKEY_UNAVAILABLE => 'Limitador de requisições sem Valkey (falha fechada ou aberta)',
    ];

    public static function store(): ActivityStore
    {
        static $store = null;
        if ($store instanceof ActivityStore) {
            return $store;
        }
        Db::loadEnv();
        $driver = getenv('LOG_DRIVER') ?: 'sqlite';
        // ACTIVITY_DB_PATH: caminho alternativo do SQLite de atividades (testes); padrão storage/logs/activity.sqlite.
        $path = getenv('ACTIVITY_DB_PATH') ?: null;
        $store = $driver === 'mongodb' ? new MongoActivityStore() : new SqliteActivityStore($path);
        return $store;
    }

    public static function record(int $code, array $params = [], mixed $valorAnterior = null, mixed $valorAtual = null): void
    {
        // HEAD nunca grava auditoria (index.php trata HEAD da lista como GET; o método original fica em REQUEST_METHOD).
        if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '')) === 'HEAD') {
            return;
        }
        try {
            $params = self::safeParams($params, $code);
            if (in_array($code, self::FIELD_NAMES_ONLY, true)) {
                $fields = self::changedFieldNames($valorAnterior, $valorAtual);
                $valorAnterior = null;
                $valorAtual = $fields === [] ? null : ['campos_alterados' => $fields];
            }
            $uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
            $params['canal'] = str_starts_with(parse_url($uri, PHP_URL_PATH) ?: '', '/api') ? 'api' : 'web';
            self::store()->append(
                $code,
                self::CATALOG[$code] ?? 'Ação não catalogada',
                isset($_SESSION['user_id']) ? LogPrivacy::user((int) $_SESSION['user_id']) : null,
                $params,
                LogPrivacy::ip((string) ($_SERVER['REMOTE_ADDR'] ?? '')),
                (string) (LogPrivacy::userAgent((string) ($_SERVER['HTTP_USER_AGENT'] ?? '')) ?? ''),
                self::encodePrevious($valorAnterior),
                self::encodePrevious($valorAtual)
            );
        } catch (Throwable $e) {
            // Sinaliza sem dados (a mensagem pode conter parâmetros).
            error_log('CasaLivre: falha ao gravar log de atividades (' . $e::class . ').');
        }
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
        self::SEARCH => ['localidade', 'checkin', 'checkout', 'hospedes', 'raio', 'preco_min', 'preco_max', 'score_min', 'amenidades', 'resultados'],
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
        self::VALKEY_UNAVAILABLE => ['rota', 'modo', 'excecao'],
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

    public static function encodePrevious(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_array($value)) {
            $safe = self::cleanList($value, 0);
            $json = json_encode($safe, JSON_UNESCAPED_UNICODE);
            return $json === false || $json === '[]' || $json === '{}' ? null : $json;
        }
        if (is_scalar($value)) {
            return is_string($value) ? mb_substr($value, 0, 200) : (string) $value;
        }
        return null;
    }
}

function activity(int $code, array $params = [], mixed $valorAnterior = null, mixed $valorAtual = null): void
{
    ActivityLog::record($code, $params, $valorAnterior, $valorAtual);
}
