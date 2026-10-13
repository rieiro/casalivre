<?php

declare(strict_types=1);

/**
 * Log estruturado por requisição da API (JSON por linha), gravado localmente.
 *
 * - request_id: reaproveita X-Request-Id válido do cliente ou gera um novo; devolvido no header.
 * - Campos sensíveis são mascarados aqui no PHP, antes de gravar.
 * - Rotação diária (api-AAAA-MM-DD.log) + corte por tamanho; arquivos antigos são apagados.
 * - Diretório 0700 e arquivos 0600.
 *
 * Fluent Bit, banco de log separado e outbox ficam para a próxima sprint.
 */
final class RequestLog
{
    public const MAX_BYTES = 10 * 1024 * 1024;
    public const KEEP_DAYS = 14;

    /** Chaves cujo valor nunca é gravado. */
    private const SECRET_KEYS = '/(pass(word)?|senha|token|csrf|secret|segredo|authorization|cookie|api[_-]?key|cvv|cvc|card|cartao|cartão|ics_body)/i';

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
                    'path' => $path,
                    'status' => http_response_code() ?: 200,
                    'duration_ms' => (int) round((microtime(true) - self::$start) * 1000),
                    'ip' => self::maskIp((string) ($_SERVER['REMOTE_ADDR'] ?? '')),
                    'user_id' => isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null,
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

    /** Mascara recursivamente um array de entrada. */
    public static function mask(mixed $value, string $key = ''): mixed
    {
        if (is_array($value)) {
            $out = [];
            $n = 0;
            foreach ($value as $k => $v) {
                if (++$n > 50) {
                    $out['_truncado'] = true;
                    break;
                }
                $out[$k] = self::mask($v, (string) $k);
            }
            return $out;
        }
        if ($key !== '' && preg_match(self::SECRET_KEYS, $key) === 1) {
            return '***';
        }
        if (!is_scalar($value) || is_bool($value)) {
            return $value;
        }
        $str = (string) $value;
        $lk = strtolower($key);
        if ($lk === 'cpf' || str_contains($lk, 'cpf')) {
            return self::maskDigits($str, 0);
        }
        if (str_contains($lk, 'email')) {
            return self::maskEmail($str);
        }
        if (str_contains($lk, 'phone') || str_contains($lk, 'telefone') || str_contains($lk, 'celular') || str_contains($lk, 'whats')) {
            return self::maskDigits($str, 2);
        }
        return self::maskFreeText($str);
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

    public static function maskDigits(string $s, int $keep): string
    {
        $digits = preg_replace('/\D+/', '', $s) ?? '';
        if ($digits === '') {
            return '***';
        }
        return '***' . ($keep > 0 ? substr($digits, -$keep) : '');
    }

    private static function maskIp(string $ip): string
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return preg_replace('/\.\d+$/', '.0', $ip) ?? $ip;
        }
        return $ip;
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
            if (is_file($file) && filesize($file) >= self::MAX_BYTES) {
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
