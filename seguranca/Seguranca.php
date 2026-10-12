<?php

declare(strict_types=1);

require_once __DIR__ . '/Pipeline.php';
require_once __DIR__ . '/Sanitize.php';
require_once __DIR__ . '/Csrf.php';
require_once __DIR__ . '/RateLimit.php';
require_once __DIR__ . '/Auth.php';

final class Seguranca
{
    /**
     * @param array{
     *   campos_puros?: list<string>,
     *   sem_csrf?: list<string>,
     *   login?: list<array{0: string, 1: string}>,
     *   bloqueado: callable(): bool,
     *   privado: callable(string, string): bool,
     *   recusar: callable(string, string): void
     * } $regras
     * @param array<string, mixed> $request
     * @param callable(array<string, mixed>): mixed $destino
     */
    public static function pipeline(array $regras, array $request, callable $destino): mixed
    {
        $recusar = $regras['recusar'];
        return (new Pipeline([
            new Sanitize($regras['campos_puros'] ?? []),
            new Csrf($regras['sem_csrf'] ?? [], $recusar),
            new RateLimit($regras['login'] ?? [], $regras['bloqueado'], $recusar),
            new Auth($regras['privado'], $recusar),
        ]))->handle($request, $destino);
    }
}
