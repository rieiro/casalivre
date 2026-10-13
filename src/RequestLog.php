<?php

declare(strict_types=1);

/**
 * Log estruturado por requisição da API (JSON por linha), gravado localmente.
 *
 * - request_id: reaproveita X-Request-Id válido do cliente ou gera um novo; devolvido no header.
 * - Chaves sensíveis são omitidas; texto livre tem CPF/cartão/e-mail mascarados (no PHP, antes de gravar).
 * - Caminhos com hash de 32 hex (ical_hash) viram {hash}; URLs perdem query string.
 * - user_id é gravado como HMAC-SHA256 com sal (LOG_SALT ou APP_KEY); sem sal, é omitido.
 * - IP truncado (IPv4 /24, IPv6 /48).
 * - Rotação diária (api-AAAA-MM-DD.log) + corte por tamanho; arquivos antigos são apagados.
 * - Diretório 0700 e arquivos 0600.
 *
 * Fluent Bit, banco de log separado e outbox ficam para a próxima sprint.
 */
final class RequestLog
{
    public const MAX_BYTES = 10 * 1024 * 1024;
    /** Retenção (dias). PENDENTE: política de retenção a confirmar com o Gerente Geral. */
    public const KEEP_DAYS = 14;
    /** Limite de rotação por tamanho (ajustável em testes). */
    public static int $maxBytes = self::MAX_BYTES;

    /** Chaves com URL: query string e fragmento são removidos (fica esquema + host + caminho). */
    private const URL_KEYS = '/(url|ical|link|href|callback|redirect)/i';

    /** Chaves sensíveis: são OMITIDAS do log (a chave e o valor não são gravados). */
    private const SENSITIVE_KEYS = '/(pass(word)?|senha|token|csrf|secret|segredo|authorization|cookie|api[_-]?key|cvv|cvc|card|cartao|cartão|ics_body|cpf|e-?mail|phone|telefone|celular|whats)/i';

    private static ?string $requestId = null;
    private static float $start = 0.0;
    private static bool $started = false;
    public static ?string $dir = null;

    public static function start(string $path, string $method, array $body): string
    {
        if (self::$started) {
            return (string) self::$requestId;
        }
        self::$started = true;
        self::$start = microtime(true);
        $incoming = (string) ($_SERVER['HTTP_X_REQUEST_ID'] ?? '');
        self::$requestId = preg_match('/^[A-Za-z0-9._-]{8,64}$/', $incoming) === 1 ? $incoming : bin2hex(random_bytes(16));
        if (!headers_sent()) {
            header('X-Request-Id: ' . self::$requestId);
        }
        $query = $_GET;
        register_shutdown_function(static function () use ($path, $method, $body, $query): void {
            try {
                self::write([
                    'ts' => date('c'),
                    'request_id' => self::$requestId,
                    'method' => $method,
                    'path' => self::redactPath($path),
                    'status' => http_response_code() ?: 200,
                    'duration_ms' => (int) round((microtime(true) - self::$start) * 1000),
                    'ip' => self::maskIp((string) ($_SERVER['REMOTE_ADDR'] ?? '')),
                    'user' => isset($_SESSION['user_id']) ? self::userRef((int) $_SESSION['user_id']) : null,
                    'user_agent' => mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 200),
                    'query' => self::mask($query),
                    'body' => self::mask($body),
                ]);
            } catch (Throwable) {
                // Log nunca pode derrubar a requisição.
            }
        });
        return self::$requestId;
    }

    public static function id(): ?string
    {
        return self::$requestId;
    }

    /**
     * Sanitiza recursivamente a entrada: chaves sensíveis são omitidas;
     * nos demais valores, padrões sensíveis em texto livre são mascarados.
     */
    public static function mask(mixed $value): mixed
    {
        if (is_array($value)) {
            $out = [];
            $n = 0;
            foreach ($value as $k => $v) {
                if (preg_match(self::SENSITIVE_KEYS, (string) $k) === 1) {
                    continue;
                }
                if (++$n > 50) {
                    $out['_truncado'] = true;
                    break;
                }
                $out[$k] = (is_string($v) && preg_match(self::URL_KEYS, (string) $k) === 1) ? self::redactUrl($v) : self::mask($v);
            }
            return $out;
        }
        if (!is_string($value)) {
            return is_scalar($value) || $value === null ? $value : null;
        }
        return self::maskFreeText($value);
    }

    /** Mascara padrões sensíveis em texto livre (CPF, cartão, e-mail). */
    public static function maskFreeText(string $s): string
    {
        $s = mb_substr($s, 0, 500);
        $s = preg_replace('/\b\d(?:[ -]?\d){12,18}\b/', '[cartao]', $s) ?? $s;
        $s = preg_replace('/\b\d{3}\.?\d{3}\.?\d{3}-?\d{2}\b/', '[cpf]', $s) ?? $s;
        $s = preg_replace_callback('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', static fn ($m) => self::maskEmail($m[0]), $s) ?? $s;
        return $s;
    }

    public static function maskEmail(string $email): string
    {
        $at = strrpos($email, '@');
        if ($at === false) {
            return '***';
        }
        return mb_substr($email, 0, 1) . '***' . substr($email, $at);
    }

    /** Substitui segmentos de 32 hex (ical_hash) por {hash}. */
    public static function redactPath(string $path): string
    {
        return preg_replace('/(?<![a-f0-9])[a-f0-9]{32}(?![a-f0-9])/i', '{hash}', $path) ?? $path;
    }

    /** Mantém só esquema + host + caminho (com hashes redigidos); remove credenciais, query e fragmento. */
    public static function redactUrl(string $url): string
    {
        $p = parse_url($url);
        if (!is_array($p) || empty($p['host'])) {
            return self::maskFreeText(preg_replace('/[?#].*$/s', '', $url) ?? '');
        }
        $out = (isset($p['scheme']) ? $p['scheme'] . '://' : '') . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '');
        return $out . self::redactPath((string) ($p['path'] ?? ''));
    }

    /** Pseudônimo do usuário: HMAC-SHA256(user_id, sal) truncado. Sem sal configurado, não grava nada. */
    public static function userRef(int $userId): ?string
    {
        $salt = (string) (getenv('LOG_SALT') ?: getenv('APP_KEY') ?: '');
        if ($salt === '') {
            return null;
        }
        return substr(hash_hmac('sha256', (string) $userId, $salt), 0, 16);
    }

    /** Trunca IP: IPv4 /24, IPv6 /48. */
    public static function maskIp(string $ip): string
    {
        $bin = @inet_pton($ip);
        if ($bin === false) {
            return '';
        }
        if (strlen($bin) === 4) {
            return (string) inet_ntop(substr($bin, 0, 3) . "\0");
        }
        return (string) inet_ntop(substr($bin, 0, 6) . str_repeat("\0", 10));
    }

    private static function directory(): string
    {
        return self::$dir ?? dirname(__DIR__) . '/storage/logs/api';
    }

    public static function write(array $entry): void
    {
        $dir = self::directory();
        $old = umask(0077);
        try {
            if (!is_dir($dir)) {
                @mkdir($dir, 0700, true);
            }
            @chmod($dir, 0700);
            $file = $dir . '/api-' . date('Y-m-d') . '.log';
            clearstatcache(true, $file);
            if (is_file($file) && filesize($file) >= self::$maxBytes) {
                @rename($file, $file . '.' . date('His') . '.' . bin2hex(random_bytes(2)));
            }
            $line = json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) . "\n";
            $new = !is_file($file);
            file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
            if ($new) {
                @chmod($file, 0600);
            }
            if (random_int(1, 100) === 1) {
                self::purge($dir);
            }
        } finally {
            umask($old);
        }
    }

    private static function purge(string $dir): void
    {
        $limit = time() - self::KEEP_DAYS * 86400;
        foreach (glob($dir . '/api-*.log*') ?: [] as $f) {
            if (@filemtime($f) < $limit) {
                @unlink($f);
            }
        }
    }
}
