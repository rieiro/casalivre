<?php

declare(strict_types=1);

require_once __DIR__ . '/Middleware.php';

final class RateLimit implements Middleware
{
    /**
     * @param list<array{0: string, 1: string}> $login
     * @param callable(): bool $bloqueado
     * @param callable(string, string): void $recusar
     */
    public function __construct(private array $login, private $bloqueado, private $recusar)
    {
    }

    public function process(array $request, callable $next): mixed
    {
        $path = (string) ($request['path'] ?? '/');
        $method = strtoupper((string) ($request['method'] ?? 'GET'));
        if ($this->isLogin($method, $path) && ($this->bloqueado)()) {
            ($this->recusar)('rate_limit', $path);
            exit;
        }
        return $next($request);
    }

    private function isLogin(string $method, string $path): bool
    {
        foreach ($this->login as $rule) {
            if ($method === strtoupper($rule[0]) && $path === $rule[1]) {
                return true;
            }
        }
        return false;
    }
}
