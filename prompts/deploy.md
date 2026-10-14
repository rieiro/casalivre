# Deploy em produção — CasaLivre

Tudo o que é preciso para subir o sistema em produção: servidor, variáveis, banco, jobs, integrações e condições bloqueantes. **Só o Bot Gerente Geral edita este arquivo**; os demais analistas enviam os blocos para consolidação. O acompanhamento do desenvolvimento (PRs, ordem de merge, pendências) fica em `prompts/backlog.md`.

## Condições bloqueantes (não publicar enquanto houver item aberto)

- [ ] Mascaramento de dados pessoais nos logs: RequestLog e ActivityLog por allowlist (PR #12).
- [ ] `verification_link` e link de redefinição fora das respostas da API e das telas em produção (PR #15).
- [ ] Envio de e-mail funcionando, com provedor SMTP definido (PR #17). Decisão do usuário (13/10): o Mailer entra pronto e sem provedor. Até haver provedor, o cadastro e a redefinição de senha continuam fechados em produção.
- [ ] Auditorias `CALENDAR_SYNC` e `CALENDAR_UPDATE` voltando a ser gravadas pelo cron (PR #14).
- [ ] XSS armazenado corrigido em `views/property.php`, `views/search.php` e `views/dashboard.php` (título, descrição, cidade e resumo montados em innerHTML sem escape), (confirmado ao vivo na develop 6b26252; PR #23), com CSP `default-src 'self'; script-src 'self'; img-src 'self' https://tile.openstreetmap.org; object-src 'none'; base-uri 'none'; frame-ancestors 'self'; form-action 'self'`. Nunca `unsafe-inline` nem `unsafe-eval` em scripts. Sem Google Maps (decisão do usuário, 13/10): o código e a variável `GOOGLE_MAPS_API_KEY` são removidos no #23. Tirar a `GOOGLE_MAPS_API_KEY` do `.env` do servidor, porque ela é ignorada. O nginx, o Apache ou a CDN não podem mandar outro CSP nem um X-Frame-Options. Ordem: #21 (SCHEMA 3) e depois o #23 (SCHEMA 4, com os seeds dos dois).
- [ ] Tokens de ativação e redefinição guardados só como hash sha256, sem as colunas `*_link`.
- [ ] Envio de e-mail fora da requisição (fila com worker ou cron) e resposta em tempo constante no esqueci-senha; mensagem "Enviamos um link" só depois de enfileirar com sucesso. Condição para abrir o cadastro e a redefinição em produção.

## Servidor e PHP

- [ ] **nginx + php-fpm** em produção. Nunca `php -S` (`scripts/start.sh`), que escreve a URL completa no stderr.
- [ ] **Log do nginx**: `log_format` sem query string e com `/ical/` e `/api/ical/` redigidos (ou `access_log off` nessas rotas).
- [ ] **IP real atrás de proxy/LB**: configurar `real_ip` no nginx. Rate limit (60 req/min por IP e endpoint) e IP truncado nos logs dependem do `REMOTE_ADDR` real.
- [ ] **PHP 8.4** (única versão testada) com `pdo_sqlite` (ou `pdo_pgsql`), `mbstring`, `openssl` (obrigatória para SMTP com STARTTLS/TLS; sem ela, o envio falha com "Falha no TLS"), `curl`, `gd`, `xml`; `mongodb` se `LOG_DRIVER=mongodb`. Sem `php-curl`, os feeds iCal ficam NOK.
- [ ] **OPcache no php-fpm de produção**: `opcache.enable=1` e `opcache.validate_timestamps=0`, com reload do php-fpm a cada deploy (sem o reload, o código antigo continua em memória).
- [ ] **`zend.exception_ignore_args = On`** no `php.ini` ou pool do php-fpm (o app também força via `ini_set` em `public/index.php`).
- [ ] **Saída HTTP 80/443 e DNS** liberados para os feeds iCal. O `CalendarSync::fetch` recusa IPs internos e não usa proxy (`CURLOPT_NOPROXY='*'`); com saída só via proxy, a importação falha (NOK).
- [ ] **Host fixo**: nginx com `server_name` explícito e `default_server` devolvendo 444 (bloqueia Host forjado nos links de e-mail).

## Limites de requisição (PR #34)

- [ ] **nginx `client_max_body_size 1m`** no geral; maior (ex.: `20m`) só no `location` `^/api/casas/[a-f0-9]{16}/fotos$`.
- [ ] **`fastcgi_request_buffering on`** (padrão; manter ligado).
- [ ] **php.ini:** `post_max_size` e `upload_max_filesize` coerentes com o nginx (ex.: `20M` / `16M`); `zend.exception_ignore_args = On`.
- [ ] **`real_ip`** só atrás de proxy confiável (não aceitar `X-Forwarded-For` de qualquer origem).
- [ ] **`storage/cache/ratelimit`** com permissão `0700` (dono do php-fpm). O diretório se limpa sozinho; o cron de limpeza fica opcional.
- [ ] **`storage/logs/error.log`** com permissão `0600`, retenção ativa, e amostrar o arquivo depois do deploy (sem dado pessoal).

## Variáveis de ambiente

- [ ] **`LOG_SALT` (obrigatório)**: valor aleatório próprio de cada ambiente (`openssl rand -hex 32`), fora do repositório, sem fallback para `APP_KEY`. Sem ele, usuário, e-mail, UA e URLs são omitidos dos logs e `scripts/log-lookup.php` sai com código 2. Trocar o valor depois quebra a busca de registros antigos.
- [ ] **`CORS_ORIGINS`**: origens separadas por vírgula, cada uma exatamente `esquema://host[:porta]` — sem `*`, sem barra final, sem caminho; em produção só `https://`. Comparação exata com o header `Origin`; itens inválidos são ignorados em silêncio; vazio = nenhuma origem externa. Exemplo: `CORS_ORIGINS=https://app.casalivre.com.br,https://admin.casalivre.com.br`.
- [ ] **`CORS_ORIGIN` (legado)**: só é lido se `CORS_ORIGINS` estiver vazio; `CORS_ORIGIN=*` passa a não liberar nada. Renomear para `CORS_ORIGINS` e remover o antigo.
- [ ] **CDN/cache** na frente deve respeitar `Vary: Origin` (respostas enviam `Access-Control-Allow-Credentials: true`).
- [ ] **`APP_ENV=production`** explícito (qualquer valor diferente de `dev`/`local` esconde os links). Nunca usar `dev`/`local` fora do desenvolvimento.
- [ ] **`APP_URL=https://<domínio>`** obrigatório (https, sem barra final, query ou credenciais): os links de ativação e redefinição saem dele; sem ele, o app dá erro em produção.
- [ ] **`ACTIVITY_DB_PATH`**: só para testes, sem valor em produção.
- [ ] `APP_ENV` e `APP_URL` documentados no `.env.example`.

- [ ] **nginx `client_max_body_size`:** ver seção Limites de requisição (PR #34).
- [ ] **php.ini:** `max_input_vars=1000` e `max_input_nesting_level=64` (os padrões; não aumentar).
- [ ] **Cache de assets:** com o #23, os assets vão em `app.css?v=19`, `app.js?v=18`, `safe-dom.js?v=3`, `property-form.js?v=3`, `map.js?v=2` e `form-data.js?v=1` (este só no formulário de imóvel); limpar o cache ou a CDN no deploy.

- [ ] **Fotos** servidas só de `/uploads/` (nginx: `location /uploads/` sem execução de PHP e com `add_header X-Content-Type-Options nosniff`). Depois do deploy, revisar as fotos já gravadas: `SELECT id, path FROM photos WHERE path NOT LIKE '/uploads/%' OR path LIKE '%..%'`.

## Integrações
- [ ] SMTP: o Gmail (casalivre95@gmail.com, senha de app) é **só provisório para desenvolvimento**, validado pelo usuário em 14/10 (cadastro e redefinição de senha chegam). Em produção, usar um provedor transacional com SPF, DKIM e DMARC no domínio.

### E-mail (SMTP, PR #17)
- [ ] `SMTP_HOST`, `SMTP_PORT`, `SMTP_SECURE`, `SMTP_USER`, `SMTP_PASS` e `SMTP_FROM` (`SMTP_CA_FILE` só com CA privada).
- [ ] Saída liberada na porta 587 ou 465; SPF, DKIM e DMARC no domínio do `SMTP_FROM`. Nunca `SMTP_SECURE=none` com servidor remoto, e nunca desligar a verificação do certificado. `SMTP_USER`/`SMTP_PASS` só no `.env` (0600, dono do php-fpm). Opcional: `SMTP_TIMEOUT`.
- [ ] Sem SMTP configurado, o app não grava o link e registra só `mail_not_configured` em `storage/logs/php.log` (via `error_log()`; o `storage/error.log` só recebe exceções): criar um alerta para essa linha nesse arquivo.
- [ ] `storage/logs/mail-dev.log` nunca pode existir em produção.

### Feeds iCal (Airbnb, Booking, Google)
- [ ] Saída HTTP 80/443 e DNS liberados (ver Servidor e PHP); export só pelo `ical_hash` secreto.

## Transferência de dados e cache (nginx)
Origem: análise do Bot Segurança e Arquitetura (develop e425cbd).
- [ ] `http2 on;` (TLS 1.2+).
- [ ] /assets/: `Cache-Control "public, max-age=31536000, immutable"` e etag on. Sempre subir o `?v=` ao mudar JS/CSS.
- [ ] /uploads/: sem PHP (`location ~ \.php$ { return 404; }`), cache immutable de 1 ano, nosniff e etag on. Ao remover uma foto, apagar o arquivo e fazer purge.
- [ ] gzip + brotli (`ngx_brotli`, de preferência `gzip_static`/`brotli_static`) só em css, js, json e svg. Não comprimir `text/html` (BREACH). `gzip_vary on;`
- [ ] Rotas autenticadas com `private, no-store`, sem o nginx sobrescrever.
- [ ] Nenhum cache de resposta com Cookie, Set-Cookie ou Authorization. Chave com `$host` fixo e cache só em /assets/ e /uploads/.
- [ ] `client_max_body_size` de 1m na API, e no upload igual ao limite do app (alvo: 10m).
- [ ] Instalar o pacote `libvips`. Depois do PR de fotos, rodar o reprocessamento (WebP 480/960/1600, sem EXIF/GPS) e tirar os originais de public/.
- [ ] OSM: atribuição "© OpenStreetMap contributors" com link em toda página com mapa (falta em /busca/mapa).
- [ ] OSM: antes de tráfego alto, proxy_cache de tiles (≥ 7 dias, com User-Agent de contato) e CSP `img-src 'self'`, ou tiles próprios.
- [ ] CDN (se usada): só /assets/ e /uploads/, num subdomínio sem cookies, com HTML e API na origem. Exige DPA (ela termina o TLS), não pode reescrever CSP nem X-Frame-Options, e precisa de um processo de purge.

## Sprint 2: tokens, API pública, fotos e mapas (PRs #35, #36, #39 e #40)

### Variáveis
- [ ] **`GEO_SALT` (obrigatório)**: valor aleatório com pelo menos 32 caracteres (`php -r "echo bin2hex(random_bytes(32));"`), próprio de cada ambiente e fora do repositório. Sem ele, ou com menos de 32 caracteres, o app se recusa a subir em produção (500 genérico em todas as rotas e `geo_salt_invalid` no log, sem o valor). O `scripts/setup.sh` mantém um `GEO_SALT` existente ou gera um. Trocar o valor muda a posição de todos os pinos públicos.
- [ ] **`APP_ENV`** nunca `dev`/`local` em produção (em dev o `GEO_SALT` tem valor fixo e público e a rota PHP de tiles fica aberta).
- [ ] **`MAP_TILES_FILE`** não definido em produção.

### Banco e tokens (#35, #39)
- [ ] Recriar o banco com `scripts/setup.sh` (SCHEMA_VERSION 7; sem script de migração).
- [ ] Tokens de ativação e redefinição já emitidos deixam de valer; quem tiver link pendente pede outro.
- [ ] Redefinir a senha desconecta o usuário em todos os dispositivos.

### Fotos (#39)
- [ ] PHP com **GD com suporte a WebP** (`php -r 'var_dump(gd_info()["WebP Support"]);'` deve dar `true`); o worker não roda sem ela. Extensão `exif` opcional (corrige a orientação).
- [ ] `storage/fotos/originais` com permissão `0700`, dono do php-fpm, fora do webroot; lock do worker `0600`; WebP em `public/uploads/fotos/` com `0644`.
- [ ] nginx: `location /uploads/` sem execução de PHP e com `add_header X-Content-Type-Options nosniff`.
- [ ] Cron do worker a cada minuto, com o usuário do php-fpm: `* * * * * php /srv/casalivre/scripts/image-worker.php`, com `memory_limit` de pelo menos `256M` no PHP CLI.
- [ ] Depois do deploy, o worker migra as fotos antigas de `/uploads/users/%` em lotes de 100 por minuto e apaga cada original antigo depois de copiar; ao final, a pasta `public/uploads/users/` pode ser removida.
- [ ] Limite de 25 MP por imagem (`MAX_PIXELS`). GIF não é mais aceito.

### Mapa PMTiles (#40)
- [ ] Copiar o bloco `location = /tiles/brasil.pmtiles` de `prompts/mapa-pmtiles.md` para antes do `location /` do PHP, repetindo nele HSTS (com o **mesmo valor** do bloco `server` de produção; o do PR é suposição), `X-Content-Type-Options nosniff`, `X-Frame-Options` e `Referrer-Policy` (o `add_header` no `location` descarta os do `server`).
- [ ] Arquivo `/srv/casalivre/storage/tiles/brasil.pmtiles`, dono root, modo `0644`, trocado com `mv` atômico de um `.new`.
- [ ] Sem gzip/brotli nesse arquivo (quebra o Range). Se houver CDN, `Range` na chave de cache.
- [ ] Validar com `nginx -t` antes do reload (o bloco não foi validado no PR).

#### Desempenho do mapa
- [ ] Usar um único `brasil.pmtiles`. O navegador lê só os tiles visíveis via HTTP Range, então um arquivo grande não deixa o mapa lento; custa apenas espaço em disco e tempo de geração.
- [ ] Não dividir em vários arquivos regionais: como fontes separadas, eles se sobrepõem nas divisas (rótulos duplicados e emendas nas ruas); trocar de arquivo por região deixa o mapa sem base fora da região.
- [ ] O que acelera: cache no nginx (`Cache-Control`, `ETag`), CDN com `Range` na chave de cache, HTTP/2 ligado e `maxzoom` limitado (13 ou 14) na geração, o que reduz o arquivo e o número de tiles.
- [ ] Regenerar periodicamente a partir do build do Protomaps e trocar com `mv` atômico; um arquivo novo muda o `ETag`.

### Rate limit nos formulários web (#35, #39)
- [ ] `POST /cadastro` (10/h), `POST /senha/esqueci` (5/15 min) e o envio de fotos pela web (30/10 min, somado com a API) usam `REMOTE_ADDR`: atrás de proxy/CDN, `real_ip` é obrigatório; senão todos os visitantes dividem o mesmo limite.

## Banco de dados (SQLite)

- [ ] **Preparação de ambiente novo**: `setup.sh` (aplica `database/schema.sql`) → `php scripts/migrate.php` → `load-ceps.php` → seeds (`seed.php`, `seed_massive.php`, `seed-users.php`, `seed-owners.php`). O `migrate.php` falha em banco vazio ("no such table: users", `src/Db.php:175`). Os seeds não precisam de `LOG_SALT` e não precisaram de alteração nesta sprint.
- [ ] **Banco em desenvolvimento** (decisão do usuário, 14/10): sem scripts de migração. Se o schema mudar, recriar o banco (`setup.sh` + seeds). Continua obrigatório aumentar `Db::SCHEMA_VERSION`.
- [ ] **Migração a cada deploy** (só depois da primeira subida) (SCHEMA_VERSION 3 com o #21 e 4 com o #23: só seeds de tradução, idempotente): `php scripts/migrate.php` antes de liberar o tráfego, com o usuário do php-fpm (`--force` reaplica). O app só migra numa requisição quando `PRAGMA user_version` < `Db::SCHEMA_VERSION`.
- [ ] **WAL**: o diretório `storage/db/` (não só o arquivo) gravável pelo php-fpm, para `-wal` e `-shm`; o mesmo vale para `storage/logs/activity.sqlite`. Disco local (WAL não funciona em NFS). `busy_timeout = 8000 ms`.
- [ ] **Backup**: `sqlite3 casalivre.sqlite ".backup destino.sqlite"` ou copiar junto com `-wal`/`-shm`.
- [ ] **Regra de desenvolvimento**: aumentar `Db::SCHEMA_VERSION` sempre que mudar o schema, `Db::migrate()` ou os dados base (`database/messages.php`, `Settings::defaults`, cidades, catálogo do ActivityLog).
- [ ] **Dados de auditoria**: `activity_log.actor_id` passa a guardar HMAC; registros antigos mantêm o id numérico até a limpeza única abaixo ou a retenção.

## Fila de e-mail (PR #32)
- [ ] Cron do worker a cada minuto, com o usuário do serviço: `* * * * * php /srv/casalivre/scripts/mail-worker.php` (a saída vai para o log do serviço e tem só contadores).
- [ ] `storage/` com permissão 0700 e dono do serviço; `storage/mail-worker.lock` com 0600.
- [ ] `QUEUE_DRIVER=sqlite` (no futuro, `redis` com a extensão `phpredis`) e `MAIL_QUEUE_RETENTION_DAYS=7` ou menos.
- [ ] Produção com `SMTP_HOST` configurado. Sem ele, nada entra na fila e o php.log mostra `mail_not_configured`: monitorar esse alerta.
- [ ] Alarme se o worker parar, ou seja, fila `pending` crescendo ou sem purga.
- [ ] Backups do banco tratados como sigilosos.

## Jobs agendados (usuário do php-fpm)

- [ ] **Retenção de logs (14 dias, aprovada)**: runit `deploy/runit/casalivre-log-retention` (`cp -r` para `/etc/sv/`, `ln -s` em `/var/service/`, `conf` com `APP_DIR`, `APP_USER`, `RETENTION_DAYS`, `PHP_BIN`) ou cron `15 3 * * * php scripts/log-retention.php`. Cobre `storage/logs/api/api-*`, `activity.sqlite` (DELETE + VACUUM), `error.log`, `calendar-sync.log`, `php.log` e Mongo (`deleteMany`). Confirmar a primeira execução (`sv status` e saída do script).
- [ ] **Sincronização de calendários**: `0 * * * * php /caminho/casalivre/scripts/sync-calendars.php`, com o usuário do php-fpm (grava no `activity.sqlite`). Agora também remove as noites além do horizonte (`trimHorizon()`), antes feito no GET.
- [ ] **Limpeza do rate limit (opcional):** com o #34 o diretório se limpa sozinho; se quiser, `find storage/cache/ratelimit -mmin +120 -delete` no cron.
- [ ] **Limpeza única**: apagar o histórico já existente em `activity.sqlite` (dados pessoais em `valor_anterior`/`valor_atual` gravados antes da allowlist).

## Permissões e arquivos

- [ ] `storage/logs/api` `0700` e arquivos de log `0600`, do usuário do php-fpm (o app usa `umask 0077`).
- [ ] `storage/cache/ratelimit/` gravável pelo php-fpm; caso contrário, o rate limit fica desligado (sem 429).
- [ ] Não publicar `vendor/`, `tests/`, `phpunit.xml` nem `.phpunit.cache/` no servidor. Em produção, não rodar `composer install` (ou só `--no-dev --no-interaction`); Composer e PHPUnit 11 são apenas para testes.

## Compatibilidade

- [ ] Rotas legadas `/cidades` e `/ceps` removidas (404): clientes externos e caches devem usar `/api/cidades` e `/api/ceps`.
- [ ] Export iCal só pelo `ical_hash` secreto; o link pelo `public_hash` passa a dar 404.

## Homologação (suspensa até a primeira subida em produção)
> Decisão do usuário (14/10): enquanto não houver produção, não há homologação por lote. Este checklist roda uma vez, num ambiente de teste, poucos dias antes da primeira subida real.


- [ ] `composer install`, `vendor/bin/phpunit` e `CASOS=todos php scripts/run-casos-de-teste.php` (os casos de teste rodam com `APP_ENV=dev`).
- [ ] CI com `tests/standalone/run.php` em PHP 8.4.
- [ ] SSRF do `CalendarSync::fetch` com DNS real e redirects.
- [ ] Importação iCal com feeds reais (Airbnb, Booking, Google).
- [ ] Carga paralela nos `.ics` com php-fpm real, sem 500 "database is locked" (DEF-008).
- [ ] Retenção sob carga do php-fpm, sem sobrar arquivos `*.ret-*`; exclusão real no Mongo.
- [ ] Mascaramento de `feeds[].url` no front separado (quando existir).
- [ ] Cadastro, ativação e redefinição de ponta a ponta com e-mail real, sem link na tela e com o domínio do `APP_URL` mesmo com `Host:` forjado.
- [ ] Tempo de resposta igual para e-mail existente e inexistente no esqueci-senha e no cadastro.
- [ ] Amostra do `activity_log` e do `api-*.log` sem nome, endereço, CEP, e-mail, CPF, telefone, IP completo ou user agent.
- [ ] Editar imóvel com quartos e camas existentes salva sem o erro "pelo menos uma cama" (issue #20), e o resumo por quarto se atualiza.
- [ ] Título `<img src=x onerror=alert(1)>` não executa na página pública, na busca nem no painel.
- [ ] As miniaturas do OSM carregam na busca, sem violação de CSP no console; o mapa do OSM funciona.
- [ ] Foto com caminho fora de `/uploads/` vira placeholder.
