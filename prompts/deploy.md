# Condições de deploy — CasaLivre

Documento único de acompanhamento do deploy. **Só o Bot Gerente Geral edita este arquivo**; os demais analistas enviam os blocos para consolidação. Origem: pareceres do Bot Segurança e Arquitetura, Bot Revisor Codigo, Bot Teste e Bot Programador na Sprint 1 (out/2026).

## Ordem de merge na develop (Sprint 1)

- [ ] #9, #7, #4, #1 (squash), #5, #2, #3, #6 (squash), #8, #10 — um por vez; após cada merge, o dono do próximo PR atualiza a branch com a develop e roda os testes.
- [ ] Conflito conhecido: #2 após #1 em `src/CalendarSync.php` — mover `trimHorizon()` para antes de `fetch()`, manter `fetch(string $url, int $timeout = 20)` e rodar `php -l`.

## Servidor e PHP

- [ ] **nginx + php-fpm** em produção. Nunca `php -S` (`scripts/start.sh`), que escreve a URL completa no stderr.
- [ ] **Log do nginx**: `log_format` sem query string e com `/ical/` e `/api/ical/` redigidos (ou `access_log off` nessas rotas).
- [ ] **IP real atrás de proxy/LB**: configurar `real_ip` no nginx. Rate limit (60 req/min por IP e endpoint) e IP truncado nos logs dependem do `REMOTE_ADDR` real.
- [ ] **PHP >= 8.2** (validado em 8.4) com `pdo_sqlite` (ou `pdo_pgsql`), `mbstring`, `curl`, `gd`, `xml`; `mongodb` se `LOG_DRIVER=mongodb`. Sem `php-curl`, os feeds iCal ficam NOK.
- [ ] **`zend.exception_ignore_args = On`** no `php.ini` ou pool do php-fpm (o app também força via `ini_set` em `public/index.php`).
- [ ] **Saída HTTP 80/443 e DNS** liberados para os feeds iCal. O `CalendarSync::fetch` recusa IPs internos e não usa proxy (`CURLOPT_NOPROXY='*'`); com saída só via proxy, a importação falha (NOK).

## Variáveis de ambiente

- [ ] **`LOG_SALT` (obrigatório)**: valor aleatório próprio de cada ambiente (`openssl rand -hex 32`), fora do repositório, sem fallback para `APP_KEY`. Sem ele, usuário, e-mail, UA e URLs são omitidos dos logs e `scripts/log-lookup.php` sai com código 2. Trocar o valor depois quebra a busca de registros antigos.
- [ ] **`CORS_ORIGINS`**: origens separadas por vírgula, cada uma exatamente `esquema://host[:porta]` — sem `*`, sem barra final, sem caminho; em produção só `https://`. Comparação exata com o header `Origin`; itens inválidos são ignorados em silêncio; vazio = nenhuma origem externa. Exemplo: `CORS_ORIGINS=https://app.casalivre.com.br,https://admin.casalivre.com.br`.
- [ ] **`CORS_ORIGIN` (legado)**: só é lido se `CORS_ORIGINS` estiver vazio; `CORS_ORIGIN=*` passa a não liberar nada. Renomear para `CORS_ORIGINS` e remover o antigo.
- [ ] **CDN/cache** na frente deve respeitar `Vary: Origin` (respostas enviam `Access-Control-Allow-Credentials: true`).

## Banco de dados (SQLite)

- [ ] **Preparação de ambiente novo**: `setup.sh` (aplica `database/schema.sql`) → `php scripts/migrate.php` → `load-ceps.php` → seeds (`seed.php`, `seed_massive.php`, `seed-users.php`, `seed-owners.php`). O `migrate.php` falha em banco vazio ("no such table: users", `src/Db.php:175`). Os seeds não precisam de `LOG_SALT` e não precisaram de alteração nesta sprint.
- [ ] **Migração a cada deploy**: `php scripts/migrate.php` antes de liberar o tráfego, com o usuário do php-fpm (`--force` reaplica). O app só migra numa requisição quando `PRAGMA user_version` < `Db::SCHEMA_VERSION`.
- [ ] **WAL**: o diretório `storage/db/` (não só o arquivo) gravável pelo php-fpm, para `-wal` e `-shm`; o mesmo vale para `storage/logs/activity.sqlite`. Disco local (WAL não funciona em NFS). `busy_timeout = 8000 ms`.
- [ ] **Backup**: `sqlite3 casalivre.sqlite ".backup destino.sqlite"` ou copiar junto com `-wal`/`-shm`.
- [ ] **Regra de desenvolvimento**: aumentar `Db::SCHEMA_VERSION` sempre que mudar o schema, `Db::migrate()` ou os dados base (`database/messages.php`, `Settings::defaults`, cidades, catálogo do ActivityLog).
- [ ] **Dados de auditoria**: `activity_log.actor_id` passa a guardar HMAC; registros antigos mantêm o id numérico até a limpeza única abaixo ou a retenção.

## Jobs agendados (usuário do php-fpm)

- [ ] **Retenção de logs (14 dias, aprovada)**: runit `deploy/runit/casalivre-log-retention` (`cp -r` para `/etc/sv/`, `ln -s` em `/var/service/`, `conf` com `APP_DIR`, `APP_USER`, `RETENTION_DAYS`, `PHP_BIN`) ou cron `15 3 * * * php scripts/log-retention.php`. Cobre `storage/logs/api/api-*`, `activity.sqlite` (DELETE + VACUUM), `error.log`, `calendar-sync.log`, `php.log` e Mongo (`deleteMany`). Confirmar a primeira execução (`sv status` e saída do script).
- [ ] **Sincronização de calendários**: `0 * * * * php /caminho/casalivre/scripts/sync-calendars.php`. Agora também remove as noites além do horizonte (`trimHorizon()`), antes feito no GET.
- [ ] **Limpeza do rate limit**: `find storage/cache/ratelimit -mmin +120 -delete` no cron (um arquivo por IP, não expiram sozinhos).
- [ ] **Limpeza única**: apagar o histórico cru já existente em `activity.sqlite` (gravado antes do mascaramento).

## Permissões e arquivos

- [ ] `storage/logs/api` `0700` e arquivos de log `0600`, do usuário do php-fpm (o app usa `umask 0077`).
- [ ] `storage/cache/ratelimit/` gravável pelo php-fpm; caso contrário, o rate limit fica desligado (sem 429).
- [ ] Não publicar `vendor/`, `tests/`, `phpunit.xml` nem `.phpunit.cache/` no servidor. Em produção, não rodar `composer install` (ou só `--no-dev --no-interaction`); Composer e PHPUnit 11 são apenas para testes.

## Compatibilidade

- [ ] Rotas legadas `/cidades` e `/ceps` removidas (404): clientes externos e caches devem usar `/api/cidades` e `/api/ceps`.
- [ ] Export iCal só pelo `ical_hash` secreto; o link pelo `public_hash` passa a dar 404.

## CI / homologação

- [ ] `composer install`, `vendor/bin/phpunit --testsuite Unit` e, com o servidor de teste no ar, `vendor/bin/phpunit` e `CASOS=todos php scripts/run-casos-de-teste.php`.
- [ ] SSRF do `CalendarSync::fetch` com DNS real e redirects.
- [ ] Importação iCal com feeds reais (Airbnb, Booking, Google).
- [ ] Carga paralela nos `.ics` com php-fpm real, sem 500 "database is locked" (DEF-008).
- [ ] Retenção sob carga do php-fpm, sem sobrar arquivos `*.ret-*`; exclusão real no Mongo.
- [ ] Mascaramento de `feeds[].url` no front separado (quando existir).

## Condições bloqueantes de produção (revisão pós-merge Sprint 1)

1. **PHP >= 8.4** até corrigir a transação aninhada em `CityRepository::ensure` na primeira migração (quebra em 8.2/8.3).
2. **Não publicar em produção** antes de o RequestLog mascarar nome, CEP, endereço e data de nascimento (LGPD).
3. **Não publicar em produção** enquanto `verification_link` sair no JSON de `POST /api/users` (Api.php:121) e do login de conta pendente (Api.php:100): permite ativar conta com e-mail alheio.
4. Corrigir o cron `sync-calendars`, que deixou de gravar as auditorias `CALENDAR_SYNC` e `CALENDAR_UPDATE`.

### Correções pós-merge (PRs #12 a #15)

- Ordem de merge: #13 antes do #15; #12 e #14 em qualquer ordem.
- #12: o corpo das requisições é logado por allowlist, sem dados pessoais.
- #13: `Db::transactional()` com SAVEPOINT. Volta a valer PHP >= 8.2 depois do merge (a condição 1 deixa de valer).
- #14: o cron `sync-calendars` precisa rodar com o usuário do php-fpm, porque grava no `activity.sqlite`.
- #15: `APP_ENV=production` (ou sem a variável) em produção, e `dev`/`local` só nas máquinas de desenvolvimento. Incluir `APP_ENV` no `.env.example`. Rodar `php scripts/migrate.php` (SCHEMA_VERSION 2). Os scripts de teste que leem `verification_link` rodam com `APP_ENV=dev`.
- **Bloqueante:** o projeto não envia e-mail (`AccountFlow::$sendLink` é uma função vazia). Não abrir o cadastro em produção antes de implementar o envio de ativação e de redefinição de senha.
- Pendência: o teste SSRF de `::ffff:8.8.8.8` falha no PHP 8.2 e passa no 8.4 (tarefa separada).

## Condições de deploy: PRs #12 (log por allowlist) e #15 (links só em dev/local)

Parecer do Bot Segurança e Arquitetura: #12 aprovado com condições; #15 não aprovado (segura até o envio de e-mail existir).

### Obrigatórias
- [ ] `APP_ENV=production` explícito no `.env` de produção.
- [ ] `APP_URL=https://<domínio>` fixo; links de ativação e redefinição não podem depender do cabeçalho `Host` (corrigir `request_base()`).
- [ ] nginx com `server_name` explícito e `default_server` devolvendo 444.
- [ ] Envio de e-mail (SMTP/msmtp) funcionando antes de publicar o #15.
- [ ] Tokens de ativação e redefinição guardados só como hash sha256; apagar as colunas `*_link`.
- [ ] `LOG_SALT` próprio por ambiente; `zend.exception_ignore_args = On`.
- [ ] Mesclar o #13 antes do #12 e do #15.
- [ ] CI rodando `tests/standalone/run.php` em PHP 8.2 e 8.4.
- [ ] `CalendarSync::isPublicIp` recusando `::ffff:0:0/96` em qualquer versão do PHP.
- [ ] Limpeza única do `activity.sqlite` (dados pessoais em `valor_anterior`/`valor_atual` gravados antes da allowlist).

### Validar em homologação
- [ ] Cadastro, ativação e redefinição de ponta a ponta com e-mail real, sem link na tela.
- [ ] Tempo de resposta igual para e-mail existente e inexistente no esqueci-senha e no cadastro.
- [ ] Amostra do `activity_log` e do `api-*.log` sem nome, endereço, CEP, e-mail, CPF, telefone, IP completo ou user agent.

### Backlog
- [ ] `ExceptionShield`: mensagem e trace só no `error.log`, mascarados; no `activity_log`, só classe, código e arquivo:linha.
- [ ] Resposta genérica no cadastro com e-mail existente (anti-enumeração).
- [ ] `BODY_VALUE_ALLOWLIST` limitado a valores escalares.

## Condições de deploy: PRs #16 (SSRF) e #17 (Mailer SMTP), e ajustes do #12, #14 e #15

- Ordem de merge: #13, #16, #12, #14, #15, #17. O #15 e o #17 entram juntos, e só com o envio de e-mail validado em homologação.
- #15: `APP_URL=https://<domínio>` obrigatório em produção (sem ele, o app dá erro); `APP_ENV=production` ou ausente; rodar `php scripts/migrate.php` (SCHEMA_VERSION 2).
- #17: `SMTP_HOST`, `SMTP_PORT`, `SMTP_SECURE`, `SMTP_USER`, `SMTP_PASS` e `SMTP_FROM` (`SMTP_CA_FILE` só com CA privada). Saída liberada na porta 587 ou 465. SPF e DKIM no domínio do `SMTP_FROM`. O `storage/logs/mail-dev.log` nunca pode existir em produção.
- #12 e #14: `ACTIVITY_DB_PATH` só para testes, sem valor em produção.
- #16: CI em PHP 8.2 e 8.4 (no 8.2, fica vermelha até o #13 entrar).

## Backlog (próximas sprints)

- `verification_link`: ver condição bloqueante 3.
- Trocar `alert()` dos formulários por mensagens na própria página.

- Expor ou gerar o `ical_hash` pela API (hoje só é criado ao abrir `views/calendar.php:140`).
- Criptografar `calendar_feeds.url`, `properties.external_ical_url` e `audit_hold`, com coluna HMAC para duplicados.
- LGPD: pseudonimizar nome, endereço e CEP em `valor_anterior` (ACCOUNT_UPDATE), política de retenção, hash de IP e e-mail; separar auditoria de BI.
- Migrar `activity_log.actor_id` para `actor_ref TEXT`.
- `LogRetention::run` incluir arquivos `*.ret-*` remanescentes.
- Prefixos IPv6/TEST-NET restantes em `CalendarSync::isPublicIp` (::ffff:0:0:0/96, 2001:10::/28, 2001:20::/28, 3fff::/20, 5f00::/16, fec0::/10); `logRef` via `LogPrivacy::url()`; `redactPath` cobrindo segmentos opacos de 20+ caracteres.
- `applyRange`/`applyMinNights` com o mesmo risco de lock do DEF-008.
- Fluent Bit, banco de log separado e outbox de auditoria.
- Migrar fotos, calendário, formulário de imóvel e autenticação para fetch/API.
