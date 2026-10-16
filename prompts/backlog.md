# Backlog de desenvolvimento — CasaLivre

Rastreamento das pendências durante o desenvolvimento. **Só o Bot Gerente Geral edita este arquivo**; os analistas enviam os itens para consolidação. O que é necessário para subir em produção fica em `prompts/deploy.md`.

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
- [ ] #35: depois de redefinir a senha, a página diz "Conta ativada"; o formulário sem JS não mostra o erro.
- [ ] #35: `MailQueue.php:85` usa valor fixo quando `LOG_SALT` está vazio.
- [ ] #36: bairro com nome de cidade é buscado como a cidade.
- [ ] #36: `public_coord` pode sair da faixa depois de 64 tentativas.
- [ ] #39: arquivo recusado devolve 201 "salvas: 0" em vez de 422.
- [ ] #39: foto corrompida fica "Processando" por minutos.
- [ ] #40: glifos só para alfabetos latinos.
- [ ] #40 (info): o bloco nginx dos tiles não envia CSP.
- [ ] Testes: `mailer_test`/`mailer_unconfigured_test` instáveis por resíduo de rate limit em `storage/cache/ratelimit`.
- [ ] Testes instáveis: `CT-E2E-070` falha às vezes quando roda junto com o 071 (corrida de tempo no teste).
- [ ] Testes instáveis: `account_tokens_test` (13 de 15 já na develop 5d697b0); abrir issue separada.
- [ ] #48 (obs. baixa, por design): o limite de avaliação por IP vale para todos os imóveis juntos, e não por imóvel.

### Comportamentos achados no #44 (sem correção)
- [ ] Salvar a conta sem mudanças registra `ACCOUNT_UPDATE`.
- [ ] O `activity_log` grava e-mail e autor como `null`.
- [ ] O dono de um imóvel pausado recebe 404 ao editar.
- [ ] A API responde `ok:true` com erro de validação no calendário e com falha no iCal.
- [ ] A API dá 419 em vez de 401 sem sessão.
- [ ] Na web, excluir um feed inexistente dispara a liberação de datas e a ressincronização.

### Mapa: fora do #40
- [ ] Buscar ao mover o mapa / "Buscar nesta área".
- [ ] Lista e mapa lado a lado.
- [ ] Carrossel no mobile.
- [ ] Skeletons dos cards.
- [ ] Sincronização entre card e pino.

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
- [ ] Temas por grupo de imóveis (imobiliária ou rede). Cada tema é uma identidade visual completa (logo, favicon, imagens, cores, fontes, ícones e sprite, estilo do mapa, cabeçalho e rodapé de e-mail) numa pasta `themes/<nome>/` preparada pelo time e versionada no repositório. Não há upload de imagens pelo grupo. O layout e as funcionalidades não mudam: o código só aponta para os arquivos do tema. Um build gera os arquivos com hash e um manifesto.
- [ ] Uma função única descobre o grupo e o tema de cada requisição, nesta ordem: (1) o domínio, por uma lista fixa de domínios aceitos; (2) o endereço `/g/<nome>/`; (3) o grupo do imóvel; (4) o tema da plataforma (`settings.theme`).
- [ ] A vitrine `/g/<nome>/` tem home, busca e mapa só com os imóveis do grupo, no tema dele, e os links continuam dentro da vitrine. O nome do endereço é único e usa só letras minúsculas, números e hífen.
- [ ] Links e e-mails saem gerados com o domínio certo do grupo.
- [ ] Modelo de dados: `groups` (slug, nome, tema), `group_members` (papéis), `properties.group_id` e a lista de domínios por grupo. Vincular um imóvel a um grupo exige o aceite do dono do imóvel. Toda mudança vai para a auditoria. O tema é aceito só da lista do manifesto. O selo da plataforma fica sempre visível.
- [ ] Ficam para depois: a configuração do nginx, os certificados por domínio e a sessão ou login separados por domínio.

## Sprint 3: pendências (#56–#69)

### Segurança
- [ ] (baixa, #63 C5) Convites de grupo sem expiração, limite ou rate limit.
- [ ] (decisão, #63 C6) Gestor remove imóvel do grupo sem aval do dono (registrado no log 43).
- [ ] (#64) Rate limit em `/admin/grupos/.../dominios/verificar` e `/dominios/token`, junto com `/admin/tema` (hoje sem limite em `/admin/*` e `/grupos/*`).
- [ ] (baixa, #65) UPDATE de reverificação condicionado a `fail_count`/`verified_at`; ponto final no nome TXT.
- [ ] (baixa, #67 B2) Grupos privados ou desativados devem dar a mesma 404 de slug inexistente (depende do status de grupo).
- [ ] (#68) N+1 no Mailer (`findBySlug` no laço, `Mailer.php:144`); domínio principal explícito por grupo (hoje o GrupoUrl usa o primeiro verificado por id).
- [ ] (baixa, #69) N+1 em `MailHtml.php:76-80`. (info) A regex de cor aceita `#rgb`.
- [ ] (#56, risco aceito) `img-src 'self'` bloqueia o `blob:` do MapLibre em navegadores sem `createImageBitmap` (Safari antigo). Avaliar `img-src blob:` se aparecer reclamação.
- [ ] (baixa, #60) Conferir no LoginAttempt se `err.deleted`/`err.inactive` aparecem antes da checagem da senha (revelaria o estado da conta).
- [ ] (baixa, #61) Diferença de poucos ms em `/senha/esqueci` (INSERT na `mail_queue` só se o e-mail existe) e no login de conta pendente com reenvio; abaixo do ruído e coberta por rate limit.
- [ ] (QA #61, já existia) Conta bloqueada responde "Conta bloqueada" (423 na API), revelando que existe; o primeiro 429 por IP sai sem `Retry-After`.
- [ ] (Reunião 15/10) Quando a importação de `.ics` externo (Airbnb/Booking) entrar, criar teste próprio: SSRF, arquivo gigante e parser malicioso.
- [ ] (baixa, #73) Limite de tentativas por IP no `POST /verificar`.
- [ ] (baixa, #73) `Cache-Control: no-store` na página de confirmação da ativação (hoje `private, no-cache`, com o token no HTML).
- [ ] (baixa, #73 D3) O RequestLog grava HEAD em `/api` como GET (o método é trocado antes do `RequestLog::start`) e o HEAD com 405 em `/api` não gera linha; passar o método original.
- [ ] (baixa, #73) `GET /verificar?token[]=x` gera PHP Warning (Array to string).
- [ ] (baixa, #72) `/idioma` compara o Referer com o Host incluindo a porta; em dev com porta, sempre volta para `/`.

### Produto / UX
- [ ] Status ativo/inativo de grupo; avisar o dono sobre convite (painel ou e-mail); link de grupos no menu.
- [ ] A raiz do domínio de grupo mostra a home da plataforma com o tema do grupo; avaliar redirecionar `/` para `/g/<slug>/`.
- [ ] Vitrine: busca sem exigir local; avaliação postada pela própria vitrine.
- [ ] Textos do `/admin` e das páginas de grupo sem `t()` (i18n); páginas de erro não seguem o tema; modo escuro do tema `padrao` (o build já suporta).
- [ ] Mapa: `?v=` nos sprites e nos glyphs (`/assets/map/` é immutable); o mapa de reserva (sem tiles) não segue o tema; cores por expressão (landcover, pois, roads_minor) não são trocadas pelo tema.
- [ ] Sistema de avisos (ou fila) para os e-mails do admin (hoje `Mailer::deliver` direto); link de admin montado à mão no e-mail (#68).
- [ ] (Divergência 6, docs sprint 3) Temas sem checagem de contraste AA: o código só exige que o tema esteja em `Theme::available()` (manifesto + `NAME_RE`). Decidir se o build passa a checar o contraste ou se a regra fica só no processo de preparo do tema.
- [ ] (Divergência de validação web, docs sprint 3) Códigos de erro inconsistentes entre telas web: `POST /conta`, `POST /imoveis` e `POST /imoveis/<h>` respondem 200 com o formulário, enquanto as telas de grupo e `/admin/tema` respondem 422/409. Padronizar.

### Qualidade / dívida técnica
- [ ] Unificar as regras de "ativo" (`activeProperty` vs `activePropertyStrict`, `status='active'` literais em HouseRepository/activeUser); a home mostra imóveis com status `''`.
- [ ] Rotas do dono: 404 em texto para imóvel de outro e página HTML para código inexistente (padronizar).
- [ ] Bug pré-existente: `POST /conta` inválido na web não mostra mensagem (`#account-error` vazio).
- [ ] Dependências circulares GrupoUrl↔Mailer e MailHtml↔Mailer (`linkFromAppUrl` num helper de URL, hoje calculado 2×); `'/g/'.slug` em 2 lugares; regra de domínio repetida em GrupoUrl e `doDominio`; DEFAULTS do MailHtml duplicam o `:root` do `app.css` (usar Theme); `?callable $allowed` em `render` só para teste; `dotStuff` "por garantia".
- [ ] Revisor #65: `matches()` só usado em teste; "em até 1 hora" duplicado; `saveDomainCheck` com 5 parâmetros posicionais. QA #65: aviso lista domínios nunca verificados; logs da CLI com canal "web"; log 46 sem valores antes/depois.
- [ ] `SearchService` lê `$_GET` direto.
- [ ] Testes: schema pgsql (sem Postgres no ambiente) e caminho com DNS real; 4 testes E2E desatualizados; flake de tempo do `account_tokens_test` ("tempo semelhante": usar mediana de N ou mais tolerância); `storage/cache/settings.json` compartilhado entre execuções de teste; `/assets/../index.php` no servidor de desenvolvimento dá 500 vazio (Cannot redeclare).
- [ ] (Reunião 15/10) Arquivo comum de apoio aos testes (cadastro, ativação via `mail-dev.log`, login) usado por `run-casos-de-teste`, `api-ical-log` e `retencao-concorrente`; remove a `link_ativacao` duplicada. Regras da Segurança: recusar rodar se `APP_ENV` não for dev/local; ficar fora do pacote de produção; limpeza direta no banco (ex.: `DELETE` do limite de login em `retencao-concorrente.php:73`) só nele e com a mesma trava; e-mails de teste sempre `@casalivre.test`; apagar o `mail-dev.log` no fim da suíte; limpar `login_rate_limit` só das linhas de `127.0.0.1` e `::1` (como `retencao-concorrente.php:73`), nunca a tabela inteira (hoje `run-casos-de-teste.php:107` faz `DELETE FROM login_rate_limit` sem filtro); antes de qualquer `DELETE`, conferir que o banco aberto é o de desenvolvimento (não basta `APP_ENV=dev`) e recusar se não for. Tarefa do Bot Teste, depois do fechamento da sprint 3.
- [ ] (Reunião 15/10) Separar `scripts/casos/api-ical-log.php` por assunto (api, ical, log), reaproveitando o arquivo comum de testes. Tarefa do Bot Teste.
- [ ] Opcionais do Revisor não feitos:
  - #58/#59: parâmetros mortos `$requirePassword`/`$current` em `AccountFlow.php:16/:52`; comentário v9→v8 em `schema_upgrade_test.php:3`; logout duplicado web/API; AccountUpdate com muitas responsabilidades; truque `$fields['email']`.
  - #62: `themePath` sem `assetPath`; validação repetida no `mapStylePath`; `clean()` duplo; cópia profunda; regex frágil de camadas.
  - #63: comentário com constantes inexistentes; `isUniqueViolation` no Db; `propertyRef` validado 2×; `activeUser` com um uso; 2 cópias do filtro de ativo.
  - #66 (`retencao-concorrente.php`): `$appDb` muda de string para PDO (:71/:79); PDO aberto à mão em :73 em vez de `pdo()`; código do filho num `-r` de uma linha (:98-103); fallback `/ical/000…` (:108) e ramo `$icsPaths ? … : $icsPath` só servem ao caso sem imóveis. Resolver junto com o arquivo comum de testes.
- [ ] Tentar de novo o WAL ao criar activity log novo (deploy novo com pedidos simultâneos).

### Pendências pós-paridade (#83–#94)
- [ ] (Segurança #93) Chamar `CalendarSync::isHttpsUrl` dentro de `resolveTarget` (`CalendarSync.php:212`), para a mesma regra de https valer em cada redirecionamento.
- [ ] (Segurança #85/#88) Limitador de taxa compartilhado (tabela no banco com UPSERT ou Redis com INCR+EXPIRE) antes do segundo servidor de aplicação.
- [ ] (baixa, Segurança #87) A API lista as fotos com `SELECT *` (`src/PhotoRepository.php:11`) e mostra ao dono colunas internas (`original`, `uuid`, `status`). Trocar por uma lista fixa de campos.
- [ ] (Teste, #87) A resposta para um id de foto inexistente deve ser igual à de uma foto de outro imóvel (travar com teste).
- [ ] (baixa, Segurança #88) Tornar atômico o intervalo da `mail_queue` (`src/MailQueue.php:80-95`, hoje SELECT e depois INSERT): transação `BEGIN IMMEDIATE`, INSERT condicionado ou `UNIQUE` em `cooldown_key` por janela.
- [ ] (baixa, Segurança #88) `IpRateLimiter`: `peek` lê sem `flock`; o retorno do `flock()` não é conferido; `purge()` pode apagar um arquivo no instante em que outro processo o abre.
- [ ] (Segurança #93) Se algum worker CLI passar a rodar em laço, chamar `GrupoResolvedor::limparCache()` a cada iteração.
- [ ] WAL: tentar de novo ao criar um activity log novo (deploy novo com pedidos simultâneos). Mesmo item da seção de qualidade acima.

## Sprint 4 (não iniciada)

Itens aprovados pelo usuário (15/10), a partir do diagnóstico do Bot Arquitetura e Desempenho na develop a2e0dc8.

1. [ ] **Busca com filtro no SQL e paginação.** Filtrar no SQL por status, cidade e hóspedes; paginar a `/api/busca` (`pagina`/`por_pagina`, `total`); criar um modo leve para o mapa. Hoje `SearchService::search` chama `HouseRepository::all()` (`src/SearchService.php:50`) e filtra em PHP, e a `/api/busca` devolve todos os itens (`src/Api.php:287-312`). Bench local sintético: ~24 ms com 2k imóveis e ~41 ms com 5k. Dono técnico: Bot Arquitetura e Desempenho (ADR `001-busca-sql-paginada`).
2. [ ] **Índice (status, city).** Fazer depois do item 1. Hoje o EXPLAIN de city+status usa só `idx_properties_status`.

Descartado: medição de concorrência de escrita no SQLite. O usuário decidiu que não é necessária, porque a migração de banco será feita de qualquer forma no futuro.

## ADR 001 search implementation
Source: ADR 001 (incl. §11) and `docs/produto/specs/spec-busca-localidade-resolvida.md`.
- [ ] Implement ADR 001 search: SQL search with pagination, `places` and `place_search_keys` (SCHEMA 20), search only on a resolved place or map bbox, free-text full scan removed, total-price sort guard (5,000 candidates), demand log with Segurança conditions 1–7. Owner: Programador. Starts after P0-5 (#120) and the `cl_app` ACL PR below are merged.
- [ ] Valkey ACL PR: add `+hlen +hexists` to `cl_app` (`ops/dev-env/services.sh:37`) and the prod ACL; add `hlen cl:x` → ok and `hlen x` → NOPERM cases to `ops/dev-env/valkey-acl-check.sh`. Owner: Programador; Segurança informed.

Blockers before the demand log ships (Jurídico, ADR 001 §11 P7 legal verdict):
- [x] (Done in #131.) Privacy notice: add a "Busca" row to `docs/produto/juridico/textos/politica-de-privacidade.md` with Jurídico's text: "Termos buscados sem resultado são registrados de forma agregada e sem identificação (sem conta, IP ou sessão), apenas quando repetidos por pelo menos 5 buscas no dia, para melhorar a oferta de imóveis. Base: legítimo interesse. Guarda: 12 meses." Do not promise "anonymous" for the transient phase. Owner: Jurídico.
- [x] (Done in #131; ANPD guide v1.0, Feb 2024, verified.) LIA at `docs/lgpd/lia-demand-log.md` (LGPD art. 10 §3): purpose (search coverage and inventory), necessity, balancing (no profiling, no user link, low impact on expectations), safeguards. Check the current version of the ANPD legitimate-interest guide. Owner: Jurídico.
- Acceptance (Jurídico): both files exist; Segurança items 1–7 are implemented; the PG table has no identifier columns and the role restrictions are in place. If a frequent term names a third party, delete it manually.

## Review follow-ups

Optional items from PR reviews, moved from the Gerente's notes (16/10). Owner is the bot expected to do the work; Gerente assigns cleanup PRs.

### P0-3b (dev environment)
- [ ] Test that fails if the app connects to PG as a superuser. Source: #111 (Arquitetura). Owner: Programador.
- [ ] Test that the nginx photo-route regex matches `public/index.php`. Source: #111 (Arquitetura). Owner: Programador.
- [ ] Temp PG test DB cleanup: retry DROP without FORCE, plus cleanup in `run.php`. The retry in `t_pg_drop()` has never been exercised. Source: #111 (Arquitetura), #116 (Teste). Owner: Programador.
- [ ] `provision.sh:143-145`: build the bwrap binds with `BW_BASE` in ro-bind mode; drop the dead if/else at `provision.sh:141,147-148`. Source: #111 (Revisor). Owner: Programador.
- [ ] `up-bwrap.sh:55-57` creates the repo mount-point dirs inside the rootfs: document it or move it to `provision.sh`. Source: #111 (Arquitetura). Owner: Programador.

### Test helpers cleanup
- [ ] `t_seed_cep()` in `lib.php` for the 10 tests and 2 e2e seeds. Source: #111 (Revisor). Owner: Programador.
- [ ] Parameterize `t_fail_trigger` in `groups_test:397-402`. Source: #111 (Revisor). Owner: Programador.
- [ ] `t_temp_db` and `run.php:10-12` should use `t_is_pg()`. Source: #111, #116 (Revisor). Owner: Programador.
- [ ] Parity test should fall back to `TEST_PG_*` (drop the copy in `test-pg.sh:13,17`). Source: #111 (Revisor). Owner: Programador.
- [ ] `check-test-deps.sh` should require `url.php` `ini_bytes()`. Source: #111 (Revisor). Owner: Programador.
- [ ] `char_snapshot_test`: change the "dev 8M" comment to 20M (dev now matches production). Source: #111 (Segurança). Owner: Programador.
- [ ] Add a `t_pg_connect(string $dsn)` helper so `t_temp_pg` and `t_pg_admin` set `TEST_PG_USER`/`PASSWORD` and ERRMODE in one place (`lib.php:106-108,114`). Source: #116 (Revisor). Owner: Programador.
- [ ] Move the shared runtime bwrap args and the /tmp mount-point rule from `test-pg.sh` and `up-bwrap.sh` into `lib.sh` helpers. Source: #116 (Revisor). Owner: Programador.
- [ ] Add `Db::close()` instead of the ReflectionProperty write at `lib.php:146`. Source: #116 (Revisor). Owner: Programador.

### Docs
- [ ] tests/README: `test-pg.sh` defaults to the host PHP; run with `CL_PHP=php8.4`. With `CL_PHP=php` it stops at the 16M upload-limit dependency check by design. Source: #111, #116 (Teste). Owner: Gerente.
- [ ] `prompts/team.md`: add `docs/decisions.md` to the Gerente-only list at :22; at :25, say Gerente also adds ready-to-merge when it sets the last approver label for a bot without GitHub writes. Source: #114 (Revisor). Owner: Gerente.
- [ ] Translate `docs/produto/specs/spec-01-busca-ate-checkout.md` and `docs/produto/specs/spec-telas-busca-mapa-upload-fotos.md` to English. Owner: UI.

### Fluent Bit removal
- [x] Fluent Bit was dropped from the stack; removed from this backlog (Infra e dados) in this PR. Owner: Gerente.
- [ ] Remove Fluent Bit from the `src/RequestLog.php` comments or code. Owner: Programador.

### Queue worker (#118)
- [ ] Move the `migrateLegacy` doc comment at `PhotoStorage.php:104` back to its method. Source: #118 (Teste). Owner: Programador.
- [ ] A failed enqueue in the legacy migration (`PhotoStorage.php:150`) leaves orphan files and is never retried. Source: #118 (Teste). Owner: Programador.
- [ ] `ValkeyJobQueue.php:105` reserve reads the whole processing list and reservation set (O(n)); review for P1. Source: #118 (Teste). Owner: Arquitetura.

### Older follow-ups (#99–#113)
- [ ] Log only once when the schema is behind, and answer `/api` with a JSON 503; needed before production traffic. Source: #99 (medium). Owner: Programador.
- [ ] Separate PR removing the legacy ALTER/CREATE IF NOT EXISTS from `migrate()`, adjusting `schema_upgrade_test` and `account_tokens_test`. Source: #99. Owner: Programador.
- [ ] PG parity test should compare the full `pg_get_indexdef` (medium); remove dead code `byCity` (low); `I18n::all()` should not run `seed()` when `translations.json` is missing (low, I18n.php:159). Source: #100. Owner: Programador.
- [ ] Cori to confirm in `pg_stat_statements` that translations calls dropped to ~0. Source: #100. Owner: Cori.
- [ ] ADR 001 proposes removing `idx_properties_status` and, if unused, `idx_properties_city`. Owner: Arquitetura/BD.
- [ ] Revisor on #100: update the `SCHEMA_VERSION` comment at Db.php:183; remove or justify the duplicated `Db::SCHEMA_META_DDL`; store `sqliteVersion` in a variable in `migrateSqlite` (Db.php:379-380). Owner: Programador.
- [ ] Teste on #100: exit-2 message of migrate should say to recreate the DB; evaluate an FK on `image_queue`; CT-E2E-041 is flaky. Owner: Programador/Teste.
- [ ] Void-musl findings (Cori): `run-casos-de-teste.php` uses `email GLOB` (SQLite only); `map_front` uses GLOB_BRACE (missing on musl); `incus stop` needs `--force` because runit ignores the stop signal; snapshot html-erro-413 assumes `post_max_size=8M`. Make tests environment-independent. Owner: Programador.
- [ ] Calendar retention (period from Negócios), types (item 9), `city_id` (item 10); LGPD retention: involve Segurança. Source: BD scale review. Owner: Arquitetura/BD.
- [ ] Cleanup: remove `views/mailbox.php` (prints raw `$mail['html']`, no route uses it). Source: Segurança. Owner: Programador.
- [ ] Segurança caveats on #109: CI assert that the author/email HMAC is non-null and 64 hex; LogPrivacy key via `*_KEY_FILE`, 32-byte HMAC, no truncation (src/LogPrivacy.php:39), with the single spec. Owner: Programador/Segurança.
- [ ] Revisor on #106: README still says "port block" (now one port); the "run etc log sv data" list duplicated in `up-bwrap.sh` (and `up-incus.sh` since #120) should be `CL_MOUNT_DIRS` in `lib.sh` (Revisor, again on #120). Owner: Programador.
- [ ] #112 Arquitetura low findings: `valkey-acl-check.sh:10` should use `env_alive`; in dev the app user can read the admin secret. Owner: Programador.
- [ ] #113 optional B1/B2 (Gerente's ADR notes, revisao-pr113.md). Owner: Programador.
- [ ] Docs: sync the Jurídico spec-05 fiscal appendix draft (line 26, merged cancellation sentence) into `docs/produto/juridico/` and the spec-05 tax part. Owner: Gerente/Jurídico.

### P0-5 Incus path (#120)
- [ ] `down.sh:12`: with no incus client, a RUNNING container is treated as stale; warn when `command -v incus` fails. Source: Teste. Owner: Programador.
- [ ] `incus-init.sh`: `exec` the `as_user` runsvdir so only PID 1 runs as root. Source: Teste. Owner: Programador.
- [ ] `incus-init.sh` worst-case stop is longer than the 30 s stop timeout; align them. Source: Teste. Owner: Programador.
- [ ] `down.sh` leaves the instance STOPPED and the `cl-rootfs` image behind; prune old `cl-rootfs-*` images. Source: Teste, Arquitetura. Owner: Programador.
- [ ] Preflight order: the subid check and the STOPPED-delete run before `refuse_running`. Source: Teste, Arquitetura. Owner: Programador.
- [ ] `ops/dev-env/sv/postgresql/run`: send the PG log to the CL_HOME log dir so a fast shutdown can be checked. Source: Teste. Owner: Programador.
- [ ] `incus-init.sh` runs as container root from the writable repo mount: copy it into the image at import, or ro-bind `ops/dev-env` (Low-Med, dev only). Source: Segurança. Owner: Programador; Segurança reviews.
- [ ] Container root is writable and the default NIC gives outbound network (Low, dev only). Source: Segurança. Owner: Arquitetura/Segurança to decide.
- [ ] `down.sh:14-15`: PG is stopped twice (explicit `incus exec sv stop`, then SIGPWR in `incus-init.sh:20`); drop the explicit stop. Source: Revisor. Owner: Programador.
- [ ] Add `incus_exec()` to `lib.sh` for the repeated `incus exec --user --group --cwd` pattern (`db-setup.sh:22-25`, `down.sh:13`). Source: Revisor. Owner: Programador.
- [ ] `up.sh:15`: use `lib.sh` `die` instead of the hand-rolled error/exit. Source: Revisor. Owner: Programador.
- [ ] README: a change to `incus-init.sh` takes effect only when the container is recreated. Source: Arquitetura. Owner: Programador.
- [ ] `test-pg.sh` and `valkey-acl-check.sh` are bwrap-only; support the Incus path. Source: Arquitetura. Owner: Programador.

### P1 image worker (libvips)
- [ ] Use xbps libvips, libvips-heif, php8.4-ffi and jcupitt/vips. `ffi.enable=preload` only in the CLI worker run by runit, never in FPM (needs Segurança review). Load-test with Teste on large photos (musl thread stack; tune `VIPS_CONCURRENCY` or the stack size). Source: Arquitetura. Owner: Programador, Segurança, Teste.

## Segurança e LGPD

- [x] ~~(Média, #47, já existia) Troca de e-mail não pede a senha atual, não confirma o novo endereço com token de uso único, não avisa o e-mail antigo e não aumenta o `session_version`.~~ Descartada: sem troca de e-mail (decisão do usuário, #59).
- [ ] (Baixa, #47, já existia) O catch genérico de `PDOException` no `AccountUpdate` vira "e-mail em uso"; deveria tratar só a violação de unicidade.
- [ ] (Baixa, #47, já existia) `PhotoRepository::makePrimary` aceita foto que não está com status `ready`.
- [ ] `CL.el`: passar `action`, `formaction` e `xlink:href` pelo safeHref (vai no #23).

- [x] (Feito no #23, confirmado pela Segurança na develop 34a62a2.) **ALTA, antes do próximo deploy público:** XSS armazenado em `views/property.php:38-44`, `views/search.php:~170` e `views/dashboard.php:~58` (innerHTML sem escape), mais CSP.
- [ ] `HouseService::fromInput`: responder 422 a campo de texto enviado como array (hoje grava "Array").

- [ ] **ALTA, antes de abrir o cadastro:** enviar o e-mail por fila (storage/mail ou tabela) com worker ou cron, com resposta em tempo constante no esqueci-senha (hoje o tempo revela quais e-mails têm conta).
- [ ] Mostrar "Enviamos um link" só depois de enfileirar com sucesso, e avisar o usuário quando o envio falhar.
- [ ] Mailer: abortar se houver bytes no buffer depois do STARTTLS (injeção de resposta); TLS 1.2+ no `tls://`; prazo total da sessão (~20 s); retenção do mail-dev.log; não gravar `$lastTranscript` no log.
- [ ] Uma só validação do `APP_URL` em `app_url()`, exigindo https fora de dev/local e validando o caminho; o Mailer passa a usá-la.
- [ ] Tirar `senha` e `link` de `VALUE_KEYS` (ou passar `link` por `LogPrivacy::url`).
- [ ] `.env.example` com `APP_URL` vazio.
- [ ] Login de conta pendente pela API reenviando o link quando ele expirou.

- [ ] **Em andamento (#35):** resposta genérica no cadastro com e-mail existente e tempo constante no esqueci-senha (anti-enumeração).
- [ ] `BODY_VALUE_ALLOWLIST` limitado a valores escalares.
- [ ] **Em andamento (#35):** tokens de ativação e redefinição só como hash sha256; remover as colunas `*_link`.
- [ ] **Em andamento (#35):** cadastro com resposta genérica (sem `409 email_taken`); aviso por e-mail ao dono, no máximo 1×/dia.
- [x] ~~Troca de e-mail estando logado: confirmar o e-mail novo por link.~~ Descartada por decisão do usuário (#58/#59).
- [ ] Criptografar `calendar_feeds.url`, `properties.external_ical_url` e `audit_hold`, com coluna HMAC para duplicados.
- [ ] LGPD: política de retenção, hash de IP e e-mail; separar auditoria de BI.
- [ ] Prefixos IPv6/TEST-NET restantes em `CalendarSync::isPublicIp` (2001:10::/28, 2001:20::/28, 3fff::/20, 5f00::/16, fec0::/10); `logRef` via `LogPrivacy::url()`; `redactPath` cobrindo segmentos opacos de 20+ caracteres.

## Funcionalidades e API

- [ ] Escolher o provedor SMTP e abrir o cadastro em produção.
- [ ] Expor ou gerar o `ical_hash` pela API (hoje só é criado ao abrir `views/calendar.php:140`).
- [ ] Migrar fotos, calendário, formulário de imóvel e autenticação para fetch/API.
- [ ] Trocar `alert()` dos formulários por mensagens na própria página.

## Mapas

- [ ] **PMTiles do Brasil** (decisão do usuário, 14/10): arquivo Protomaps (`pmtiles extract`), servido estático pelo nginx em produção; em dev, rota PHP com HTTP Range. Front com **MapLibre GL** (não Leaflet). Atribuição obrigatória "© OpenStreetMap contributors". Mapa com lazy-load e clusters em GeoJSON. CSP: liberar só na rota do mapa, com hosts explícitos e sem curinga; nonce se precisar de style; nunca liberar domínio que aceite conteúdo de usuários. O Google Maps não será usado.

## Bugs

- [ ] Testes: no erro de nonce do t_server(), mostrar só as últimas 20 linhas do stderr, e não umas 200.

- [ ] Baixa (QA #34): o aviso do teto cheio do rate limit vai para `php.log`.
- [ ] Baixa (QA #34): a suíte standalone deixa estado em `storage/cache/ratelimit`.
- [ ] Baixa (QA #34): não há E2E que submeta avaliação.

- [ ] **Em andamento (#36 / mapas):** coordenada pública aproximada (3 casas + deslocamento fixo 300–500 m por imóvel) e clusters no MapLibre; resolve marcadores empilhados e o vazamento da lat/lng exata.

- [ ] Imóvel antigo com título acima de 25 caracteres não pode ser editado pela API sem encurtar o título (422 com mensagem clara). Sem migração: recriar o banco ou validar só na criação.

- [ ] `/busca/mapa` sempre recebe uma lista de resultados vazia, então o mapa nunca desenha nada. Correção pronta na branch fix/busca-mapa (PR a abrir; QA depois do #23).

## Infra e dados

- [ ] Migrar `activity_log.actor_id` para `actor_ref TEXT`.
- [ ] `LogRetention::run` incluir arquivos `*.ret-*` remanescentes.
- [ ] `applyRange`/`applyMinNights` com o mesmo risco de lock do DEF-008.
- [ ] Banco de log separado e outbox de auditoria (Fluent Bit saiu da stack).

## Redução de dados enviados
Origem: análise do Bot Segurança e Arquitetura (develop e425cbd). Hoje a busca, o detalhe, o painel e a edição baixam cerca de 13,4 MB, e 98% disso são fotos originais.
- [x] **PR de fotos (feito no #39, com GD e WebP 320/800/1920; libvips e AVIF não adotados):** resize no upload, original fora de public/, limite de 50 MP e script de reprocessamento. Ganho medido: 13,4 MB → ~0,3 MB.
- [ ] **No mesmo PR:** remover EXIF/GPS das fotos, inclusive das já gravadas (LGPD, alto).
- [ ] **No mesmo PR:** `srcset`/`sizes`, `width`/`height` e `decoding=async` nas imagens.
- [ ] Upload de 16 MB → ~10 MB, com limite de pixels (DoS).
- [ ] **Em andamento (#36):** API pública sem `address`, `property_id` e `audit_hold` em `/api/imoveis/{hash}` e nas fotos; página pública com "bairro · cidade"; coordenada aproximada no mapa.
- [ ] Sessão só quando há cookie ou rota autenticada. `ETag`/304 nas rotas públicas e `private, no-store` nas autenticadas (pré-requisito de cache/CDN).
- [ ] Paginação no servidor em `/api/busca` e período `?de=&ate=` no calendário (hoje são 24 meses).
- [ ] Referer nos tiles da busca.
- [ ] HTML da página de edição (44 KB): enxugar.
- [ ] Caminho das fotos sem `user_id`/`property_id` (evita enumerar ids).
- [ ] Avaliar: cache OSS (só depois da sessão), CDN comercial e tiles próprios (PMTiles/OpenMapTiles).
- [ ] QA do PR de fotos: verificar que não há EXIF e conferir as variantes geradas.

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
