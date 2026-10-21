<?php

declare(strict_types=1);

/**
 * Per-client limits of ApiRateLimit (public/index.php): [method, path regex, max, window (s), bucket].
 * Counted by IpRateLimiter on Valkey; the fail mode of each bucket is IpRateLimiter::FAIL_CLOSED_BUCKETS.
 * Also read by the CASOS scripts to reset the loopback client (IpRateLimiter::resetClient).
 *
 * @return list<array{0: string, 1: string, 2: int, 3: int, 4: string}>
 */
return [
    ['GET', '#^/api/cidades$#', 60, 60, '/api/cidades'],
    ['GET', '#^/api/ceps$#', 60, 60, '/api/ceps'],
    ['GET', '#^/api/busca$#', 60, 60, '/api/busca'],
    // Esqueci a senha e cadastro: um balde por IP somando web e API (como fotos e avaliação).
    ['POST', '#^/api/auth/senha/esqueci$#', 5, 900, '/senha/esqueci'],
    ['POST', '#^/api/users$#', 10, 3600, '/cadastro'],
    ['POST', '#^/api/imoveis/[a-f0-9]{16}/avaliar$#', 10, 3600, '/api/imoveis/*/avaliar'],
    ['POST', '#^/api/casas/[a-f0-9]{16}/fotos$#', 30, 600, '/api/casas/*/fotos'],
    // Upload pelo formulário web: mesmo balde da API (30 por 10 min por IP, somando os dois).
    ['POST', '#^/imoveis/[a-f0-9]{16}/fotos$#', 30, 600, '/api/casas/*/fotos'],
    // Avaliação pelo formulário web: mesmo balde da API (10 por hora por IP, somando os dois).
    ['POST', '#^/imoveis/[a-f0-9]{16}/avaliar$#', 10, 3600, '/api/imoveis/*/avaliar'],
    // Formulários web (mesma camada e mesmos baldes da API). Atrás de proxy, REMOTE_ADDR precisa ser o IP real (real_ip no nginx).
    ['POST', '#^/cadastro$#', 10, 3600, '/cadastro'],
    ['POST', '#^/senha/esqueci$#', 5, 900, '/senha/esqueci'],
];
