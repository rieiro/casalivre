<?php

declare(strict_types=1);

require_once __DIR__ . '/Middleware.php';

final class Pipeline
{
    /** @param list<Middleware> $middlewares de fora para dentro */
    public function __construct(private array $middlewares)
    {
    }

    /**
     * @param array<string, mixed> $request
     * @param callable(array<string, mixed>): mixed $destination
     */
    public function handle(array $request, callable $destination): mixed
    {
        $next = $destination;
        foreach (array_reverse($this->middlewares) as $middleware) {
            $next = static function (array $request) use ($middleware, $next): mixed {
                return $middleware->process($request, $next);
            };
        }
        return $next($request);
    }
}
