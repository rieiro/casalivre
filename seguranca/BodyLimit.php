<?php

declare(strict_types=1);

require_once __DIR__ . '/Middleware.php';

/**
 * Tamanho do corpo em métodos de escrita (exceto multipart, limitado por post_max_size):
 * Transfer-Encoding ou POST/PUT/PATCH sem Content-Length -> 411; Content-Length acima de MAX_BYTES -> 413.
 */
final class BodyLimit implements Middleware
{
    public const MAX_BYTES = 1048576;

    /** @param callable(string, string): void $recusar */
    public function __construct(private $recusar)
    {
    }

    /** 411, 413 ou null (aceito) para a requisição atual. */
    public static function status(?string $method = null): ?int
    {
        $method = strtoupper($method ?? (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        if (!in_array($method, self::WRITE_METHODS, true)) {
            return null;
        }
        if (str_starts_with(strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? '')), 'multipart/form-data')) {
            return null;
        }
        if (trim((string) ($_SERVER['HTTP_TRANSFER_ENCODING'] ?? '')) !== '') {
            return 411;
        }
        $length = $_SERVER['CONTENT_LENGTH'] ?? $_SERVER['HTTP_CONTENT_LENGTH'] ?? '';
        if (!is_numeric($length)) {
            // DELETE sem corpo não precisa de Content-Length.
            return $method === 'DELETE' ? null : 411;
        }
        return (int) $length > self::MAX_BYTES ? 413 : null;
    }

    public function process(array $request, callable $next): mixed
    {
        $status = self::status((string) ($request['method'] ?? 'GET'));
        if ($status !== null) {
            ($this->recusar)($status === 411 ? 'length_required' : 'too_large', (string) ($request['path'] ?? '/'));
            exit;
        }
        return $next($request);
    }
}
