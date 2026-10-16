# CasaLivre: Spec 02, modos e papéis (v1, 16/10/2026, alvo S5 e S7, meta WCAG 2.2 AA)

Base: `roadmap.md` v0.2 (árvore de gestão, canais, aprovação delegável), `spec-01-busca-ate-checkout.md` v1.1 e `casos-de-uso.md`. A spec usa tokens de tema do canal e não fixa cores. O `tipo` de organização é só um rótulo, e **as telas dependem de permissões, não do tipo**.

## 0. Papéis, escopo e navegação
O escopo segue a árvore de gestão, que tem **profundidade livre** (`parent_id`). "Rede" e "organização" são **só rótulos** (`tipo`), não níveis fixos. Cada papel é concedido em **qualquer nó** e vale para os descendentes dele. Canais de vitrine são um eixo **separado** da árvore. *(provisório, revisão Negócios)*

| Papel (escopo = qualquer nó) | Aterrissagem no 1º acesso | Hoje | Reservas (fila) | Calendário | Anúncios | Recebimento (Pix) | Ganhos | Equipe e convites | Canais e temas | Organizações (árvore) | Funil (P1) |
|---|---|---|---|---|---|---|---|---|---|---|---|
| admin_plataforma (nó raiz) | Painel da plataforma | ✓ | ver | ver | ver | ver mascarada | ✓ | ✓ | ✓ todos | cria e move | ✓ tudo |
| admin_org (qualquer nó) | Painel do nó | ✓ | ver | ver | ver | ver mascarada | ✓ sub-árvore | ✓ sub-árvore | ✓ canais do nó | cria filhos | ✓ sub-árvore |
| gestor (qualquer nó) | Hoje | ✓ | ✓ | ✓ | ✓ | só com delegação explícita | ✓ | ✓ sub-árvore | ✓ se o canal pertence ao nó dele; senão desabilitado* | — | ✓ sub-árvore |
| operador (qualquer nó) | Reservas | ✓ | ✓ aprova e recusa; marca pago se tiver delegação (com log) | ver | — oculto | — oculto | — | — | — | — | — |
| proprietário (nó do imóvel) | Hoje | ✓ | ✓ se a aprovação não foi delegada | ✓ | ✓ | ✓ | ✓ | coanfitrião | — | — | ✓ imóveis dele |
| hóspede (sem escopo) | Explorar | modo Viajar: Explorar, Favoritos, Viagens, Mensagens, Perfil | | | | | | | | | |

\* É aqui que vale a regra **oculto × desabilitado**: o item fica **oculto** quando o papel nunca terá acesso (o operador não vê Anúncios). Fica **desabilitado com explicação** quando o acesso depende de outra pessoa ou de outra configuração, com o texto "Só o admin_org do nó dono do canal edita canais. Fale com [nome]" ou "A aprovação deste imóvel foi delegada a [org]". O item desabilitado continua focável (`aria-disabled`) e mostra a explicação. Os admins **só visualizam** reservas e pagamentos, e veem a chave Pix mascarada (só os últimos dígitos). Uma pessoa pode ter vários papéis em vários nós. *(provisório)* As permissões são verificadas no servidor (403), e esconder o item não basta.

## 0b. E-mail de conta em exclusão (carência de 30 dias) *(Produto; controles de Segurança C-1 a C-6)*
- O e-mail de uma conta em carência **não é liberado** para uma conta nova.
- **Resposta idêntica** na tela e na API para e-mail inexistente, ativo ou em carência: **"Se houver uma conta, enviamos um código."** O status HTTP, o corpo e o tempo são os mesmos (C-1, C-2). O que muda vai **só no e-mail** enviado.
- **"Reativar conta" só aparece depois do OTP válido**. O OTP tem 6 dígitos, vale **10 min**, permite no máximo 5 tentativas e tem rate limit por IP e por e-mail. Ao atingir o limite, aparece a mesma resposta neutra (C-3).
- Antes de reativar, a tela não mostra nenhum dado da conta. Depois, exige login com senha ou a troca de senha. Uma conta comprometida vai para revisão manual. Ao reativar, todas as sessões são invalidadas, todos os canais são avisados, e nem a chave Pix pendente nem as delegações são restauradas sem confirmação (C-4).
- **"Excluir definitivamente agora"** só aparece numa **sessão autenticada**, com reautenticação (senha) e OTP recente (no máximo 10 min). Nunca pelo fluxo de cadastro ou checkout. Pede a confirmação digitando "EXCLUIR" e mostra o aviso "Irreversível, sem os 30 dias de carência". As pendências são checadas no servidor e, se houver alguma, a exclusão é recusada com a lista. O ledger e o KYC com retenção legal são mantidos (C-5).
- **Regra final do "excluir agora"** *[E] (Negócios); base legal [decidido, pareceres J3, J23, J27]*:
  - **Impedimentos, mostrados por categoria** (X-3), só depois da reautenticação e do OTP:
    | Categoria exibida | Mensagem / próximo passo |
    |---|---|
    | Reservas | "Você tem a reserva CL-1234 (aguardando pagamento, futura ou em andamento). Cancele ou conclua antes." + "Ver reserva" |
    | Disputas e incidentes | "Há uma disputa aberta na reserva CL-1234." + "Ver disputa" |
    | Financeiro | Saldo em aberto no ledger, **reembolso pendente** (para você ou para terceiros), chargeback em andamento: "Há R$ 342,00 em aberto." + "Ver" |
    | Recebimento | Recebedor vinculado a um anúncio publicado, ou **troca de chave Pix em carência** (ou correção pelo suporte em andamento): "Aguarde o fim da carência em [data]." |
    | Gestão | admin_org, franqueado ou gestor com imóveis geridos ou franquia ativa: "Transfira os imóveis ou encerre o nó." (spec-04). **Único admin_plataforma**: "Nomeie outro administrador antes." |
    | **Genérica** (motivos sensíveis) | **Conta travada para revisão, fraude ou incidente em investigação, denúncia em análise CONTRA o usuário, legal hold e ordem judicial** aparecem **só** como **"Há uma pendência com a sua conta. Fale com o suporte."** (X-3) |
  - **Denúncias feitas PELO usuário não impedem**: elas continuam ligadas ao id e aparecem como "Usuário removido".
  - **Mensagens do usuário excluído** *(Produto, Q3)*: o texto é **mantido até o fim da retenção** (12 meses depois do check-out, ou 90 dias sem reserva), com o autor "Usuário removido", e depois é purgado (spec-08).
  - A checagem é feita **no servidor, na mesma transação da purga** (X-2).
  - **Não impedem, mas criam a lista de bloqueio de novo cadastro** (X-4 a X-7). Os dados ficam como **HMAC com chave secreta** do CPF e do telefone, **sempre com prazo e revisão, nunca permanentes**:
    - **bloqueios comportamentais** (expirações seguidas de 7 dias, suspensão por moderação) duram o **tempo da penalidade**;
    - **fraude comprovada** dura **5 anos**.
    A tela avisa: "Você poderá criar uma nova conta com este CPF a partir de [data]."
  - **O titular pode pedir revisão a qualquer momento**, com **resposta em até 15 dias corridos**, e **o pedido, a decisão, o motivo e quem decidiu ficam guardados por 5 anos** ([decidido, parecer itens 47 e 48]) (fila na spec-10, 2.9). Um cadastro bloqueado mostra a mensagem neutra e "Pedir revisão" (X-6).
  - **Registro do atendimento** (J23): o pedido de exclusão e a resposta (data, o que foi apagado, o que ficou retido e por quê) ficam guardados por **5 anos**, com dados mínimos (id, e-mail em HMAC, datas). O que fica retido é **pseudonimizado** (J3).
  - **A tela de confirmação lista o que NÃO é apagado** por retenção legal: "Mantemos, pelo prazo legal: documentos de KYC dos recebedores aprovados (relacionamento + 5 anos), lançamentos do ledger, informes de IR e notas fiscais (6 anos), o registro deste pedido (5 anos) e casos com legal hold. Esses dados ficam pseudonimizados e bloqueados para qualquer outro uso."
  - **Critérios de aceite:** (1) cada impedimento aparece pela sua categoria, e os sensíveis só pela mensagem genérica; (2) a exclusão e uma nova reserva ao mesmo tempo: só uma vence (X-2); (3) uma denúncia feita pelo usuário não impede, e fica como "Usuário removido"; (4) toda entrada da lista de bloqueio tem data de fim, e as de fraude têm 5 anos; (5) o pedido de revisão é aceito a qualquer momento e respondido em até 15 dias; (6) a confirmação lista os itens retidos; (7) ser o único admin_plataforma impede a exclusão.
**Critérios de aceite:** (1) os 3 casos de e-mail dão a mesma resposta (C-1, C-6); (2) "Reativar" nunca aparece antes do OTP; (3) uma conta comprometida não se reativa sozinha; (4) "excluir agora" com motivo bloqueante é recusado com o motivo e o próximo passo, e sem reautenticação recebe 403; (5) todas as ações ficam no log, e o "excluir agora" é registrado só com o marcador e o id.

- **C-1** (**Alta**): **Enumeração.** A resposta da tela/API é **idêntica** para e-mail inexistente, ativo e em carência: "Enviamos um código para [e-mail], se ele puder ser usado." O que muda vai **só no e-mail**: inexistente → OTP de cadastro; ativo → "Você já tem conta: entrar / recuperar senha"; em carência → "Sua conta está em exclusão: reativar com este código". A opção "reativar" **só aparece depois do OTP válido**, nunca antes. Mesmo status HTTP, mesmo corpo e mesmo formato de JSON.
- **C-2** (Média): **Timing.** Tempo de resposta constante: enfileirar o e-mail (mail_queue) em todos os casos e responder sem esperar o envio. Fazer a mesma quantidade de consultas e hashes nos três caminhos. Teste: a diferença média entre os três casos fica abaixo de um limite (ex.: 50 ms em 100 requisições).
- **C-3** (Média): **Rate limit** por IP (ex.: 10/h) **e** por e-mail (ex.: 3 OTPs/h e 10/dia), com cooldown no `mail_queue.cooldown_key` existente. O OTP tem 6 dígitos, validade de 10 min, no máximo 5 tentativas e é guardado como hash. Ao atingir o limite, mostrar a **mesma resposta neutra** (não revelar o bloqueio por e-mail). CAPTCHA opcional depois de N tentativas.
- **C-4** (**Alta**): **Abuso do reativar.** Só com OTP no e-mail da conta. Antes de reativar, a tela não mostra nenhum dado da conta (nome, imóveis); depois, exigir **login com senha ou a troca de senha**. **Nunca reativar automaticamente** uma conta excluída com o motivo "roubo de conta / comprometida": ela vai para revisão manual. Ao reativar: invalidar todas as sessões e tokens, mandar notificação a todos os canais ("Sua conta foi reativada em [data, IP]. Não foi você?"), registrar no log e **não** restaurar a chave Pix pendente nem as delegações sem confirmação. Os imóveis voltam só os inativados pela exclusão (N3).
- **C-5** (**Alta**): **Abuso do excluir agora.** Só para uma sessão **autenticada**: reautenticação (senha) **e** OTP recente (no máximo 10 min). Nunca pelo fluxo de cadastro ou checkout, ou seja, quem só conhece o e-mail não inicia. Confirmação explícita (digitar "EXCLUIR" ou o e-mail) e o aviso "irreversível; sem os 30 dias de carência". Pendências checadas **no servidor** na mesma transação: reservas futuras ou em andamento, pagamentos a receber ou repassar, estornos, disputas, denúncias em análise, bloqueio legal ou fraude, recebedor com saldo. Se houver algo, recusar com a lista. A purga é a mesma do job (mesmo código). O log grava o marcador `purga_imediata` + id + data, sem dados pessoais. Os itens com retenção legal (ledger, KYC) são mantidos (N8). Mandar um e-mail de confirmação **antes** da purga do endereço.
- **C-6** (Baixa): Rate limit também no excluir agora e no reativar (por conta e IP). Testes: os 3 casos de e-mail com a mesma resposta; uma conta comprometida não se reativa sozinha; excluir agora com pendência → recusado; excluir agora sem a reautenticação → 403.

- [decidido, parecer J23] **J23**: Se o "excluir agora" precisa reter algo além do ledger e do KYC (ex.: registro do pedido do titular como prova de atendimento ao art. 18). → **Decisão:** guardar o **registro do pedido e da resposta por 5 anos** (id, e-mail em HMAC, datas).

## Requisitos de Segurança: excluir agora e lista de bloqueio (parecer de 16/10)
Fonte: `/workspace/casalivre-seguranca-evidence/specs-ui-04-06/relatorio.md`. O texto técnico foi transcrito sem alteração.

- **X-1** (Média): **Lacunas na lista de impedimentos:** (a) **troca de chave Pix em carência** ou correção pelo suporte em andamento (excluir no meio pode ser parte de um takeover, então bloquear até o fim da carência); (b) **reembolso ou estorno pendente** (para o próprio usuário ou para terceiros); (c) **denúncias em análise** em que o usuário é o denunciado (excluir apagaria o alvo; a denúncia feita **por** ele não impede); (d) **avaliações pendentes de publicação** do duplo-cego (não impede, mas definir: publicar anonimizada ou descartar); (e) **legal hold** em conversas, ou uma ordem judicial; (f) **KYC em análise** (concluir ou cancelar antes); (g) único **admin_plataforma** (não pode ficar sem nenhum); (h) uma conversa com pagamento fora da plataforma em investigação (= incidente). · Acrescentar (a), (b), (c), (e) e (g) como impedimentos. (d) e (f) são resolvidos automaticamente na purga.
- **X-2** (**Alta**): **Atomicidade e corrida:** checar os impedimentos e purgar na **mesma transação**, com `SELECT ... FOR UPDATE` na linha de `users` (PG) ou `BEGIN IMMEDIATE` (SQLite) e um lock nas reservas do usuário. Outros fluxos (criar reserva, receber pagamento, nova disputa) verificam `status='deleted'`/`purging` dentro da própria transação. Assim não entra uma reserva ou um pagamento entre a checagem e a purga. Idempotente (um segundo pedido → "já excluída"). A checagem é feita só no servidor, nunca pela UI. · Teste de concorrência: excluir agora × criar reserva ao mesmo tempo, e só um vence.
- **X-3** (Baixa): **Mostrar o motivo do impedimento vaza informação?** Para o **próprio titular autenticado** (reautenticado + OTP, C-5), mostrar as categorias ("você tem 1 reserva futura", "há saldo a receber") é aceitável e necessário para a pessoa resolver. **Não mostrar** os motivos sensíveis: fraude ou incidente em investigação, denúncia contra ele, legal hold, ordem judicial. Para esses, usar a mensagem genérica "Há uma pendência com a sua conta. Fale com o suporte." Nunca mostrar nada disso antes da reautenticação. · Duas classes de motivo: exibível e genérico.
- **X-4** (**Alta**): **O hash na lista de bloqueio é aceitável? Hash simples, não.** O CPF tem cerca de 10^9 valores e o telefone um pouco mais. Um SHA-256 puro é revertido por força bruta em minutos ou horas, então **continua sendo dado pessoal** e não traz proteção real. **Com HMAC, sim**, como pseudonimização (continua sendo dado pessoal; enquadramento a validar com jurídico): normalizar (CPF só os 11 dígitos; telefone em E.164) e fazer **HMAC-SHA256 com uma chave secreta (pepper) fora do DB e do repo**, no gerenciador de segredos (ou arquivo 0400 do usuário php-fpm), com um **id de versão da chave** em cada linha. A comparação é por igualdade de HMAC (índice). A rotação exige recalcular com o dado disponível (o CPF de um novo cadastro é comparado nas duas chaves durante a transição) ou **manter as duas chaves** até as entradas antigas vencerem. Nunca reaproveitar a mesma chave para outros fins (ex.: a pseudonimização dos logs de N4: usar uma chave separada). · Ver o bloco de deploy.
- **X-5** (Média): **O que guardar e por quanto tempo:** só `hmac`, `key_version`, `tipo` (cpf/telefone), `motivo` (código), `expira_em` e `caso_ref` (id do caso, sem texto livre com dados pessoais). Prazos: bloqueio de 7 dias → expira em 7 dias; suspensão por moderação → o prazo da suspensão. **"Para sempre" em fraude comprovada vira um prazo com revisão periódica** (ex.: 5 anos, com revisão anual ou a cada 2 anos; a validar J27). Purga automática no vencimento (cron diário, idempotente, log com a contagem). |
- **X-6** (Média): **Acesso e direitos:** consulta só pelo sistema (cadastro e KYC fazem a comparação) e pelo admin_plataforma/antifraude; log de cada uso (consulta com hit, inclusão, remoção). **Transparência** no aviso de privacidade. Quando um cadastro é bloqueado pela lista, mostrar uma mensagem neutra com um canal de **contestação com revisão humana** (art. 20, decisão automatizada). Um falso positivo (CPF de terceiro usado por fraudador) é corrigido com a remoção da entrada e log. Não compartilhar a lista com terceiros. |
- **X-7** (Baixa): **Coerência:** a lista de bloqueio é um tratamento **novo**, que sobrevive à exclusão. Ela não pode ser usada para reidentificar a conta purgada (sem id de usuário na linha; `caso_ref` aponta para o caso de moderação, que já é pseudonimizado). |

- [decidido, parecer J27] **J27**: A base legal da lista de bloqueio (legítimo interesse, art. 7º IX/art. 10, com teste de balanceamento; ou prevenção à fraude, art. 11 II g, se houver biometria). Prazos por motivo (7 dias, suspensão, fraude comprovada). Se a **guarda permanente** em fraude comprovada é admissível ou precisa de prazo com revisão (recomendo prazo). O **direito de oposição** (art. 18 §2º) e a contestação/revisão humana (art. 20). O enquadramento do HMAC como dado pessoal pseudonimizado (**validar com jurídico**). → **Decisão:** HMAC; prazo da punição; **fraude, 5 anos**; **nunca permanente**.

## 1. Troca de modo e de contexto: UC-C08, UC-P33
**Modos:** Viajar (hóspede) e Gerenciar (qualquer papel). A troca fica no menu do avatar (desktop) e em Perfil (mobile). Sem papel e sem imóvel, o botão vira "Anuncie seu imóvel".
**Seletor de contexto:** fica no topo da barra lateral no desktop e, no mobile, abre um painel que sobe da parte de baixo da tela. É uma árvore de **N níveis** carregada sob demanda, com **busca por nome** (o resultado mostra o caminho: "… › Imobiliária Sol › Casa Azul") e lista de recentes. A pessoa só vê os nós em que tem papel e os descendentes deles. Com um único nó, o seletor fica oculto.
**Indicador de contexto ativo:** uma trilha sempre visível. Com mais de 3 níveis, **os do meio são recolhidos**: "Plataforma › … › Imobiliária Sol › Casa Azul", e o "…" abre a lista dos níveis ocultos. A trilha vem com o selo do papel ("Gestor"). Ao trocar de nó, os dados, a fila, os contadores e o menu são recalculados.
**Aterrissagem:** o **último contexto usado**. No primeiro acesso, vale o papel de maior escopo. *(provisório)*
**Deep links:** a URL leva o modo e o contexto (`/gerenciar/{no}/reservas/{id}`). Quem abre o link cai no modo e no contexto certos, mesmo que estivesse em outro. Se a pessoa tem acesso por outro nó, o sistema troca para ele e avisa ("Mudamos para Imobiliária Sol para abrir esta reserva"). Sem login, ela entra na mesma página (spec-01) e volta ao link.
**Sem permissão:** a página mostra "Você não tem acesso a isto em [contexto]", com as ações "Trocar de contexto", "Pedir acesso a [gestor]" e "Voltar". Não revela dados do recurso (nem nome nem valor). O servidor responde 403, ou 404 quando a existência do recurso já seria um vazamento.
**Estados:** trocando ("Abrindo Imobiliária Sol…" com skeleton); convite pendente (banner "Aceitar convite da Rede Lagos"); nó arquivado ("Esta organização foi desativada").
**Critérios de aceite**
1. Para cada um dos 6 papéis, o primeiro login leva à aterrissagem da matriz e os seguintes, ao último contexto usado, e o menu mostra exatamente as colunas ✓ e ver.
2. Dado um operador, então Anúncios, Recebimento e Ganhos não existem no menu, e uma chamada direta recebe 403.
3. Dado um gestor sem permissão de canais, então "Canais e temas" aparece desabilitado e o foco revela a explicação.
4. Dado um deep link para uma reserva de outro nó a que tenho acesso, quando o abro, então caio no modo Gerenciar, no nó certo, com o aviso da troca. Sem acesso, vejo o estado "sem permissão" sem nenhum dado da reserva.
5. Ao trocar de contexto, a trilha muda, o título da página inclui o nó, e o aria-live anuncia "Contexto: Rede Lagos, admin da organização".
6. Os itens de menu dependem de permissão no nó, nunca de `tipo`.
7. Dado um admin_org, então o seletor não mostra nós fora da sub-árvore dele.
8. Dada uma árvore com 6 níveis, então a trilha recolhe os níveis do meio, o "…" é focável e lista os ocultos, e a busca do seletor encontra qualquer nó acessível pelo nome.

## 2. Fila de reservas (aprovar ou recusar): UC-P20, UC-P21, UC-P22
> **Telas substituídas pela spec-06** (confirmado pelo Produto): as telas desta seção estão na spec-06, seções 1 e 2. As **regras** continuam valendo aqui.
**Objetivo:** responder a tempo e sem erro.
**Layout:** no desktop, uma lista ordenada pelo **prazo mais próximo** e o detalhe ao lado. No mobile, a lista e o detalhe ficam em telas separadas. A aba "Hoje" tem um contador ("3 aguardando você").
**Item:** hóspede (nome e nota, sem contato), imóvel, datas, hóspedes, total, canal de origem, mensagem ao anfitrião e o **prazo**.
**Prazo:** 24 h a partir do envio. O padrão é definido no **nó da árvore**, herdado pelos descendentes, e pode ser sobrescrito por imóvel *(provisório)*, que cai para **4 h se o check-in for em menos de 72 h**. A tela mostra o texto "Responder até 16/10, 14:30 · faltam 3 h 05 min". Com menos de 1 h, o item ganha o selo "Urgente" (texto e ícone). O aria-live só anuncia quando falta 1 h e quando expira, nunca a cada minuto.
**Ações:** "Aprovar" leva a reserva para Aguardando pagamento (spec-01, seção 5). "Recusar" abre um modal com um **motivo fixo**: Datas indisponíveis, Não atende às regras da casa, Manutenção, ou Outro (com texto). Depois vem uma confirmação. O hóspede vê só a mensagem neutra e sugestões de imóveis parecidos nas mesmas datas, com prioridade para a mesma rede e o mesmo canal (spec-01, seção 5b). Ao vencer o prazo, a reserva vira Expirada e as datas são liberadas.
**Estados:** fila vazia ("Nenhuma solicitação aguardando. Bom trabalho!"); carregando (skeleton); erro ("Não foi possível carregar. Tentar novamente"); ação concorrente ("Esta solicitação já foi respondida por Ana às 10:12").
**Critérios de aceite**
1. Dado um check-in daqui a 48 h, então o prazo exibido é de 4 h. Daqui a 10 dias, é o valor configurado.
2. A lista vem ordenada pelo prazo, e o selo Urgente tem texto, não só cor.
3. Dado que outra pessoa da organização respondeu, quando tento aprovar, então vejo o aviso e não há dupla ação.
4. Recusar exige uma confirmação. Aprovar mostra "Aprovada. O hóspede tem até [dd/mm, hh:mm] para pagar (24 h, ou 4 h se o check-in for em menos de 72 h)." e o anuncia.
5. Um prazo vencido sem resposta gera Expirada, e as datas voltam ao calendário.

## 3. Marcar como pago (piloto, pagamento por fora): UC-P30 *(provisório, revisão Negócios)*
> **Telas substituídas pela spec-06** (confirmado pelo Produto): as telas desta seção estão na spec-06, seções 1 e 2. As **regras** continuam valendo aqui.
**Quem:** o proprietário, o gestor ou o operador com permissão delegada. Toda ação fica no log.
**Onde:** no detalhe da reserva em Aguardando pagamento, com o prazo (24 h / 4 h) visível.
**Duas confirmações separadas, cada uma com seu registro:** **"Estadia recebida"** (valor da estadia, recebedor do imóvel) e **"Limpeza recebida"** (valor da limpeza, recebedor prestador). Cada uma tem modal com valor, data e **comprovante opcional** (imagem ou PDF, com o upload da spec v1). A reserva só vira **Confirmada quando as duas estão marcadas**. Enquanto isso, o status é "Estadia recebida · Limpeza pendente".
**Resultado:** com as duas marcadas, a reserva vira Confirmada, o endereço é liberado e o ledger lança a comissão como conta a receber (D11). Há "Desfazer" por 5 s. Depois disso, **só o proprietário ou o gestor** podem desfazer, com **justificativa obrigatória** e log. Disputas ficam só com o admin_plataforma.
**Critérios de aceite:** (1) a diária e a limpeza geram dois registros independentes, com autor, data e comprovante; (2) com uma só marcada, a reserva não é confirmada; (3) um valor diferente do esperado pede confirmação extra; (4) desfazer depois dos 5 s exige justificativa e o operador não vê essa ação; (5) com o prazo vencido sem as duas marcações, a reserva expira; (6) o comprovante só é visível para quem tem papel no nó.

## 4. Recebedores e chave Pix ⚠ fraude-crítico, revisão de Segurança obrigatória *(provisório)*
**Onde:** os dados de pagamento ficam no **cadastro de recebedores (S6)**, que é feito **no nó da árvore**. Em Anúncios → [imóvel] → "Recebimento", a pessoa só **escolhe ou vincula** o recebedor da estadia e o recebedor prestador da limpeza.
**Quem:** o proprietário cadastra o próprio recebedor. O gestor cadastra um recebedor **só com delegação explícita**, e **nunca altera sozinho a chave do próprio proprietário**: isso exige a aprovação dele. O operador nunca acessa.
**Incluir ou trocar a chave:**
1. A titularidade da chave precisa **corresponder ao CPF/CNPJ do recebedor**. Se não corresponder, aparece "A chave não pertence ao titular [nome, CPF mascarado]".
2. **OTP** para quem faz a alteração (canal da D3), e **aviso ao proprietário** em todos os casos.
3. **Carência de 48 h** (aprovada pelo Negócios): o status vira **"Pendente até [dd/mm, hh:mm]"**, e a chave nova só vale para **reservas novas** depois disso. Reservas existentes continuam com a chave do snapshot. Durante a carência, o proprietário vê "Não reconhece? Cancelar alteração".
4. **Trilha de auditoria:** quem, quando, IP ou dispositivo, valor anterior e novo (mascarados).
**Critérios de aceite:** (1) uma chave com titular diferente é recusada; (2) sem OTP, nada é salvo; (3) uma chave em "Pendente até" não aparece em nenhuma instrução de pagamento; (4) o proprietário recebe o aviso e pode cancelar durante a carência; (5) o gestor sem delegação vê o item desabilitado com a explicação; (6) os admins veem só os últimos dígitos.

## 4a. Mover um nó da árvore *(provisório)*
**Quem:** só o **admin_plataforma** move nós. Criar um nó filho cabe ao admin do nó pai.
**Tela de confirmação:** origem → destino, mais a quantidade de imóveis e de reservas afetadas, com o texto em destaque **"A mudança vale só para reservas novas. Reservas existentes mantêm as regras de split, prazo e termos do momento em que foram feitas."** A pessoa digita o nome do nó para confirmar. A ação fica no log.
**Critérios de aceite:** (1) a confirmação mostra os números e o texto sobre reservas existentes; (2) as reservas antigas mantêm o snapshot; (3) a ação é registrada no log com autor e motivo.

## 4c. Confiabilidade do anfitrião *(provisório)*
O painel (Hoje e Desempenho) mostra **"Confiabilidade nos últimos 12 meses: 96% · 1 cancelamento"**. A fórmula é **cancelamentos pelo anfitrião ÷ reservas confirmadas nos últimos 12 meses**, e o rótulo sempre diz "12 meses" *(Negócios)*. A janela de **90 dias** é usada **só como gatilho** (3+ cancelamentos em 90 dias → revisão da conta, spec-09) e não aparece no indicador. Ao cancelar, o anfitrião escolhe um **motivo obrigatório**, e os de **força maior** (dano no imóvel, clima, saúde) ficam registrados mas **não contam** na métrica. **Força maior: comprovação** *[estimativa; aprovado pelo Negócios]*:
- O anfitrião tem **7 dias** para enviar a prova pelo painel ("Enviar comprovação · faltam 5 dias"). Aceita foto ou laudo do dano, atestado médico ou alerta oficial da Defesa Civil.
- Quem decide é o **admin do nó acima do anfitrião** (franqueado ou admin_org). Cabe recurso ao **admin_plataforma**.
- **Conflito de interesse:** se quem decide for a mesma pessoa ou organização que cancelou ou que ganha com a reserva, a decisão **sobe um nível** automaticamente.
- Toda decisão fica no log, com quem decidiu, o motivo e o documento.
- Sem prova no prazo, ou com a prova recusada, o cancelamento **conta** na métrica.
- Estados: "Comprovação pendente até [data]", "Em análise", "Aceita (não conta)", "Recusada (conta)" e "Em recurso".
- **Porta futura (sem tela agora):** força maior do **hóspede** (saúde, Defesa Civil, bloqueio de estrada), com reembolso fora da política, pelo mesmo fluxo. e o painel mostra o cálculo. **3 ou mais cancelamentos em 90 dias** geram um aviso ao anfitrião e entram numa **revisão pelo admin** *[estimativa]*. Todos os valores são parametrizáveis. Os limites possíveis, como a suspensão da reserva instantânea, são decididos na revisão. O aviso é "Seus cancelamentos recentes reduziram sua confiabilidade. Reserva instantânea suspensa até [data]." Antes de cancelar, ele vê "Cancelar afeta sua confiabilidade".
**Critérios de aceite:** a métrica fica visível ao anfitrião com o histórico; o limite mostra a data de fim; o aviso usa texto e ícone.

## 4b. Canais de vitrine e temas (admins, e o gestor quando o canal pertence ao nó dele; S5)
**Onde:** Gerenciar → Canais. Lista os canais do escopo (marketplace, site regional, site do parceiro), com domínio, status e quantidade de imóveis.
**Canal:** nome, domínio, tema (logo, tokens de cor, fonte), imóveis publicados no canal (um imóvel pode estar em vários) e a organização responsável.
**Tema:** editor de tokens com uma prévia ao vivo dos cards, botões, card de preço e estados de erro e de sucesso. **A validação de contraste roda ao salvar**: texto/fundo ≥ 4,5:1 e componentes ≥ 3:1, para cada par de tokens. Se reprovar, o tema não é salvo e aparece um resumo dos pares reprovados ("Texto em primary: 3,1:1, mínimo 4,5:1"), com link para cada campo e o foco no primeiro erro.
**Estados:** domínio não verificado ("Aguardando DNS"); canal sem imóveis (vazio com "Adicionar imóveis").
**Critérios de aceite**
1. Dado um tema com contraste abaixo do mínimo, quando salvo, então nada é salvo e vejo cada par reprovado com a razão medida.
2. Um tema aprovado é aplicado ao domínio do canal sem mudar outros canais do mesmo imóvel.
3. Um admin_org só vê os canais da sub-árvore dele. O gestor edita o tema se o canal pertencer ao nó dele, sempre com a validação de contraste. Senão, vê o item desabilitado com a explicação.
4. A prévia tem um texto equivalente (a lista dos pares e das razões), sem depender só da visualização.

## 5. WCAG 2.2 AA
- Contadores de prazo em texto absoluto e relativo, com anúncio só nos marcos (4.1.3, 2.2.1). Nenhuma ação vence sem aviso visível.
- Modais (recusar, marcar como pago) com foco preso, Esc fecha e o foco volta ao botão de origem (2.4.3).
- Selos de status e de urgência com texto e ícone (1.4.1). Contraste de 4,5:1 e de 3:1 para componentes (1.4.3, 1.4.11).
- Upload de comprovante com alternativa ao arrastar (2.5.7) e alvos de no mínimo 24 px (2.5.8).
- O seletor de contexto é uma árvore acessível (padrão Tree View da APG: setas, Home/End, digitar para buscar). A trilha usa `nav aria-label="Contexto"`, com `aria-current` no último nível.
- Itens desabilitados ficam focáveis, com `aria-disabled` e a explicação ligada por `aria-describedby`.
- O validador de contraste informa os erros em texto (3.3.1), com um resumo e links para os campos.
- Ações destrutivas (recusar, estornar) pedem confirmação (3.3.4).

## Pendências para Produto
Os itens pendentes não bloqueiam o restante da spec.
1. [pendente: Segurança, via Gerente Geral] Parecer sobre a seção 4 (chave Pix). A carência de 48 h foi aprovada pelo Negócios.
2. [pendente: Negócios] Calibrar no piloto os parâmetros de confiabilidade e definir as sanções depois da revisão pelo admin.
3. [pendente: Rafael] D3: canal do código para a troca de chave e para os avisos.
4. [pendente: Negócios] Impedimentos extras ao "excluir agora" além da lista da seção de exclusão.
5. [pendente: Rafael] Liberar o e-mail só depois da purga, nunca durante a carência (parecer J19; decisão de produto).
6. [pendente: Negócios] Revisão das decisões provisórias.
