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

## PR #1: iCal e ExceptionShield

- [ ] **PHP**: `zend.exception_ignore_args = On` no `php.ini` ou no pool do php-fpm (o app também força com `ini_set` no `public/index.php`). Assim os stack traces do `storage/error.log` saem sem valores de argumentos.
- [ ] **Saída HTTP para feeds iCal**: o servidor precisa resolver DNS e acessar a internet pública em 80/443. O `CalendarSync::fetch` recusa IPs internos e não usa proxy (`CURLOPT_NOPROXY='*'`). Se a produção só tiver saída via proxy, a importação de feeds vai falhar com status NOK.
- [ ] **Extensão `php-curl`** instalada: sem ela, o `fetch` devolve null (feeds NOK).
