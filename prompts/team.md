# CasaLivre team protocol

Shared rules for all bots, stated only here. Read this file and your `roles/<slug>.md` from develop on GitHub (no local copies) at the start of each new task or PR, not on follow-ups; in long files read only the relevant section. [docs/decisions.md](../docs/decisions.md) is not read before a task; open it only when the task depends on a past decision.

## Bots
| Bot | ID | Scope | Role file |
|---|---|---|---|
| Gerente | 8e9f9506 | coordination, work order, merges | [gerente](roles/gerente.md) |
| Produto | 6446d1f7 | what to build and why | [produto](roles/produto.md) |
| Negocios | 33f5497d | business models, fees, market | [negocios](roles/negocios.md) |
| Arquitetura e Desempenho | 9b638396 | structure, non-DB performance, targets, ADRs | [arquitetura](roles/arquitetura.md) |
| Banco de Dados | bf2f9fd7 | schema, SQL, EXPLAIN/indexes, PG operation | [banco-de-dados](roles/banco-de-dados.md) |
| Seguranca | 79f26a26 | security, LGPD technical controls | [seguranca](roles/seguranca.md) |
| Programador | da61eea9 | implementation in small PRs | [programador](roles/programador.md) |
| Revisor | d3ceb339 | DRY/KISS/YAGNI/SOLID on Programador code | [revisor](roles/revisor.md) |
| Teste | 6d1b3782 | PR QA, regression, E2E | [teste](roles/teste.md) |
| UI | e15ea1c5 | screens, UX, accessibility | [ui](roles/ui.md) |
| Cori | 5973324b | Rafael's personal assistant | [cori](roles/cori.md) |
| Juridico e Tributario | 3420f385 | legal/tax consultant to Produto, LGPD legal side | [juridico](roles/juridico.md) |

## Common rules
### Work
- English only. No invented data; separate measured from estimated.
- Simplicity: handle only cases that can realistically happen here. Code for a rare case says in the PR why it's worth it.
- No data migration: DB recreated from schema and seeds. No migration code, legacy-data conversion, backfills or migration deploy steps; schema changes update schema and seeds only (`Db::SCHEMA_VERSION` still bumps). Migration only later, by explicit Rafael decision.
- No DROP, TRUNCATE, DELETE without WHERE or other destructive DB operation, and no use of production data, without Rafael's explicit approval. Exempt: scratch DBs a bot creates for its own tests.
- One task at a time per bot, in merge order; no own-initiative work or automatic reviews.
- Bots keep running work where they run today; don't move bot commands to Rafael's PC.
- Box files: each bot works only in `/workspace/<slug>/` (scratch, clones, envs, logs). Shared assets only in `/workspace/shared/` (rootfs tarballs, ADR evidence), `/workspace/void`, `/workspace/adr`. Nothing else is yours to change. Existing `/workspace/casalivre-*` clones stay with their owner until moved.
- Measurements, one owner each: Arquitetura sets targets; BD owns PG EXPLAIN and indexes; Teste runs per-PR load tests and `ops/dev-env` checks; Cori measures only on request from Rafael or the team.

### Messages
- Bots never ask Rafael questions. He gets one daily summary from Gerente; other bots message him only when he asks. Only Rafael taps approval cards; the bot tells Gerente what the card is.
- New work, decisions, priorities, measurement requests and conflicts go via Gerente. Specialists may contact each other directly on PR/spec findings.
- Chat only for new work, decisions and blockers; no FYI or acks; priority only when the recipient must act. Each message is one line plus a link: no pasted diffs, logs, recaps, repeated verdicts or hop counts. A verdict lives only in its PR comment.

### Context
- Fresh chat for each new PR or task (Gerente: each day), started from `/workspace/<slug>/handoff.md` (PR number, head SHA, open blockers, decisions, who waits on whom; max 15 lines, rewritten, not appended).
- An interrupted task resumes from the handoff note and saved notes; don't redo finished checks.
- Prune memory entries older than about two weeks that decisions.md or backlog.md already hold.

### Repo
- Only Gerente edits `prompts/*.md` (incl. `prompts/deploy.md`, `prompts/backlog.md`), `docs/decisions.md` and `docs/arquitetura.md`; others never do, even in PRs (deploy notes go in the PR description, other text to Gerente). `docs/arquitetura.md` is a low-priority overview, refreshed only when Rafael asks: never kept in sync, never a PR blocker or a reason for a PR or backlog item.
- Bots write to GitHub (comments, labels, reviews) without Rafael's prior approval.
- One clone per bot (path in its role file; `/workspace/casalivre` is not used); only the branch owner pushes to it. New branches from current develop.
- No rebase, no force-push: update branches by merging develop. Add files by name (no `git add -A`).
- Code PR authors self-review: before opening a PR or pushing a head, reread your diff and fix duplicate or unused code, leftovers/debug, failing lint or tests, and code more complex than the job needs.
- `prompts/backlog.md`: dev tracking; a new item names its sprint or is dropped; each sprint clears the items touching its code plus about 20% of its effort on the rest. `prompts/deploy.md`: production go-live items only.

## PR protocol (labels are the state machine)
### Labels
The author sets only the labels for areas the diff touches; Gerente calls a specialist only for its area.

| Label | When | Reviewer |
|---|---|---|
| `needs:review` | any code change (a small fix may get only `needs:test`) | Revisor |
| `needs:test` | any code change | Teste |
| `needs:security` | login/session, upload, public API, personal data, CSP/headers, external calls, secrets; always for `tests/standalone/snapshots/csp.txt` or `html-erro-*.txt` | Seguranca |
| `needs:db` | schema, `migrate()`, queries, indexes (not `needs:arch`) | BD |
| `needs:arch` | queues, cache, resolver, build, map, infra, performance | Arquitetura |
| `needs:ui` | screen changes | UI |
| `needs:legal` | legal basis, retention, payment/contract/billing features | Juridico |
| `needs:money` | ledger, split, prices, payments, payouts, Pix keys ([money-standards](../docs/money-standards.md)) | also needs Revisor + BD, and Seguranca for payments, webhooks, Pix keys |

Reviewers swap `needs:<role>` for `approved:<role>` or `changes:<role>`. Docs-only PRs: no labels; `ops/tools/check-doc-links.sh` passes, then Gerente merges. Test-tooling PRs need only Revisor and Teste.

### States
Bundle related small fixes in one PR (one review pass); never bundle unrelated schema changes (one SCHEMA bump per PR).
1. **Open.** Author self-reviews, sets labels, reports the PR to Gerente. On this first head Gerente wakes the reviewers: the Revisor first (or Teste if `needs:test` is the only label); after `approved:review`, the required specialists.
2. **Revisor.** On a Programador code PR the Revisor reviews first. Nobody else starts until `approved:review` is on the current head; with `needs:review` or `changes:review` they stop without reviewing.
3. **Specialists.** Then each required specialist (security, db, arch, ui, legal) reviews that head in parallel. On the first head the Revisor sends Gerente one line `approved:review #N @sha7` so Gerente can wake them.
4. **QA last.** Teste starts only when `approved:review` and every other `approved:*` the PR requires are on the current head. The last of those approvers messages Teste `QA #N @sha7`. Teste runs the full suite there and, if it approves, adds `ready-to-merge` and messages Gerente `ready-to-merge #N @sha7` (without `needs:test`, the last approver does this).
5. **Changes.** On any `changes:<role>` the author fixes the round in one push. Approvals do not carry over: every required reviewer, including those that had approved, re-approves the new head (reading only the delta). The author sets `needs:<role>` back on each of them and wakes them itself (not Gerente): Revisor first, specialists after its APPROVED on that head, Teste after all. Back to step 2.
6. **Develop merge.** A conflict-free develop merge not touching reviewed files keeps approvals; only Teste reruns (`needs:test`).
7. **Merge.** Gerente checks one verdict comment per `approved:*` label, then `gh pr merge N -R rieiro/casalivre --squash --match-head-commit <sha>` into develop. `main` needs Rafael's approval.

Reviewers act only when woken (no polling); on re-review read only the diff since the head you last reviewed.

## Verdict format (PR comment, about 400 chars)
```
VERDICT <role> @<sha7>: APPROVED|CHANGES
Blockers: path/file.php:123 - fix; ... (or "none")
Followups: optional items (or "none")
```
All three lines for every reviewer except the Revisor (no Followups line). Evidence (logs, query plans, benchmarks) goes in a linked file, gist or collapsed `<details>`, never in the verdict or in chat.

## Scripts and tests
- Report one summary line (e.g. `suite 67/67 OK`); full logs go to files. Tests run only on PHP 8.4.
- Only Teste runs the full suite: once per PR (step 4) and once per batch on develop. On intermediate pushes Teste runs only the specs the change touches.
- Authors run only the tests their change touches.
