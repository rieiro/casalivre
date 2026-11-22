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
    // Validar o Google Place ID no painel: 10 tentativas por 10 min por IP.
    ['POST', '#^/api/google-place/verificar$#', 10, 600, '/api/google-place/verificar'],
    // Cadastro/edição de feeds iCal: 20 por 10 min por IP (web e API).
    ['POST', '#^/api/imoveis/[a-f0-9]{16}/ical/importar$#', 20, 600, '/api/imoveis/*/ical/importar'],
    ['PUT', '#^/api/imoveis/[a-f0-9]{16}/ical/\d+$#', 20, 600, '/api/imoveis/*/ical/*'],
    // Verificar a titularidade de um feed iCal: 30 checagens por minuto por IP.
    ['POST', '#^/api/imoveis/[a-f0-9]{16}/ical/\d+/verificar$#', 30, 60, '/api/imoveis/*/ical/*/verificar'],
    // Exportação iCal por plataforma: 20 novos links por 10 min por IP.
    ['POST', '#^/api/imoveis/[a-f0-9]{16}/ical-exports$#', 20, 600, '/api/imoveis/*/ical-exports'],
    // Upload pelo formulário web: mesmo balde da API (30 por 10 min por IP, somando os dois).
    ['POST', '#^/imoveis/[a-f0-9]{16}/fotos$#', 30, 600, '/api/casas/*/fotos'],
    // Avaliação pelo formulário web: mesmo balde da API (10 por hora por IP, somando os dois).
    ['POST', '#^/imoveis/[a-f0-9]{16}/avaliar$#', 10, 3600, '/api/imoveis/*/avaliar'],
    // Formulários web (mesma camada e mesmos baldes da API). Atrás de proxy, REMOTE_ADDR precisa ser o IP real (real_ip no nginx).
    ['POST', '#^/cadastro$#', 10, 3600, '/cadastro'],
    ['POST', '#^/senha/esqueci$#', 5, 900, '/senha/esqueci'],
    // Agent surface & MCP limits (docs/ai/ARCHITECTURE_AI_PLAN.md §4.5)
    ['GET', '#^/api/v1/agent/search$#', 60, 60, '/api/v1/agent/search'],
    ['GET', '#^/api/v1/agent/properties/[a-f0-9]{16}$#', 120, 60, '/api/v1/agent/property'],
    ['GET', '#^/api/v1/agent/properties/[a-f0-9]{16}/quote$#', 30, 60, '/api/v1/agent/quote'],
    ['POST', '#^/api/v1/agent/properties/[a-f0-9]{16}/intent$#', 10, 600, '/api/v1/agent/intent'],
    ['POST', '#^/mcp$#', 60, 60, '/mcp'],
];
