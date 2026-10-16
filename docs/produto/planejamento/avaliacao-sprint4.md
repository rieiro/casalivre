# Avaliação da Sprint 4 × plano v0.4 (Gerente de Produto, 16/10/2026)
Fontes: `adr/001-busca-sql-paginada.md` (Aceito; #99 e #100 mesclados, develop d2824fc), `adr/spec-schema-modelagem.md`, `prompts/backlog.md` (Sprint 4, itens 1–2), `casalivre-gerente/planejamento/sequencia-s4-s9.md`.

Mapeamento T1–T7 **corrigido pelo Gerente** (16/10).

| Tarefa | O que faz | Cruzamento com o plano v0.4 | Classificação |
|---|---|---|---|
| T1 Schema v20: índices da busca + `rating_*` | PG `(city,id) INCLUDE(max_guests) WHERE active` + `(lat,lng)`; SQLite `(status,city)`; colunas `rating_sum/rating_count`; remove `idx_properties_status`; parity | Infra P0 (PG 18) | **Manter com ajuste 4:** medir no PG 18 (EXPLAIN ANALYZE) aqui e no fechamento; SQLite só funcional. Absorve o item 2 do backlog |
| T1b Índice da capa das fotos | `idx_photos_cover_ready` parcial | Independente | **Manter** |
| T2 `rating_*` gravado no `addReview` | UPDATE na mesma transação do INSERT | A P3 vincula a avaliação à estadia | **Manter, compatível com a P3:** o contador fica em um só ponto de escrita (o caminho de avaliação), sem supor autor livre, para a P3 só trocar a validação de quem pode avaliar e não a contagem |
| T3 Construtor SQL híbrido (filtros), remove `byCity` | `SearchService`, `GroupRepository::searchSource` no mesmo construtor | P2a (canais N:N), P2 (esperas de pagamento) | **Manter com ajustes 1 e 2:** (1) **vitrine genérica**: o construtor recebe um escopo de vitrine (hoje grupo, depois `channel_id`) e não cita `group_id` por dentro; (2) **função única de disponibilidade**. Regra para a P2: reserva/espera = `calendar_days` com `blocked=1, origin='reserva'` |
| T4 Paginação keyset | 20 itens, lotes de 60, até 4 consultas | Igual à spec de UI | **Manter** |
| T5 Contrato JSON | `items`, `next_cursor`, `total_aprox`, facetas sem contagem | Preço total aprovado pelo Negócios | **Manter com ajuste 3:** com datas, cada item traz `total_estadia` (diárias + `cleaning_fee` + taxa de serviço, hoje 0%) como valor principal e `por_noite` como secundário; sem datas, "a partir de". O filtro de preço continua **por noite** |
| T6 Modo mapa | bbox, `LIMIT 301`, `public_coord` (desloca 300–500 m, compatível com ~500 m) | Canais | **Manter com ajuste 1:** usa o mesmo escopo de vitrine genérico do T3 |
| T7 Front "Mostrar mais imóveis" | `search.js`, `map-data.js`, cursor na URL | Mostra total + por noite (T5) | **Manter** (exibir o total como principal) |

Nenhuma tarefa precisa ser descartada: o ADR já ataca o maior gargalo medido (o `all()`, 65–118 ms na Q4; p95 de 680–2.423 ms contra a meta de 300 ms).

**Ajustes (resumo)**
1. **Vitrine genérica** no T3 e no T6: evita reescrever a busca e a vitrine na P2a.
2. **Função única de disponibilidade** no T3: as esperas de pagamento da P2 entram pelo `calendar_days`, sem uma 2ª fonte de disponibilidade.
3. **Preço total** no T5 (aprovado pelo Negócios): total como valor principal, por noite como secundário. O filtro continua por noite (critério 6 do ADR). Filtrar ou ordenar pelo total é porta futura (P4/P5), fora do keyset por `id`.
4. **Medição no PG 18** no T1 e no fechamento da sprint (critérios 1, 3 e 10 do ADR). **DECIDIDO pelo Rafael (16/10):** o SQLite continua funcionando; otimizações e medição só no PostgreSQL.
- O T2 precisa continuar compatível com a P3 (avaliação ligada à estadia).

**Ordem recomendada**
- **P0 (receita `ops/dev-env/`) antes do ADR 001, ou no início da Sprint 4.** Motivo: os critérios de aceite 1, 3 e 10 do ADR exigem medição no php-fpm + PG 18, e a Valkey/runit muda o ambiente; medir antes significa medir duas vezes.
- O XSS ALTA é independente e pode seguir já.
- Spec de schema (itens 2, 3, 4, 8) logo depois. Coordenar o `SCHEMA_VERSION` com o ADR 001 (já decidido: cada PR sobe a sua).
