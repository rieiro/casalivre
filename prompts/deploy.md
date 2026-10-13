# Condições de deploy — CasaLivre

Checklist de acompanhamento antes de publicar em produção. Origem: pareceres do Bot Segurança e Arquitetura, Bot Revisor Codigo e Bot Teste na Sprint 1 (out/2026).

## Obrigatórias antes do deploy

- [ ] **Servidor web**: produção com nginx + php-fpm. Nunca usar `php -S` (`scripts/start.sh`), pois ele escreve a URL completa no stderr.
- [ ] **Log do nginx**: `log_format` que redija os caminhos `/ical/` e `/api/ical/` (tokens no path não podem ir para o access log).
- [ ] **LOG_SALT**: definir um valor próprio e aleatório no `.env` de produção (ex.: `openssl rand -hex 32`). Não reutilizar `APP_KEY`. Sem ele, o log não grava o usuário e `scripts/log-lookup.php` não roda.
- [ ] **PHP**: `zend.exception_ignore_args=1` no `php.ini`.
- [ ] **Retenção de logs (14 dias)**: instalar o serviço `deploy/runit/casalivre-log-retention/run` e confirmar a execução diária (api-*, activity.sqlite, error.log, calendar-sync.log, php.log, Mongo via deleteMany).
- [ ] **Limpeza única**: apagar o histórico cru já existente no `activity.sqlite` (gravado antes das regras de mascaramento).
- [ ] **Permissões**: arquivos de log com permissão `0600`.
- [ ] **CORS**: allowlist explícita de origens (PR #4). Nunca `*` com credenciais.
- [ ] **Ordem de merge na develop**: #7, #4, #2, #1 (squash), #3, #5 (rebase após #1), #6 (squash), #8.

## Não verificado ainda (validar em homologação)

- [ ] SSRF do `CalendarSync::fetch` com DNS real.
- [ ] Exclusão real no Mongo pelo job de retenção.
- [ ] Importação iCal com feed real (Airbnb, Booking, Google).

## Backlog (próximas sprints)

- Criptografar `calendar_feeds.url` e `properties.external_ical_url` no banco, com coluna HMAC para checar duplicados.
- LGPD: PII em claro (incluindo `valor_anterior` de ACCOUNT_UPDATE), audit_hold com PII, política de retenção, hash de IP e e-mail; separar auditoria de BI.
- Migrar `actor_id INTEGER` para `actor_ref TEXT`.
- Prefixos IPv6/TEST-NET restantes em `CalendarSync.php` (::ffff:0:0:0/96, 2001:10::/28, 2001:20::/28, 3fff::/20, 5f00::/16, fec0::/10).
- `logRef` com `LogPrivacy::url()`; `redactPath` cobrindo segmentos opacos de 20+ caracteres; flock/rotação no LogRetention.
- Fluent Bit, banco de log separado e outbox de auditoria.
- Migrar fotos, calendário, formulário de imóvel e autenticação para fetch/API.
