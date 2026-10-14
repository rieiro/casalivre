<?php

declare(strict_types=1);

require_once __DIR__ . '/Middleware.php';
require_once dirname(__DIR__) . '/src/IpRateLimiter.php';

/** Limite por IP (IpRateLimiter) em rotas /api listadas: [método, caminho, máximo, janela em s]. */
final class ApiRateLimit implements Middleware
{
    /**
     * @param list<array{0: string, 1: string, 2: int, 3: int}> $limites
     * @param callable(string, string): void $recusar
     */
    public function __construct(private array $limites, private $recusar)
    {
    }

    public function process(array $request, callable $next): mixed
    {
        $path = (string) ($request['path'] ?? '/');
        $method = strtoupper((string) ($request['method'] ?? 'GET'));
        if (str_starts_with($path, '/api/')) {
            foreach ($this->limites as [$m, $p, $max, $window]) {
                if ($method === strtoupper($m) && $path === $p) {
                    $wait = IpRateLimiter::hit($path, $max, $window);
                    if ($wait > 0) {
                        if (!headers_sent()) {
                            header('Retry-After: ' . $wait);
                        }
                        ($this->recusar)('api_rate_limit', $path);
                        exit;
                    }
                    break;
                }
            }
        }
        return $next($request);
    }
}
