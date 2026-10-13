# Deploy em produção — CasaLivre

Tudo o que é preciso para subir o sistema em produção: servidor, variáveis, banco, jobs, integrações e condições bloqueantes. **Só o Bot Gerente Geral edita este arquivo**; os demais analistas enviam os blocos para consolidação. O acompanhamento do desenvolvimento (PRs, ordem de merge, pendências) fica em `prompts/backlog.md`.

## Condições bloqueantes (não publicar enquanto houver item aberto)

- [ ] Mascaramento de dados pessoais nos logs: RequestLog e ActivityLog por allowlist (PR #12).
- [ ] `verification_link` e link de redefinição fora das respostas da API e das telas em produção (PR #15).
- [ ] Envio de e-mail funcionando, com provedor SMTP definido (PR #17). Decisão do usuário (13/10): o Mailer entra pronto e sem provedor. Até haver provedor, o cadastro e a redefinição de senha continuam fechados em produção.
- [ ] Banco novo no PHP 8.2/8.3 (PR #13). Até ele entrar, exigir PHP >= 8.4.
- [ ] Auditorias `CALENDAR_SYNC` e `CALENDAR_UPDATE` voltando a ser gravadas pelo cron (PR #14).
- [ ] Tokens de ativação e redefinição guardados só como hash sha256, sem as colunas `*_link`.
- [ ] Envio de e-mail fora da requisição (fila com worker ou cron) e resposta em tempo constante no esqueci-senha; mensagem "Enviamos um link" só depois de enfileirar com sucesso. Condição para abrir o cadastro e a redefinição em produção.

## Servidor e PHP

- [ ] **nginx + php-fpm** em produção. Nunca `php -S` (`scripts/start.sh`), que escreve a URL completa no stderr.
- [ ] **Log do nginx**: `log_format` sem query string e com `/ical/` e `/api/ical/` redigidos (ou `access_log off` nessas rotas).
- [ ] **IP real atrás de proxy/LB**: configurar `real_ip` no nginx. Rate limit (60 req/min por IP e endpoint) e IP truncado nos logs dependem do `REMOTE_ADDR` real.
- [ ] **PHP >= 8.2** (validado em 8.4) com `pdo_sqlite` (ou `pdo_pgsql`), `mbstring`, `curl`, `gd`, `xml`; `mongodb` se `LOG_DRIVER=mongodb`. Sem `php-curl`, os feeds iCal ficam NOK.
- [ ] **`zend.exception_ignore_args = On`** no `php.ini` ou pool do php-fpm (o app também força via `ini_set` em `public/index.php`).
- [ ] **Saída HTTP 80/443 e DNS** liberados para os feeds iCal. O `CalendarSync::fetch` recusa IPs internos e não usa proxy (`CURLOPT_NOPROXY='*'`); com saída só via proxy, a importação falha (NOK).
- [ ] **Host fixo**: nginx com `server_name` explícito e `default_server` devolvendo 444 (bloqueia Host forjado nos links de e-mail).

## Variáveis de ambiente

- [ ] **`LOG_SALT` (obrigatório)**: valor aleatório próprio de cada ambiente (`openssl rand -hex 32`), fora do repositório, sem fallback para `APP_KEY`. Sem ele, usuário, e-mail, UA e URLs são omitidos dos logs e `scripts/log-lookup.php` sai com código 2. Trocar o valor depois quebra a busca de registros antigos.
- [ ] **`CORS_ORIGINS`**: origens separadas por vírgula, cada uma exatamente `esquema://host[:porta]` — sem `*`, sem barra final, sem caminho; em produção só `https://`. Comparação exata com o header `Origin`; itens inválidos são ignorados em silêncio; vazio = nenhuma origem externa. Exemplo: `CORS_ORIGINS=https://app.casalivre.com.br,https://admin.casalivre.com.br`.
- [ ] **`CORS_ORIGIN` (legado)**: só é lido se `CORS_ORIGINS` estiver vazio; `CORS_ORIGIN=*` passa a não liberar nada. Renomear para `CORS_ORIGINS` e remover o antigo.
- [ ] **CDN/cache** na frente deve respeitar `Vary: Origin` (respostas enviam `Access-Control-Allow-Credentials: true`).
- [ ] **`APP_ENV=production`** explícito (qualquer valor diferente de `dev`/`local` esconde os links). Nunca usar `dev`/`local` fora do desenvolvimento.
- [ ] **`APP_URL=https://<domínio>`** obrigatório (https, sem barra final, query ou credenciais): os links de ativação e redefinição saem dele; sem ele, o app dá erro em produção.
- [ ] **`ACTIVITY_DB_PATH`**: só para testes, sem valor em produção.
- [ ] `APP_ENV` e `APP_URL` documentados no `.env.example`.

## Integrações

### E-mail (SMTP, PR #17)
- [ ] `SMTP_HOST`, `SMTP_PORT`, `SMTP_SECURE`, `SMTP_USER`, `SMTP_PASS` e `SMTP_FROM` (`SMTP_CA_FILE` só com CA privada).
- [ ] Saída liberada na porta 587 ou 465; SPF, DKIM e DMARC no domínio do `SMTP_FROM`. Nunca `SMTP_SECURE=none` com servidor remoto, e nunca desligar a verificação do certificado. `SMTP_USER`/`SMTP_PASS` só no `.env` (0600, dono do php-fpm). Opcional: `SMTP_TIMEOUT`.
- [ ] Sem SMTP configurado, o app não grava o link e registra só `mail_not_configured` em `storage/logs/php.log` (via `error_log()`; o `storage/error.log` só recebe exceções): criar um alerta para essa linha nesse arquivo.
- [ ] `storage/logs/mail-dev.log` nunca pode existir em produção.

### Feeds iCal (Airbnb, Booking, Google)
- [ ] Saída HTTP 80/443 e DNS liberados (ver Servidor e PHP); export só pelo `ical_hash` secreto.

## Banco de dados (SQLite)

- [ ] **Preparação de ambiente novo**: `setup.sh` (aplica `database/schema.sql`) → `php scripts/migrate.php` → `load-ceps.php` → seeds (`seed.php`, `seed_massive.php`, `seed-users.php`, `seed-owners.php`). O `migrate.php` falha em banco vazio ("no such table: users", `src/Db.php:175`). Os seeds não precisam de `LOG_SALT` e não precisaram de alteração nesta sprint.
- [ ] **Migração a cada deploy** (SCHEMA_VERSION atual: 2 com o #15): `php scripts/migrate.php` antes de liberar o tráfego, com o usuário do php-fpm (`--force` reaplica). O app só migra numa requisição quando `PRAGMA user_version` < `Db::SCHEMA_VERSION`.
- [ ] **WAL**: o diretório `storage/db/` (não só o arquivo) gravável pelo php-fpm, para `-wal` e `-shm`; o mesmo vale para `storage/logs/activity.sqlite`. Disco local (WAL não funciona em NFS). `busy_timeout = 8000 ms`.
- [ ] **Backup**: `sqlite3 casalivre.sqlite ".backup destino.sqlite"` ou copiar junto com `-wal`/`-shm`.
- [ ] **Regra de desenvolvimento**: aumentar `Db::SCHEMA_VERSION` sempre que mudar o schema, `Db::migrate()` ou os dados base (`database/messages.php`, `Settings::defaults`, cidades, catálogo do ActivityLog).
- [ ] **Dados de auditoria**: `activity_log.actor_id` passa a guardar HMAC; registros antigos mantêm o id numérico até a limpeza única abaixo ou a retenção.

## Jobs agendados (usuário do php-fpm)

- [ ] **Retenção de logs (14 dias, aprovada)**: runit `deploy/runit/casalivre-log-retention` (`cp -r` para `/etc/sv/`, `ln -s` em `/var/service/`, `conf` com `APP_DIR`, `APP_USER`, `RETENTION_DAYS`, `PHP_BIN`) ou cron `15 3 * * * php scripts/log-retention.php`. Cobre `storage/logs/api/api-*`, `activity.sqlite` (DELETE + VACUUM), `error.log`, `calendar-sync.log`, `php.log` e Mongo (`deleteMany`). Confirmar a primeira execução (`sv status` e saída do script).
- [ ] **Sincronização de calendários**: `0 * * * * php /caminho/casalivre/scripts/sync-calendars.php`, com o usuário do php-fpm (grava no `activity.sqlite`). Agora também remove as noites além do horizonte (`trimHorizon()`), antes feito no GET.
- [ ] **Limpeza do rate limit**: `find storage/cache/ratelimit -mmin +120 -delete` no cron (um arquivo por IP, não expiram sozinhos).
- [ ] **Limpeza única**: apagar o histórico já existente em `activity.sqlite` (dados pessoais em `valor_anterior`/`valor_atual` gravados antes da allowlist).

## Permissões e arquivos

- [ ] `storage/logs/api` `0700` e arquivos de log `0600`, do usuário do php-fpm (o app usa `umask 0077`).
- [ ] `storage/cache/ratelimit/` gravável pelo php-fpm; caso contrário, o rate limit fica desligado (sem 429).
- [ ] Não publicar `vendor/`, `tests/`, `phpunit.xml` nem `.phpunit.cache/` no servidor. Em produção, não rodar `composer install` (ou só `--no-dev --no-interaction`); Composer e PHPUnit 11 são apenas para testes.

## Compatibilidade

- [ ] Rotas legadas `/cidades` e `/ceps` removidas (404): clientes externos e caches devem usar `/api/cidades` e `/api/ceps`.
- [ ] Export iCal só pelo `ical_hash` secreto; o link pelo `public_hash` passa a dar 404.

## Homologação (validar antes de produção)

- [ ] `composer install`, `vendor/bin/phpunit` e `CASOS=todos php scripts/run-casos-de-teste.php` (os casos de teste rodam com `APP_ENV=dev`).
- [ ] CI com `tests/standalone/run.php` em PHP 8.2 e 8.4.
- [ ] SSRF do `CalendarSync::fetch` com DNS real e redirects.
- [ ] Importação iCal com feeds reais (Airbnb, Booking, Google).
- [ ] Carga paralela nos `.ics` com php-fpm real, sem 500 "database is locked" (DEF-008).
- [ ] Retenção sob carga do php-fpm, sem sobrar arquivos `*.ret-*`; exclusão real no Mongo.
- [ ] Mascaramento de `feeds[].url` no front separado (quando existir).
- [ ] Cadastro, ativação e redefinição de ponta a ponta com e-mail real, sem link na tela e com o domínio do `APP_URL` mesmo com `Host:` forjado.
- [ ] Tempo de resposta igual para e-mail existente e inexistente no esqueci-senha e no cadastro.
- [ ] Amostra do `activity_log` e do `api-*.log` sem nome, endereço, CEP, e-mail, CPF, telefone, IP completo ou user agent.
