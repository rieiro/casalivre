# CasaLivre: Spec 06, painel Hoje, fila de aprovação e calendário (v1, 16/10/2026, alvo S7 (P2 e P10), meta WCAG 2.2 AA)

Base: `sprints.md` (S7: reserva P2, critérios 1 a 23; D3 bloqueia a S7), `backlog.md` P2 e as specs 01 a 05. **Esta spec substitui as telas das seções 2 (fila de reservas) e 3 (marcar como pago) da spec-02. As regras continuam na spec-02** (confirmado pelo Produto). A spec usa só tokens de tema.
**Casos de uso:** UC-P15 a UC-P18, UC-P20 a UC-P22, UC-P28, UC-P30, UC-P32 e UC-C10.
**Navegação (spec-02):** no mobile, as abas inferiores são **Hoje, Reservas, Calendário, Anúncios e Menu**. No desktop, uma barra lateral com o seletor de contexto e a trilha. **Os itens dependem do papel:** o operador não vê Anúncios. Os admins (admin_plataforma e admin_org) **só visualizam**: as ações aparecem desabilitadas, com "Somente visualização para administradores".

## 1. Hoje (painel)
**Objetivo:** mostrar em 10 segundos o que precisa de ação agora, no contexto ativo (nó ou imóvel).
**Layout:** no desktop, uma grade de cartões em 2 colunas: à esquerda, a ação (Aprovações, Pagamentos); à direita, a operação (Chegadas e saídas, Portaria, Alertas, Tarefas). No mobile, uma coluna na mesma ordem de prioridade. O topo tem um resumo: "3 aprovações · 2 pagamentos · 4 chegadas hoje".
**Cartões**
1. **Aprovações pendentes:** ordenadas pelo prazo. Cada item mostra hóspede, imóvel, datas, total e "Responder até 16/10, 14:30 · faltam 3 h 05 min". O prazo é de 24 h, ou 4 h se o check-in for em menos de 72 h, com o padrão herdado do nó e possível exceção por imóvel. Com menos de 1 h, aparece o selo **"Urgente"** (texto e ícone). O botão "Analisar" abre a seção 2.
2. **Aguardando pagamento:** cada item tem o prazo (mesma regra) e os botões **"Estadia recebida"** e **"Limpeza recebida"**, separados (spec-02, seção 3). Cada um abre o modal com o valor, a data e o comprovante opcional. O status parcial aparece como "Diária ✓ · Limpeza pendente".
3. **Chegadas e saídas de hoje e amanhã:** hóspede, imóvel, horário (check-in às 14h e check-out às 11h por padrão) e "Ver reserva".
4. **Portaria** *[E]*: o prazo padrão é **48 h antes do check-in**, configurável por imóvel. O hóspede recebe lembretes com 72 h e com 24 h de antecedência. Se a lista atrasar, o anfitrião e o gestor são avisados, e a reserva **não é cancelada**. A lista de documentos dos hóspedes (spec-01) aparece com o status **"Enviada"**, **"Pendente: faltam 2 dias para o check-in"** ou **"Atrasada"**. **No cartão aparece só o status** (06-5). A lista é vista pelo proprietário e pelos delegados de check-in. Os admins só a veem com motivo e log (06-2). A portaria recebe nome e documento, sem foto, por um link autenticado de validade curta (06-4).
5. **Alertas:**
   - chave Pix **"Pendente até [data]"**;
   - **KYC pendente ou com pendência**;
   - **erro de sincronização iCal** ("Airbnb: falha às 09:12. Verificar link");
   - **anúncio pausado ou incompleto** ("Faltam 2 itens para publicar");
   - **aviso de confiabilidade** ("3 cancelamentos em 90 dias, em revisão").
   Cada alerta tem uma ação direta.
   **Intervalo curto (G19) não é alerta:** não entra nos cartões, no resumo, em selo, toast nem chamada.
6. **Tarefas:** comprovação de força maior pendente (prazo de 7 dias), avaliações a responder e convites aceitos.
**Por papel:** o proprietário vê os imóveis dele. O gestor vê a sub-árvore dele. O operador vê só Aprovações, Pagamentos (se tiver a delegação "marcar como pago"), Chegadas e Portaria. Os admins veem tudo do escopo, só para leitura.
**Estados:** carregando (skeleton por cartão; um cartão com erro não derruba os outros: "Não carregou. Tentar novamente"); dia tranquilo ("Nada pendente hoje. Próxima chegada: 18/10."); sem imóveis ("Cadastre seu primeiro imóvel").
**Critérios de aceite**
1. Dado um contexto ativo, então os cartões mostram só os dados daquele nó e dos descendentes, e trocar o contexto atualiza tudo.
2. Dado um check-in daqui a menos de 72 h, então o prazo exibido é de 4 h. Caso contrário, é o prazo herdado do nó ou a exceção do imóvel.
3. O tempo restante aparece em texto, com a data e hora absolutas, e é **anunciado só quando falta 1 h e quando expira**.
4. "Estadia recebida" e "Limpeza recebida" geram registros separados. Com uma só marcada, a reserva continua em Aguardando pagamento.
5. Um operador sem a delegação não vê os botões de pagamento. Um admin vê os botões desabilitados, com a explicação.
6. Cada alerta leva à tela que resolve o problema em um clique ou toque.
7. Um intervalo curto no calendário não cria item em Alertas, nem selo, toast ou chamada.

## 2. Fila de aprovação e detalhe da solicitação
**Layout:** no desktop, a lista (ordenada pelo prazo) e o detalhe ao lado. No mobile, a lista e, ao tocar, o detalhe em tela cheia, com as ações fixas no rodapé.
**Detalhe**
- **Hóspede:** nome, foto, **verificação** ("Identidade verificada · CPF validado"), avaliações anteriores ("4,8 · 6 estadias") e "Membro desde 2026". Nada de contato (mascarado).
- **Pedido:** datas, noites, hóspedes por faixa (adultos, crianças, bebês), pets, canal de origem e o **total discriminado**: diárias, "Limpeza (paga ao prestador)" e a taxa de serviço (se for maior que 0%).
- **Checagem das regras da casa:** cada regra aparece como ✓ "Atende" ou ⚠ **"Conflito"**, com texto. Exemplos: "⚠ 2 pets: o imóvel aceita até 1", "⚠ 7 hóspedes: a capacidade é de 6 (bebês não contam)", "⚠ Responsável com menos de 21 anos: o imóvel exige 21+". Os conflitos aparecem primeiro, e o aviso do ECA é exibido quando há menores.
- **Mensagem ao anfitrião** (se houver).
- **Prazo:** "Responder até… · faltam…".
**Ações:**
- **"Aprovar"** abre a confirmação "Aprovar a reserva de [nome], de 20 a 25/12? O hóspede terá [24 h/4 h] para pagar." e leva a reserva para Aguardando pagamento.
- **"Recusar"** abre um modal com o motivo fixo (Datas indisponíveis, Não atende às regras da casa (já preenchido se houver conflito), Manutenção, Outro (com texto)), mais a nota "O hóspede verá só uma mensagem neutra e imóveis parecidos." e a confirmação.
**Concorrência:** se outra pessoa responder antes, aparece "Esta solicitação já foi respondida por Ana às 10:12 (Aprovada)", as ações ficam desabilitadas e o foco vai para o aviso.
**Auditoria:** cada visualização de documento, aprovação e recusa fica registrada (autor, papel, horário, motivo).
**Estados:** fila vazia ("Nenhuma solicitação aguardando"); expirada enquanto aberta ("O prazo acabou e as datas foram liberadas"); erro ao enviar a decisão ("Não enviamos. Tentar novamente", sem perder o motivo).
**Critérios de aceite**
1. Os conflitos com as regras aparecem no topo da checagem, com texto e ícone. A capacidade desconta os bebês conforme a configuração.
2. Aprovar e recusar exigem confirmação. Na recusa, o motivo é obrigatório, e um conflito pré-seleciona "Não atende às regras da casa".
3. Com duas decisões concorrentes, só uma vale. A segunda pessoa vê o aviso e não há duplicidade (o servidor responde 409).
4. O hóspede recusado nunca vê o motivo (spec-01, seção 5b).
5. Toda decisão fica no log com autor e motivo.
6. Não há nenhum dado de contato do hóspede no detalhe.

## 3. Calendário
**Visões:** **Mensal por imóvel** (padrão quando o contexto é um imóvel) e **Multicalendário** (padrão quando o contexto é um nó, com uma linha por imóvel e uma coluna por dia, rolagem horizontal por semanas, e o filtro e a busca de imóvel fixos à esquerda).
**Status de cada dia (com padrão visual, texto e não só cor).** Cada dia tem um só status; eles não se combinam. O campo é `day_status`.
| Status | Visual | Rótulo |
|---|---|---|
| Disponível | fundo liso, com o preço | "Disponível · R$ 450" |
| Reservado | sólido, com o nome do hóspede | "Reservado · Ana" |
| Aguardando aprovação | listrado diagonal | "Aguardando aprovação" |
| Aguardando pagamento | pontilhado | "Aguardando pagamento · até 17/10, 10:30" |
| Bloqueado | hachurado com ícone de cadeado | "Bloqueado · Manutenção" |
| Intervalo curto | preenchimento suave de `color.warning` (âmbar de aviso) e listras horizontais | "Indisponível · intervalo curto" |
**Intervalo curto (G19, calendário do anfitrião):** noite livre entre reservas que o mínimo de estadia impede de reservar. Tela da UI (`/workspace/shared/ui/g19-host-calendar-gap-nights.md`). Campo confirmado pela Arquitetura.
- O dia traz `day_status` de primeira classe. Intervalo curto é `day_status: "unbookable_gap"`. Não há objeto `flags`.
- Os status desta seção são exclusivos. A tela lê só `day_status` (um caminho de pintura). Todo dia que o motor marca como `unbookable_gap` usa a mesma pintura, só no calendário do anfitrião (mensal e multicalendário). Não aparece na busca, no calendário do hóspede nem no painel Hoje. A tela não calcula o intervalo.
- A pintura é o preenchimento suave do token `color.warning` mais listras horizontais, com o rótulo "Indisponível · intervalo curto". O leitor de tela lê a data e esse rótulo. Não depende só da cor.
- Continuar selecionável, com o mesmo painel de um dia livre (bloquear, desbloquear, preço e mínimo de noites), é regra desse status.
- **Não entra:** alerta ao anfitrião ou ao nó acima; relaxamento automático do mínimo de estadia; selo, toast ou chamada no Hoje.
**Seleção de intervalo:** clicar no 1º e no último dia, ou arrastar. **Alternativa por teclado:** setas para mover, Shift+setas para estender e Enter para abrir o painel. No mobile, tocar no início e no fim, com o botão "Selecionar intervalo".
**Painel lateral (desktop) ou painel inferior (mobile), para o intervalo selecionado ("20 a 25/12 · 5 noites"):**
- **Bloquear** ou **desbloquear**, com o motivo (manutenção, uso próprio, outro) e uma **nota**. O **operador** não bloqueia livremente: com a permissão delegada, ele cria só um **bloqueio de manutenção, com motivo obrigatório** e registro no log *[E]*.
- **Mínimo de noites** no intervalo.
- **Preço:** a diária base é **única por imóvel**. **Períodos (S7, versão mínima)** *[E]*: datas de início e fim, com **preço por noite** e **mínimo de noites** próprios. Por exemplo, "Réveillon 28/12 a 02/01 · R$ 900 · mín. 5 noites". Os períodos aparecem no calendário com o nome. **Não é permitido salvar períodos sobrepostos**: aparece "Este período sobrepõe '[nome]'. Ajuste as datas para salvar." (na S8, vale o mais curto; spec-07). A extensão (preço por dia da semana e descontos) fica na spec-07. O **acréscimo por canal aparece só para leitura** ("Marketplace R$ 450 · OTA (+15%) R$ 517,50").
- Datas com reserva não podem ser bloqueadas: "Há uma reserva neste intervalo. Abra a reserva para alterar."
**iCal:** o status por calendário externo aparece como "Airbnb: sincronizado às 10:05" ou "⚠ Falha às 09:12: link inválido", com os botões "Sincronizar agora" e "Ver link de exportação". As datas importadas aparecem como Bloqueado, com origem "iCal: Airbnb".
**Estados:** carregando (skeleton da grade); sem imóveis; erro de sincronização (banner); salvando ("Salvando 5 dias…" seguido de "5 dias bloqueados"); conflito ao salvar ("Uma reserva entrou nessas datas agora. Atualizamos o calendário.").
**Critérios de aceite**
1. Cada status é distinguível sem cor (padrão e rótulo), e o leitor de tela lê "20 de dezembro, Aguardando pagamento até 17/10".
2. Um intervalo pode ser selecionado e bloqueado só pelo teclado.
3. Bloquear um intervalo que tem reserva é recusado, com a mensagem e o link para a reserva.
4. O preço por canal é só de leitura e bate com o preço que o hóspede vê.
5. A falha de iCal aparece no calendário e como alerta em Hoje.
6. O multicalendário mantém fixa a coluna dos imóveis ao rolar, e os dias com reserva continuam visíveis para o operador, que não edita preço.
7. Com `day_status` `"unbookable_gap"`, o mensal e o multicalendário mostram o preenchimento de `color.warning`, as listras horizontais e o rótulo "Indisponível · intervalo curto". Qualquer outro `day_status` não usa essa pintura. A resposta do dia não traz `flags`.
8. Dois dias com `day_status` `"unbookable_gap"` usam a mesma pintura. O leitor de tela anuncia a data e "Indisponível · intervalo curto".
9. A edição igual à de um dia livre é a regra desse status: a célula entra na seleção e abre o painel (bloquear, preço, mínimo).
10. O mínimo de estadia do imóvel não muda por causa desse status.

## 3b. Origem da reserva e "Lançar reserva manual"
**Toda reserva nasce de uma solicitação** com a **origem** registrada: **Pedido** (aprovação manual), **Imediata** (reserva instantânea), **OTA** (iCal ou parceiro) ou **Manual** (lançada pelo anfitrião). A origem aparece como **rótulo** ("Origem: Manual") no painel Hoje, na fila, no detalhe da reserva (aqui e na spec-09) e no calendário.
**Reservas OTA** (Produto; detalhes técnicos: Arquitetura): entram direto como **Confirmada**, com `origem = ota` e o rótulo **"Origem: OTA"** no painel Hoje, no detalhe e no calendário; **sem etapa de pagamento casalivre** (não aparecem em Aguardando pagamento nem na fila de aprovação); **bloqueiam o calendário na hora**; **ficam fora do funil de conversão**; o **cancelamento segue a política da OTA**; o ledger registra **só o que a casalivre cobra**.
**Critérios OTA:** (a) uma reserva OTA sincronizada aparece Confirmada e bloqueia as datas no mesmo ciclo de sincronização; (b) ela não gera os botões "Estadia recebida"/"Limpeza recebida"; (c) o funil (P1) não a conta; (d) o ledger não lança diária nem limpeza dela.

**Lançar reserva manual** (para os clientes fiéis do anfitrião): o botão fica no calendário ("+ Reserva manual") ou aparece depois de selecionar um intervalo. Campos:
- **datas**;
- **hóspede** (nome, e-mail e celular; a identificação por OTP segue a spec-01 quando o hóspede acessar);
- **valor** (o cálculo da spec-07 já preenchido e editável, com o motivo da alteração);
- **política de cancelamento** (a do imóvel, já preenchida).
Antes de confirmar, aparece a **comissão do canal manual**: "Comissão casalivre (canal manual): R$ X (Y%)" *[E] [pendente: Rafael]*. Ao confirmar, a reserva é criada como uma **solicitação já aprovada** e fica em **"Aceite pendente"**: o hóspede recebe um **link com OTP** para aceitar os termos e o contrato (com o Anexo I, inventário). Depois do aceite, segue para Aguardando pagamento (spec-01). O anfitrião vê o estado "Aceite pendente" no painel Hoje, no calendário e no detalhe, com **"Reenviar link"** (o novo link invalida o anterior; limite de reenvios e log; *confirmado pelo Produto*) e **grava quem a lançou** ("Lançada por Ana (gestora) em 16/10, 10:12").
**Critérios de aceite:** (1) toda reserva tem uma origem, visível como rótulo; (2) a reserva manual mostra a comissão antes de confirmar; (2a) a reserva manual fica em "Aceite pendente" até o hóspede aceitar pelo link com OTP, e "Reenviar link" gera um novo link e invalida o anterior; (3) o autor e a data ficam gravados e no log; (4) as datas lançadas são bloqueadas como as de qualquer reserva, e o lançamento é recusado se houver conflito; (5) só quem tem papel no imóvel com permissão de reservas consegue lançar (o servidor responde 403 aos outros).

## 3c. Meus documentos (anfitrião) *(Produto, requisito de tela)*
No menu do modo Gerenciar: tabela com **termo** (contrato do anfitrião, termos de uso, política de privacidade etc.), **versão**, **data e hora do aceite** e **"Baixar PDF"**. O PDF reproduz a versão aceita, não a atual. Estado vazio: "Nenhum termo aceito ainda."

## 3d. Caução e danos (anfitrião) *(Produto, requisito de tela)*
- Depois do check-out, a reserva com caução mostra o card **"Registrar dano · até [check-out + 48 h]"** no painel Hoje e no detalhe.
- **Registrar dano:** itens do inventário (Anexo I) ou texto livre, **fotos obrigatórias** (upload com as regras da spec-09 §6), valor pedido.
- Sem registro em 48 h, a caução fica **"Devolver caução"** para o anfitrião, e o hóspede é avisado (spec-09).
- Com registro, o hóspede vê e pode aceitar ou contestar; a contestação abre disputa (spec-09 §6).
**Critérios de aceite:** (1) depois de 48 h do check-out o botão "Registrar dano" some e o servidor recusa o registro; (2) um registro sem foto não é salvo; (3) o hóspede vê o registro e as fotos.

## 4. WCAG 2.2 AA
- Calendário mensal no padrão **Grid da APG** (`role="grid"`, com setas, Home/End, PageUp/PageDown e `aria-selected`). No multicalendário, cada célula é rotulada com o imóvel, a data e o status (1.3.1, 2.1.1).
- **Contadores de prazo:** atualizados na tela sem aria-live a cada minuto. **Só o aviso de 1 h e o de expiração** são anunciados (role=status), e há sempre um texto absoluto (4.1.3, 2.2.1).
- **Foco:** ao abrir o detalhe, o foco vai para o título. Depois de aprovar ou recusar, vai para o próximo item da fila. Fechar o painel devolve o foco à seleção. O foco não fica coberto pelo rodapé fixo (2.4.3, 2.4.11).
- Status com padrão e texto, não só cor. Contraste de 4,5:1 para texto e 3:1 para padrões (1.4.1, 1.4.11).
- Alternativa ao arrastar na seleção do calendário (2.5.7). Alvos de no mínimo 24 px, inclusive as células no mobile (2.5.8).
- Ações destrutivas ou de decisão pedem confirmação (3.3.4).
- Valores em "R$ 1.234,56", lidos corretamente pelo leitor de tela.

## Requisitos de Segurança/LGPD (parecer de 16/10/2026)
Fonte: `/workspace/casalivre-seguranca-evidence/specs-ui-04-06/relatorio.md`. O texto técnico de cada achado foi **transcrito sem alteração**. O mecanismo é com Arquitetura.
**Decisões de tela (Produto):** (a) O **cartão do painel mostra só o status** da lista (Enviada, Pendente, Atrasada), sem nenhum dado de documento. A lista abre só na tela da reserva (06-5). (b) **A lista é vista pelo proprietário e pelos delegados de check-in** do imóvel. **Os admins** só a veem **com motivo e log** (06-2). (c) A **portaria recebe só nome e documento, sem foto**, por um **link autenticado de validade curta**, e nunca uma cópia por e-mail ou WhatsApp (06-2, 06-4). (d) A **finalidade aparece no checkout** (spec-01), e só os campos que a portaria exige são coletados (06-3). (e) **Retenção** *[decidido, parecer J21]*: a lista fica guardada **até 90 dias depois do check-out (7 dias se a reserva for cancelada)**. Depois dos primeiros 7 dias, o acesso fica restrito, com motivo e log (ver "Refinamento"). O prazo **se estende enquanto houver um incidente aberto**, que fica registrado na reserva com o motivo. Depois disso, ela é purgada. Isso substitui a proposta de check-out + 7 dias. Depois da purga, a tela mostra "Lista enviada em [data], N pessoas" (06-1).

- **06-1** (**Alta**; no parecer: spec-06:14, :93): **Sem retenção definida.** Proposta: os documentos da portaria (nome, documento, às vezes a foto do documento) ficam disponíveis do envio até o **check-out + 7 dias** (prazo a validar com Negócios e jurídico, para cobrir incidentes) e depois são **purgados automaticamente** (arquivo e colunas), mantendo só "lista enviada em [data], N pessoas". Reserva cancelada: purga em até 7 dias. Cron diário com log da purga.
- **06-2** (**Alta**; no parecer: spec-06:5, :14, :23): **Quem acessa: "papel no imóvel" é amplo demais.** Proposta, pelo mínimo necessário: o proprietário e o gestor/operador com a delegação de check-in do imóvel veem a lista. **Os admins (admin_plataforma/admin_org) não veem os documentos por padrão** (eles "só visualizam" o painel, spec-06:5). Abrir para eles só com um motivo (suporte/incidente) e log. A **portaria do condomínio** (terceiro) recebe só os campos necessários (nome e documento), nunca a foto, e com a base legal informada ao hóspede.
- **06-3** (Média; no parecer: spec-06:14, :38): **Finalidade:** a finalidade (controle de acesso ao condomínio, segurança) e a base legal precisam aparecer no momento da coleta, no checkout (spec-01), e ser informadas ao hóspede, incluindo os **menores** (art. 14: melhor interesse; dado de criança, o consentimento do responsável pode ser necessário; validar com jurídico). Coletar só os campos que a portaria pede. Foto do documento só se for exigida.
- **06-4** (Média; no parecer: spec-06:14, :92): Armazenamento: as mesmas regras do 05-1 (fora do webroot, servido por controlador autenticado, no-store, nosniff, cifrado se houver imagem de documento) e exclusão no fluxo de conta (hóspede excluído purga as listas futuras). Sem cópia em e-mail/WhatsApp (D3): só enviar um link autenticado e com validade curta.
- **06-5** (Baixa; no parecer: spec-06:14, :45): O cartão do painel mostra só o status (Enviada/Pendente/Atrasada), sem dados do documento. O documento abre só na tela da reserva, com o log já previsto (spec-06:45).
- **06-6** (Info; no parecer: spec-06:36, :61): O detalhe da solicitação sem contato do hóspede (spec-06:36, :53) e a recusa com mensagem neutra (spec-06:43, :51) estão corretos. A "verificação de identidade" do hóspede (spec-06:36) mostra só o selo, nunca o documento. O calendário mostra o primeiro nome do hóspede ("Reservado · Ana", spec-06:61), o que é aceitável para quem tem papel no imóvel.

**Critérios de aceite de Segurança**
1. (06-1) Depois de 90 dias do check-out (sem incidente aberto), a reserva mostra só "Lista enviada em [data], N pessoas", sem nomes nem documentos.
2. (06-2) Um operador sem a delegação de check-in e um admin sem motivo não abrem a lista. O admin com motivo abre, e isso fica no log.
3. (06-3) O formulário do hóspede mostra a finalidade e pede só os campos que o imóvel marcou como exigidos pela portaria.
4. (06-4) O link para a portaria exige autenticação, expira e não contém foto. Nenhum aviso por e-mail ou WhatsApp leva os dados.
5. (06-5) O cartão do painel não mostra nenhum dado de documento.
6. (06-6) O detalhe da solicitação mostra só o selo de verificação, nunca o documento.

**Questões jurídicas** (decididas no [`parecer-J1-J35.md`](../juridico/parecer-J1-J35.md)):
- [decidido, parecer J9] **J9**: A base legal para coletar e repassar os documentos dos hóspedes à portaria (legítimo interesse ou execução de contrato? regra do condomínio?) e o papel do condomínio (controlador independente?). → **Decisão:** base art. 7º V (**execução do contrato**); o condomínio é controlador independente; só os campos exigidos; repasse por canal controlado.
- [decidido, parecer J10] **J10**: O prazo de guarda após o check-out e se há obrigação de registro de hóspedes (ex.: ficha de hóspede da Lei 11.771/2008, que se aplica a meios de hospedagem; confirmar se vale para locação por temporada, Lei 8.245 art. 48). **Validar com jurídico.** → **Decisão:** a **ficha de hóspede (FNRH) não se aplica** à locação por temporada; conferir as normas municipais.
- [decidido, parecer J11] **J11**: Dados de menores (art. 14 da LGPD; ECA). → **Decisão:** dados de menores só quando a portaria exigir, sem consentimento específico; **declaração do adulto responsável** (ECA arts. 82 e 83).

## Refinamento de Segurança: prazo da portaria (16/10)
Fonte: relatório de Segurança (`/workspace/casalivre-seguranca-evidence/specs-ui-04-06/relatorio.md`), seção "Prazos do Negócios". O texto técnico foi transcrito sem alteração.
**Decisão de tela** *[decidido, parecer J9, J10, J21]*: base legal **execução do contrato**, e a **ficha de hóspede (FNRH) não se aplica**. a lista fica guardada **até 90 dias depois do check-out**, ou **7 dias se a reserva for cancelada**. **Depois dos primeiros 7 dias** após o check-out, o acesso fica **restrito** ao proprietário e ao admin_plataforma, **com motivo obrigatório e log**: "Acesso restrito: informe o motivo (incidente, dano, reclamação)". O hóspede é informado do prazo na coleta (spec-01).
**Critérios de aceite:** (1) no D+8 depois do check-out, o operador e o gestor não abrem mais a lista; (2) o proprietário abre só com motivo, e isso fica no log; (3) uma reserva cancelada purga em 7 dias; (4) aos 90 dias, a lista é purgada (com a extensão por incidente aberto, a validar).

- **P2-1** (Média): 90 dias é maior que o necessário para a finalidade (acesso ao condomínio). Justificar (incidentes, danos, prazo de reclamação?) e definir o marco: **90 dias depois do check-out** (reserva cancelada: 7 dias). Validar com o jurídico (J10).
- **P2-2** (Média): **Controles:** depois do check-out + 7 dias, o acesso sai do operador e do gestor e fica só com o proprietário e o admin_plataforma, com motivo e log (o resto do prazo é só para incidente). Foto do documento cifrada e fora do webroot. Purga automática aos 90 dias (arquivos e colunas), cron idempotente, log com a contagem. O hóspede é informado do prazo na coleta.

- [decidido, parecer J21] **J21**: Os 90 dias da portaria (finalidade e proporcionalidade). → **Decisão:** 90 dias é aceitável; reserva cancelada, 7 dias; incidente aberto, legal hold.

## Pendências para Produto
Os itens pendentes não bloqueiam o restante da spec.
1. [pendente: Rafael] Valor da comissão do canal manual [E].
2. [pendente: Rafael] D3, o provedor de WhatsApp, SMS e e-mail. Bloqueia a S7 (OTP), os avisos de nova solicitação e os lembretes da portaria. Até lá, tudo aparece só no painel.
3. [pendente: Jurídico e Tributário] Confirmar a taxa de turismo de Búzios (R$ 14,60 por hóspede, 20/12 ao Carnaval + 5 dias) para ativar a regra (spec-04 2b).
