# CasaLivre: Spec 10, backoffice do admin_plataforma (v1, 16/10/2026, alvo S7 a S9, meta WCAG 2.2 AA)

**Hub de filas** criado pelas specs 04 a 09. Esta spec **não repete as telas**: cada fila abre a tela de origem indicada. Fontes: spec-02 (papéis, força maior, exclusão e lista de bloqueio), spec-04 (log e auditoria), spec-05 (KYC, refinamento P1 e P3, apêndice A da fatura P9), spec-06 (portaria), spec-07 (moderação A3), spec-08 (denúncia de mensagem), spec-09 (disputa e incidente) e o relatório de Segurança (`/workspace/casalivre-seguranca-evidence/specs-ui-04-06/relatorio.md`). A spec usa só tokens de tema.
**Casos de uso:** UC-A13, UC-A16, UC-A17, UC-A25, UC-C11, UC-C12, UC-H24 e o UC-A26 proposto (Operar as filas do backoffice), a acrescentar em `casos-de-uso-admin-proposta.md`.
**Quem acessa:** o **admin_plataforma**. O **admin_org** vê só a **triagem** da moderação e as disputas do próprio escopo (spec-07, A3). O operador, o gestor e o proprietário não acessam.

## 1. Estrutura comum das filas
**Layout (desktop-first):** à esquerda, um menu de filas com contadores ("KYC 7 · 2 vencendo"). No centro, a **tabela da fila**. À direita, um **painel de detalhe** que abre a tela de origem. **No mobile**, só consulta: a lista e o detalhe para leitura, com "Disponível no computador" nas ações. A exceção é "Assumir", permitido no mobile *(Produto)*.
**Filtros:** status, prioridade, **SLA** (no prazo, vencendo, vencido), responsável (meus, sem responsável, todos), nó ou território, tipo e período. O filtro fica salvo por usuário.
**Selo de SLA (texto e ícone):** "No prazo · 1 d 4 h", "Vencendo · 3 h" e "**Vencido** · há 2 h". O prazo é absoluto e relativo, e é anunciado só quando vence.
**Atribuição:** "Assumir" atribui o item a mim, "Liberar" devolve à fila, e "Reatribuir" (com motivo) passa para outra pessoa. Cada item mostra "Com [nome] desde 10:12".
**Proteção de concorrência:** um item assumido fica **travado** para os outros ("Em análise por Ana desde 10:12"), e as ações ficam desabilitadas com essa explicação. Uma decisão enviada sobre um item já decidido recebe 409 e a mensagem "Já decidido por [nome] às [hora]". O foco vai para o aviso.
**Segregação:** nas filas em que **quem atende não aprova** (Pix, item 6), o botão Aprovar fica desabilitado para quem atendeu, com "Outra pessoa precisa aprovar".
**Conflito de interesse:** se quem decide é parte da reserva ou ganha com ela, o item **sobe um nível** automaticamente (spec-02, spec-07), com o selo "Escalado por conflito de interesse".
**Log:** cada abertura, atribuição e decisão fica registrada (spec-04, com as classes da 04-1).
**Estados comuns:** fila vazia ("Nada pendente nesta fila. 🎉" com texto, sem depender do emoji); carregando (skeleton); erro ("Não carregamos a fila. Tentar novamente", sem perder os filtros); sem permissão (estado da spec-02, 404/403).

**Critérios de aceite comuns**
1. Cada fila tem filtros, selo de SLA, atribuição e travamento por item.
2. Duas pessoas não decidem o mesmo item (409, com o aviso).
3. Toda ação fica no log com autor, papel, motivo e horário.
4. O admin_org vê só a triagem e o escopo dele, e o servidor responde 403 ao resto.

## 2. Filas
| Fila | Origem (tela) | SLA | Ações | Regras de Segurança |
|---|---|---|---|---|
| **2.1 KYC** | spec-05, seção 1 e "Refinamento" | **2 dias úteis** *[E]* | Aprovar, **Pendência com motivo** (reenvio), Recusar | Mostra os **resultados das validações automáticas** (dígito, Receita/BrasilAPI, titular da chave). Falha na BrasilAPI = análise manual (05-8). **Ver um documento exige motivo e fica no log** (05-2). Só o admin_plataforma vê o documento; o franqueado vê só o status. |
| **2.2 Moderação** | spec-07 A3 (avaliações), spec-08 (mensagens), denúncias | **Denúncias gerais: 48 h · dados pessoais: 24 h** *[E]* | Manter, Remover, Editar (ocultar trecho) | **Triagem pelo admin_org** no escopo dele, e a decisão é do admin_plataforma. **Um moderador com vínculo ao imóvel não decide** ([decidido, parecer J14]). O texto sai como texto puro (07-1). O texto removido por "Dados pessoais" sai do log (07-9). Rate limit de denúncias (07-6). |
| **2.2b URGENTE** | denúncias e disputas marcadas como urgentes | **Nó: assumir em 1 h · plataforma: 4 h em horário comercial** *[E]* | Assumir, Acionar o responsável local, Escalar, Resolver | Categorias: **segurança física**, **hóspede sem acesso ao imóvel** e **suspeita de golpe em andamento**. Vai **primeiro ao responsável local** (gestor ou franqueado do nó), conforme a **escala de plantão configurável por organização**. **Escala para o admin_plataforma** se o nó não assumir em 1 h, com SLA de 4 h em horário comercial. O selo vermelho tem o texto "URGENTE". |
| **2.3 Disputas e incidentes** | spec-09, seção 6 | **10 dias úteis** *[E]* | Mediar, Decidir em definitivo, Encaminhar | **Primeiro, o admin do nó acima media.** Chegam aqui os casos com **conflito de interesse, sem acordo ou com prazo excedido**, e o admin_plataforma decide em definitivo. Inclui as **disputas automáticas de reembolso não feito** no piloto (5 dias úteis, spec-09, seção 4). **Leitura da conversa só com o caso aberto, motivo, log e aviso aos participantes** (08-6). Os documentos da portaria só são abertos com motivo (06-2, P2-2). |
| **2.4 Força maior** | spec-02 (comprovação), spec-09, seção 5 | 7 dias para o envio, mais a análise [E] | Aceitar (não conta), Recusar (conta), Recurso | Decide o **admin do nó acima**. Aqui chegam o **recurso** e os casos **escalados por conflito de interesse**. |
| **2.5 Correção de chave Pix (suporte por vídeo)** | spec-05, "Refinamento", P3 | Conforme o agendamento | Confirmar agendamento, Registrar verificação, **Aprovar (outra pessoa)**, Recusar, Registrar não comparecimento | Só quando o titular perdeu o canal de OTP. **O usuário agenda pelo painel**, escolhendo um horário livre na **janela de dias úteis das 9h às 18h (horário de Brasília)** *[E]*. **A plataforma sempre faz a chamada**, ao vivo. A tolerância é de **10 min**. **Uma falta permite reagendar. A segunda cancela o pedido**, que só pode ser reaberto **depois de 7 dias**, com log. A **confirmação é enviada 24 h antes e o lembrete 1 h antes**, pelo **canal já cadastrado**. **Quem atende não aprova.** **Carência de 7 dias** ("Pendente até…"). **No máximo 1 correção a cada 30 dias.** O vídeo **não é gravado** ([decidido, parecer J22]). Vale até a D3. |
| **2.6 Lista de bloqueio** | spec-02, seção 0b | — | Consultar, Remover antecipadamente (com motivo) | A consulta é por **HMAC** do CPF ou do telefone (X-4), e a lista nunca mostra o valor. **Toda entrada tem prazo**: comportamental, pela duração da penalidade; **fraude comprovada, 5 anos**. **Nunca é permanente** (X-5). Mostra o motivo (código), a data de fim e o `caso_ref`. Todo uso fica no log (X-6). |
| **2.9 Revisão da lista de bloqueio** | spec-02, seção 0b (pedido do titular) | **15 dias corridos** ([decidido, parecer item 47]) | Manter (com justificativa), Remover, Reduzir prazo | **Revisão humana** (X-6, art. 20). O pedido e a decisão ficam guardados **5 anos** (item 48). O titular pede a qualquer momento e recebe a resposta com o motivo. Um falso positivo é removido com log. |
| **2.7 Faturas P9 em atraso** | spec-05, apêndice A | Pelo **escalonamento: D+1, D+5, D+15, D+30** | Registrar pagamento, Negociar (nova data, com motivo), Reverter etapa (com motivo) | O selo mostra a etapa ("D+15: novas reservas suspensas"). A quitação reverte o D+15 e o D+30. |
| **2.8 Acessos com motivo** | spec-05 (05-10, "Mostrar"), spec-06 (portaria, 06-2 e P2-2), spec-04 (log) | — | Consultar (auditoria) | Fila **de auditoria**, só leitura: quem pediu "Mostrar", documentos da portaria ou KYC, com o motivo, a data e o alvo mascarado. O filtro "Sem motivo válido" serve para revisão. |

**Critérios de aceite das filas**
1. (2.1) O SLA de 2 dias úteis aparece no selo, e a Pendência exige motivo. A abertura de um documento sem motivo é bloqueada.
2. (2.2) O admin_org consegue fazer a triagem (encaminhar ou arquivar uma duplicata), mas não decide Remover.
3. (2.4) Um item escalado por conflito não aparece para a pessoa em conflito.
4. (2.5) Quem atendeu não consegue aprovar. A chave aprovada fica "Pendente até [+7 dias]". Uma segunda correção em 30 dias trava o recebedor.
5. (2.6) A lista de bloqueio nunca mostra o CPF ou o telefone em claro, toda entrada tem data de fim (sem a opção "permanente") e a consulta fica no log.
5b. (2.9) Cada pedido de revisão mostra o prazo de 15 dias no selo de SLA e exige uma decisão com justificativa.
5c. (2.2b) Um item urgente não assumido pelo nó em 1 h aparece na fila da plataforma, com o SLA de 4 h.
5d. (2.5) A segunda falta cancela o pedido e bloqueia a reabertura por 7 dias.
5e. (2.3) A disputa só chega à plataforma escalada (conflito, sem acordo ou prazo vencido), ou como disputa automática de reembolso.
6. (2.7) Cada fatura mostra a etapa atual e a próxima, com a data.
7. (2.8) Todo acesso com motivo aparece na fila de auditoria em até 1 min [E].

## 3. Painel inicial do backoffice
Cartões por fila com o total, os vencidos e os "sem responsável", além de "Meus itens". Clicar num cartão aplica o filtro. Não há gráfico obrigatório nesta fase [E].

## 4. WCAG 2.2 AA
- Tabelas com `<caption>`, cabeçalhos, `aria-sort` e linha selecionável por teclado (Enter abre o detalhe). O foco volta à linha ao fechar o detalhe (1.3.1, 2.4.3).
- SLA e travamento com texto e ícone, não só cor (1.4.1). O vencimento é anunciado por role=status, uma única vez (4.1.3).
- Ações de decisão com confirmação, e a reatribuição com motivo obrigatório (3.3.4). Erros em texto (3.3.1).
- Motivo de acesso num campo com rótulo visível. "Mostrar" só revela depois do envio, e o valor revelado tem um rótulo acessível.
- Alvos de no mínimo 24 px (2.5.8). Sem rolagem horizontal da página a 320 px no modo de consulta mobile (1.4.10).

## Pendências para Produto
Os itens pendentes não bloqueiam o restante da spec.
1. [pendente: Segurança, via Gerente Geral] A ferramenta da chamada de vídeo (sem gravação) e o modelo de travamento e concorrência das filas.
2. [pendente: Arquitetura] Cálculo de dias úteis (calendário de feriados) para o SLA do KYC e da disputa.
