# Documentação de IA, Agentes e Rastreamento

Esta pasta centraliza os documentos de especificação arquitetural, recomendações de segurança e relatórios de verificação da camada de IA e Agentes do CasaLivre.

## Documentos

1. **[ARCHITECTURE_AI_PLAN.md](ARCHITECTURE_AI_PLAN.md)**
   - Especificação técnica e arquitetural da integração com agentes de IA, crawlers e LLMs.
   - Detalha endpoints REST (`/api/v1/agent/*`), servidor MCP (`/mcp`), negociação de conteúdo Markdown (`Accept: text/markdown`), `robots.txt`, `llms.txt`, `openapi.json`, ciclo de vida de soft-holds (15 minutos), telemetria assíncrona com Valkey e mitigação de abuso/scraping.

2. **[RECOMMENDATION_IP_RATELIMIT_FOR_HUMANS_AND_AI.md](RECOMMENDATION_IP_RATELIMIT_FOR_HUMANS_AND_AI.md)**
   - Diretrizes de governança e dimensionamento de limites de taxa por IP (*Rate Limiting*).
   - Separação entre tráfego humano (sessão/cookies/busca pública) e tráfego automatizado (agentes/mcp/intents).
   - Recomendações OWASP e fail-closed para proteção de rotas transacionais.

3. **[VERIFICATION_REPORT.md](VERIFICATION_REPORT.md)**
   - Relatório consolidado de testes de ponta a ponta (E2E).
   - Validação de headers, JSON-LD VacationRental, ciclo de soft-hold, simulação de tráfego com 4 perfis (`search_bots`, `mcp_agents`, `human_mix`, `spike_abuse`), auditoria de LGPD (anonymization de IP por hash diário, isolamento de humanos) e persistência via `SIGTERM`.
