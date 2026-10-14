<?php

declare(strict_types=1);

require_once __DIR__ . '/Middleware.php';

final class Csrf implements Middleware
{
    /** @param list<string> $semCsrf @param callable(string, string): void $recusar */
    public function __construct(private array $semCsrf, private $recusar)
    {
    }

    public static function token(): string
    {
        if (empty($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(16));
        }
        return (string) $_SESSION['csrf'];
    }

    /** Limite do corpo bruto (JSON/form) lido por rawBody(); uploads multipart seguem post_max_size. */
    public const MAX_BODY_BYTES = 1048576;

    /** true se o corpo não-multipart excede MAX_BODY_BYTES (pelo CONTENT_LENGTH declarado). */
    public static function tooLarge(): bool
    {
        $type = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));
        return !str_starts_with($type, 'multipart/form-data') && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > self::MAX_BODY_BYTES;
    }

    /** Corpo bruto, no máximo MAX_BODY_BYTES (acima disso devolve ''). */
    public static function rawBody(): string
    {
        static $cached = null;
        if ($cached === null) {
            $read = self::tooLarge() ? '' : file_get_contents('php://input', false, null, 0, self::MAX_BODY_BYTES + 1);
            $cached = is_string($read) && strlen($read) <= self::MAX_BODY_BYTES ? $read : '';
        }
        return $cached;
    }

    public function process(array $request, callable $next): mixed
    {
        $method = strtoupper((string) ($request['method'] ?? 'GET'));
        $path = (string) ($request['path'] ?? '/');
        if (in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true) && self::tooLarge()) {
            ($this->recusar)('too_large', $path);
            exit;
        }
        if (in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true) && !in_array($path, $this->semCsrf, true) && !$this->valid()) {
            ($this->recusar)('csrf', $path);
            exit;
        }
        return $next($request);
    }

    private function valid(): bool
    {
        $sent = $this->sent();
        return $sent !== '' && hash_equals(self::token(), $sent);
    }

    private function sent(): string
    {
        $post = $_POST['_csrf'] ?? '';
        if (is_string($post) && $post !== '') {
            return $post;
        }
        $header = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        if (is_string($header) && $header !== '') {
            return $header;
        }
        $type = (string) ($_SERVER['CONTENT_TYPE'] ?? '');
        if (!str_contains($type, 'application/json')) {
            return '';
        }
        $decoded = json_decode(self::rawBody(), true);
        $json = is_array($decoded) ? ($decoded['_csrf'] ?? '') : '';
        return is_string($json) ? $json : '';
    }
}
