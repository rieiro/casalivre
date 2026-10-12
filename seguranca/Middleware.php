<?php

declare(strict_types=1);

interface Middleware
{
    /**
     * @param array<string, mixed> $request
     * @param callable(array<string, mixed>): mixed $next
     */
    public function process(array $request, callable $next): mixed;
}
