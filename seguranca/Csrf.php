<?php

declare(strict_types=1);

require_once __DIR__ . '/Middleware.php';
require_once __DIR__ . '/BodyLimit.php';

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

    /** Corpo bruto, no máximo o limite efetivo da rota (5 MiB + margem nas rotas de importação); vazio se o BodyLimit recusaria. */
    public static function rawBody(): string
    {
        static $cached = null;
        if ($cached === null) {
            $max = BodyLimit::maxBytes();
            $read = BodyLimit::status() !== null ? '' : file_get_contents('php://input', false, null, 0, $max + 1);
            $cached = is_string($read) && strlen($read) <= $max ? $read : '';
        }
        return $cached;
    }

    public function process(array $request, callable $next): mixed
    {
        $method = strtoupper((string) ($request['method'] ?? 'GET'));
        $path = (string) ($request['path'] ?? '/');
        $isSemCsrf = false;
        foreach ($this->semCsrf as $exempt) {
            if ($exempt === $path || (str_starts_with($exempt, '#') && preg_match($exempt, $path) === 1)) {
                $isSemCsrf = true;
                break;
            }
        }
        if (in_array($method, self::WRITE_METHODS, true) && !$isSemCsrf && !$this->valid()) {
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
