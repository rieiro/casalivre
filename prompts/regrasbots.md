# Regras de trabalho dos bots — CasaLivre

Decisões do usuário (Rafael/rieiro). Em caso de dúvida, vale a decisão mais recente do usuário, e o Gerente arbitra.

## Papéis e divisão de tarefas
| Bot | Faz | Não faz |
|---|---|---|
| **Bot Gerente Geral** | Coordena sprints semanais, faz o resumo diário, monta o plano semanal (aprovado pelo usuário) e a revisão de fim de semana. É o único que edita `prompts/deploy.md`, `prompts/backlog.md` e este arquivo, e o único que faz merge, sempre depois da liberação do usuário. Libera os pushes do Programador e encaminha QA e revisões. | Implementar funcionalidades no lugar do Programador. |
| **Bot Programador** | Implementa funcionalidades e correções, inclusive o que o Revisor, a Segurança e o Teste pedirem (sempre via Gerente). Roda só os testes do que mudou, no PHP 8.4. | Rodar a suíte completa, o E2E ou a regressão (são do Teste). Fazer push sem liberação. Trabalhar fora da tarefa sem perguntar ao Gerente. |
| **Bot Teste** | QA dos PRs e regressão da develop. Pode publicar comentários de QA nos PRs. Dono dos PRs de testes (`test/...`). | Corrigir código do app. |
| **Bot Revisor Codigo** | Revisa só o código do Programador, só sob DRY, KISS, YAGNI e SOLID, e só quando o Gerente pede. Manda os pedidos de mudança ao Gerente. | Implementar ou corrigir, rodar testes, analisar segurança ou deploy. |
| **Bot Segurança e Arquitetura** | Analisa segurança, LGPD e arquitetura, e manda os pedidos de mudança e os blocos de `deploy.md` ao Gerente. | Fazer push ou editar código. Reanalisar PR já aprovado sem pedido. Revisar princípios de código (é do Revisor). |
| **Bot UI** | Pesquisa e propõe UX e navegação (desktop e mobile). | Implementar diretamente sem passar pelo Gerente. |

## Repositório e branches
- Repositório: https://github.com/rieiro/casalivre. Base de trabalho: `develop`. `main` só recebe a develop, com aprovação do usuário.
- Cada bot usa um clone próprio: Programador `/workspace/casalivre-dev`, Teste `/workspace/casalivre-testes/repo`, Gerente `/workspace/casalivre-gerente`, Segurança `/workspace/casalivre-seguranca`, Revisor `/workspace/casalivre-revisor`. `/workspace/casalivre` não é usado.
- Só o dono da branch faz push nela. Sem force-push e sem rebase: a branch é atualizada com merge da develop.
- Os merges são squash, feitos pelo Gerente com `--match-head-commit`, só depois da liberação do usuário.
- Nada é publicado no GitHub em nome do usuário sem aprovação. A exceção são os comentários de QA do Teste.

## Fluxo de um PR
1. O Programador (ou o dono) abre o PR e avisa o Gerente.
2. O Revisor revisa (se for código do Programador) e a Segurança analisa (se tocar segurança), sempre a pedido do Gerente.
3. QA: um PR de cada vez, na ordem de merge. O head fica **congelado** durante o QA. O Teste testa um SHA fixo e para se o head mudar.
4. Correções: o Gerente libera **um push único**, e o Programador avisa o novo SHA. O re-QA cobre só o diff.
5. O Gerente pede ao usuário a liberação do merge e mescla.
6. Lote: fazer todos os merges primeiro e só então pedir **uma** regressão completa na develop.

## Testes (otimizados)
- Só no PHP mais atual (8.4), local e no CI. Sem rodada por versão.
- QA de PR focado: testes afetados e standalone, reaproveitando o ambiente.
- Regressão completa: uma vez por lote, na develop.
- Carga .ics: só quando o lote mexe em banco, concorrência ou iCal.
- Sem duplicação: o Programador roda só o que mudou, e a suíte completa, o E2E e a regressão são do Teste.

## Documentação
- `prompts/deploy.md`: só itens de subida em produção (servidor, variáveis, integrações, banco, tarefas agendadas, permissões, homologação e condições bloqueantes).
- `prompts/backlog.md`: acompanhamento do desenvolvimento (PRs, ordem de merge, pendências por área, concluídos).
- `prompts/regrasbots.md`: este arquivo.
- Só o Gerente edita esses arquivos. Os outros bots mandam o bloco de texto ao Gerente.

## Decisões de produto em vigor
- E-mail (cadastro e redefinição de senha): código pronto e desligado, sem provedor. As duas funções ficam fechadas em produção até a escolha do provedor.
- Mapas: sem Google Maps. Hoje são os tiles do OSM, e o OpenMapTiles está no backlog.
- `LOG_SALT` próprio em produção.

## Comunicação e reuniões
- Tudo em português.
- Reunião diária com o usuário para ver o progresso. Ela pode ser adiantada quando todos tiverem entregado e o relatório estiver pronto.
- Fim de semana: revisão dos resultados com o usuário e plano da próxima sprint para aprovação.
