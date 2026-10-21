<?php

declare(strict_types=1);

require_once __DIR__ . '/Middleware.php';
require_once dirname(__DIR__) . '/src/IpRateLimiter.php';

/**
 * Limite por IP (IpRateLimiter) nas rotas da API listadas: [método, regex do caminho, máximo, janela (s), balde].
 * Regras da API e dos formulários web sensíveis (POST /cadastro, POST /senha/esqueci); o balde
 * agrupa a rota (ex.: todas as casas no upload de fotos). Contadores no Valkey; com ele fora do ar, o modo de falha
 * do balde vem de IpRateLimiter::FAIL_CLOSED_BUCKETS (429 + Retry-After 30 ou passa).
 */
final class ApiRateLimit implements Middleware
{
    /**
     * @param list<array{0: string, 1: string, 2: int, 3: int, 4: string}> $limites
     * @param callable(string, string): void $recusar
     */
    public function __construct(private array $limites, private $recusar)
    {
    }

    public function process(array $request, callable $next): mixed
    {
        $path = (string) ($request['path'] ?? '/');
        $method = strtoupper((string) ($request['method'] ?? 'GET'));
        foreach ($this->limites as [$m, $pattern, $max, $window, $bucket]) {
            if ($method === strtoupper($m) && preg_match($pattern, $path) === 1) {
                $wait = IpRateLimiter::hit($bucket, $max, $window);
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
        return $next($request);
    }
}
