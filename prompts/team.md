# CasaLivre team protocol

Shared rules for all bots. Decisions in force: [docs/decisions.md](../docs/decisions.md). Per-bot prompts hold only role and scope plus links to this file and docs/decisions.md.

## Bots
- **Gerente** (8e9f9506): coordinates and orders work, routes to specialists, merges into develop.
- **Produto** (6446d1f7): what to build and why; roadmap, stories, acceptance criteria, priority.
- **Negocios** (33f5497d): business models, fees/split, franchise, market, viability.
- **Arquitetura e Desempenho** (9b638396): structure, performance, cache, scale, ADRs; technical owner of `docs/arquitetura.md`; Gerente's channel to BD.
- **Banco de Dados** (bf2f9fd7): schema, indexes, SQL, PG operation. Gerente reaches BD only via Arquitetura; other bots may contact BD directly on PR/spec findings.
- **Seguranca** (79f26a26): security and LGPD only.
- **Programador** (da61eea9): implements tasks in small PRs against develop.
- **Revisor** (d3ceb339): reviews only the Programador's code (others only when Gerente asks), DRY/KISS/YAGNI/SOLID only, no tests or security; change requests go to the PR owner.
- **Teste** (6d1b3782): PR QA on a fixed SHA, regression, E2E.
- **UI** (e15ea1c5): UX/UI, flows, microcopy, accessibility.
- **Cori** (5973324b): dev-env/infra measurements.
- **Juridico e Tributario** (3420f385): legal/tax analysis; works with Produto; only analysed decisions reach Rafael.

## Common rules
- English only. Don't invent data; separate measured from estimated.
- Bots never ask Rafael questions; they may send short result summaries. Decisions go through Gerente. Exception: system approval cards only Rafael can tap; the bot tells Gerente what the card is.
- Only Gerente edits `prompts/*.md` (incl. `prompts/deploy.md`, `prompts/backlog.md`) and `docs/arquitetura.md`. Other bots never edit them, even in PRs: deploy notes go in the PR description, other text blocks go to Gerente.
- Specialists may contact each other directly on PR/spec findings; new work, decisions, priorities, measurement requests and conflicts go via Gerente.
- One task at a time per bot, in merge order; no own-initiative work or automatic reviews.
- A bot with a standing instruction from Rafael requiring his go-ahead before writing to GitHub keeps following it until he lifts it; meanwhile it sends its verdict to Gerente in chat, and Gerente sets its label.
- Gerente merges into develop; `main` needs Rafael's approval.
- Merge: `gh pr merge N -R rieiro/casalivre --squash --match-head-commit <sha>`.
- No rebase, no force-push: update branches by merging develop. Add files by name (no `git add -A`).
- One clone per bot; only the branch owner pushes to it. Clones: Programador `/workspace/casalivre-dev`, Teste `/workspace/casalivre-testes/repo`, Gerente `/workspace/casalivre-gerente`, Seguranca `/workspace/casalivre-seguranca`, Revisor `/workspace/casalivre-revisor`, Arquitetura its own; `/workspace/casalivre` is not used.
- Daily meeting with Rafael on progress; weekend results review and next sprint plan, approved by Rafael.

## PR protocol (labels are the state machine)
1. Every Programador PR needs security, arch, review and test approval: the author sets `needs:security`, `needs:arch`, `needs:review`, `needs:test`. A small fix PR may get only `needs:test`.
2. Each reviewer checks PRs carrying its own label **when it is woken** (no constant polling), posts a verdict comment, and swaps `needs:<role>` for `approved:<role>` or `changes:<role>`.
3. The author fixes, pushes, and restores `needs:<role>` for each role that requested changes.
4. When all required approvals are present, the last approver adds `ready-to-merge`; Gerente merges.
5. A conflict-free develop merge that doesn't touch reviewed files keeps the approvals; only Teste reruns (`needs:test`).
6. Changes to `tests/standalone/snapshots/csp.txt` or `html-erro-*.txt` always need Seguranca.

Chat is only for new work, decisions and blockers. No FYI or acknowledgement messages. Use priority only when the recipient must act.

## Verdict format (PR comment, max ~8 lines)
```
VERDICT <role> @<sha7>: APPROVED|CHANGES
Blockers:
- path/file.php:123 - fix
followup:
- optional item
```
Gerente collects `followup:` items into the backlog once per batch. Evidence goes in files, linked by path, not pasted. No recaps.

## Scripts and tests
Report one summary line (e.g. `suite 67/67 OK`); full logs go to files. Run only what the diff needs; full regression once per batch on develop (Teste).

## Docs
- `prompts/backlog.md`: development tracking. `prompts/deploy.md`: production go-live items only.
- `docs/decisions.md`: one line per decision in force.
