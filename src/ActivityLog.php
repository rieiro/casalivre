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
    ];

    public static function store(): ActivityStore
    {
        static $store = null;
        if ($store instanceof ActivityStore) {
            return $store;
        }
        Db::loadEnv();
        $driver = getenv('LOG_DRIVER') ?: 'sqlite';
        $store = $driver === 'mongodb' ? new MongoActivityStore() : new SqliteActivityStore();
        return $store;
    }

    public static function record(int $code, array $params = [], mixed $valorAnterior = null, mixed $valorAtual = null): void
    {
        try {
            $params = self::safeParams($params);
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

    public static function safeParams(array $params): array
    {
        $blocked = ['password', 'password_hash', '_csrf', 'csrf'];
        $clean = [];
        foreach ($params as $key => $value) {
            if (in_array((string) $key, $blocked, true)) {
                continue;
            }
            if (is_array($value)) {
                $clean[$key] = self::safeParams($value);
                continue;
            }
            $lk = strtolower((string) $key);
            if (is_string($value) && str_contains($lk, 'email')) {
                $clean[$key] = LogPrivacy::email($value);
                continue;
            }
            if (is_string($value) && ($lk === 'ip' || str_ends_with($lk, '_ip'))) {
                $clean[$key] = LogPrivacy::ip($value);
                continue;
            }
            if (is_string($value) && (str_contains($lk, 'cpf') || str_contains($lk, 'phone') || str_contains($lk, 'telefone'))) {
                $clean[$key] = LogPrivacy::hmac($value, $lk);
                continue;
            }
            if (is_scalar($value) || $value === null) {
                $clean[$key] = $value;
            }
        }
        return $clean;
    }

    public static function encodePrevious(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_array($value)) {
            $safe = self::safeParams($value);
            $json = json_encode($safe, JSON_UNESCAPED_UNICODE);
            return $json === false || $json === '[]' || $json === '{}' ? null : $json;
        }
        if (is_scalar($value)) {
            return (string) $value;
        }
        return null;
    }
}

function activity(int $code, array $params = [], mixed $valorAnterior = null, mixed $valorAtual = null): void
{
    ActivityLog::record($code, $params, $valorAnterior, $valorAtual);
}
