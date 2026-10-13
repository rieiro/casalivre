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
- [ ] **Ordem de merge na develop**: #9, #7, #4, #1 (squash), #5 (rebase após #1), #2, #3, #6 (squash), #8, #10. Nas seções do deploy.md em conflito, manter todas.

## Não verificado ainda (validar em homologação)

- [ ] SSRF do `CalendarSync::fetch` com DNS real.
- [ ] Exclusão real no Mongo pelo job de retenção.
- [ ] Importação iCal com feed real (Airbnb, Booking, Google).
- [ ] DEF-008: "database is locked" (SQLite) sob carga paralela nos `.ics` (PR #10). No php-fpm real, confirmar o modo WAL e o busy_timeout, e garantir permissão de escrita na pasta do banco para os arquivos `-wal` e `-shm`.

## Backlog (próximas sprints)

- Criptografar `calendar_feeds.url` e `properties.external_ical_url` no banco, com coluna HMAC para checar duplicados.
- LGPD: PII em claro (incluindo `valor_anterior` de ACCOUNT_UPDATE), audit_hold com PII, política de retenção, hash de IP e e-mail; separar auditoria de BI.
- Se o #5 não resolver, corrigir a perda de linha em `pruneTextLog()` durante a retenção concorrente.
- Migrar `actor_id INTEGER` para `actor_ref TEXT`.
- Prefixos IPv6/TEST-NET restantes em `CalendarSync.php` (::ffff:0:0:0/96, 2001:10::/28, 2001:20::/28, 3fff::/20, 5f00::/16, fec0::/10).
- `logRef` com `LogPrivacy::url()`; `redactPath` cobrindo segmentos opacos de 20+ caracteres; flock/rotação no LogRetention.
- Fluent Bit, banco de log separado e outbox de auditoria.
- Migrar fotos, calendário, formulário de imóvel e autenticação para fetch/API.

## Condições de deploy: PRs #1 (iCal) e #5 (log), parecer final de segurança

### Obrigatórias
- [ ] nginx: `log_format` sem query string e com `/ical/` e `/api/ical/` redigidos (ou `access_log off` nessas rotas).
- [ ] `zend.exception_ignore_args = On` no `php.ini` ou no pool do php-fpm.
- [ ] Retenção: instalar `deploy/runit/casalivre-log-retention` (`/etc/sv/` + `ln -s` em `/var/service/`, `conf` com APP_DIR, APP_USER, RETENTION_DAYS e PHP_BIN), rodando com o mesmo usuário do php-fpm. Confirmar a primeira execução com `sv status` e a saída do script.
- [ ] Saída HTTP 80/443 e DNS liberados para os feeds iCal (o fetch não usa proxy). Extensão `php-curl` instalada.
- [ ] Se `LOG_DRIVER=mongodb`: extensão `mongodb` instalada e retenção validada via `deleteMany`.

### Validar em homologação
- [ ] Retenção sob carga do php-fpm, sem sobrar arquivos `*.ret-*`.
- [ ] Mascaramento de `feeds[].url` no front separado (quando existir).

### Backlog
- [ ] `LogRetention::run` incluir arquivos `*.ret-*` que sobrarem.
- [ ] Criptografar também o `audit_hold` no banco.
- [ ] Pseudonimizar nome e endereço em `valor_anterior`.
