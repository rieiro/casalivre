# Backlog de desenvolvimento — CasaLivre

Rastreamento das pendências durante o desenvolvimento. **Só o Bot Gerente Geral edita este arquivo**; os analistas enviam os itens para consolidação. O que é necessário para subir em produção fica em `prompts/deploy.md`.

## Em andamento

| PR | Assunto | Situação |
|----|---------|----------|
| #21 | Issue #20: camas por quarto e separação no layout | aprovado pela Segurança e pelo Revisor (ajustes menores); QA em andamento |
| #23 | XSS nas views, CSP e `str_field()` | mudanças pedidas pelo Revisor; entra depois do #21 |
| — | Refatoração DRY do que já foi mesclado (Mailer/helpers, base_url, Db::transactional, RequestLog) | sem urgência |

## Segurança e LGPD

- [ ] `CL.el`: passar `action`, `formaction` e `xlink:href` pelo safeHref (vai no #23).

- [ ] **ALTA, antes do próximo deploy público:** XSS armazenado em `views/property.php:38-44`, `views/search.php:~170` e `views/dashboard.php:~58` (innerHTML sem escape), mais CSP.
- [ ] `HouseService::fromInput`: responder 422 a campo de texto enviado como array (hoje grava "Array").
- [ ] `Csrf::rawBody` com limite de tamanho (checar `CONTENT_LENGTH`).

- [ ] **ALTA, antes de abrir o cadastro:** enviar o e-mail por fila (storage/mail ou tabela) com worker ou cron, com resposta em tempo constante no esqueci-senha (hoje o tempo revela quais e-mails têm conta).
- [ ] Mostrar "Enviamos um link" só depois de enfileirar com sucesso, e avisar o usuário quando o envio falhar.
- [ ] Mailer: abortar se houver bytes no buffer depois do STARTTLS (injeção de resposta); TLS 1.2+ no `tls://`; prazo total da sessão (~20 s); retenção do mail-dev.log; não gravar `$lastTranscript` no log.
- [ ] Uma só validação do `APP_URL` em `app_url()`, exigindo https fora de dev/local e validando o caminho; o Mailer passa a usá-la.
- [ ] Tirar `senha` e `link` de `VALUE_KEYS` (ou passar `link` por `LogPrivacy::url`).
- [ ] `.env.example` com `APP_URL` vazio.
- [ ] Login de conta pendente pela API reenviando o link quando ele expirou.

- [ ] `ExceptionShield` e `INTERNAL_ERROR`: mensagem e trace (que podem trazer SQL com dado pessoal) só no `error.log`, mascarados; no `activity_log`, só classe, código e arquivo:linha.
- [ ] Resposta genérica no cadastro com e-mail existente e tempo constante no esqueci-senha (anti-enumeração).
- [ ] `BODY_VALUE_ALLOWLIST` limitado a valores escalares.
- [ ] Tokens de ativação e redefinição só como hash sha256; remover as colunas `*_link`.
- [ ] Criptografar `calendar_feeds.url`, `properties.external_ical_url` e `audit_hold`, com coluna HMAC para duplicados.
- [ ] LGPD: política de retenção, hash de IP e e-mail; separar auditoria de BI.
- [ ] Prefixos IPv6/TEST-NET restantes em `CalendarSync::isPublicIp` (2001:10::/28, 2001:20::/28, 3fff::/20, 5f00::/16, fec0::/10); `logRef` via `LogPrivacy::url()`; `redactPath` cobrindo segmentos opacos de 20+ caracteres.

## Funcionalidades e API

- [ ] Escolher o provedor SMTP e abrir o cadastro em produção.
- [ ] Expor ou gerar o `ical_hash` pela API (hoje só é criado ao abrir `views/calendar.php:140`).
- [ ] Migrar fotos, calendário, formulário de imóvel e autenticação para fetch/API.
- [ ] Trocar `alert()` dos formulários por mensagens na própria página.

## Mapas

- [ ] Trocar o mapa para o OpenMapTiles (decisão do usuário, 13/10) e ajustar o CSP: liberar só na rota do mapa, com hosts explícitos e sem curinga, usar nonce se precisar de style e nunca liberar um domínio que aceite conteúdo de usuários (no teste com o Google Maps, uma injeção de CSS vazou o token CSRF por `*.googleapis.com`). O Google Maps não será usado agora.

## Bugs

- [ ] Testes: no erro de nonce do t_server(), mostrar só as últimas 20 linhas do stderr, e não umas 200.

- [ ] Mapa: imóveis da mesma cidade têm a mesma lat/lng, então só 1 marcador aparece. Agrupar com contador ou dar um pequeno deslocamento (junto com o OpenMapTiles).

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
- [ ] API pública: tirar `address`, `property_id` e `audit_hold` de `/api/imoveis/{hash}` e das fotos (privacidade).
- [ ] Sessão só quando há cookie ou rota autenticada. `ETag`/304 nas rotas públicas e `private, no-store` nas autenticadas (pré-requisito de cache/CDN).
- [ ] Paginação no servidor em `/api/busca` e período `?de=&ate=` no calendário (hoje são 24 meses).
- [ ] Referer nos tiles da busca.
- [ ] HTML da página de edição (44 KB): enxugar.
- [ ] Caminho das fotos sem `user_id`/`property_id` (evita enumerar ids).
- [ ] Avaliar: cache OSS (só depois da sessão), CDN comercial e tiles próprios (PMTiles/OpenMapTiles).
- [ ] QA do PR de fotos: verificar que não há EXIF e conferir as variantes geradas.

## Concluído

- [x] PRs #18 (scripts de teste) e #19 (docs) mesclados na develop (6b26252).

## Regras

- Próximo PR que mudar seeds ou schema usa `SCHEMA_VERSION` 4, sobre o #21.

- [x] Correções pós-merge: PRs #11 a #17 mesclados na develop (4c609b3) e retestados no PHP 8.2 e no 8.4: standalone 57/57, PHPUnit 27/27, casos 83/0, carga 1000/1000, E2E 15/15, logs sem dados pessoais.

- [x] Sprint 1: PRs #1 a #10 mesclados na develop (a6d5a48) e testados: PHPUnit 27/27, 15/15 páginas HTML e 1.000/1.000 na carga do `.ics`.
