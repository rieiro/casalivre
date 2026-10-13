# Condições de deploy — CasaLivre

Checklist de acompanhamento antes de publicar em produção. Origem: pareceres do Bot Segurança e Arquitetura, Bot Revisor Codigo e Bot Teste na Sprint 1 (out/2026).

## Obrigatórias antes do deploy

- [ ] **Servidor web**: produção com nginx + php-fpm. Nunca usar `php -S` (`scripts/start.sh`), pois ele escreve a URL completa no stderr.
- [ ] **Log do nginx**: `log_format` que redija os caminhos `/ical/` e `/api/ical/` (tokens no path não podem ir para o access log).
- [ ] **LOG_SALT**: definir um valor próprio e aleatório no `.env` de produção (ex.: `openssl rand -hex 32`), diferente por ambiente e fora do repositório. Não reutilizar `APP_KEY`. Sem ele, o log não grava o usuário e `scripts/log-lookup.php` não roda. Trocar o valor depois quebra a busca de registros antigos no log-lookup.
- [ ] **PHP**: `zend.exception_ignore_args=1` no `php.ini`.
- [ ] **Retenção de logs (14 dias, aprovada)**: instalar o serviço `deploy/runit/casalivre-log-retention/run` ou o cron `15 3 * * * php scripts/log-retention.php`, com o usuário correto, e confirmar a execução diária (api-*, activity.sqlite, error.log, calendar-sync.log, php.log, Mongo via deleteMany).
- [ ] **Limpeza única**: apagar o histórico cru já existente no `activity.sqlite` (gravado antes das regras de mascaramento).
- [ ] **Permissões**: diretório `storage/logs/api` com `0700` e arquivos de log com `0600`.
- [ ] **IP atrás de proxy**: se houver proxy ou load balancer, configurar o IP real do cliente (ex.: `real_ip` no nginx). Caso contrário, `REMOTE_ADDR` será o do proxy e todos os usuários dividirão o mesmo contador de rate limit.
- [ ] **CORS**: allowlist explícita de origens (PR #4). Nunca `*` com credenciais.
- [ ] **Ordem de merge na develop**: #7, #4, #2, #1 (squash), #3, #5 (rebase após #1), #6 (squash), #8.

## Não verificado ainda (validar em homologação)

- [ ] SSRF do `CalendarSync::fetch` com DNS real.
- [ ] Exclusão real no Mongo pelo job de retenção.
- [ ] Importação iCal com feed real (Airbnb, Booking, Google).
- [ ] DEF-008: "database is locked" (SQLite) sob carga paralela nos `.ics`. Validar no php-fpm real depois da correção (WAL + busy_timeout).

## Backlog (próximas sprints)

- Criptografar `calendar_feeds.url` e `properties.external_ical_url` no banco, com coluna HMAC para checar duplicados.
- LGPD: PII em claro (incluindo `valor_anterior` de ACCOUNT_UPDATE), audit_hold com PII, política de retenção, hash de IP e e-mail; separar auditoria de BI.
- Se o #5 não resolver, corrigir a perda de linha em `pruneTextLog()` durante a retenção concorrente.
- Migrar `actor_id INTEGER` para `actor_ref TEXT`.
- Prefixos IPv6/TEST-NET restantes em `CalendarSync.php` (::ffff:0:0:0/96, 2001:10::/28, 2001:20::/28, 3fff::/20, 5f00::/16, fec0::/10).
- `logRef` com `LogPrivacy::url()`; `redactPath` cobrindo segmentos opacos de 20+ caracteres; flock/rotação no LogRetention.
- Fluent Bit, banco de log separado e outbox de auditoria.
- Migrar fotos, calendário, formulário de imóvel e autenticação para fetch/API.

## PR #4: CORS com lista explícita de origens

- [ ] **CORS_ORIGINS**: lista de origens permitidas separadas por vírgula, por exemplo `CORS_ORIGINS=https://app.casalivre.com.br,https://admin.casalivre.com.br`.
  - Cada item precisa ser exatamente `esquema://host[:porta]`: **sem `*`, sem barra final, sem caminho**. Itens inválidos são ignorados em silêncio.
  - A comparação com o header `Origin` é **exata**.
  - **Em produção, só `https://`.** O código aceita `http://` apenas para desenvolvimento local.
  - Vazio ou ausente: nenhuma origem externa é liberada. O frontend no mesmo domínio continua funcionando.
- [ ] **CORS_ORIGIN (nome legado)**: lido apenas se `CORS_ORIGINS` estiver vazio, com as mesmas regras (`*` é ignorado, então `CORS_ORIGIN=*` passa a **não liberar nada**). Ao migrar, renomear para `CORS_ORIGINS` e remover `CORS_ORIGIN` do `.env`.
- [ ] **Credenciais**: as respostas para origens permitidas enviam `Access-Control-Allow-Credentials: true` e `Vary: Origin`. Se houver CDN ou cache na frente, ele precisa respeitar o `Vary: Origin`.
