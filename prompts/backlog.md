# Backlog de desenvolvimento — CasaLivre

Rastreamento das pendências durante o desenvolvimento. **Só o Bot Gerente Geral edita este arquivo**; os analistas enviam os itens para consolidação. O que é necessário para subir em produção fica em `prompts/deploy.md`.

## Cleanup sprint (Rafael 2026-10-21, before the standard plan, about 1-2 days)
- [x] Line-143 live bug (queue worker `limparCache`): fixed in #142 and #149.
- [x] DROP items from the backlog review: closed in #139.
- [x] Remove SQLite and MongoDB (PostgreSQL only): done in #148. Owner: Programador.
- [x] Move `IpRateLimiter` to Valkey now (done in #160) (INCR+EXPIRE under `cl:*`, shared by web and API; replaces the files in `storage/cache/ratelimit`), Rafael 2026-10-21. Keeps today's limits and `Retry-After`; fail-open/closed behavior agreed with Segurança. Makes the file-limiter `flock` findings and the `storage/cache/ratelimit` test flakes moot. Owner: Programador; needs Segurança and Teste.
- [x] (done in #164) Remove `PhotoStorage::migrateLegacy` and its callers/tests (legacy `/uploads/users/` photo migration; no data migration, decision 2026-10-21). Owner: Programador.
- [x] (already gone; confirmed in #205) Remove the leftover `pkg.php-sqlite` pin from `ops/dev-env` `versions.lock` (PostgreSQL only). Source: Arquitetura #162. Sprint: cleanup. Owner: Programador.
- [x] (done in #205) Same ops PR as the pin removal: `provision.sh` refreshes the package index when re-provisioning after a pin bump (today the workaround is deleting `.provisioned`), and the CI Valkey step PINGs as `cl_app` instead of only waiting for the socket. Estimate 1 h. Source: Arquitetura #162/#174. Sprint: cleanup. Owner: Programador.
- [x] (done in #206) Add `Db::sqlState(PDOException): ?string` and use it at the four call sites that still read SQLSTATE differently (`src/Db.php` ~224 and ~248, `src/GroupRepository.php` ~258, `src/Acoes/ThemeSet.php` ~68). Source: Revisor (larger than #148). Sprint: cleanup. Owner: Programador.

After the cleanup sprint the standard plan resumes. Each sprint clears the KEEP items that touch the code it changes, plus a fixed slice of the remaining backlog of about 20% of the sprint's effort. A new item must name its sprint or it is dropped; Gerente reviews this list once per sprint.

## Priority now (right after the cleanup sprint)
- [ ] ~~`.ics` import tests for huge files and malicious parser input (plus SSRF, already in `ssrf_test`), due now because the import exists (`src/Acoes/ICalImport.php`, `CalendarSync::MAX_BYTES`).~~ Kept in Calendar-sync below (Rafael 2026-10-26). Source: meeting 15/10. Sprint: Calendar-sync. Owner: Teste.
- [x] (done in #167) Queue worker never reconnects to the DB (`Db::$conn`, measured on PG): after a PG restart every mail job fails until it is given up, so activation and reset emails are lost. `tick()` pings the DB once per tick and exits non-zero on failure so runit restarts the worker; connection errors don't count as job failures. Source: Arquitetura #142. Owner: Programador.
- [x] (done in #168) `mail_queue` cooldown race (`src/MailQueue.php:83-97`, SELECT then INSERT), which also happens on PG: add a UNIQUE constraint on the cooldown window or a conditional insert. Source: Segurança #88. Owner: Programador.
- [x] (done in #170) `FileStamp` misses a change when two same-size writes land in the same second and ext4 reuses the inode (measured by Arquitetura), so the worker keeps a stale Settings/I18n/manifest cache. Add a stamp field that changes on every write (for example, nanosecond mtime or a content hash). Source: Arquitetura #149. Owner: Programador.
- [ ] ~~`FileStamp`: add `ctime`~~ Superseded by the simplification below (Rafael 2026-10-21, simplicity rule); #180 is closed.
- [x] (done in #193) Simplify the worker file caches (from #149/#154/#170): store each file's `filemtime()` at load; on each check call `clearstatcache()` and reload when `filemtime()` differs from the stored value ("differs", not "is newer", so rollbacks and checkouts with older mtimes are caught). Drop the inode+size checks and the same-second logic; a stale file in the rare same-second case is acceptable. Fold in the open `FileStamp` failed-read and triple-copy items below only if they still apply. Estimate 1 h. Source: Rafael 2026-10-21. Sprint: cleanup. Owner: Programador.
- [x] (done in #173) `Valkey::connect()` from web requests (queue producers) has no tight timeout: with Valkey frozen it took over 20 s, so an FPM worker would hang up to `default_socket_timeout`. Give producers about 0.5 s and keep the long timeout only for the workers. Estimate 1 h. Source: Arquitetura #160. Sprint: Priority now. Owner: Programador.
- [x] (done in #173) Queue worker restarts about once a second during a PG outage: sleep 5-30 s before `exit(1)` when the DB is lost, and alert on the `DB connection lost` log line. Estimate 0.5 h. Source: Arquitetura #167. Sprint: Priority now. Owner: Programador.
- [x] (done in #174) Worker tests: a lost ack after a successful send (the email goes out again after 10 min, possibly with a second activation or reset token), and a Valkey outage (today the worker crashes with exit 255 and dumps a stack trace into the runit log; catch it and log one line). Estimate 1 h. Source: Arquitetura #167. Sprint: Priority now. Owner: Programador; Teste; Segurança.
- [x] (done in #174) Worker hardening, one PR: (a) `queue-worker.php:63-65` logs other exceptions (including the Valkey AUTH/secret-file `RuntimeException`) as one line with the class only, plus `zend.exception_ignore_args=On`, with no stack trace; (b) a stale reclaim counts as an attempt, so retries are bounded; (c) the Valkey-outage test uses its own queue instead of draining the real `cl:q:mail` in dev. (d) also send #174's Valkey ALERT to the error log; optionally give the worker a Valkey timeout of about 5 s (a frozen Valkey is detected only after about 61 s today; Arquitetura #174). Estimate 2 h. Source: Segurança #174. Sprint: Priority now. Owner: Programador; Segurança.
- [x] (done in #201/#202) **(high)** Production has no queue-worker runit service and no log capture, so nothing collects the worker's ALERT lines or alerts on them; the log-retention exit-code alert from #148 has the same gap. Add the runit service (`deploy/runit/`) with `svlogd` capture and an alert on ALERT lines and non-zero exits, and document it in `prompts/deploy.md`. Estimate 2 h. Source: Arquitetura #148/#174. Sprint: Priority now. Owner: Programador; Arquitetura.
- [x] (done in #203) `ActivityLog::securityPdo` is cached and never reconnects in long-running workers, so security events can be lost after a PG restart (code reading, not measured). Reconnect or ping like the worker's `Db` check. Estimate 1 h. Source: Arquitetura #148. Sprint: Priority now. Owner: Programador; Segurança.
- [x] (done in #204) Photo upload opens a Valkey connection per photo; with Valkey frozen each waits 0.5 s, so a 20-photo upload takes about 10 s [E]. Reuse one connection per request and skip the rest after the first failure. Estimate 1 h. Source: Arquitetura #173. Sprint: Priority now. Owner: Programador.
- [x] (done in #178) Limiter hardening, one PR: (a) HMAC with a derived subkey `HMAC(LOG_SALT, 'cl-ratelimit-v1')` instead of `LOG_SALT` itself; (b) move the outage-log throttle stamp from `/tmp` to a private `0700` dir (`IpRateLimiter.php:224`); (c) fold the refund `INCR` into the `MULTI` (`:171`); (d) log a dedicated `VALKEY_UNAVAILABLE` code instead of `INTERNAL_ERROR` so alerts and BI can tell it apart; (e) a Teste case that `SIGSTOP`s Valkey and proves the fail-closed buckets return 429 within about 100 ms. Source: Segurança #160. Sprint: Priority now. Owner: Programador; Teste; Segurança.
- [ ] Partial index `CREATE INDEX idx_prop_latlng_active ON properties (lat, lng) WHERE status = 'active';`, replacing the unused gist `idx_prop_geo_active` (index count flat, counts toward ADR 001 §12.6). SCHEMA bump, recreate-only, plus `random_page_cost = 1.1` in `ops/dev-env`. Small PR; BD reviews. Rafael approved 2026-10-25. Source: BD #218. Sprint: Priority now. Owner: BD/Programador.
- [ ] `queue_worker_valkey_outage_test` collides with the dev env's live queue-worker (#201): use its own queue, or skip while the worker runs. Still open after #174. Source: Teste #217. Sprint: Priority now. Owner: Programador; Teste.

## Queue, photos and calendar sync hardening (Rafael 2026-10-26)

Decisions from Rafael 2026-10-26 via Cori (code read of develop @6e87dcb). No data migration code; schema changes only update the schema and the seeds; handle only realistic cases. Revisor reviews each Programador PR first (#200). Each item names its sprint. Order: after ADR 001 PR3 (UI) and before ADR 001 PR4, unless Rafael reprioritizes.

### Queue-A (photos)

One PR. Needs Revisor, Teste, Segurança and Arquitetura. BD reviews the photos sweep `UPDATE` and any new column.

- [ ] **1.** Stuck photos (status `processing` for too long, for example 30 minutes) are discarded: mark the row failed, delete the original, and log the event. The sweep runs in the worker loop as one `UPDATE ... RETURNING`. The `photos` table in `database/schema.pgsql.sql` has no creation or updated time column; add one to the schema and the seeds. Estimate 2 h. Source: Rafael 2026-10-26. Sprint: Queue-A (photos). Owner: Programador.
- [ ] **2.** Bad images are tried once. `PermanentJobFailure` covers an invalid type, too many pixels, a corrupt file, or a missing original. `QueueWorker` fails that job immediately in both `SqlJobQueue` and `ValkeyJobQueue`. Transient errors keep the 5-attempt backoff. Log a short reason code as well as the exception class. Estimate 3 h. Source: Rafael 2026-10-26. Sprint: Queue-A (photos). Owner: Programador.
- [ ] **7.** Delete the unused `PhotoStorage` methods `directory()`, `publicPath()`, `writeGenerated()`, `deleteHouse()`, `deleteUser()` and `deleteTree()`, and the assertion at `tests/standalone/photo_async_test.php:190`. Estimate 1 h. Source: Rafael 2026-10-26. Sprint: Queue-A (photos). Owner: Programador.
- [ ] **8.** Rejected uploads reach the user. `PhotoStorage::storeUploads()` returns a reason per rejected file (wrong type, too large, over the limit), shown on both upload routes, including API JSON. Async failures (too many pixels, invalid image) show in the photo list as failed, with a reason code and a remove action. Use translated text and mention HEIC. Needs UI review (`needs:ui`). Estimate 4 h. Source: Rafael 2026-10-26. Sprint: Queue-A (photos). Owner: Programador.

### Queue-B (workers)

One PR. Needs Revisor, Teste, Segurança and Arquitetura.

- [ ] **3.** Incus PHP will have GD WebP. Remove the skip-image-queue branch in `scripts/queue-worker.php`. Exit non-zero with an ALERT line when WebP is missing. Estimate 1 h. Source: Rafael 2026-10-26. Sprint: Queue-B (workers). Owner: Programador.
- [ ] **4.** Workers run under runit. Remove the cron entries in `prompts/deploy.md` (~123, 163-164, 242). Delete `scripts/mail-worker.php` and `scripts/image-worker.php`, and move the `APP_URL` check into the mail worker. Clean the tests and comments that still name those cron scripts. Estimate 2 h. Source: Rafael 2026-10-26. Sprint: Queue-B (workers). Owner: Programador.
- [ ] **5.** The runit worker sets `php -d memory_limit=256M` on the deploy runit run script (`deploy/runit/casalivre-queue-worker/run` today; both worker run scripts after the split in this PR) and on the dev run script that `ops/dev-env/services.sh` installs (`ops/dev-env/sv/queue-worker/run`). Estimate 0.5 h. Source: Rafael 2026-10-26. Sprint: Queue-B (workers). Owner: Programador.
- [ ] **6.** Separate the mail and image workers into two runit services, `casalivre-mail-worker` and `casalivre-image-worker`. Replace `QueueWorkerLoop::tick` (`src/QueueWorkerLoop.php`) with a single-queue loop and reuse the existing helpers. Update `ops/dev-env/services.sh`, `prompts/deploy.md` and the Valkey ACL. Estimate 4 h. Source: Rafael 2026-10-26. Sprint: Queue-B (workers). Owner: Programador.

### Calendar-sync

Separate PR(s). Needs Revisor, Teste, Segurança, Arquitetura and BD (`next_sync_at`, `last_error`, `fail_count`, `feed_id` in the schema). Produto/UI review the text in `views/calendar.php`. Today an hourly cron, `scripts/sync-calendars.php` (`prompts/deploy.md` lines 165 and 282), runs the sync: there is no queue and no runit service, and the download runs inside web requests (`src/Acoes/ICalImport.php`, `CalendarSync::resyncProperty`). Programador estimate for this sprint: 12 h.

- [ ] Own queue, plus a runit service `casalivre-calendar-worker` with `php -d memory_limit=256M`. Add `next_sync_at` and drop the cron. Web requests only enqueue, and the page says the import is in progress ("importing..."). `CalendarSync::fetch()` returns a reason: a permanent failure (`bad_url`, `private_ip`, `http_4xx`, `not_ics`, `too_big`) fails once and is logged; a temporary failure (`timeout`, `dns`, `http_5xx`) retries with backoff. Store `last_error` and `fail_count`. Stop scheduling a feed after several days of failures until the owner re-saves it. Show the reason and a retry button in `views/calendar.php`. Tag imported nights with `feed_id` and replace that feed's nights in one transaction, skipping `STATUS:CANCELLED` (a cancellation frees those nights, and removing one feed frees only that feed's nights). Delete the dead `HouseRepository::tagOriginIfEmpty` and `HouseRepository::findFeed`. Enforce the 5-feed limit in the database. Create `calendar-sync.log` with a 0700 directory and a 0600 file. Estimate 12 h. Source: Rafael 2026-10-26. Sprint: Calendar-sync. Owner: Programador.
- [ ] `.ics` import tests for huge files and malicious parser input (SSRF is already covered by `ssrf_test`; the import is `src/Acoes/ICalImport.php`, `CalendarSync::MAX_BYTES`). Kept in this sprint. Source: meeting 15/10. Sprint: Calendar-sync. Owner: Teste.

## Em andamento

### Sprint 2 (ordem)

1. ~~**#35** — tokens sha256 + resposta genérica no cadastro (`email_taken`)~~ (mesclado)
2. ~~**#36** — API pública sem endereço + coordenada pública aproximada~~ (mesclado)
3. ~~**#39** — Fotos assíncronas em WebP (caminho com uuid)~~ (mesclado)
4. ~~**#40** — Mapas PMTiles (Brasil) + MapLibre GL~~ (mesclado)
5. ~~**#43** — script `run-casos-de-teste`~~ (mesclado)
6. ~~**#44–#47, #49, #50** — Refatoração DRY (PRs 0 a 5)~~ (mesclado)
7. ~~**#48** — limite de avaliação pela web igual ao da API~~ (mesclado)
8. ~~**Próximo passo:** sprint 3 (tema visual)~~ (sprint 3 concluída até o #69)

| PR | Assunto | Situação |
|----|---------|----------|
| #35 | Tokens só como hash; cadastro sem revelar e-mail existente | concluído (mesclado) |
| #36 | API pública sem `address`; bairro e cidade; coordenada aproximada | concluído (mesclado) |
| #38 | (sprint 2) | concluído (mesclado) |
| #39 | Fotos WebP assíncronas (caminho uuid) | concluído (mesclado) |
| #40 | Mapas PMTiles + MapLibre | concluído (mesclado) |
| #43 | Script `run-casos-de-teste` | concluído (mesclado) |
| #44 | Refatoração 0: testes de caracterização | concluído (mesclado) |
| #45 | Refatoração 1: app-env | concluído (mesclado) |
| #46 | Refatoração 2: index-servicos | concluído (mesclado) |
| #47 | Refatoração 3: ações compartilhadas | concluído (mesclado) |
| #48 | Avaliação pela web com o mesmo limite da API (10/h por IP, contado junto) | concluído (mesclado) |
| #49 | Refatoração 4: api-guardas | concluído (mesclado) |
| #50 | Refatoração 5: helpers por tema | concluído (mesclado) |

### Decisões (sprint 2)
- Envio de fotos continua pelo formulário web, sem depender de JavaScript, porque o dono configura o imóvel no desktop (decisão do usuário, 14/10). A API de fotos fica para o futuro app Android.

### Pendências baixas (sprint 2)
Removed 2026-10-21: already fixed; replaced by ADR 001; moot after the Valkey rate limiter; closed by Rafael.
- [ ] #35: depois de redefinir a senha, a página diz "Conta ativada"; o formulário sem JS não mostra o erro.
- [ ] #35: `MailQueue.php:85` usa valor fixo quando `LOG_SALT` está vazio.
- [ ] #36: `public_coord` pode sair da faixa depois de 64 tentativas.
- [ ] #40: glifos só para alfabetos latinos.
- [ ] #40 (info): o bloco nginx dos tiles não envia CSP.
- [ ] Testes instáveis: `CT-E2E-070` falha às vezes quando roda junto com o 071 (corrida de tempo no teste).
- [ ] Testes instáveis: `account_tokens_test` (13 de 15 já na develop 5d697b0); abrir issue separada.

### Comportamentos achados no #44 (sem correção)
Removed 2026-10-21: already fixed (#109).
- [ ] Salvar a conta sem mudanças registra `ACCOUNT_UPDATE`.
- [ ] O dono de um imóvel pausado recebe 404 ao editar.
- [ ] A API responde `ok:true` com erro de validação no calendário e com falha no iCal.
- [ ] A API dá 419 em vez de 401 sem sessão.
- [ ] Na web, excluir um feed inexistente dispara a liberação de datas e a ressincronização.

### Mapa: fora do #40
Moved 2026-10-21: three items are now ADR 001 acceptance requirements.
- [ ] Lista e mapa lado a lado.
- [ ] Carrossel no mobile.

## Sprint 3

### Tema visual (em etapas)
Relatório: `/workspace/casalivre-seguranca-evidence/tema/relatorio.md`.
- [x] Etapa 0: `asset_url` com hash, OPcache e brotli.
- [x] Etapa 1: tokens CSS e páginas de erro sem estilo inline.
- [x] Etapa 2: `brand.php`, logo e pasta do tema.
- [x] Etapa 3: estilo do mapa gerado.
- [x] Etapa 4: fonte do mapa, se mudar. Pulada: a fonte não mudou (NotoSans, glyphs PBF desde o #40).
- [x] Etapa 5 (opcional): e-mail HTML.

### Tema escolhido pelo banco
- [x] (#57) Tema padrão da plataforma (usado quando a requisição não tem grupo) em `settings.theme`, com cache num arquivo mantido pelo OPcache e CSS e estilo do mapa com hash por tema. Aceitar só nomes da lista do manifesto; troca só pelo admin, com CSRF e log. Entra junto com o PR do `brand.php`. Modo claro e escuro, se houver, por `prefers-color-scheme`.

### Temas por grupo de imóveis (decisão do usuário, 14/10)
Removed 2026-10-21: already done in Sprint 3 (#56–#69). The rest moved to the Sprint 5 groups rewrite.

## Sprint 3: pendências (#56–#69)

### Segurança
Moved 2026-10-21: group findings are now Sprint 5 groups rewrite requirements; the .ics item moved to Priority now; the live limparCache bug was fixed in #142 and #149.
- [ ] (#56, risco aceito) `img-src 'self'` bloqueia o `blob:` do MapLibre em navegadores sem `createImageBitmap` (Safari antigo). Avaliar `img-src blob:` se aparecer reclamação.
- [ ] (baixa, #60) Conferir no LoginAttempt se `err.deleted`/`err.inactive` aparecem antes da checagem da senha (revelaria o estado da conta).
- [ ] (baixa, #61) Diferença de poucos ms em `/senha/esqueci` (INSERT na `mail_queue` só se o e-mail existe) e no login de conta pendente com reenvio; abaixo do ruído e coberta por rate limit.
- [ ] (QA #61, já existia) Conta bloqueada responde "Conta bloqueada" (423 na API), revelando que existe; o primeiro 429 por IP sai sem `Retry-After`.
- [ ] (baixa, #73) Limite de tentativas por IP no `POST /verificar`.
- [ ] (baixa, #73) `Cache-Control: no-store` na página de confirmação da ativação (hoje `private, no-cache`, com o token no HTML).
- [ ] (baixa, #73 D3) O RequestLog grava HEAD em `/api` como GET (o método é trocado antes do `RequestLog::start`) e o HEAD com 405 em `/api` não gera linha; passar o método original.
- [ ] (baixa, #73) `GET /verificar?token[]=x` gera PHP Warning (Array to string).
- [ ] (baixa, #72) `/idioma` compara o Referer com o Host incluindo a porta; em dev com porta, sempre volta para `/`.

### Produto / UX
Removed 2026-10-21: decided by Rafael (themes stay repo-only). Group items moved to the Sprint 5 groups rewrite.
- [ ] Mapa: `?v=` nos sprites e nos glyphs (`/assets/map/` é immutable); o mapa de reserva (sem tiles) não segue o tema; cores por expressão (landcover, pois, roads_minor) não são trocadas pelo tema.
- [ ] Sistema de avisos (ou fila) para os e-mails do admin (hoje `Mailer::deliver` direto); link de admin montado à mão no e-mail (#68).
- [ ] (Divergência de validação web, docs sprint 3) Códigos de erro inconsistentes entre telas web: `POST /conta`, `POST /imoveis` e `POST /imoveis/<h>` respondem 200 com o formulário, enquanto as telas de grupo e `/admin/tema` respondem 422/409. Padronizar.

### Qualidade / dívida técnica
Removed 2026-10-21: SQLite removal (WAL retry). Search items moved to ADR 001.
- [ ] Rotas do dono: 404 em texto para imóvel de outro e página HTML para código inexistente (padronizar).
- [ ] Bug pré-existente: `POST /conta` inválido na web não mostra mensagem (`#account-error` vazio).
- [ ] Testes: caminho com DNS real; 4 testes E2E desatualizados; flake de tempo do `account_tokens_test` ("tempo semelhante": usar mediana de N ou mais tolerância); `storage/cache/settings.json` compartilhado entre execuções de teste; `/assets/../index.php` no servidor de desenvolvimento dá 500 vazio (Cannot redeclare).
- [ ] (Reunião 15/10) Arquivo comum de apoio aos testes (cadastro, ativação via `mail-dev.log`, login) usado por `run-casos-de-teste`, `api-ical-log` e `retencao-concorrente`; remove a `link_ativacao` duplicada. Regras da Segurança: recusar rodar se `APP_ENV` não for dev/local; ficar fora do pacote de produção; limpeza direta no banco (ex.: `DELETE` do limite de login em `retencao-concorrente.php:73`) só nele e com a mesma trava; e-mails de teste sempre `@casalivre.test`; apagar o `mail-dev.log` no fim da suíte; limpar `login_rate_limit` só das linhas de `127.0.0.1` e `::1` (como `retencao-concorrente.php:73`), nunca a tabela inteira (hoje `run-casos-de-teste.php:107` faz `DELETE FROM login_rate_limit` sem filtro); antes de qualquer `DELETE`, conferir que o banco aberto é o de desenvolvimento (não basta `APP_ENV=dev`) e recusar se não for. Tarefa do Bot Teste, depois do fechamento da sprint 3.
- [ ] (Reunião 15/10) Separar `scripts/casos/api-ical-log.php` por assunto (api, ical, log), reaproveitando o arquivo comum de testes. Tarefa do Bot Teste.
- [ ] Opcionais do Revisor não feitos:
  - #58/#59: parâmetros mortos `$requirePassword`/`$current` em `AccountFlow.php:16/:52`; comentário v9→v8 em `schema_upgrade_test.php:3`; logout duplicado web/API; AccountUpdate com muitas responsabilidades; truque `$fields['email']`.
  - #62: `themePath` sem `assetPath`; validação repetida no `mapStylePath`; `clean()` duplo; cópia profunda; regex frágil de camadas.
  - #63: comentário com constantes inexistentes; `isUniqueViolation` no Db; `propertyRef` validado 2×; `activeUser` com um uso; 2 cópias do filtro de ativo.
  - #66 (`retencao-concorrente.php`): `$appDb` muda de string para PDO (:71/:79); PDO aberto à mão em :73 em vez de `pdo()`; código do filho num `-r` de uma linha (:98-103); fallback `/ical/000…` (:108) e ramo `$icsPaths ? … : $icsPath` só servem ao caso sem imóveis. Resolver junto com o arquivo comum de testes.

### Pendências pós-paridade (#83–#94)
Removed 2026-10-21: SQLite removal; moot after the Valkey rate limiter. Two items moved to Priority now.
- [ ] (Segurança #93) Chamar `CalendarSync::isHttpsUrl` dentro de `resolveTarget` (`CalendarSync.php:212`), para a mesma regra de https valer em cada redirecionamento.
- [ ] (baixa, Segurança #87) A API lista as fotos com `SELECT *` (`src/PhotoRepository.php:11`) e mostra ao dono colunas internas (`original`, `uuid`, `status`). Trocar por uma lista fixa de campos.
- [ ] (Teste, #87) A resposta para um id de foto inexistente deve ser igual à de uma foto de outro imóvel (travar com teste).

## ADR 001 search implementation
Source: ADR 001 (incl. §11) and `docs/produto/specs/spec-busca-localidade-resolvida.md`. Replaces the old "Sprint 4" items 1–2 (removed 2026-10-21; the SQLite write-concurrency note went with SQLite removal).
- [ ] Implement ADR 001 search: SQL search with pagination, `places` and `place_search_keys` (SCHEMA 20), search only on a resolved place or map bbox, free-text full scan removed, total-price sort guard (5,000 candidates). The demand log, including kind `search`, is PR7 below (Priority: Should; it does not block PR1–PR3). Owner: Programador. Starts after P0-5 (#120) and the `cl_app` ACL PR below are merged.
  - (#214) Demand-log redaction (Segurança condition 6) is already done: `src/SearchService.php` drops raw `localidade` and `q` from the `SEARCH` activity row, and `RequestLog::QUERY_VALUE_ALLOWLIST` no longer keeps those values. PR7 does not redo it.
- [ ] Aliases live only in `place_search_keys` kind='alias', seeded from a data file. Remove the PHP const `CityRepository::ALIASES` in ADR 001 PR2 (Produto decision 2026-10-25; the const is the Búzios alias from #213). Source: Produto 2026-10-25. Sprint: ADR 001. Owner: Programador.
- [ ] PR7 — Demand log, third kind `search` (ADR 001 §11.3). Priority: Should. Does not block PR1–PR3. Owner: Programador. Labels on that implementation PR: `needs:db`, `needs:security`, `needs:legal`, `needs:arch`. Approved 2026-10-25 by Segurança, Arquitetura, BD and Jurídico. Rafael: count searches per resolved place, for trends and hints.
  - Create `search_demand_daily` with all three kinds at once (`unresolved`, `zero_result`, `search`). No migration: recreate the DB and seeds. Unique index `sdd_search_uk`. DDL is BD's text in ADR 001 §11.4. Schema bump in the same PR (the table is new, so no ALTER).
  - One daily count per catalog `place_id`: one number per (day, place_id). No guests, dates, price, radius, referrer, device, or anything finer than the day. Do not join the table to logs, accounts, bookings or listing views. If filters are ever added, re-check the LIA. `hits >= 1` (the k ≥ 5 threshold does not apply to a catalog place_id).
  - Validate `place_id` as an int against the place catalog before HINCRBY; drop anything else.
  - Valkey key `cl:demand:search:{YYYY-MM-DD}`, field = place_id, EXPIRE 172800, no HLEN cap. Same nightly flush into `search_demand_daily`. No Postgres write on the request path.
  - Count only page 1 of a resolved place (not cursor pages, not map pans), after the response is flushed. It must never delay or fail a search.
  - PG rows kept 12 months. The table is readable only by the BI/admin role; writes by the flush worker only.
  - Product acceptance: an admin report lists the top places by searches per day and per week, with counts, and search responses show no measurable added time.
- [x] (done in #138) Valkey ACL PR: add `+hlen +hexists` to `cl_app` on `ops/dev-env/services.sh:37` and `+rename` on `services.sh:36` (flush handoff, ADR 001 §11.3/§11.4), plus the prod ACL; add `hlen cl:x` → ok and `hlen x` → NOPERM cases to `ops/dev-env/valkey-acl-check.sh`. Owner: Programador; Segurança informed.

Acceptance requirements folded in from the backlog review (2026-10-21):
- [ ] Map: "Search as I move the map" (ON by default on desktop) and "Search this area" (mobile), bbox in the URL (spec-busca-localidade-resolvida item 6).
- [ ] List: card skeletons for the first page and for "Mostrar mais" (§5c).
- [ ] Card ↔ pin sync: a pin opens the card by id (§5).
- [ ] One "active" rule in the new SQL builder, shared by list, map and storefront scope; replaces `activeProperty`/`activePropertyStrict` and the `status='active'` literals (`src/GroupRepository.php:15,26,197`, `HouseRepository::all()`); the home must not show status `''`.
- [ ] The builder takes typed parameters; `SearchService` no longer reads `$_GET` (`src/SearchService.php:15-27`).
- [ ] Remove `idx_properties_status` and, if unused, `idx_properties_city` (ADR §1), and their recreation in `migrate()` (`src/Db.php:503-504,534-535`); remove `HouseRepository::byCity` (`src/HouseRepository.php:173`, ADR §11.4).
- [ ] With the SCHEMA 20 bump: update the `SCHEMA_VERSION` comment (`src/Db.php:183`) and remove or justify the duplicated `Db::SCHEMA_META_DDL`.
- [ ] `properties.city` becomes `city_place_id` (ADR §11; was "city_id", BD scale review item 10).
- [ ] Result-card images get `width`/`height` and `decoding=async` (`srcset` is already done); same on the property page.

Blockers before the demand log ships (Jurídico, ADR 001 §11 P7 legal verdict):
- [x] (Done in #131.) Privacy notice: add a "Busca" row to `docs/produto/juridico/textos/politica-de-privacidade.md` with Jurídico's text: "Termos buscados sem resultado são registrados de forma agregada e sem identificação (sem conta, IP ou sessão), apenas quando repetidos por pelo menos 5 buscas no dia, para melhorar a oferta de imóveis. Base: legítimo interesse. Guarda: 12 meses." Do not promise "anonymous" for the transient phase. Owner: Jurídico.
- [x] (Done in #131; ANPD guide v1.0, Feb 2024, verified.) LIA at `docs/lgpd/lia-demand-log.md` (LGPD art. 10 §3): purpose (search coverage and inventory), necessity, balancing (no profiling, no user link, low impact on expectations), safeguards. Check the current version of the ANPD legitimate-interest guide. Owner: Jurídico.
- Acceptance (Jurídico): both files exist; Segurança items 1–7 are implemented; the PG table has no identifier columns and the role restrictions are in place. If a frequent term names a third party, delete it manually.
- Kind `search` (2026-10-25, ADR 001 §11.3): Jurídico updates `docs/lgpd/lia-demand-log.md` separately before PR7 (do not edit it in the ADR or backlog PR). The "Busca" row still covers unsuccessful terms only; the daily per-place count (no account, IP or session) is an input for the end-of-project policy rewrite (#155). Do not edit the privacy notice now.

## PostgreSQL only (decision 2026-10-21)
Source: [docs/decisions.md](../docs/decisions.md) (Rafael, 2026-10-21).
- [ ] Remove SQLite code paths (Db.php migrateSqlite and similar), SQLite tests and parity checks, SQLite config and .env options, and the SQLite backup references; remove any remaining MongoDB driver code. Then Teste runs a full regression on PG. Owner: Programador; Teste regression.
- [ ] Remove the legacy `ALTER`/`CREATE … IF NOT EXISTS` steps from `migrate()` (55 in `src/Db.php`) and adjust `schema_upgrade_test` and `account_tokens_test`: schema changes go to the schema file and seeds only (no data migration, decision 2026-10-21). Source: #99.
- [ ] `activity_log` on PostgreSQL: the table uses `actor_ref TEXT` (schema only; no conversion of old rows).
- [ ] `activity_log` on PostgreSQL follows the decided design (docs/decisions.md): INSERT-only app role, separate purge role, own transaction, sealed JSONL fallback loaded by a runit worker with COPY. Replaces "separate log DB and audit outbox".
- [ ] LGPD on the PG `activity_log`: retention per event class (Jurídico parecer J1), IP and email as HMAC, audit separated from BI. Involve Segurança.
- [ ] Segurança caveats on #109, must hold in the PG `activity_log`: CI asserts that the author/email HMAC is non-null and 64 hex; LogPrivacy key via `*_KEY_FILE`, 32-byte HMAC, no truncation (today `substr(…, 0, 16)` at `src/LogPrivacy.php:39`), with the single spec. Owner: Programador/Segurança.
- [ ] `deploy/runit/casalivre-log-retention/run:17` swallows exit codes 1 and 2 (`|| echo`), so a failed log purge never alerts in production. Wire an alert within 1 h of any non-zero exit. Estimate 1 h. Source: Arquitetura #148. Owner: Arquitetura (alert design, assigned by BD); Programador implements.
- [ ] Teste measures the `cl_logw` connection limit (20, estimated) against peak FPM plus worker connections. Source: Arquitetura #148. Owner: Teste.
- [ ] The `audit.audit_log` 5-year cleanup job must ship before 2031-10-21 (first rows expire then). Source: Arquitetura #148. Owner: BD; Programador.

## Sprint 5 groups rewrite (P2a: org tree + channels)
Source: D1, approved by Rafael 2026-10-21 ([docs/decisions.md](../docs/decisions.md)); spec-04. Groups become a management tree of free depth plus separate storefront channels; group domains and themes move to the channel. Acceptance requirements carried from the group backlog:
- [ ] Channel membership (Rafael 2026-10-21): a channel manager may remove an owner's property from their channel without the owner's consent, but the property is never deleted or hidden from the platform; the owner gets a notice and an audit log entry. Adding a property to a channel needs the owner's consent. (Was #63 C6.)
- [ ] Invites: single-use, valid 72 h, revocable (spec-04 04-6), with a per-inviter limit and rate limit. (Was #63 C5.)
- [ ] Rate limits on the channel domain verify/token routes and the theme selection route (today no limit on `/admin/*` and `/grupos/*`; was #64).
- [ ] Domain reverification: UPDATE conditioned on `fail_count`/`verified_at`; trailing dot in the TXT name (was #65 low). Revisor/QA #65 nits: `matches()` only used in tests; "em até 1 hora" duplicated; `saveDomainCheck` with 5 positional parameters; notice listing never-verified domains; CLI logs with channel "web"; log 46 without before/after values.
- [ ] Draft, unpublished or disabled channels return the same 404 as an unknown slug (was #67 B2).
- [ ] Explicit primary domain per channel; no N+1 in Mailer (`findBySlug` in a loop, `Mailer.php:144`) or `MailHtml.php:76-80` (was #68/#69). The color regex accepting `#rgb` is checked in the theme build.
- [ ] Per-domain certificates and states ("DNS ok · emitindo SSL", spec-04 §2); nginx config and per-domain session/login.
- [ ] Channel/node status active/inactive; notify the owner about invites (panel or email); menu link.
- [ ] A channel domain root shows the channel home (today: platform home with the group theme).
- [ ] Storefront search defaults to the channel's region (ADR 001: resolved place, no free-text search); reviews posted from the storefront come with the P3 reviews work.
- [ ] Admin and channel pages use `t()` (i18n). (Error pages following the theme and the `padrao` dark mode stay separate.)
- [ ] Channel URL helper without the GrupoUrl↔Mailer and MailHtml↔Mailer cycles: `linkFromAppUrl` in one URL helper, `'/g/'.slug` and the domain rule in one place, MailHtml defaults from Theme, no test-only `?callable $allowed` in `render`, no "por garantia" `dotStuff`.
- [ ] The seeded storefronts (`/g/...`) and verified domains work the same after the rewrite (regression; no data migration).

## P10 listing wizard (S5–S7)
Source: spec-03; `docs/produto/planejamento/sprints.md`. The wizard decides its own approach to forms; no-JS forms stay where they work (Rafael 2026-10-21).
- [ ] The edit page HTML (44 KB) is slimmed down in the wizard rewrite.

## Review follow-ups

### Places neighborhoods (#210, SCHEMA 22)
- [ ] Búzios: `places_city_uf_uk` drops the duplicate Búzios row (`database/cities.json` has two Armação dos Búzios/RJ rows; the second label is "Búzios"), so `CityRepository::match('Búzios')` returns null. Produto decides alias vs a dual city row. Source: #210. Sprint: ADR 001. Owner: Produto.
- [ ] `CityRepository::ensureNeighborhoods` top-up on an existing DB. Source: Teste followup from #210. Sprint: ADR 001. Owner: Teste.
- [ ] `CepRepository::ensureTable` runtime `CREATE TABLE` is DDL outside `database/schema.pgsql.sql`. Move it into the schema and the seeds (no migration). Source: Teste followup from #210. Sprint: ADR 001. Owner: Teste.

### ADR 001 PR1 (#218)
- [ ] Move the `status = 'active'` rule into HouseRepository (for example `HouseRepository::active($alias)`) and use it in `all()`, `listByUser()`, line ~451, SearchSql, `GroupRepository::properties()` and SeedGrupoTeste, instead of `SearchSql::active()` (4+ files). Do this after ADR 001 PR1 merges. Source: Revisor #218. Sprint: ADR 001 (after PR1 merges). Owner: Programador.

### SCHEMA 23 geo index (#220)
- [ ] `docs/adr/001-busca-sql-paginada.md` (~61 and ~338) still describes the GiST `idx_prop_geo_active` as the geo index. Update both to the partial btree `idx_prop_latlng_active` (#220). Doc fix. Estimate 0.5 h. Source: Revisor #220. Sprint: ADR 001. Owner: Arquitetura/Gerente.
- [ ] `listen_addresses=''` is set twice (`ops/dev-env/templates/postgresql.conf.in:3` and `ops/dev-env/sv/postgresql/run:26`). Keep one. Estimate 0.5 h. Source: Revisor #220. Sprint: the next sprint that touches `ops/dev-env`. Owner: Programador.

### ADR 001 PR2 places (#221)
- [ ] `HouseRepository::CITY_LABEL_SQL` (`src/HouseRepository.php:11`) and `cityText()` (`src/HouseRepository.php:68`) build the same "Name, UF" label in SQL that `place_label()` builds in PHP (`src/helpers/geo.php`), and both still use `rtrim(uf)`, a no-op on CHAR(2). `cityText` is also a second place-by-id query next to `CityRepository::byId()` / `option()` (`conta/Geo/CityRepository.php`). Estimate 1 h. Source: Revisor #221. Sprint: ADR 001. Owner: Programador.
- [ ] Once the old cities cache is removed, `CityRepository::refresh()` (`conta/Geo/CityRepository.php:483`) and its wiring (`scripts/refresh-settings-cache.php`, `README.md`, `prompts/deploy.md:22`) are write-only: remove them. Estimate 1 h. Source: Revisor #221. Sprint: ADR 001 (after the old cities cache is removed). Owner: Programador.

### Listing filter by place type (#223)
- [ ] The neighborhood listing filter does a sequential scan on properties (14 ms at 61k rows; no index added in #223). BD decides whether a `neighborhood_place_id` index is worth it after the listing-filter PR (#223) merges, under the ADR 001 §12.6 index budget (`docs/adr/001-busca-sql-paginada.md`). Estimate 0.5 h. Source: Programador #223. Sprint: ADR 001 (after #223 merges). Owner: BD.

### OSM POI seed (#225)
- [ ] The 2026-10-23 places entry in `docs/decisions.md` still says POIs are an admin-curated seed with Geribá, Praia do Forte and Ferradura pilots and "never a full Brazil OSM dump". #225 seeds 8,030 OSM POIs and removes the pilots. Update the entry or cite the newer decision. Estimate 0.5 h. Source: Revisor #225. Sprint: ADR 001 (after #225 merges). Owner: Gerente.
- [ ] `prompts/deploy.md:21` names only `places_neighborhoods.json.bz2` as opened by `migrate.php`. It now also opens `places_pois.json.bz2`, and it references `CityRepository::ensureNeighborhoods` and `::neighborhoodRows`, which no longer exist after #225 (now `ensureChildren` and `bz2JsonRows`). Update after #225 merges. Estimate 0.5 h. Source: Revisor #225. Sprint: ADR 001 (after #225 merges). Owner: Gerente.
- [ ] `docs/adr/001-busca-sql-paginada.md:401` expects "forte" to resolve to Praia do Forte. After #225, `resolve()` returns "ambiguous" for "forte", "centro" and "praia do forte" because POIs and cities fill LIMIT 9 and hide the neighborhood. The fix PR after #225 must make it match the ADR or update the ADR. Estimate 1 h. Source: Revisor #225. Sprint: ADR 001 (after #225 merges). Owner: Programador.
- [ ] `CityRepository::bz2JsonRows()` (`conta/Geo/CityRepository.php`, added in #225) and `CepRepository::openSource()` / `closeSource()` (`conta/Geo/CepRepository.php:148` and `conta/Geo/CepRepository.php:180`) each open and close bz2 files with their own error handling. Merge them in a cross-file refactor. Estimate 2 h. Source: Revisor #225. Sprint: a later sprint. Owner: Programador.

- [ ] (low, UX only) Reuse the activation/reset token when the mail job is retried, so the first email's link keeps working. Two valid tokens never exist today: the hash column per purpose is overwritten (`AccountFlow::issueLink:192-205`). Source: Segurança #167/#174. Sprint: the next sprint that touches `AccountFlow`. Owner: Programador.

Optional items from PR reviews, moved from the Gerente's notes (16/10). Owner is the bot expected to do the work; Gerente assigns cleanup PRs.

### P0-3b (dev environment)
Removed 2026-10-21: already done (#116).
- [ ] Temp PG test DB cleanup: retry DROP without FORCE, plus cleanup in `run.php`. The retry in `t_pg_drop()` has never been exercised. Source: #111 (Arquitetura), #116 (Teste). Owner: Programador.
- [ ] `up-bwrap.sh:55-57` creates the repo mount-point dirs inside the rootfs: document it or move it to `provision.sh`. Source: #111 (Arquitetura). Owner: Programador.

### Test helpers cleanup
Removed 2026-10-21: SQLite removal; already fixed.
- [ ] `t_seed_cep()` in `lib.php` for the 10 tests and 2 e2e seeds. Source: #111 (Revisor). Owner: Programador.
- [ ] Parameterize `t_fail_trigger` in `groups_test:397-402`. Source: #111 (Revisor). Owner: Programador.
- [ ] `check-test-deps.sh` should require `url.php` `ini_bytes()`. Source: #111 (Revisor). Owner: Programador.
- [ ] Add a `t_pg_connect(string $dsn)` helper so `t_temp_pg` and `t_pg_admin` set `TEST_PG_USER`/`PASSWORD` and ERRMODE in one place (`lib.php:106-108,114`). Source: #116 (Revisor). Owner: Programador.
- [ ] Move the shared runtime bwrap args and the /tmp mount-point rule from `test-pg.sh` and `up-bwrap.sh` into `lib.sh` helpers. Source: #116 (Revisor). Owner: Programador.
- [ ] Add `Db::close()` instead of the ReflectionProperty write at `lib.php:146`. Source: #116 (Revisor). Owner: Programador.

### Docs
Removed 2026-10-21: already done or obsolete.
- [ ] Translate `docs/produto/specs/spec-01-busca-ate-checkout.md` and `docs/produto/specs/spec-telas-busca-mapa-upload-fotos.md` to English. Owner: UI.

### Fluent Bit removal
- [x] Fluent Bit was dropped from the stack; removed from this backlog (Infra e dados) in this PR. Owner: Gerente.
- [ ] Remove Fluent Bit from the `src/RequestLog.php` comments or code. Owner: Programador.

### Queue worker (#118)
Removed 2026-10-21: no data migration (migrateLegacy goes away).
- [ ] Restart the queue worker on deploy, after the asset build, so `AssetManifest` and code changes are picked up; add the step to `prompts/deploy.md`. Source: Arquitetura #142. Owner: Programador.
- [ ] Recycle the queue worker under runit after a maximum job count, memory use or uptime. Source: Arquitetura #142. Owner: Programador.
- [ ] Dev env: give each bot its own port range (Teste's nginx held 38542 while Arquitetura used it). Source: Arquitetura #142. Owner: Programador.
- [ ] `FileStamp`: keep the stamp empty when a read fails, so a failed read is not cached as "unchanged". Today `AssetManifest::load` takes the stamp before reading, so a failed read caches `[]` until the file changes (still open after #170). Estimate 0.5 h. Source: Arquitetura #149, #170. Sprint: Priority now (with the ctime item). Owner: Programador.
- [ ] `GrupoResolvedor`: cap the number of expired entries kept in the TTL cache. Source: Arquitetura #149. Owner: Programador.
- [ ] `Settings`, `AssetManifest`, `I18n`: the same "skip if the stamp is empty or equal, else drop" check is copied 3 times and each class writes its file path twice. Move the comparison into `FileStamp` (for example, `FileStamp::changed($file, $stamp)`) and keep one file-path constant per class. Source: Revisor #149. Owner: Programador.
- [ ] `GrupoResolvedor` (`src/GrupoResolvedor.php:55-58,70`): keep `[group, expiry]` in one array instead of the parallel `$cacheExpira`, and drop the public mutable static `$clock` test hook. Source: Revisor #149. Owner: Programador.
- [ ] `queue_worker_manifest_cache_test` and `queue_worker_cache_unchanged_test` rewrite the tracked `public/assets/manifest.json`; use a temp copy instead. Still open after #170. Source: Arquitetura #149, #170. Sprint: Priority now (with the ctime item). Owner: Programador.
- [ ] `ValkeyJobQueue.php:105` reserve reads the whole processing list and reservation set (O(n)); review for P1. Source: #118 (Teste). Owner: Arquitetura.

### Older follow-ups (#99–#113)
Removed 2026-10-21: SQLite removal; already fixed; replaced by the libvips worker. Several items moved to ADR 001 and PostgreSQL only.
- [ ] Log only once when the schema is behind, and answer `/api` with a JSON 503; needed before production traffic. Source: #99 (medium). Owner: Programador.
- [ ] `I18n::all()` should not run `seed()` when `translations.json` is missing (low; `I18n::refresh()` seeds, `src/I18n.php:120-122`). Source: #100. Owner: Programador.
- [ ] Cori to confirm in `pg_stat_statements` that translations calls dropped to ~0. Source: #100. Owner: Cori.
- [ ] Teste on #100: exit-2 message of migrate should say to recreate the DB; CT-E2E-041 is flaky. (The `image_queue` FK goes to the schema spec, SCHEMA 21.) Owner: Programador/Teste.
- [ ] Calendar retention (period from Negócios) and types (item 9); LGPD retention: involve Segurança. Goes into the schema spec (SCHEMA 21). (`city_id`, item 10, is done by ADR 001 as `city_place_id`.) Source: BD scale review. Owner: Arquitetura/BD.
- [ ] Cleanup: remove `views/mailbox.php` (prints raw `$mail['html']`, no route uses it). Source: Segurança. Owner: Programador.
- [ ] Revisor on #106: README still says "port block" (now one port); the "run etc log sv data" list duplicated in `up-bwrap.sh` (and `up-incus.sh` since #120) should be `CL_MOUNT_DIRS` in `lib.sh` (Revisor, again on #120). Owner: Programador.
- [ ] Docs: sync the Jurídico spec-05 fiscal appendix draft (line 26, merged cancellation sentence) into `docs/produto/juridico/` and the spec-05 tax part. Owner: Gerente/Jurídico.

### P0-5 Incus path (#120)
Removed 2026-10-21: Incus polish dropped by Rafael; the writable-root/outbound-NIC risk is accepted (docs/decisions.md).
- [ ] `ops/dev-env/sv/postgresql/run`: send the PG log to the CL_HOME log dir so a fast shutdown can be checked. Source: Teste. Owner: Programador.
- [ ] `incus-init.sh` runs as container root from the writable repo mount: copy it into the image at import, or ro-bind `ops/dev-env` (Low-Med, dev only). Source: Segurança. Owner: Programador; Segurança reviews.
- [ ] `tests/standalone/devenv_up_incus_test.php` needs python3, which is not in the Void rootfs, so the full suite fails 3 cases inside the env. Use a non-Python stub, or add python3 to the rootfs. Source: Programador, found on #138. Owner: Programador.
- [ ] The PG prestart removes a stale `postmaster.pid` but not the socket lock `.s.PGSQL.5432.lock` in the PG run dir. After an unclean stop PG refuses to start, while `up.sh` still reports up. Remove the stale lock, and make `up.sh` fail when PG is not accepting connections. Source: Programador, found on #138. Owner: Programador.

### P1 image worker (libvips)
- [ ] Use xbps libvips, libvips-heif, php8.4-ffi and jcupitt/vips. `ffi.enable=preload` only in the CLI worker run by runit, never in FPM (needs Segurança review). Load-test with Teste on large photos (musl thread stack; tune `VIPS_CONCURRENCY` or the stack size). Source: Arquitetura. Owner: Programador, Segurança, Teste.
- [ ] A corrupt or undecodable photo is marked failed at once (no retry with backoff; today `ImageWorker` throws and the job retries up to 5 times, so the photo shows "Processando" for minutes). EXIF/GPS stays stripped. Source: #39.

## Segurança e LGPD
Removed 2026-10-21: already done (#23, #32, #35, #85). The LGPD item moved to PostgreSQL only.

- [x] ~~(Média, #47, já existia) Troca de e-mail não pede a senha atual, não confirma o novo endereço com token de uso único, não avisa o e-mail antigo e não aumenta o `session_version`.~~ Descartada: sem troca de e-mail (decisão do usuário, #59).
- [ ] (Baixa, #47, já existia) `PhotoRepository::makePrimary` aceita foto que não está com status `ready`.

- [x] (Feito no #23, confirmado pela Segurança na develop 34a62a2.) **ALTA, antes do próximo deploy público:** XSS armazenado em `views/property.php:38-44`, `views/search.php:~170` e `views/dashboard.php:~58` (innerHTML sem escape), mais CSP.

- [ ] Mailer: abortar se houver bytes no buffer depois do STARTTLS (injeção de resposta); TLS 1.2+ no `tls://`; prazo total da sessão (~20 s); retenção do mail-dev.log; não gravar `$lastTranscript` no log.
- [ ] Uma só validação do `APP_URL` em `app_url()`, exigindo https fora de dev/local e validando o caminho; o Mailer passa a usá-la.
- [ ] Tirar `senha` e `link` de `VALUE_KEYS` (ou passar `link` por `LogPrivacy::url`).
- [ ] `.env.example` com `APP_URL` vazio.

- [ ] `BODY_VALUE_ALLOWLIST` limitado a valores escalares.
- [x] ~~Troca de e-mail estando logado: confirmar o e-mail novo por link.~~ Descartada por decisão do usuário (#58/#59).
- [ ] Criptografar `calendar_feeds.url`, `properties.external_ical_url` e `audit_hold`, com coluna HMAC para duplicados.
- [ ] Prefixos IPv6/TEST-NET restantes em `CalendarSync::isPublicIp` (2001:10::/28, 2001:20::/28, 3fff::/20, 5f00::/16, fec0::/10); `logRef` via `LogPrivacy::url()`; `redactPath` cobrindo segmentos opacos de 20+ caracteres.

## Funcionalidades e API
Removed 2026-10-21: decided by Rafael (no blanket fetch/API move; P10 decides).

- [ ] Map: list cards beyond the top 300 pins highlight their area on the map (for example a cluster cell id per item). Could-have. Source: UI via Produto (#189). Sprint: post-pilot. Owner: UI; Programador.
- [ ] Escolher o provedor SMTP e abrir o cadastro em produção.
- [ ] Expor ou gerar o `ical_hash` pela API (hoje só é criado ao abrir `views/calendar.php:140`).
- [ ] Trocar `alert()` dos formulários por mensagens na própria página.

## Bugs
Removed 2026-10-21: already done; no legacy data; moot after the Valkey rate limiter.

- [ ] Testes: no erro de nonce do t_server(), mostrar só as últimas 20 linhas do stderr, e não umas 200.

- [ ] Baixa (QA #34): não há E2E que submeta avaliação.

## Infra e dados
Removed 2026-10-21: SQLite removal. Items moved to PostgreSQL only.

- [ ] `LogRetention::run` incluir arquivos `*.ret-*` remanescentes.
- [ ] Add the CI gate for money paths (PHPStan max + php-cs-fixer + coverage check) before the first money PR ([docs/money-standards.md](../docs/money-standards.md)). Owner: Programador; Teste.
- [ ] JobQueue: claim jobs with a single `UPDATE ... FOR UPDATE SKIP LOCKED ... RETURNING`, add a lease token, make `fail()` atomic, and make BD's queue-table changes in the same separate PR. The lease token also lets a reserved job be reclaimed sooner than 10 min (Arquitetura #167). Estimate 0.5 d. Source: Arquitetura #148. Sprint: Priority now. Owner: Programador; BD.
- [ ] Add a Valkey service to CI; `valkey_queue_test` is still skipped there. Estimate 1 h. Source: Arquitetura #148. Owner: Programador.
- [ ] After a Valkey failure, skip Valkey for about 5 s per worker so rate-limited routes don't each pay the 100 ms connect timeout. Estimate 1 h. Source: Arquitetura #160. Sprint: ADR 001. Owner: Programador.
- [ ] Add a production `valkey.conf` to the repo: `maxmemory` with `maxmemory-policy noeviction` only (not `volatile-ttl`: the limiter and `cl:cd:*` keys all have TTLs, so they would be evicted first and silently reset limits and cooldowns) plus a memory alert at 80%, `appendonly yes` + `appendfsync everysec`, and require Valkey 7 or later (`EXPIRE NX`). Estimate 1 h. Source: Arquitetura #160; Segurança sign-off on noeviction. Sprint: ADR 001. Owner: Programador; Segurança.

## Redução de dados enviados
Removed 2026-10-21: already done (#36, #39); superseded (20 MB limit). Items moved to ADR 001 and P10.

Origem: análise do Bot Segurança e Arquitetura (develop e425cbd). Hoje a busca, o detalhe, o painel e a edição baixam cerca de 13,4 MB, e 98% disso são fotos originais.
- [x] **PR de fotos (feito no #39, com GD e WebP 320/800/1920; libvips e AVIF não adotados):** resize no upload, original fora de public/, limite de 50 MP e script de reprocessamento. Ganho medido: 13,4 MB → ~0,3 MB.
- [ ] Sessão só quando há cookie ou rota autenticada. `ETag`/304 nas rotas públicas e `private, no-store` nas autenticadas (pré-requisito de cache/CDN).
- [ ] Período `?de=&ate=` no calendário (hoje são 24 meses). (A paginação da `/api/busca` é o ADR 001.)
- [ ] Referer nos tiles da busca.
- [ ] Avaliar: cache OSS (só depois da sessão), CDN comercial e tiles próprios (PMTiles/OpenMapTiles).

## Concluído

- [x] Sprint 3 (#54–#69): assets com hash, temas, grupos, domínios verificados por DNS TXT, reverificação e e-mail HTML (SCHEMA 13); #66 (E2E e script de carga `.ics`) mesclado na develop (b605dc7).
- [x] Fechados na sprint 3: troca de e-mail descartada (decisão do usuário, #58/#59); #67 B1 (filtro de grupo no SQL) e B3 (forçar o grupo resolvido em `/api/busca` no domínio do grupo), feitos no #67/#68; comentário do `findBySlugValid` (o método existe em `GroupRepository`); host do SearchService obrigatório (#68); `Vary: Host` não é necessário (Segurança #64); C1 (verified_at + DNS TXT) e C3 (normalizar Host), feitos no #64/#65; script de carga `.ics` chamando a exportação com id vazio (#66).
- [x] Sprint 2: refatoração (#44 testes de caracterização, #45 app-env, #46 index-servicos, #47 ações compartilhadas, #49 api-guardas, #50 helpers por tema), #43 (script `run-casos-de-teste`) e #48 (avaliação pela web com limite de 10/h por IP, igual à API) mesclados na develop (b13328b).
- [x] Sprint 2: PRs #35, #36, #38, #39 e #40 mesclados na develop (fca4832).

- [x] PR #34 (pipeline de segurança: rate limit da API, `Csrf::rawBody`/413, máscara no `error.log`, sessão preguiçosa) mesclado na develop (7de17a9).
- [x] PRs #18 (scripts de teste) e #19 (docs) mesclados na develop (6b26252).

## Regras

- Próximo PR que mudar seeds ou schema usa `SCHEMA_VERSION` 4, sobre o #21.

- [x] Correções pós-merge: PRs #11 a #17 mesclados na develop (4c609b3) e retestados no PHP 8.2 e no 8.4: standalone 57/57, PHPUnit 27/27, casos 83/0, carga 1000/1000, E2E 15/15, logs sem dados pessoais.

- [x] Sprint 1: PRs #1 a #10 mesclados na develop (a6d5a48) e testados: PHPUnit 27/27, 15/15 páginas HTML e 1.000/1.000 na carga do `.ics`.
