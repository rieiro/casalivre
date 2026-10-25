# Deploy em produção — CasaLivre

Tudo o que é preciso para subir o sistema em produção: servidor, variáveis, banco, jobs, integrações e condições bloqueantes. **Só o Bot Gerente Geral edita este arquivo**; os demais analistas enviam os blocos para consolidação. O acompanhamento do desenvolvimento (PRs, ordem de merge, pendências) fica em `prompts/backlog.md`.

## Condições bloqueantes (não publicar enquanto houver item aberto)

- [ ] Mascaramento de dados pessoais nos logs: RequestLog e ActivityLog por allowlist (PR #12).
- [ ] `verification_link` e link de redefinição fora das respostas da API e das telas em produção (PR #15).
- [ ] Envio de e-mail funcionando, com provedor SMTP definido (PR #17). Decisão do usuário (13/10): o Mailer entra pronto e sem provedor. Até haver provedor, o cadastro e a redefinição de senha continuam fechados em produção.
- [ ] Auditorias `CALENDAR_SYNC` e `CALENDAR_UPDATE` voltando a ser gravadas pelo cron (PR #14).
- [x] (Corrigido no #23, confirmado pela Segurança na develop 34a62a2.) XSS armazenado corrigido em `views/property.php`, `views/search.php` e `views/dashboard.php` (título, descrição, cidade e resumo montados em innerHTML sem escape), (confirmado ao vivo na develop 6b26252; PR #23), com a CSP atual (igual em todas as páginas; referência: `tests/standalone/snapshots/csp.txt` e `xss_csp_test`): `default-src 'self'; script-src 'self'; style-src 'self'; style-src-attr 'none'; img-src 'self'; connect-src 'self'; worker-src 'self'; object-src 'none'; base-uri 'none'; frame-ancestors 'none'; form-action 'self'`, mais `X-Frame-Options: DENY`. Nunca `unsafe-inline` nem `unsafe-eval` em scripts. Sem Google Maps (decisão do usuário, 13/10): o código e a variável `GOOGLE_MAPS_API_KEY` são removidos no #23. Tirar a `GOOGLE_MAPS_API_KEY` do `.env` do servidor, porque ela é ignorada. O nginx, o Apache ou a CDN não podem mandar outro CSP nem um X-Frame-Options. Ordem: #21 (SCHEMA 3) e depois o #23 (SCHEMA 4, com os seeds dos dois).
- [ ] Tokens de ativação e redefinição guardados só como hash sha256, sem as colunas `*_link`.
- [ ] Envio de e-mail fora da requisição (fila com worker ou cron) e resposta em tempo constante no esqueci-senha; mensagem "Enviamos um link" só depois de enfileirar com sucesso. Condição para abrir o cadastro e a redefinição em produção.

## Postgres, migrate and dev environment (#95–#118)
Moved from the Gerente's notes (16/10). Superseded #99 notes (money_unit, price sums, stopped traffic) are left out.

### Clean deploy (Cori, #95/#98)
- [ ] On a new server, `php -d extension=bz2 scripts/migrate.php` creates the folders (#98) and seeds places, including `database/places_neighborhoods.json.bz2`. Load the PG schema first (see below). Still check that `storage/mail`, `storage/sessions` and `public/uploads/users` exist. Omit `-d extension=bz2` only when bz2 is already enabled in that php.ini.
- [ ] Install `php-bz2`. Production php.ini does not enable the extension (same as `ops/dev-env`; see `ops/dev-env/db-setup.sh` and `ops/dev-env/README.md`). The package supplies the `.so`; each CLI run that opens a bz2 artifact passes `-d extension=bz2` unless that php.ini already loads bz2.
  - `scripts/migrate.php` opens `database/places_neighborhoods.json.bz2` on a seed run. `Db::migrateStep` calls `Db::migrate` → `CityRepository::ensure` when `schema_meta` is absent (version 0, right after `psql -f database/schema.pgsql.sql`) or the run is `--force`. A stored version from 1 up to but not including `Db::SCHEMA_VERSION` aborts with `SchemaDesatualizado` before the seed. On that new database the city count is under 5000, so `ensure` calls `seedNeighborhoods`. `CityRepository::ensureNeighborhoods` is the other opener: once at least 5000 cities are seeded, a later `ensure` opens the file while the neighborhood count is below 61543 (`NEIGHBORHOOD_COUNT`). `CityRepository::neighborhoodRows` (`conta/Geo/CityRepository.php`) uses `bzopen` only. This path has no python3 fallback; the error tells you to rerun with `php -d extension=bz2`. Command: `php -d extension=bz2 scripts/migrate.php`.
  - `scripts/refresh-settings-cache.php` calls `CityRepository::refresh` → `ensure` (same neighborhood rule, including the top-up under 61543) and may open `database/cep.csv.bz2` from `CepRepository::ensureLoaded`. Command: `php -d extension=bz2 scripts/refresh-settings-cache.php`.
  - `scripts/load-ceps.php` opens `database/cep.csv.bz2`. `CepRepository::openSource` (`conta/Geo/CepRepository.php`) uses the bz2 extension when it is loaded, and otherwise python3 (`CEP_PYTHON`). `ops/dev-env/db-setup.sh` runs `php8.4 -d extension=bz2 scripts/load-ceps.php`. Production command: `php -d extension=bz2 scripts/load-ceps.php`. With neither the extension nor python3, the script fails with a clear error.
  - `scripts/start.sh` runs `scripts/load-ceps.php` with no `-d extension=bz2` and does not run `scripts/migrate.php`, so that process does not open `database/places_neighborhoods.json.bz2`. The CEP load from `scripts/start.sh` succeeds when that PHP already has bz2, or when python3 is on `PATH`. Production does not use `scripts/start.sh` (`php -S`).
  - `ops/dev-env/db-setup.sh` runs `php8.4 scripts/seed.php` with no `-d`. `scripts/seed.php`, `scripts/seed_massive.php`, `scripts/seed-users.php` and `scripts/seed-owners.php` do not open the bz2 artifacts. `ops/dev-env/test-pg.sh` passes `-d extension=bz2` to standalone tests: the dev php.ini matches production, and the neighborhood seed needs the extension, as load-ceps does.
- [ ] Tiles permissions: with `storage/` at 0711 owned by casalivre, nginx cannot read `storage/tiles/brasil.pmtiles`. Set the tiles path permissions or move the tiles out of `storage/`.

### Migrate and schema (#99, #100, SCHEMA 19; #210 SCHEMA 22)
- [ ] No data migration (docs/decisions.md, 2026-10-21): on a new PG, run `psql -f database/schema.pgsql.sql` BEFORE `php -d extension=bz2 scripts/migrate.php` (seeds and version only), then the seeds. This migrate decompresses `database/places_neighborhoods.json.bz2`. Omit `-d extension=bz2` only when bz2 is already enabled in php.ini.
- [ ] (#210, SCHEMA 22) Recreate only, no migration: `psql -f database/schema.pgsql.sql` then `php -d extension=bz2 scripts/migrate.php`.
- [ ] Every deploy: run `php -d extension=bz2 scripts/migrate.php` before releasing traffic, unless bz2 is already enabled in php.ini. The flag is required when that run seeds (new database or `--force`). A later run that finds `schema_meta` already at `Db::SCHEMA_VERSION` does not open the bz2 file. Exit codes: 0 ok, 1 version below expected, 2 failure (message on STDERR). Anything other than 0: do not release traffic.
- [ ] On PG, web and workers do not migrate; they only check `schema_meta`. If the DB is behind, the site answers 503 (Retry-After: 60). Never delete the `schema_meta` table.
- [ ] migrate.php uses `pg_advisory_lock(718331201)` in one transaction; concurrent runs wait. If PgBouncer is added, migrate must connect directly to PG or use `pg_advisory_xact_lock` (BD).
- [ ] `properties.user_id` FK is RESTRICT (PG raises SQLSTATE 23001, not 23503).
- [ ] VPS ops (BD): `ALTER ROLE app SET timezone='UTC'` so `created_at` is stored in UTC.
- [ ] Production PostgreSQL config: `random_page_cost = 1.1` (Rafael approved 2026-10-25 on BD's evidence, #218). The `ops/dev-env` side is a separate code PR (Programador).
- [ ] PG p50 must be measured; ADR 001 targets after the search PR: p50 < 150 ms and p95 < 500 ms with 10 concurrent users (final target p95 < 300 ms).

### Dev environment and Valkey (#109–#118, Segurança/Arquitetura)
- [ ] Blocking: no environment outside dev goes up before P0-4 (definitive Valkey ACL, `valkey.sock` 0660) is merged (merged in #118; confirm production parity).
- [ ] Production parity for P0-2 (Segurança, #106): socket, Valkey ACL, `clear_env=yes`.
- [ ] #112: in production the FPM uid needs its own `pg_ident` line (map casalivre).
- [ ] #112 (Segurança): `valkey-admin.secret` 0400 root:root; only the app password file is root:casalivre 0440. Test: as the FPM user, `cat valkey-admin.secret` fails. In production the admin secret lives in a vault.
- [ ] #112: PUBLISH is not allowlisted in the Valkey ACL; any pub/sub use needs Segurança review.
- [ ] #113: photos left in failed state by the push bug must be re-uploaded.

## Servidor e PHP

- [ ] **nginx + php-fpm** em produção. Nunca `php -S` (`scripts/start.sh`), que escreve a URL completa no stderr.
- [ ] **Log do nginx**: `log_format` sem query string e com `/ical/` e `/api/ical/` redigidos (ou `access_log off` nessas rotas).
- [ ] **IP real atrás de proxy/LB**: configurar `real_ip` no nginx. Rate limit (60 req/min por IP e endpoint) e IP truncado nos logs dependem do `REMOTE_ADDR` real.
- [ ] **PHP 8.4** (única versão testada) com `pdo_pgsql`, `mbstring`, `openssl` (obrigatória para SMTP com STARTTLS/TLS; sem ela, o envio falha com "Falha no TLS"), `curl`, `gd`, `xml`. Sem `php-curl`, os feeds iCal ficam NOK.
- [ ] **`php-bz2`** on the production install. php.ini does not enable it; CLI commands that open the bz2 artifacts pass `-d extension=bz2` (Clean deploy, above).
- [ ] **OPcache no php-fpm de produção**: `opcache.enable=1` e `opcache.validate_timestamps=0`, com reload do php-fpm a cada deploy (sem o reload, o código antigo continua em memória).
- [ ] **`zend.exception_ignore_args = On`** no `php.ini` ou pool do php-fpm (o app também força via `ini_set` em `public/index.php`).
- [ ] **Saída HTTPS (443) e DNS** liberados para os feeds iCal (só https desde o #91; a porta 80 não é necessária). O `CalendarSync::fetch` recusa IPs internos e não usa proxy (`CURLOPT_NOPROXY='*'`); com saída só via proxy, a importação falha (NOK).
- [ ] **Host fixo**: nginx com `server_name` explícito e `default_server` devolvendo 444 (bloqueia Host forjado nos links de e-mail).

## Limites de requisição (PR #34)

- [ ] **nginx `client_max_body_size 1m`** no geral; `20m` só nas rotas de fotos, web e API: `location ~ ^/(api/casas|imoveis)/[a-f0-9]{16}/fotos$` (ver Sprint 3).
- [ ] **`fastcgi_request_buffering on`** (padrão; manter ligado).
- [ ] **php.ini:** `post_max_size` e `upload_max_filesize` coerentes com o nginx (ex.: `20M` / `16M`); `zend.exception_ignore_args = On`.
- [ ] **`real_ip`** só atrás de proxy confiável (não aceitar `X-Forwarded-For` de qualquer origem).
- [ ] **`storage/logs/error.log`** com permissão `0600`, retenção ativa, e amostrar o arquivo depois do deploy (sem dado pessoal).

## Variáveis de ambiente

- [ ] **`LOG_SALT` (obrigatório)**: valor aleatório próprio de cada ambiente (`openssl rand -hex 32`), fora do repositório, sem fallback para `APP_KEY`. Sem ele, usuário, e-mail, UA e URLs são omitidos dos logs e `scripts/log-lookup.php` sai com código 2. Trocar o valor depois quebra a busca de registros antigos.
- [ ] **`CORS_ORIGINS`**: origens separadas por vírgula, cada uma exatamente `esquema://host[:porta]` — sem `*`, sem barra final, sem caminho; em produção só `https://`. Comparação exata com o header `Origin`; itens inválidos são ignorados em silêncio; vazio = nenhuma origem externa. Exemplo: `CORS_ORIGINS=https://app.casalivre.com.br,https://admin.casalivre.com.br`.
- [ ] **`CORS_ORIGIN` (legado)**: só é lido se `CORS_ORIGINS` estiver vazio; `CORS_ORIGIN=*` passa a não liberar nada. Renomear para `CORS_ORIGINS` e remover o antigo.
- [ ] **CDN/cache** na frente deve respeitar `Vary: Origin` (respostas enviam `Access-Control-Allow-Credentials: true`).
- [ ] **`APP_ENV=production`** explícito (qualquer valor diferente de `dev`/`local` esconde os links). Nunca usar `dev`/`local` fora do desenvolvimento.
- [ ] **`APP_URL=https://<domínio>`** obrigatório (https, sem barra final, query ou credenciais): os links de ativação e redefinição saem dele; sem ele, o app dá erro em produção.
- [ ] `APP_ENV` e `APP_URL` documentados no `.env.example`.

- [ ] **nginx `client_max_body_size`:** ver seção Limites de requisição (PR #34).
- [ ] **php.ini:** `max_input_vars=1000` e `max_input_nesting_level=64` (os padrões; não aumentar).
- [ ] **Cache de assets:** os assets saem com hash gerado pelo `scripts/build-assets.php` (Sprint 3, Etapa 0). Não existe mais `?v=` manual; ver a seção Sprint 3.

- [ ] **Fotos** servidas só de `/uploads/` (nginx: `location /uploads/` sem execução de PHP e com `add_header X-Content-Type-Options nosniff`). Depois do deploy, revisar as fotos já gravadas: `SELECT id, path FROM photos WHERE path NOT LIKE '/uploads/%' OR path LIKE '%..%'`.

## Integrações
- [ ] SMTP: o Gmail (casalivre95@gmail.com, senha de app) é **só provisório para desenvolvimento**, validado pelo usuário em 14/10 (cadastro e redefinição de senha chegam). Em produção, usar um provedor transacional com SPF, DKIM e DMARC no domínio.

### E-mail (SMTP, PR #17)
- [ ] `SMTP_HOST`, `SMTP_PORT`, `SMTP_SECURE`, `SMTP_USER`, `SMTP_PASS` e `SMTP_FROM` (`SMTP_CA_FILE` só com CA privada).
- [ ] Saída liberada na porta 587 ou 465; SPF, DKIM e DMARC no domínio do `SMTP_FROM`. Nunca `SMTP_SECURE=none` com servidor remoto, e nunca desligar a verificação do certificado. `SMTP_USER`/`SMTP_PASS` só no `.env` (0600, dono do php-fpm). Opcional: `SMTP_TIMEOUT`.
- [ ] Sem SMTP configurado, o app não grava o link e registra só `mail_not_configured` em `storage/logs/php.log` (via `error_log()`; o `storage/error.log` só recebe exceções): criar um alerta para essa linha nesse arquivo.
- [ ] `storage/logs/mail-dev.log` nunca pode existir em produção (é gerado só pelo mail-worker em modo dev, usado pelos scripts de teste).

### Feeds iCal (Airbnb, Booking, Google)
- [ ] Saída HTTPS (443) e DNS liberados (ver Servidor e PHP); export só pelo `ical_hash` secreto.

## Transferência de dados e cache (nginx)
Origem: análise do Bot Segurança e Arquitetura (develop e425cbd).
- [ ] `http2 on;` (TLS 1.2+).
- [ ] /assets/: `Cache-Control "public, max-age=31536000, immutable"` e etag on. As versões vêm do manifesto gerado pelo build (ver Sprint 3), sem `?v=` manual.
- [ ] /uploads/: sem PHP (`location ~ \.php$ { return 404; }`), cache immutable de 1 ano, nosniff e etag on. Ao remover uma foto, apagar o arquivo e fazer purge.
- [ ] gzip + brotli (`ngx_brotli`, de preferência `gzip_static`/`brotli_static`) só em css, js, json e svg. Não comprimir `text/html` (BREACH). `gzip_vary on;`
- [ ] Rotas autenticadas com `private, no-store`, sem o nginx sobrescrever.
- [ ] Nenhum cache de resposta com Cookie, Set-Cookie ou Authorization. Chave com `$host` fixo e cache só em /assets/ e /uploads/.
- [ ] `client_max_body_size` de 1m em todas as rotas; 20m só nas rotas de fotos (`/api/casas/<h>/fotos` e `/imoveis/<h>/fotos`).
- [ ] Fotos com GD e WebP (desde o #39): o image-worker gera WebP em 320/800/1920, sem EXIF/GPS, com o original fora de public/ (ver Sprint 2, Fotos).
- [ ] OSM: atribuição "© OpenStreetMap contributors" com link em toda página com mapa (falta em /busca/mapa).
- [ ] CDN (se usada): só /assets/ e /uploads/, num subdomínio sem cookies, com HTML e API na origem. Exige DPA (ela termina o TLS), não pode reescrever CSP nem X-Frame-Options, e precisa de um processo de purge.

## Sprint 2: tokens, API pública, fotos e mapas (PRs #35, #36, #39 e #40)

### Variáveis
- [ ] **`GEO_SALT` (obrigatório)**: valor aleatório com pelo menos 32 caracteres (`php -r "echo bin2hex(random_bytes(32));"`), próprio de cada ambiente e fora do repositório. Sem ele, ou com menos de 32 caracteres, o app se recusa a subir em produção (500 genérico em todas as rotas e `geo_salt_invalid` no log, sem o valor). O `scripts/setup.sh` mantém um `GEO_SALT` existente ou gera um. Trocar o valor muda a posição de todos os pinos públicos. **Nunca trocar o `GEO_SALT` em produção, exceto se ele vazar:** quem guardou a posição antiga e vê a nova passa a ter dois pontos sorteados em volta da casa e, cruzando os dois, chega mais perto do endereço real. Se for preciso trocar por vazamento, saiba que as posições antigas continuam valendo para quem as guardou.
- [ ] **`APP_ENV`** nunca `dev`/`local` em produção (em dev o `GEO_SALT` tem valor fixo e público e a rota PHP de tiles fica aberta).
- [ ] Produção: `APP_ENV=production` no `.env`, e nenhum `APP_ENV=dev`/`local` no ambiente do php-fpm (`env[...]` no pool), nos serviços runit nem no crontab dos workers. A variável do ambiente vence o `.env` (#40, #45).
- [ ] **`MAP_TILES_FILE`** não definido em produção.

### Banco e tokens (#35, #39)
- [ ] Redefinir a senha desconecta o usuário em todos os dispositivos.

### Fotos (#39)
- [ ] PHP com **GD com suporte a WebP** (`php -r 'var_dump(gd_info()["WebP Support"]);'` deve dar `true`); o worker não roda sem ela. Extensão `exif` opcional (corrige a orientação).
- [ ] `storage/fotos/originais` com permissão `0700`, dono do php-fpm, fora do webroot; lock do worker `0600`; WebP em `public/uploads/fotos/` com `0644`.
- [ ] nginx: `location /uploads/` sem execução de PHP e com `add_header X-Content-Type-Options nosniff`.
- [ ] Cron do worker a cada minuto, com o usuário do php-fpm: `* * * * * php /srv/casalivre/scripts/image-worker.php`, com `memory_limit` de pelo menos `256M` no PHP CLI.
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
  - nginx: o bloco `location = /tiles/brasil.pmtiles` de `prompts/mapa-pmtiles.md` (ver item "Copiar o bloco" em Mapa PMTiles (#40)) já traz `etag on` e `Cache-Control "public, max-age=86400" always`. Sem `immutable`: o nome é fixo e a troca do arquivo muda o `ETag`.
  - Geração: `pmtiles extract "https://build.protomaps.com/$BUILD" brasil.pmtiles.new --bbox=-74.0,-33.8,-28.6,5.3 --maxzoom=13`. Usar `--maxzoom=14` só se precisar de mais detalhe; o arquivo fica bem maior.
  - CDN (se houver): `Range` na chave de cache de `/tiles/brasil.pmtiles`, respeitando o `Cache-Control` da origem e sem compressão nesse caminho; sem essa opção, não cachear o caminho na CDN. Purge ao trocar o arquivo.
- [ ] Regenerar periodicamente a partir do build do Protomaps e trocar com `mv` atômico; um arquivo novo muda o `ETag`.
  - Por quê: o arquivo é uma foto do OSM na data do build (ruas, nomes e lugares novos não aparecem depois). Regenerar a cada 3 a 6 meses, ou antes da alta temporada. A data dos dados está em `planetiler:osm:osmosisreplicationtime` no `pmtiles show --metadata`.
  - Compatibilidade: `public/assets/map/style-light.json` foi gerado de `@protomaps/basemaps` 5.7.2 (ver `public/assets/vendor/README.md`), que espera tiles da versão 4 do schema Protomaps. Antes do `mv`, conferir em `pmtiles show --metadata <arquivo>` o campo `version` (ex.: `"version": "4.15.2"`; não confundir com `planetiler:version`, que é a versão da ferramenta). Se a major mudar de 4, não trocar o arquivo: regenerar o estilo com um `@protomaps/basemaps` compatível e trocar os dois no mesmo PR.
  - Integridade antes do `mv`: baixar ou extrair só por `https://` (`https://build.protomaps.com/AAAAMMDD.pmtiles`). O Protomaps publica em `https://build-metadata.protomaps.dev/builds.json`, por build, o `size`, o `md5sum` (base64) e o `b3sum` (BLAKE3) do arquivo planeta inteiro; não publica sha256, e o `ETag` do download é de upload multipart (sufixo `-N`), não um hash do conteúdo. Esses hashes só servem para conferir quem baixa o planeta inteiro (~139 GB).
  - O `pmtiles extract` lê só partes do planeta e gera um arquivo novo, cujo hash não é publicado. Por isso, gerar num arquivo temporário no mesmo disco e, antes do `mv`: rodar `pmtiles verify <arquivo>` (confere a estrutura do arquivo, não o conteúdo de cada tile; existe no go-pmtiles v1.31.2) e `pmtiles show <arquivo>` (conferir que os limites, o bbox do Brasil, e o zoom máximo são os esperados). Se algo falhar, não trocar.
  - Guardar o `sha256sum` do arquivo gerado (junto com a data do build de origem) para conferir depois que o arquivo servido é o mesmo que foi validado.

### Rate limit nos formulários web (#35, #39)
- [ ] `POST /cadastro` (10/h), `POST /senha/esqueci` (5/15 min) e o envio de fotos pela web (30/10 min, somado com a API) usam `REMOTE_ADDR`: atrás de proxy/CDN, `real_ip` é obrigatório; senão todos os visitantes dividem o mesmo limite.

## Sprint 3 — temas, grupos, domínios e e-mail HTML (SCHEMA_VERSION 13)

**Ordem do deploy**
- [ ] Código → `php scripts/build-assets.php && php scripts/build-assets.php --check` (manifesto, `public/assets/themes/<tema>.css`, `map-style.json`; sai com 1 se algum `brand.php` for inválido) → pré-compressão (brotli/gzip, sem o .pmtiles) → `systemctl reload php8.4-fpm`.
- [ ] `php -d extension=bz2 scripts/refresh-settings-cache.php` (unless bz2 is already enabled in php.ini; this run can open `database/places_neighborhoods.json.bz2` and `database/cep.csv.bz2`) e, para o primeiro admin, `php scripts/set-admin.php <email>`. Tema da plataforma em /admin/tema.

**.env**
- [ ] `APP_URL` obrigatório em produção e **https://** (links de e-mail, links de grupo e raiz da plataforma). Sem ele válido o mail-worker não inicia: escreve `mail-worker: APP_URL não configurada.` / `APP_URL inválida.` no stderr, sai com código 2 e não toca na fila. Conferir o .env lido pelo cron e monitorar o código de saída.
- [ ] `PLATFORM_ROOT_DOMAIN` (opcional): domínio raiz quando o APP_URL é subdomínio diferente de www. (ex.: app.casalivre.com.br → casalivre.com.br). Ele e seus subdomínios nunca viram domínio de grupo.
- [ ] (#64) Domínios de grupo já cadastrados ficam não verificados: só passam a valer depois da verificação DNS TXT.
- [ ] `MAIL_HTML`: padrão ligado (texto + HTML); `MAIL_HTML=0` força só texto.
- [ ] `SESSION_SAMESITE` (opcional, padrão Lax; None só com HTTPS).

**Crons (usuário da aplicação, storage/ gravável)**
- [ ] `* * * * *  php /srv/casalivre/scripts/mail-worker.php`
- [ ] `* * * * *  php /srv/casalivre/scripts/image-worker.php` (memory_limit ≥ 256M no CLI)
- [ ] `0 * * * *  php /srv/casalivre/scripts/sync-calendars.php`
- [ ] `17 * * * *  php /srv/casalivre/scripts/verify-domains.php >> /var/log/casalivre/verify-domains.log 2>&1`
- [ ] Log retention: prefer the runit services in **Jobs agendados** below (not a bare cron). Cron `15 3 * * * php …/log-retention.php` only if runit is unavailable.
- [ ] Avisos de domínio (log 46) exigem admin ativo, mail-worker rodando e SMTP configurado.

**nginx / DNS / TLS (domínios de grupo)**
- [ ] `server { listen 80 default_server; listen 443 ssl default_server; ssl_reject_handshake on; return 444; }`
- [ ] server_name da aplicação: host do APP_URL (+ www/raiz) e só os domínios verificados, via include gerado e `nginx -s reload` após verificar/remover.
- [ ] `fastcgi_param HTTP_HOST $host;` e não repassar X-Forwarded-Host.
- [ ] Não sobrescrever o Cache-Control do HTML (sessão no-store; erro private, no-cache). Só /assets/ e /tiles/ têm cache próprio.
- [x] CSP já inclui frame-ancestors 'none' e XFO DENY: trocar `X-Frame-Options SAMEORIGIN` por `DENY` em prompts/mapa-pmtiles.md:82.
- [ ] TLS (ACME HTTP-01) só depois do verified_at; parar de renovar quando perder a verificação.
- [ ] Cliente publica `TXT _casalivre.<domínio> = casalivre-verificacao=<token>` e A/AAAA ou CNAME. "Verificar agora" só agenda; o cron confere em até 1 hora.
- [ ] /etc/resolv.conf: resolvedor confiável (ideal unbound com DNSSEC), sem search/domain, `options timeout:3 attempts:2`.
- [ ] Antes de um domínio ir para produção: rodar verify-domains.php à mão e conferir a saída.

**Limites de requisição e domínios de grupo (Segurança, PR #70)**
- [ ] nginx `client_max_body_size`: `1m` no `server` (todas as rotas, domínios de grupo incluídos). A única exceção é `location ~ ^/(api/casas|imoveis)/[a-f0-9]{16}/fotos$ { client_max_body_size 20m; }` (web E API). Teste: um multipart de 2 MiB em `POST /cadastro` volta 413 do nginx; um upload de 15 MiB em `/imoveis/<h>/fotos` é aceito.
- [ ] php.ini: `post_max_size = 20M`, `upload_max_filesize = 16M`, `max_file_uploads = 20` (nunca os 128M do `start.sh` de dev).
- [ ] Domínios de grupo: cada domínio verificado entra no `server_name` (o `default_server` 444 continua). Login, cadastro, conta, painel, admin e a API autenticada só no host do `APP_URL`; num domínio de grupo, 302/404. Só habilitar em produção o domínio próprio de um grupo depois dessa trava.

**Pacote de produção**
- [ ] `scripts/casos`, `scripts/extras` e o arquivo comum de apoio aos testes ficam fora do pacote de produção. O arquivo comum se recusa a rodar se `APP_ENV` não for `dev` ou `local`; e-mails de teste sempre em `@casalivre.test`; limpeza direta no banco (ex.: o `DELETE` do limite de login) só nele e com a mesma trava; o `mail-dev.log` é apagado no fim da suíte (reunião 15/10).

**Assets com hash, OPcache e compressão (Etapa 0, #54)**
- [ ] O manifesto é versionado, mas é regerado no deploy para bater com os arquivos publicados.
- [ ] nginx: `location /assets/ { add_header Cache-Control "public, max-age=31536000, immutable" always; etag on; brotli_static on; gzip_static on; }`. O `brotli_static` precisa do módulo ngx_brotli; sem ele, só `gzip_static`.
- [ ] `/assets/manifest.json` sem cache longo: `Cache-Control: no-cache` ou `location = /assets/manifest.json { deny all; }`. Fontes `.pbf` e sprites do mapa ainda sem `?v=`: manter o cache atual deles, sem immutable.
- [ ] Pré-compressão: `find public/assets -type f \( -name '*.css' -o -name '*.js' -o -name '*.json' -o -name '*.svg' -o -name '*.pbf' \) -exec brotli -f -k -q 11 {} \; -exec gzip -f -k -9 {} \;`. Não comprimir o `.pmtiles`.
- [ ] OPcache no FPM de produção: `opcache.enable=1`, `memory_consumption=128`, `interned_strings_buffer=16`, `max_accelerated_files=10000`, `validate_timestamps=0` (reload do FPM obrigatório a cada deploy) e `jit=off`. Sem reload possível: `validate_timestamps=1` com `revalidate_freq=60`. Em dev/local: `validate_timestamps=1` e `revalidate_freq=0`.
- [ ] Conferir: `curl -I https://<host>/assets/app.css?v=<hash>` volta com immutable e `content-encoding: br`.

**Outros pontos da sprint (#56–#65)**
- [ ] CSP (#56): `style-src 'self'`, `style-src-attr 'none'`, `connect-src 'self'`, `worker-src 'self'`, `frame-ancestors 'none'` e XFO DENY. O site não pode ser exibido em iframe, nem pelo próprio domínio.
- [ ] nginx (#58/#59): sem query string no access_log em `/verificar` e `/senha/redefinir` (`log_format` com `$uri` ou `access_log off`); `real_ip` confirmado.
- [ ] `storage/cache` gravável pelo PHP (#61): o login guarda ali o `login_dummy_hash.json`. Sem ele, o login de e-mail inexistente fica mais lento que o real.
- [ ] `storage/` gravável pelo usuário do cron (lock do verify-domains, #65). Servidor com DNS de saída (`dns_get_record`). Domínios verificados são reverificados na primeira execução depois do SCHEMA 13.

## #72 e #73: vitrine, ativação em duas etapas e HEAD (SCHEMA_VERSION 14)
- [ ] (#72) nginx: o limite das locations de fotos (`20m`) também cobre o multipart chunked, que o BodyLimit deixa passar nessas rotas.
- [ ] (#72) `APP_URL` de produção absoluto e `https://`: o 302 da vitrine depende dele (sem ele, 404).
- [ ] (#73) O link de ativação do e-mail não mudou (`/verificar?token=…`), mas abre uma página de confirmação; a conta só é ativada ao clicar em "Ativar conta" (POST com CSRF).
- [ ] (#73) HEAD só é aceito em páginas públicas somente leitura; as demais rotas respondem 405. Monitores e health checks devem usar GET, ou HEAD em `/`.
- [ ] (#73) Requisições HEAD não gravam `activity_log`.
- [ ] (#73) iCal (`/ical/…`, `/api/ical/…`) em domínio de grupo responde 404 para imóvel de outro grupo ou sem grupo.
- [ ] (#73) nginx: `autoindex off` em `/uploads/`.

## Paridade web/API, calendário e grupo de teste (#83–#94, SCHEMA_VERSION 17)
- [ ] (#86) API do iCal aceita só `external_ical_url` e `feed_title`; download que falha dá 502; calendário inválido dá 422 ou 409. (#90) Upload pela API sem nenhuma foto salva dá 422.
- [ ] (#86, Segurança) Proxy (nginx/CDN): **não substituir as respostas 5xx da aplicação em `/api/`** (sem `proxy_intercept_errors on` nem `error_page 502` nesse location). O `POST /api/imoveis/<h>/ical/importar` devolve 502 com JSON (`error`, `status: NOK`, `feeds`) quando o download do feed falha.
- [ ] (#89/#91) Feeds só `https://`, sem exceção. Feed `http://` que existir é recusado no cadastro e não sincroniza (NOK).
- [ ] (#85) O limite de reenvio de ativação (3 por hora por conta) usa o e-mail como chave; isso funciona porque o e-mail de uma conta não pode ser trocado (não existe troca de e-mail).
- [x] (#93) `GrupoResolvedor::doDominio` has a per-process cache. Web requests are not affected. Fixed in #142 and #149: the `scripts/queue-worker.php` runit service resets the caches every tick, and resolver entries expire by TTL, so no restart is needed after a domain loses verification.
- [ ] (#94) `scripts/seed-grupo-teste.php` é **só para dev**: recusa rodar se `APP_ENV` não for `dev`/`local` e nunca entra em scripts de setup ou deploy de produção.

## Rate limiter (Valkey, #160)
- [ ] `IpRateLimiter` runs on Valkey (keys `cl:rl:<bucket>:<HMAC>`, shared by web and API, so it works with more than one app server). Valkey with the cl_app ACL is now **required for the web/API**: PHP-FPM needs phpredis and `VALKEY_SOCKET`, `VALKEY_USER`, `VALKEY_PASSWORD_FILE` in its env. The prod ACL line is unchanged.
- [ ] `LOG_SALT` is required (the limiter HMAC uses it). Without Valkey or without `LOG_SALT` outside dev, signup, password reset and the activation resend fail **closed** (429, `Retry-After: 30`) and every other bucket fails open; the outage is logged once per 60 s per worker.
- [ ] Delete the old `storage/cache/ratelimit/` directory and any cleanup cron for it; it is no longer used.

## PHP, sessão e arquivos (condições da Segurança)
- [ ] (#89/#91) `display_errors=Off` e `log_errors=On` (php.ini ou pool do php-fpm). Entradas malformadas, como um array em campo de texto, geram avisos que, com `display_errors` ligado, mostrariam caminhos internos ao cliente.
- [ ] (#64) `session.cookie_domain` **vazio** no php.ini e no pool do php-fpm. O app não define o `Domain` do cookie (`conta/Auth/Session.php`), então vale o do php.ini: vazio, o cookie de sessão fica só no host do `APP_URL` e nunca é compartilhado com os domínios de grupo.
- [ ] (#57) `themes/` (e todo o código da aplicação) pertence a um usuário diferente do php-fpm e é **só leitura** para ele. O php-fpm escreve apenas em `storage/` e `public/uploads/`. Nunca permitir upload ou edição de tema pela web, porque o `brand.php` é executado.

## Páginas de erro do nginx (#56)
- [ ] Se o nginx servir uma página de erro própria (ex.: 502/504 fora de `/api/`), ela precisa ter os mesmos cabeçalhos de segurança do app: CSP (a mesma do item de XSS acima), `X-Frame-Options DENY` e `X-Content-Type-Options nosniff`, todos com `add_header ... always` (sem `always`, o nginx não manda os cabeçalhos em respostas de erro). Continua valendo: o nginx não manda outro CSP nem outro X-Frame-Options nas páginas do PHP.

## Banco de dados (PostgreSQL)

- [ ] **Preparação de ambiente novo**: `psql -f database/schema.pgsql.sql` → `php -d extension=bz2 scripts/migrate.php` → `php -d extension=bz2 scripts/load-ceps.php` → seeds (`php scripts/seed.php`, `php scripts/seed_massive.php`, `php scripts/seed-users.php`, `php scripts/seed-owners.php`; these four do not open the bz2 artifacts). Omit `-d extension=bz2` only when bz2 is already enabled in php.ini. O `migrate.php` falha em banco vazio, sem o schema carregado. Os seeds não precisam de `LOG_SALT` e não precisaram de alteração nesta sprint.
- [ ] **No data migration** (docs/decisions.md, 2026-10-21): if the schema changes, recreate the DB (schema + seeds). `Db::SCHEMA_VERSION` still bumps.
- [ ] **(#210, SCHEMA 22) Recreate only, no migration:** `psql -f database/schema.pgsql.sql` then `php -d extension=bz2 scripts/migrate.php`.
- [ ] **Backup**: plano de backup do PostgreSQL (dump, PITR e restauração testada) definido pelo Banco de Dados.
- [ ] **Regra de desenvolvimento**: aumentar `Db::SCHEMA_VERSION` sempre que mudar o schema, `Db::migrate()` ou os dados base (`database/messages.php`, `Settings::defaults`, cidades, catálogo do ActivityLog).

## Fila de e-mail (PR #32)
- [ ] Cron do worker a cada minuto, com o usuário do serviço: `* * * * * php /srv/casalivre/scripts/mail-worker.php` (a saída vai para o log do serviço e tem só contadores).
- [ ] `storage/` com permissão 0700 e dono do serviço; `storage/mail-worker.lock` com 0600.
- [ ] Fila na tabela do PostgreSQL ou no Valkey (`QUEUE_DRIVER=valkey`), com `MAIL_QUEUE_RETENTION_DAYS=7` ou menos.
- [ ] Produção com `SMTP_HOST` configurado. Sem ele, nada entra na fila e o php.log mostra `mail_not_configured`: monitorar esse alerta.
- [ ] Alarme se o worker parar, ou seja, fila `pending` crescendo ou sem purga.
- [ ] Backups do banco tratados como sigilosos.

## Jobs agendados (usuário do php-fpm)

- [ ] **Queue worker and log retention (runit, Void).** Install the helpers once, then both services. `conf` is optional, mode 0640 root:root, and holds no secrets (the app reads `APP_DIR/.env`).

      sudo install -d -m 0755 /usr/local/sbin
      sudo install -m 0755 deploy/runit/casalivre-alert deploy/runit/casalivre-resolve \
        deploy/runit/casalivre-svlogd deploy/runit/casalivre-finish \
        /usr/local/sbin/
      # keep an existing conf if you already have one
      sudo cp -a /etc/sv/casalivre-log-retention/conf /tmp/casalivre-log-retention.conf 2>/dev/null || true
      sudo rm -rf /etc/sv/casalivre-log-retention /etc/sv/casalivre-queue-worker
      sudo cp -a deploy/runit/casalivre-log-retention /etc/sv/
      sudo cp -a deploy/runit/casalivre-queue-worker /etc/sv/
      sudo cp -a /tmp/casalivre-log-retention.conf /etc/sv/casalivre-log-retention/conf 2>/dev/null || true
      sudo ln -sfn /etc/sv/casalivre-log-retention /var/service/casalivre-log-retention
      sudo ln -sfn /etc/sv/casalivre-queue-worker /var/service/casalivre-queue-worker

      Optional `/etc/sv/<service>/conf`:
        APP_DIR=/srv/casalivre
        APP_USER=casalivre
        LOG_GROUP=casalivre
        PHP_BIN=/usr/bin/php
        RETENTION_DAYS=14          # log-retention only
        RESTART_DELAY=86400        # log-retention only; seconds before a failed run is tried again
        LOG_DIR=/var/log/casalivre/queue-worker   # or .../log-retention
        ALERT_THROTTLE=900
        ALERT_CMD=/usr/local/sbin/your-pager      # optional absolute path, no shell

- [ ] Log dirs: `/var/log/casalivre` is `0711` root:root (traversable, not listable). `/var/log/casalivre/queue-worker` and `/var/log/casalivre/log-retention` are `0700`, owned by the service user, and keep about 14 days (`t86400`, `n14`). Do not remove that rotation from the seeded `config` (it is written only when `config` is missing). Worker errors stay one ALERT line with no DSN, host, or secrets.

- [ ] Alerts: a line containing `ALERT` is paged from the log service; a non-zero exit is paged from `finish` (not SIGTERM/SIGINT). Each service is paged at most once per 15 minutes. Every event is still appended to `/var/log/casalivre/alerts.log` (0600 root) and a page also goes to syslog (`logger -t casalivre -p user.err`). `alerts.log` is not rotated by svlogd; add a logrotate rule if it should follow the same 14 days.

- [ ] Check: `sv status casalivre-queue-worker casalivre-log-retention` and the same for `.../log`. Confirm `find /var/log/casalivre/queue-worker /var/log/casalivre/log-retention -type d -prune -printf '%m %u\n'` is `700` and the service user. A bad `PHP_BIN` in conf should exit non-zero, write one page line, and (for the worker) come back; log-retention then sits in `finish` for `RESTART_DELAY` instead of looping.
- [ ] **Sincronização de calendários**: `0 * * * * php /caminho/casalivre/scripts/sync-calendars.php`, com o usuário do php-fpm (grava no `activity_log`). Agora também remove as noites além do horizonte (`trimHorizon()`), antes feito no GET.

## Permissões e arquivos

- [ ] `storage/logs/api` `0700` e arquivos de log `0600`, do usuário do php-fpm (o app usa `umask 0077`).
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
- [ ] Retenção sob carga do php-fpm, sem sobrar arquivos `*.ret-*`; exclusão real no `activity_log` do PostgreSQL.
- [ ] Mascaramento de `feeds[].url` no front separado (quando existir).
- [ ] Cadastro, ativação e redefinição de ponta a ponta com e-mail real, sem link na tela e com o domínio do `APP_URL` mesmo com `Host:` forjado.
- [ ] Tempo de resposta igual para e-mail existente e inexistente no esqueci-senha e no cadastro.
- [ ] Amostra do `activity_log` e do `api-*.log` sem nome, endereço, CEP, e-mail, CPF, telefone, IP completo ou user agent.
- [ ] Editar imóvel com quartos e camas existentes salva sem o erro "pelo menos uma cama" (issue #20), e o resumo por quarto se atualiza.
- [ ] Título `<img src=x onerror=alert(1)>` não executa na página pública, na busca nem no painel.
- [ ] As miniaturas carregam na busca, sem violação de CSP no console; o mapa de fundo carrega via `/tiles/brasil.pmtiles` com respostas 206, sem requisições externas.
- [ ] Foto com caminho fora de `/uploads/` vira placeholder.
