# CasaLivre: Spec 07, avaliações e preço (v1, 16/10/2026, alvo S8 (P3 e P4), meta WCAG 2.2 AA)

Base: `sprints.md` (S8: P3 1 a 4, P4 1 a 5, P9), `backlog.md` P3 e P4, `adr/001-busca-sql-paginada.md` (colunas `rating_sum`/`rating_count` em `properties`, nota `score`, filtro `score_min` com 1 casa decimal; **escala de 1 a 5 confirmada pelo Produto: notas inteiras (geral e por critério); a média é exibida com 1 casa decimal**; preço `nightly_price` + `calendar_days.price` via `stay_quote`; `preco_total` como valor principal e `preco_noite` como secundário) e as specs 01 a 06. A spec usa só tokens de tema.
**Casos de uso:** UC-H09, UC-H13, UC-H25, UC-P12, UC-P13, UC-P16, UC-P19, UC-P26, UC-P27 e UC-C12.

# Parte A: Avaliações (P3)

## A1. Convite e formulário
**Objetivo:** avaliações reais, só de estadias concluídas, sem uma avaliação influenciar a outra.
**Convite:** depois do check-out, a reserva fica `concluida`. Em Viagens aparece o **selo "Avaliar"** e o cartão "Como foi sua estadia em [imóvel]? Você tem até [dd/mm] (14 dias)" *(aprovado)*. O anfitrião vê a tarefa no painel Hoje (spec-06). O **gancho de e-mail e WhatsApp** fica pendente da D3.
**Duplo-cego** *(aprovado)*: o hóspede avalia o imóvel e o anfitrião, e o anfitrião avalia o hóspede. **As duas avaliações aparecem só quando as duas forem enviadas ou quando a janela de 14 dias fechar.** Até lá, nenhum lado vê a do outro ("Sua avaliação será publicada quando [nome] avaliar ou em [data]").
**Notas** *(Negócios)*:
- **Critérios:** limpeza, fidelidade ao anúncio, localização, comunicação, check-in, custo-benefício e **atendimento local**. O atendimento local alimenta a métrica de confiabilidade do franqueado ou do parceiro.
- Os critérios são **configuráveis por canal e por organização** e ficam numa **tabela de notas por critério**.
- A **nota geral** (o `score` do ADR 001, de 1 a 5) e as colunas `rating_sum`/`rating_count` continuam como **resumo para a busca**. Ela é um inteiro calculado como a média arredondada dos critérios e mostrada antes do envio. Cada critério também recebe nota inteira de 1 a 5.
- **Avaliação do hóspede pelo anfitrião:** **não é pública**. Outros anfitriões a veem **só quando esse hóspede pede uma reserva com eles** (no detalhe da solicitação da spec-06). O hóspede vê as próprias avaliações e pode responder. A visibilidade vale **só enquanto o pedido está pendente**, e o agregado considera **24 meses** ([decidido, parecer J15]). A pedido, o hóspede recebe a lista dos anfitriões que viram as avaliações nos últimos 6 meses (J16). Um moderador com vínculo ao imóvel não decide (J14). Os controles estão na seção de Segurança (07-11 a 07-16).
**Texto:** de 30 a 1.000 caracteres *(aprovado)*, com contador. Contatos (telefone, e-mail, links) e dados pessoais são bloqueados com o erro inline "Remova contatos: eles não são permitidos nas avaliações." O conteúdo passa por escape contra XSS (revisão de Segurança).
**Critérios de aceite**
1. Sem uma reserva `concluida` do próprio usuário, não há botão de avaliar e o servidor responde 403.
2. Uma avaliação por reserva e por lado. A segunda tentativa recebe 409 e a mensagem "Você já avaliou esta estadia."
3. Depois de 14 dias, o formulário fecha ("O prazo para avaliar terminou").
4. Nenhum lado vê a outra avaliação antes de as duas serem enviadas ou de o prazo vencer, nem pela API.
5. A nota geral calculada é exibida antes do envio ("Nota geral: 4").
6. Um texto com contato é recusado inline, e o conteúdo é exibido escapado.
7. Teste E2E: concluir a estadia, avaliar e publicar quando o outro lado avalia.

## A2. Exibição
**No card da busca:** "★ 4,8 (23)", com o rótulo para leitor de tela "Nota 4,8 de 5, 23 avaliações". Com **menos de 3 avaliações** *(aprovado)*, aparece "Novo" no lugar da média. A média é `rating_sum/rating_count`, arredondada para 1 casa, igual ao filtro `score_min` do ADR.
**No detalhe:** a média e a contagem, as médias por critério (quando houver), a lista paginada ("Mostrar mais avaliações"), cada avaliação com o primeiro nome, o mês e o ano da estadia e o selo **"Estadia verificada"**, e a resposta do anfitrião embaixo.
**Resposta pública do anfitrião:** **uma** por avaliação, editável por **48 h** *(aprovado)* ("Editável até [data]"). Depois disso fica fixa. A resposta passa pelas mesmas regras de texto.
**Critérios de aceite:** (1) a média do card é igual à do detalhe e à do filtro `score_min`; (2) com menos do mínimo de avaliações, aparece "Novo" e não a média; (3) só uma resposta, e depois do prazo não há botão de editar.

## A3. Denúncia e moderação
**Denunciar** (UC-C12): o link "Denunciar" em cada avaliação e em cada resposta. Os motivos são: Conteúdo ofensivo, Dados pessoais ou contato, Não aconteceu / estadia falsa, Conflito de interesse, Outro (com texto). A mensagem é "Recebemos sua denúncia. Você será avisado do resultado."
**Estados:** Recebida → Em análise → **Mantida** / **Removida** / **Editada (trecho ocultado)**. Enquanto está em análise, a avaliação continua visível. Ela só é ocultada se o motivo for "Dados pessoais" *[E]*.
**Quem modera (spec-02):** o admin_plataforma decide. O admin_org pode fazer a triagem no próprio escopo. Com **conflito de interesse** (o moderador é parte da reserva ou ganha com ela), a decisão sobe um nível. Toda decisão fica no log, e **o resultado é avisado** a quem denunciou e a quem foi denunciado.
**Critérios de aceite:** (1) cada denúncia tem motivo, estado e histórico no log; (2) remover uma avaliação atualiza `rating_sum`/`rating_count` na mesma transação; (3) as duas partes recebem o aviso do resultado (no painel até a D3).

# Parte B: Preço (P4, extensão do modelo de períodos da S7)

## B1. Modelo e precedência
O **preço base é único** por imóvel (`nightly_price`). Os **períodos** da S7 (spec-06: início, fim, preço por noite e mínimo de noites) são o **único** modelo de exceção, e esta spec os **estende** sem criar um modelo novo:
- **Preço por dia da semana** dentro do período ou da base. Exemplo: sexta e sábado a R$ 550.
- **Exceção de 1 dia** é um período de 1 dia.
- **Descontos de estadia longa:** semanal (≥ 7 noites) e mensal (≥ 28 noites), em %.
**Ordem de cálculo (exibida no editor como texto fixo):**
1. **Diária base**, substituída pelo **período** que cobre a noite (com o preço do dia da semana, se houver). Com períodos sobrepostos, **vale o mais curto** *(Produto)*. **Na versão mínima da S7, não é permitido salvar períodos sobrepostos do mesmo nível**, e aparece um aviso.
2. **Descontos** (semanal ou mensal) sobre a soma das diárias. **Só o maior desconto é aplicado** *(Negócios)*. Fica como porta futura uma opção "acumulável" por desconto, desligada por padrão.
3. **Acréscimo do canal** (−20% a +30%, só leitura) sobre as diárias com desconto.
4. **Limpeza** (fixa, "paga ao prestador") e **taxa de serviço** (0% por padrão, por canal e organização; a base é definida na spec-05).
**Arredondamento:** cada linha em centavos. Na divisão, o centavo que sobra vai para o proprietário (spec-05).
**Onde se edita:** a base, os períodos, o preço por dia da semana, os descontos e a limpeza são editados pelo proprietário e pelo gestor (com a permissão). O acréscimo do canal é editado só nos canais (spec-04). A taxa de serviço, só no nó ou no canal (spec-04/05). Na tela de preço, esses dois aparecem **só para leitura**, com o link "Configurado em [canal/nó]".

## B2. Calendário de preços e edição em lote
**Layout:** o calendário da spec-06, na **camada "Preço"** (o preço de cada dia na célula; dias de período com o nome e com padrão visual). No desktop, um painel lateral. No mobile, um painel que sobe da parte de baixo da tela.
**Editar em lote:** selecionar um intervalo (clicando, arrastando ou com **Shift + setas**), ou usar "Selecionar por regra" ("Todas as sextas e sábados de dezembro"). Depois vem "Criar período" ou "Definir preço do dia da semana", com uma prévia ("12 noites vão mudar: R$ 450 → R$ 550").
**Simulador:** uma estadia de exemplo, com as datas, e o resultado linha a linha no formato do checkout, mais a versão por canal.
**Aviso fixo:** **"Mudanças de preço valem só para reservas novas. Reservas existentes mantêm o preço do momento em que foram feitas."**
**Estados:** salvando ("Salvando 12 dias…" seguido de "Preço atualizado"); conflito de períodos (S7: "Este período sobrepõe 'Réveillon'. Ajuste as datas para salvar.", e o salvamento fica bloqueado; S8: o mais curto prevalece, com o aviso); desconto inválido ("O desconto mensal deve ser maior ou igual ao semanal") *[E]*; erro.
**Critérios de aceite**
1. A ordem de cálculo é a mesma em todo lugar: card, detalhe, checkout, snapshot e simulador.
2. A edição em lote funciona inteira pelo teclado e mostra a prévia antes de salvar.
3. Mudar o preço não altera o snapshot de nenhuma reserva existente (verificável na reserva).
4. O acréscimo do canal e a taxa ficam só para leitura nesta tela, com o link para onde são configurados.
5. Períodos sobrepostos geram um aviso, e o cálculo segue a regra exibida.

## B3. Composição do total (o que o hóspede vê)
**Card da busca:** **só o total** ("R$ 2.250 total · 5 noites"), com o preço por noite como secundário ("~R$ 400/noite") (ADR 001, T5). Sem datas, aparece "a partir de R$ 400/noite".
**Detalhe e checkout:**
- "Diárias (5 noites): R$ 2.150", que expande para mostrar cada noite com a variação ("Sex 20/12 · R$ 550 (fim de semana)").
- "Desconto semanal (−10%): −R$ 215".
- "Limpeza (paga ao prestador): R$ 250".
- "Taxa de serviço: R$ X", só quando for maior que 0%.
- **"Total: R$ 2.185"**.
O acréscimo do canal já vem embutido nas diárias, sem linha própria.
**Ordenar por preço total** *(Produto)*: a opção só aparece quando há **cidade definida** ou o **mapa está aproximado**. Fora disso, fica oculta. O mecanismo fora do keyset é com Arquitetura (ADR 001).
**Snapshot:** a reserva grava cada linha (por noite, desconto, acréscimo, limpeza, taxa, total). Na página da reserva, aparece "Preço garantido em [data da reserva]".
**Critérios de aceite**
1. A soma das linhas é igual ao total, que é igual ao do card, ao do snapshot e ao da API.
2. Sem datas, aparece "a partir de". Com datas, o total vem como valor principal.
3. A linha da taxa não aparece quando ela é 0%.
4. A lista expandida de noites é acessível (botão com `aria-expanded`, e a lista é lida como lista).

# WCAG 2.2 AA
- As estrelas sempre têm texto: "Nota 4,8 de 5" (1.1.1). O ícone é decorativo (`aria-hidden`).
- A **entrada de nota** é um **grupo de rádios** por critério ("Limpeza: 1 a 5", com os rótulos "1, Ruim" até "5, Excelente"), navegável pelas setas e com o `fieldset`/`legend` do critério (1.3.1, 4.1.2).
- Calendário de preços no padrão **Grid da APG**. As células são lidas como "20 de dezembro, R$ 550, período Réveillon" (1.3.1, 2.1.1).
- Alternativa ao arrastar na seleção (2.5.7). Alvos de no mínimo 24 px (2.5.8).
- Valores em "R$ 1.234,56", descontos com o sinal "−" e o texto "desconto" (1.4.1).
- Contadores de caracteres anunciados só perto do limite. Erros inline com texto (3.3.1).
- Prévia em lote e confirmação antes de salvar (3.3.4). As atualizações de total são anunciadas por role=status (4.1.3).

## Requisitos de Segurança/LGPD (parecer de 16/10/2026)
Fonte: `/workspace/casalivre-seguranca-evidence/specs-ui-04-06/relatorio.md`. O texto técnico de cada achado foi **transcrito sem alteração**. O mecanismo é com Arquitetura.
**Decisões de tela (Produto e Gerente):**
- (a) A **avaliação do hóspede** só é vista pela rota **`/pedidos/{id}/hospede/avaliacoes`**, ligada ao pedido. Sem esse vínculo, a resposta é **404** (07-11).
- (b) O acesso **some quando o pedido é recusado, expira ou é cancelado**, com a checagem feita a cada requisição. A resposta tem **`Cache-Control: no-store`** (07-12).
- (c) No detalhe da solicitação (spec-06), o anfitrião vê **só o agregado** (nota média, número de estadias, % "Recomendaria") e, no máximo, os textos das últimas N avaliações, **sem** o nome ou o imóvel de quem avaliou e só com mês e ano (07-13).
- (d) O **formulário de pedido** (spec-01, checkout) avisa: "Os anfitriões que receberem seu pedido verão suas avaliações de estadias anteriores." O hóspede vê as avaliações que recebeu e pode **responder** e **contestar** cada uma pela denúncia (07-14).
- (e) A conta excluída aparece como **"Usuário removido"**, sem foto nem link (07-4).
- (f) O texto é **puro**: links não ficam clicáveis (07-1).

- **07-1** (Média; no parecer: spec-07:16, :23, :29): "Escape contra XSS" sem definição. Exigir: texto **puro, sem HTML nem Markdown**, guardado como foi digitado (depois da normalização) e **escapado na saída** com `htmlspecialchars(ENT_QUOTES\|ENT_SUBSTITUTE, 'UTF-8')` em todo contexto (HTML, atributo, JSON da API com `JSON_HEX_TAG\|JSON_HEX_AMP`, e-mail/WhatsApp futuros). Nunca sanitizar na entrada como única defesa. Quebras de linha via `nl2br` **depois** do escape. Links não ficam clicáveis. A CSP atual (`csp.txt`, sem `unsafe-inline`) segue como segunda camada e não muda. Teste com payloads (`<script>`, `"><img onerror>`, `javascript:`) no card, no detalhe, no painel, no log de moderação e na API.
- **07-2** (Média; no parecer: spec-07:16, :33): **Normalização Unicode e limites.** Aplicar NFC, remover caracteres de controle e de formatação (Cc, e Cf exceto ZWJ dentro de emoji; bidi override U+202A–202E e U+2066–2069; zero-width), recusar UTF-8 inválido, colapsar espaços e quebras repetidas (ex.: no máximo 2 quebras seguidas). Contar o limite de 30 a 1.000 em **caracteres** (`mb_strlen` ou grafemas) **no servidor**, e limitar também os bytes (ex.: 4 KB) contra combinação abusiva (zalgo). A detecção de contato roda **depois** da normalização. O mesmo vale para o texto do motivo "Outro" da denúncia.
- **07-3** (Média; no parecer: spec-07:16, :34): **Dados pessoais no texto.** O bloqueio de contato (spec-07:16) precisa listar os padrões, aplicados no servidor sobre o texto normalizado: e-mail; telefone BR (com ou sem DDD/+55, incluindo dígitos separados por espaço ou ponto); CPF/CNPJ (com e sem máscara); URL/domínio; @usuário. Endereço e nome completo não dá para detectar com segurança: isso fica para a **denúncia "Dados pessoais"**, que já **oculta na hora** (spec-07:34, correto) e permite "Editada (trecho ocultado)". A recusa inline não deve ecoar o trecho detectado em logs. Falsos positivos: a mensagem genérica já cobre.
- **07-4** (Média; no parecer: spec-07:15, :28): **Anonimização da autoria na exclusão de conta** (já decidido) não está na spec. Incluir: com a conta purgada, a avaliação fica com o autor "Usuário removido", sem foto e sem link de perfil. Manter o mês e o ano. O texto fica, com remoção pelo fluxo de denúncia se tiver dado identificável. A avaliação **privada do anfitrião sobre o hóspede** (spec-07:15) é dado pessoal do hóspede: na exclusão do hóspede, desvincular ou apagar. O efeito na `rating_*` é preservar a nota (decisão de Negócios; para Arquitetura).
- **07-5** (Média; no parecer: spec-07:15, :95): **Avaliação privada do hóspede vista por outros anfitriões** (spec-07:15, pendência :95). É um perfil compartilhado do titular: exige transparência e direito de acesso e correção (art. 18 I/III), mostrar a nota ao próprio hóspede e uma retenção definida. Ver J12.
- **07-6** (Média; no parecer: spec-07:18-20, :33-36): **Abuso e rate limit.** Faltam: rate limit no envio e na edição de avaliações e respostas (por usuário e IP, na pasta de rate limit existente), rate limit de **denúncias** (ex.: 10 por dia por usuário, com uma denúncia por item e por usuário) para evitar ocultação em massa via "Dados pessoais". A ocultação imediata só vale para denúncias de contas verificadas, ou exige triagem em até X horas. A elegibilidade (reserva `concluida`, prazo de 14 dias, um por lado) é verificada **no servidor** com o vínculo usuário↔reserva (IDOR). Avaliação de reserva cancelada ou estornada: recusar.
- **07-7** (Baixa; no parecer: spec-07:11, :21): **Duplo-cego:** além da API, garantir que listagens, feeds, e-mail de aviso e a contagem `rating_count` não revelem a nota antes da publicação (a nota só entra na `rating_*` quando publicada).
- **07-8** (Baixa; no parecer: spec-07:29, :35): **Resposta do anfitrião:** editável por 48 h (spec-07:29). Guardar o histórico de versões (regra de auditoria: valor anterior) e checar a autoria no servidor (só o dono do imóvel ou quem tem a delegação). O moderador com conflito sobe um nível (spec-07:35, ok).
- **07-9** (Baixa; no parecer: spec-07:35-36): O log de moderação guarda o texto denunciado (valor anterior). Ele entra na mesma regra do S8-2: na purga da conta, anonimizar o autor, e o texto removido por "Dados pessoais" sai do log após a decisão (fica só o hash).
- **07-10** (Info; no parecer: spec-07:51, :55, :75): A Parte B (preço) não tem risco relevante: o cálculo fica no servidor (o snapshot e a API são a fonte) e o preço não pode vir do cliente no checkout. As permissões de edição (spec-07:51) precisam ser checadas no servidor (403). O "Selecionar por regra" faz parse de texto livre? Se sim, usar um parser estrito, nunca `eval` (para Arquitetura).
- **07-11** (**Alta**; no parecer: spec-07:15; spec-06:36): **Autorização no servidor por pedido ativo.** Só liberar quando existe um `booking_request` com `guest_id = avaliado`, um imóvel em que o solicitante tem papel (proprietário, gestor ou operador com a delegação de aprovar) e status **aguardando aprovação** (ou aprovado e ainda dentro do prazo de pagamento). A rota precisa ser **por pedido** (`/pedidos/{id}/hospede/avaliacoes`), nunca por `guest_id` ou `review_id`, para não haver enumeração/IDOR. Sem o vínculo, responder **404** (não 403, para não confirmar que existe). Nenhum endpoint público, de busca ou de perfil retorna essas avaliações. Teste: anfitrião B sem pedido → 404; IDs sequenciais → 404.
- **07-12** (**Alta**; no parecer: spec-06:33-46): **O acesso some quando o pedido é recusado, expira ou é cancelado.** A checagem acontece **a cada requisição** (nunca uma permissão gravada ou um token de longa duração). Resposta com `Cache-Control: private, no-store` e sem ETag compartilhado, e sem cache de página/CDN/microcache do nginx nessa rota. Dados fora do cache por requisição entre usuários. Depois de aprovado e concluído, decidir (Negócios + jurídico) se continua visível. Recomendo: só enquanto o pedido está pendente; depois disso a reserva já existe, sem necessidade.
- **07-13** (Média; no parecer: spec-07:15; spec-06:36): **Minimização.** O anfitrião vê só o agregado (nota média, nº de estadias, % "Recomendaria") e, no máximo, os textos das N últimas, **sem** o nome nem o imóvel do anfitrião que avaliou, sem datas exatas (só mês/ano), e sem a avaliação de um hóspede excluído. Nunca CPF, contato ou documento (spec-06:36 já mascara o contato). Nada disso aparece em exportações, no CSV do log nem em e-mail/WhatsApp (D3): o aviso diz só "nova solicitação", com um link autenticado.
- **07-14** (Média; no parecer: spec-07:15, :29, :33): **Transparência, contestação e resposta.** O hóspede precisa (a) ser avisado no aviso de privacidade e **no formulário de pedido** ("anfitriões que receberem seu pedido verão suas avaliações de estadias anteriores"); (b) ver as avaliações que recebeu (art. 18 I/II); (c) **responder** a cada uma (as mesmas regras de texto da A2) e **contestar** pela denúncia (motivos já existentes), com revisão humana; (d) se a nota bloquear ou filtrar automaticamente, aplica-se o art. 20 (revisão). Recomendo que não haja bloqueio automático.
- **07-15** (Média; no parecer: spec-06:45): **Log de cada visualização:** registrar quem viu (usuário, papel), o hóspede (id), o pedido, data/hora e IP. Isso é log de acesso: **6 meses pseudonimizados**. O hóspede pode pedir a lista de quem viu (art. 18 VII, informação sobre compartilhamento; validar com jurídico). Aceite: abrir o detalhe gera 1 evento, e um acesso negado (404) também é logado como tentativa.
- **07-16** (Baixa; no parecer: spec-07:36): **Retenção.** A avaliação do hóspede tem prazo (sugestão: as dos últimos 24 meses entram no agregado; validar com Negócios e jurídico). Na exclusão de conta do hóspede, todas são desvinculadas ou apagadas (07-4). Uma avaliação removida na moderação sai do agregado na mesma transação.

**Critérios de aceite de Segurança**
1. (07-1) Os payloads `<script>`, `"><img onerror>` e `javascript:` aparecem como texto no card, no detalhe, no painel, no log de moderação e na API. Os links não ficam clicáveis.
2. (07-2) Os limites de 30 a 1.000 caracteres são contados no servidor, depois da normalização. Caracteres de controle e bidi são removidos.
3. (07-3) Texto com e-mail, telefone BR, CPF/CNPJ, URL ou @usuário é recusado com a mensagem genérica, e o trecho não vai para o log.
4. (07-4) Depois da purga da conta, a avaliação mostra "Usuário removido", com o mês e o ano mantidos.
5. (07-5, 07-14) O hóspede vê, na conta dele, as avaliações que recebeu, e tem Responder e Denunciar/contestar.
6. (07-6) Um limite de envio, edição ou denúncia mostra "Você atingiu o limite. Tente de novo mais tarde." A avaliação de uma reserva cancelada ou estornada é recusada.
7. (07-7) Antes da publicação, nenhuma listagem, contagem ou aviso revela a nota.
8. (07-8) A resposta do anfitrião guarda o histórico de edições, visível na moderação.
9. (07-9) O texto removido por "Dados pessoais" não aparece mais no log de moderação depois da decisão.
10. (07-10) O preço vem sempre do servidor. Editar sem permissão recebe 403.
11. (07-11) O anfitrião B, sem pedido, recebe 404. IDs sequenciais também recebem 404.
12. (07-12) Depois de recusar, expirar ou cancelar o pedido, a rota responde 404, e a resposta tem `Cache-Control: private, no-store`.
13. (07-13) A tela não mostra o nome ou o imóvel de quem avaliou, nem datas exatas.
14. (07-15) Abrir o detalhe gera 1 evento de acesso, e uma tentativa negada (404) também fica no log.
15. (07-16) Uma avaliação removida na moderação sai do agregado na hora.

**Questões jurídicas** (decididas no `juridico/parecer-J1-J35.md`):
- [decidido, parecer J12] **J12**: Base legal e transparência para a avaliação privada do hóspede compartilhada entre anfitriões (art. 7º IX, legítimo interesse? art. 10 com o teste de balanceamento; art. 20, revisão de decisão automatizada se a nota bloquear reservas). **Validar com jurídico.** → **Decisão:** art. 7º IX com LIA e RIPD, restrito ao anfitrião com pedido pendente; transparência ao hóspede; oposição analisada; sem bloqueio automático (art. 20).
- [decidido, parecer J12] **J12 (atualizado)**: Legítimo interesse (art. 7º IX/art. 10) para o compartilhamento restrito ao pedido: teste de balanceamento (LIA), registro em RIPD, redação do aviso ao hóspede e se o titular pode se opor (art. 18 §2º). **Validar com jurídico.** → **Decisão:** art. 7º IX com LIA e RIPD, restrito ao anfitrião com pedido pendente; transparência ao hóspede; oposição analisada; sem bloqueio automático (art. 20).
- [decidido, parecer J13] **J13**: Manter o texto da avaliação após a exclusão de conta (com a autoria anonimizada): confirmar que basta frente ao art. 18 VI, e qual a responsabilidade por conteúdo de terceiro (Marco Civil art. 19/21 para remoção de conteúdo). **Validar com jurídico.** → **Decisão:** manter o texto com "Usuário removido" basta; remoção por ordem judicial (MCI art. 19) ou por notificação, nos casos do art. 21.
- [decidido, parecer J14] **J14**: Regras de avaliações verdadeiras (CDC, prática enganosa) e a remoção de avaliações negativas pelo moderador com conflito. **Validar com jurídico.** → **Decisão:** é proibido remover uma avaliação negativa verdadeira; a remoção segue uma política escrita; **um moderador com vínculo ao imóvel não decide**.
- [decidido, parecer J15] **J15**: Prazo de visibilidade depois do pedido (só enquanto pendente ou até o check-out?) e o prazo de retenção das avaliações do hóspede. → **Decisão:** visível **só enquanto o pedido está pendente**; agregado por **24 meses**.
- [decidido, parecer J16] **J16**: Se o hóspede tem direito a saber quais anfitriões viram as avaliações dele (art. 18 VII; art. 9º). → **Decisão:** informar a categoria e, a pedido, a lista dos anfitriões que acessaram nos **últimos 6 meses**.

## Pendências para Produto
Os itens pendentes não bloqueiam o restante da spec.
1. [pendente: Negócios] Quantos textos (N) o anfitrião vê no agregado (07-13). A visibilidade só enquanto o pedido está pendente e o agregado de 24 meses foram decididos no J15.
2. [pendente: Arquitetura e Banco de Dados] Tabela de notas por critério e configuração de critérios por canal e organização.
3. [pendente: Rafael] D3: convites e avisos de moderação. O aviso diz só "nova solicitação", com um link autenticado (07-13).
