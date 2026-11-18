<?php

declare(strict_types=1);

require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/ValkeyJobQueue.php';

/**
 * Async bot telemetry middleware with O(1) in-memory ring buffer (Architecture §5).
 * Humans never enter this table or ring buffer.
 */
final class BotTelemetry
{
    public const VALKEY_KEY = Valkey::PREFIX . 'bot_action_logs';
    private const RING_SIZE = 256;

    /** @var array<int, array<string, mixed>> */
    private static array $ring = [];
    private static int $head = 0;
    private static bool $registered = false;
    private static ?string $cachedBotName = null;
    private static ?string $cachedChannel = null;
    private static ?string $cachedAction = null;
    private static ?int $cachedPropertyId = null;
    private static ?array $cachedSearchParams = null;
    private static float $startTime = 0.0;

    public static function start(): void
    {
        self::$startTime = microtime(true);
        if (!self::$registered) {
            self::$registered = true;
            register_shutdown_function([self::class, 'shutdown']);
        }
    }

    public static function classifyRequest(string $path, string $method): ?string
    {
        $ua = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
        $bots = [
            'OAI-SearchBot' => 'OAI-SearchBot',
            'ClaudeBot' => 'ClaudeBot',
            'Google-Extended' => 'Google-Extended',
            'PerplexityBot' => 'PerplexityBot',
            'CasaLivre-MCP-Sim' => 'CasaLivre-MCP-Sim',
        ];

        foreach ($bots as $needle => $name) {
            if (stripos($ua, $needle) !== false) {
                return $name;
            }
        }

        if (str_starts_with($path, '/api/v1/agent') || $path === '/mcp') {
            return 'agent';
        }

        return null;
    }

    public static function setContext(string $channel, string $action, ?int $propertyId = null, ?array $searchParams = null): void
    {
        self::$cachedChannel = $channel;
        self::$cachedAction = $action;
        self::$cachedPropertyId = $propertyId;
        self::$cachedSearchParams = $searchParams;
    }

    public static function record(
        string $botName,
        string $channel,
        string $actionName,
        int $statusCode,
        int $latencyMs,
        ?int $propertyId = null,
        ?array $searchParams = null
    ): void {
        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        $ipHash = self::hashIp($ip);

        // Sanitize search params to allow-list
        $cleanParams = null;
        if ($searchParams !== null) {
            $allowed = ['localidade', 'checkin', 'checkout', 'hospedes', 'preco_min', 'preco_max', 'raio', 'sort', 'amenidades'];
            $cleanParams = [];
            foreach ($allowed as $k) {
                if (isset($searchParams[$k])) {
                    if ($k === 'localidade') {
                        $cleanParams[$k] = mb_substr((string) $searchParams[$k], 0, 80);
                    } elseif ($k === 'amenidades' && is_array($searchParams[$k])) {
                        $cleanParams[$k] = array_slice($searchParams[$k], 0, 20);
                    } else {
                        $cleanParams[$k] = $searchParams[$k];
                    }
                }
            }
            if (empty($cleanParams)) {
                $cleanParams = null;
            }
        }

        $event = [
            'actor_type' => 'bot',
            'bot_name' => substr($botName, 0, 64),
            'channel' => in_array($channel, ['html', 'markdown', 'rest', 'mcp'], true) ? $channel : 'rest',
            'action_name' => substr($actionName, 0, 64),
            'property_id' => $propertyId,
            'search_params' => $cleanParams ? json_encode($cleanParams, JSON_UNESCAPED_UNICODE) : null,
            'status_code' => $statusCode,
            'latency_ms' => max(0, min(600000, $latencyMs)),
            'ip_hash' => $ipHash, // hex string for json serialization
        ];

        // O(1) ring buffer slot assignment
        self::$ring[self::$head] = $event;
        self::$head = (self::$head + 1) % self::RING_SIZE;
    }

    public static function shutdown(): void
    {
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $botName = self::classifyRequest($path, $method);

        if ($botName !== null) {
            $status = http_response_code() ?: 200;
            $latency = self::$startTime > 0 ? (int) round((microtime(true) - self::$startTime) * 1000) : 0;

            $channel = self::$cachedChannel;
            if ($channel === null) {
                if ($path === '/mcp') {
                    $channel = 'mcp';
                } elseif (str_starts_with($path, '/api/v1/agent')) {
                    $channel = 'rest';
                } else {
                    $channel = 'html';
                }
            }

            $action = self::$cachedAction;
            if ($action === null) {
                if (in_array($path, ['/robots.txt', '/llms.txt', '/llms-full.txt', '/openapi.json'], true)) {
                    $action = 'discover';
                } elseif (str_starts_with($path, '/imoveis/')) {
                    $action = 'get_property_details';
                } else {
                    $action = 'discover';
                }
            }

            self::record(
                $botName,
                $channel,
                $action,
                $status,
                $latency,
                self::$cachedPropertyId,
                self::$cachedSearchParams
            );
        }

        if (empty(self::$ring)) {
            return;
        }

        try {
            $redis = Valkey::connect(0.1);
            $items = [];
            foreach (self::$ring as $ev) {
                $items[] = json_encode($ev, JSON_UNESCAPED_UNICODE);
            }
            self::$ring = [];
            self::$head = 0;
            if (!empty($items)) {
                $redis->rPush(self::VALKEY_KEY, ...$items);
            }
        } catch (Throwable) {
            // Fail open on telemetry flush; do not block response or error out
        }
    }

    private static function hashIp(string $ip): ?string
    {
        if ($ip === '') {
            return null;
        }
        $date = gmdate('Ymd');
        $dir = dirname(__DIR__) . '/storage/bot-ip-salt';
        $file = $dir . '/' . $date;

        if (!is_file($file)) {
            @mkdir($dir, 0700, true);
            try {
                $salt = random_bytes(32);
                @file_put_contents($file, $salt);
                @chmod($file, 0600);
            } catch (Throwable) {
                return null;
            }
        } else {
            $salt = @file_get_contents($file);
            if ($salt === false || strlen($salt) !== 32) {
                return null;
            }
        }

        return hash_hmac('sha256', $ip, $salt); // 64 hex chars = 32 bytes
    }
}
