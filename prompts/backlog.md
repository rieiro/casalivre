# Backlog de desenvolvimento — CasaLivre

Rastreamento das pendências durante o desenvolvimento. **Só o Bot Gerente Geral edita este arquivo**; os analistas enviam os itens para consolidação. O que é necessário para subir em produção fica em `prompts/deploy.md`.

## Em andamento (pós-merge da Sprint 1)

| PR | Assunto | Situação |
|----|---------|----------|
| #11 | Reorganização do deploy.md e do backlog.md | aguardando merge |
| #13 | Transação aninhada no banco novo (PHP 8.2/8.3) | aprovado |
| #16 | SSRF `::ffff:0:0/96` e CI 8.2/8.4 | aprovado |
| #12 | Log e ActivityLog por allowlist (LGPD) | aprovado |
| #14 | Auditorias do sync-calendars | aprovado |
| #17 | Mailer SMTP (sem provedor por enquanto) | aprovado com condições |
| #15 | Links só em dev/local, `APP_URL` | aprovado com condições |

Ordem de merge: #13, #16, #12, #14, #17, #15 (o #17 antes do #15, para o link não sumir sem existir envio de e-mail). Depois do lote, o Bot Teste refaz os testes na develop. Ajustar `scripts/run-casos-de-teste.php` e `scripts/casos/api-ical-log.php` para rodar com `APP_ENV=dev` e corrigir a contagem fixa do Caso 4 (Bot Teste).

## Segurança e LGPD

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

## Infra e dados

- [ ] Migrar `activity_log.actor_id` para `actor_ref TEXT`.
- [ ] `LogRetention::run` incluir arquivos `*.ret-*` remanescentes.
- [ ] `applyRange`/`applyMinNights` com o mesmo risco de lock do DEF-008.
- [ ] Fluent Bit, banco de log separado e outbox de auditoria.

## Concluído

- [x] Sprint 1: PRs #1 a #10 mesclados na develop (a6d5a48) e testados: PHPUnit 27/27, 15/15 páginas HTML e 1.000/1.000 na carga do `.ics`.
