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
8. **Próximo passo:** sprint 3 (tema visual)

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
- [ ] Etapa 0: `asset_url` com hash, OPcache e brotli.
- [ ] Etapa 1: tokens CSS e páginas de erro sem estilo inline.
- [ ] Etapa 2: `brand.php`, logo e pasta do tema.
- [ ] Etapa 3: estilo do mapa gerado.
- [ ] Etapa 4: fonte do mapa, se mudar.
- [ ] Etapa 5 (opcional): e-mail HTML.

### Tema escolhido pelo banco
- [ ] Tema padrão da plataforma (usado quando a requisição não tem grupo) em `settings.theme`, com cache num arquivo mantido pelo OPcache e CSS e estilo do mapa com hash por tema. Aceitar só nomes da lista do manifesto; troca só pelo admin, com CSRF e log. Entra junto com o PR do `brand.php`. Modo claro e escuro, se houver, por `prefers-color-scheme`.

### Temas por grupo de imóveis (decisão do usuário, 14/10)
- [ ] Temas por grupo de imóveis (imobiliária ou rede). Cada tema é uma identidade visual completa (logo, favicon, imagens, cores, fontes, ícones e sprite, estilo do mapa, cabeçalho e rodapé de e-mail) numa pasta `themes/<nome>/` preparada pelo time e versionada no repositório. Não há upload de imagens pelo grupo. O layout e as funcionalidades não mudam: o código só aponta para os arquivos do tema. Um build gera os arquivos com hash e um manifesto.
- [ ] Uma função única descobre o grupo e o tema de cada requisição, nesta ordem: (1) o domínio, por uma lista fixa de domínios aceitos; (2) o endereço `/g/<nome>/`; (3) o grupo do imóvel; (4) o tema da plataforma (`settings.theme`).
- [ ] A vitrine `/g/<nome>/` tem home, busca e mapa só com os imóveis do grupo, no tema dele, e os links continuam dentro da vitrine. O nome do endereço é único e usa só letras minúsculas, números e hífen.
- [ ] Links e e-mails saem gerados com o domínio certo do grupo.
- [ ] Modelo de dados: `groups` (slug, nome, tema), `group_members` (papéis), `properties.group_id` e a lista de domínios por grupo. Vincular um imóvel a um grupo exige o aceite do dono do imóvel. Toda mudança vai para a auditoria. O tema é aceito só da lista do manifesto. O selo da plataforma fica sempre visível.
- [ ] Ficam para depois: a configuração do nginx, os certificados por domínio e a sessão ou login separados por domínio.

## Segurança e LGPD

- [ ] (Média, #47, já existia) Troca de e-mail não pede a senha atual, não confirma o novo endereço com token de uso único, não avisa o e-mail antigo e não aumenta o `session_version`.
- [ ] (Baixa, #47, já existia) O catch genérico de `PDOException` no `AccountUpdate` vira "e-mail em uso"; deveria tratar só a violação de unicidade.
- [ ] (Baixa, #47, já existia) `PhotoRepository::makePrimary` aceita foto que não está com status `ready`.
- [ ] `CL.el`: passar `action`, `formaction` e `xlink:href` pelo safeHref (vai no #23).

- [ ] **ALTA, antes do próximo deploy público:** XSS armazenado em `views/property.php:38-44`, `views/search.php:~170` e `views/dashboard.php:~58` (innerHTML sem escape), mais CSP.
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
- [ ] Troca de e-mail estando logado: confirmar o e-mail novo por link (hoje ainda devolve `email_taken`).
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
- [ ] Fluent Bit, banco de log separado e outbox de auditoria.

## Redução de dados enviados
Origem: análise do Bot Segurança e Arquitetura (develop e425cbd). Hoje a busca, o detalhe, o painel e a edição baixam cerca de 13,4 MB, e 98% disso são fotos originais.
- [ ] **PR de fotos (prioridade):** resize no upload com libvips (WebP 480/960/1600, + AVIF), original fora de public/, limite de 50 MP e script de reprocessamento. Ganho medido: 13,4 MB → ~0,3 MB.
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

- [x] Sprint 2: refatoração (#44 testes de caracterização, #45 app-env, #46 index-servicos, #47 ações compartilhadas, #49 api-guardas, #50 helpers por tema), #43 (script `run-casos-de-teste`) e #48 (avaliação pela web com limite de 10/h por IP, igual à API) mesclados na develop (b13328b).
- [x] Sprint 2: PRs #35, #36, #38, #39 e #40 mesclados na develop (fca4832).

- [x] PR #34 (pipeline de segurança: rate limit da API, `Csrf::rawBody`/413, máscara no `error.log`, sessão preguiçosa) mesclado na develop (7de17a9).
- [x] PRs #18 (scripts de teste) e #19 (docs) mesclados na develop (6b26252).

## Regras

- Próximo PR que mudar seeds ou schema usa `SCHEMA_VERSION` 4, sobre o #21.

- [x] Correções pós-merge: PRs #11 a #17 mesclados na develop (4c609b3) e retestados no PHP 8.2 e no 8.4: standalone 57/57, PHPUnit 27/27, casos 83/0, carga 1000/1000, E2E 15/15, logs sem dados pessoais.

- [x] Sprint 1: PRs #1 a #10 mesclados na develop (a6d5a48) e testados: PHPUnit 27/27, 15/15 páginas HTML e 1.000/1.000 na carga do `.ics`.
