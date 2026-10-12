<?php

declare(strict_types=1);

require_once __DIR__ . '/Middleware.php';

final class Auth implements Middleware
{
    /** @param callable(string, string): bool $privado @param callable(string, string): void $recusar */
    public function __construct(private $privado, private $recusar)
    {
    }

    public function process(array $request, callable $next): mixed
    {
        $path = (string) ($request['path'] ?? '/');
        $method = strtoupper((string) ($request['method'] ?? 'GET'));
        if (($this->privado)($path, $method) && empty($_SESSION['user_id'])) {
            ($this->recusar)(str_starts_with($path, '/api') ? 'unauthenticated' : 'login', $path);
            exit;
        }
        return $next($request);
    }
}
