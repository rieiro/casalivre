# CasaLivre: Spec 01, da busca ao checkout (v1.1, 16/10/2026, com os ajustes do Negócios, alvo S7/S8, meta WCAG 2.2 AA)

Base: `docs/produto/planejamento/roadmap.md` e `docs/produto/planejamento/sprints.md` (v0.2, não aprovados), `spec-telas-busca-mapa-upload-fotos.md` (v1, que esta spec complementa sem substituir) e `casos-de-uso.md`.
Fluxo: **Busca → Resultados (lista + mapa) → Detalhe → Checkout → Confirmação**.

## 0. Regras gerais
- **Tema por canal:** o mesmo imóvel aparece em vários canais e domínios, e o logo, as cores e a tipografia vêm do canal. A spec usa só tokens (`color.primary`, `color.on-primary`, `color.surface`, `color.danger`, `color.success`, `color.warning`, `radius.*`, `space.*`, `font.*`). Nenhuma tela tem cor fixa. O contraste AA do tema é **validado ao salvar no admin de canais**, e o tema não salva se reprovar (critério da S5).
- **Preço:** o card mostra só o **total do período**. O detalhe e o checkout mostram a composição: diárias (N × R$), limpeza e taxa de serviço. A limpeza aparece como a linha **"Limpeza (paga ao prestador)"**, entra no total e tem tooltip explicando que é paga ao prestador. A taxa de serviço começa em **0%** e é configurada por canal **e** por organização. Com 0%, a linha da taxa **fica oculta**. Os valores vêm do snapshot da reserva, em centavos inteiros.
- **Sem pagamento online (piloto):** o pagamento é feito por fora, direto ao anfitrião, depois da aprovação, com prazo de 24 h (ver seção 5). Nenhuma tela tem campo de pagamento ou de cartão.
- **Estados da reserva:** Aguardando aprovação → Aguardando pagamento → Confirmada. Os estados finais alternativos são Recusada, Expirada (sem resposta ou sem pagamento) e Cancelada.
- **Dependência D3:** o OTP e os avisos precisam de um provedor de WhatsApp, SMS e e-mail, que ainda está pendente.
- **Prazo de pagamento:** a mesma regra da aprovação, 24 h, ou 4 h se o check-in for em menos de 72 h. As datas ficam bloqueadas durante o prazo e são liberadas se ele expirar. *(provisório, revisão Negócios)*
- **Abas:** Explorar, Favoritos, Viagens, Mensagens e Perfil. Favoritos e Mensagens ficam com estado vazio até a S9. Quando houver Mensagens, o contato fica mascarado.
- **Métricas (P1):** busca, ver imóvel, início do checkout e envio, com o canal de origem e sem dado pessoal.

## 1. Busca (home): UC-H01, UC-H02, UC-H06
**Objetivo:** começar uma busca em até 3 passos: destino, datas e hóspedes.
**Layout:** no desktop, uma pílula de busca no hero com 3 segmentos e o botão Buscar. No mobile, um campo "Para onde?" abre a busca em tela cheia, em etapas, com Voltar e Limpar.
**Componentes:** autocomplete de destino, calendário de 2 meses (1 no mobile) com "Datas flexíveis", contador de hóspedes, com adultos (12+), crianças (3 a 11) e bebês (0 a 2, que por padrão não contam na capacidade), mais os pets. Se houver menores, aparece o aviso fixo "Menores só com os pais ou o responsável, ou com autorização (ECA, arts. 82 e 83)", e o checkbox de **declaração do adulto responsável** "Declaro ser maior de 18 anos e responsável pelos menores, que estarão acompanhados ou autorizados na forma do ECA" ([decidido, parecer J11]), buscas recentes e categorias.
**Destination field:** follows `spec-busca-localidade-resolvida.md` (UX items 1–5, 9–10): APG combobox, labels with type and parent place, "Did you mean", a no-match state with suggestions and "Search on the map". On an autocomplete network error the field keeps accepting text, and Enter goes through server resolution; a raw-text search never runs.
**Microcopy:** "Para onde?", "Quando?", "Quem vai?", "Buscar".
**Critérios de aceite**
1. Dado que estou na home, quando ela carrega, então a busca fica visível sem rolar, em 360 px e em 1280 px.
2. Dado que escolhi o check-in, quando escolho o check-out, então o número de noites aparece e datas anteriores ao check-in não podem ser escolhidas.
3. Dado que busco sem datas, quando vejo os resultados, então os cards mostram "Escolha as datas para ver o total" no lugar do preço.
4. A busca funciona inteira pelo teclado, e o calendário segue o padrão de grid da APG (setas, PageUp/PageDown, Esc).

## 2. Resultados (lista + mapa): UC-H03, UC-H04, UC-H05
**Objetivo:** comparar pelo preço total e pela localização. O layout, os pins, os clusters, o popup, a atribuição OSM e os estados do mapa seguem a spec v1, seção 1.
**Novidades por causa da S4 (paginação por cursor):**
- Ordenação: **Relevância** (padrão), **Menor preço total** e **Melhor avaliação**. "Menor preço total" is a performance guard only, and the server decides: it runs with ≤ 5,000 candidates, otherwise the response has `sort_unavailable: "zoom_in"` and the list falls back to Relevance with the announcement and the inline text "Zoom in to sort by total price". As a client hint, the option is disabled with that text below zoom 13 (desktop) or 12 (mobile) unless a city or neighborhood is fixed (`spec-busca-localidade-resolvida.md`, UX item 8; spec-07).
- A lista carrega **20 itens por vez**, com o botão **"Mostrar mais imóveis"** no fim. Não há número de página nem rolagem infinita, e o rodapé continua acessível.
- As contagens são **aproximadas**: "Mais de 120 imóveis", "~40 com piscina".
- O mapa consulta pela área visível (bbox), separado da lista. Aviso quando são diferentes: "O mapa mostra os imóveis desta área".
- Ao voltar do detalhe, a lista restaura os itens já carregados e a posição de rolagem (o cursor fica guardado na URL ou no estado).
**Card:** "R$ 2.250 total · 5 noites", com o selo "Reserva instantânea" ou "Sujeita a aprovação".
**Estados**
- Carregando mais: o botão vira "Carregando…" e fica desabilitado.
- Fim da lista: "Você viu todos os imóveis desta busca".
- Erro ao carregar mais: "Não foi possível carregar mais. Tentar novamente", sem perder os itens já exibidos.
- Vazio: Limpar filtros, Ampliar área e Datas flexíveis.
**Critérios de aceite**
1. Dado que há mais resultados, quando toco em "Mostrar mais imóveis", então os novos cards entram depois dos atuais, o foco vai para o 1º card novo e o aria-live anuncia "20 imóveis adicionados".
2. Dado que abri um imóvel e voltei, então vejo a mesma lista e a mesma posição.
3. Dado que há datas, então todo card mostra o total com limpeza e taxa incluídas, e esse valor é igual ao do detalhe.
4. Nenhuma contagem é apresentada como exata ("Mais de", "~").
5. Mover o mapa não reinicia a lista, a não ser que o usuário peça "Buscar nesta área" ou a opção "Buscar ao mover o mapa" esteja ligada (ON by default on desktop, OFF on mobile). Then the field shows "Map area" with × to return to the last place, and the URL has `bbox` instead of `local_id` (`spec-busca-localidade-resolvida.md`, UX items 6–7).
7. Below the hint zoom, "Menor preço total" is visible but disabled with "Zoom in to sort by total price". On `sort_unavailable: "zoom_in"` (even for a city), the sort falls back to Relevance, announced in a polite live region and shown next to the sort control.
6. O selo do tipo de reserva tem texto e não depende só de cor.

## 3. Detalhe do imóvel: UC-H07, UC-H08, UC-H09, UC-H13
**Objetivo:** decidir com segurança, sabendo o total, as regras e o tipo de aprovação.
**Layout:** no desktop, uma grade de fotos e, à direita, o **card de reserva fixo**. No mobile, um carrossel de fotos e uma **barra inferior fixa** com o total e o botão "Reservar" ou "Solicitar reserva", e as abas ficam escondidas.
**Ordem do conteúdo:** título, nota, resumo (hóspedes, quartos, banheiros), descrição, comodidades, calendário, **bairro e mapa aproximado com um círculo de ~500 m** (sem pin exato; o endereço só é liberado em Confirmada), regras, cancelamento e avaliações.
**Card de reserva:** datas, hóspedes, **composição**: "Diárias: 5 × R$ 400 = R$ 2.000", "Limpeza (paga ao prestador): R$ 250" (tooltip), "Taxa de serviço: R$ X" (oculta quando é 0%), **"Total: R$ 2.250"**. O "i" de cada linha explica o item. O botão diz "Reservar" (instantânea) ou "Solicitar reserva" (manual), com a frase "Você ainda não paga nada agora".
**Estados:** skeleton; datas indisponíveis ("Essas datas não estão livres. Veja outras no calendário."); mínimo de noites ("Mínimo de 3 noites"); preço indisponível; imóvel fora do ar ("Este anúncio não está mais disponível", com link para voltar aos resultados).
**Critérios de aceite**
1. Dado que há datas válidas, então a soma das linhas é igual ao total, que é igual ao valor do card.
2. Dado que mudo as datas ou os hóspedes, então a composição é recalculada e o aria-live anuncia o novo total.
3. Dado um imóvel com aprovação manual, então o botão diz "Solicitar reserva" e a página avisa "O anfitrião responde em até 24 h" (ou "em até 4 h" quando o check-in é em menos de 72 h; o prazo vem da configuração).
4. As regras e a política de cancelamento ficam visíveis antes do botão de reserva.
5. Antes de Confirmada, o detalhe mostra só o bairro e o círculo de ~500 m. O endereço, a rua e o pin exato não aparecem no HTML, na API nem no texto alternativo do mapa.
6. Com a taxa de serviço em 0%, a linha não aparece e o total não muda.
7. No mobile, a barra fixa não cobre conteúdo focado (WCAG 2.4.11).

## 4. Checkout (revisão e solicitação): UC-H13, UC-H14, UC-H15, UC-C01, UC-C02, UC-C03
**Objetivo:** confirmar dados, CPF e termos, e enviar sem surpresa de preço.
**Layout:** página única linear. No desktop, formulário à esquerda e resumo fixo à direita (foto, datas, hóspedes, composição e total). No mobile, o resumo fica recolhível no topo ("Total R$ 2.250 ▾") e o botão de envio fica no rodapé. As abas ficam escondidas.
**Sem login antes do checkout.** **Etapas na página:** (1) **identificação obrigatória** (detalhada abaixo); (2) dados do hóspede: nome e **CPF**; (3) campo opcional **"Mensagem ao anfitrião"** (entra na S7; até 500 caracteres; contatos mascarados); (4) regras da casa e **política de cancelamento com as datas calculadas para esta reserva** ("Cancelamento com 100% de reembolso até 06/12, 23:59 · 50% até 13/12 · depois disso, sem reembolso"; mais **100% em até 7 dias a partir da confirmação da reserva, desde que antes do check-in** (CDC art. 49; [decidido, parecer J35]), e a devolução da limpeza não usada). Se o imóvel tiver **regras do condomínio**, aparece um checkbox próprio com link para o anexo ("Li e aceito as regras do condomínio"), não marcado de início; (5) **3 termos**; (6) como será o pagamento; (7) enviar.
**Identificação (depende da D3):**
- Campos: e-mail, celular (+55, máscara `(00) 00000-0000`) e CPF. Depois vem "Enviar código".
- O OTP de 6 dígitos vai pelo **WhatsApp**. Se não chegar em 30 a 60 s, aparece "Enviar por SMS" e, depois, "Enviar por e-mail" *(provisório, revisão Negócios; proposta para a D3)*. O campo tem `autocomplete="one-time-code"`, aceita colar e tem "Reenviar em 0:30" (o contador só é anunciado ao chegar a 0) e "Trocar e-mail ou celular".
- Com o código válido, a **conta é criada por trás**, sem senha, e o hóspede vê "Pronto, identificamos você".
- Se o e-mail, o celular ou o CPF já tiverem conta: "Você já tem conta. Entre para continuar", com login **na mesma página** (senha **ou** código, à escolha do usuário). Uma identificação nova é sempre por código. Os dados do checkout são preservados. *(provisório, revisão Negócios)*
- **E-mail já usado ou de conta em exclusão (spec-02, seção 0b):** a tela responde sempre **"Se houver uma conta, enviamos um código."**, igual para qualquer e-mail (C-1). Depois do OTP válido (10 min, com rate limit), quem tem conta ativa entra, e quem tem conta em carência vê **"Reativar conta"**. O "excluir definitivamente agora" **não existe no checkout**: ele fica só na conta autenticada (C-5).
- Erros: "Código incorreto. Faltam 2 tentativas.", "Código expirado. Envie outro." e limite de tentativas ("Tente de novo em X min").
- Sem a D3 (sem provedor): o OTP não funciona e o checkout não pode ir para produção. Em homologação, o código é simulado.

**CPF:** máscara `000.000.000-00`, `inputmode="numeric"` e validação dos dígitos verificadores ao sair do campo e ao enviar. Erro inline: "CPF inválido. Confira os números." com `aria-describedby`. Conta com CPF já validado mostra o CPF mascarado (`***.456.789-**`) e o link Alterar.
**Aviso de avaliações (spec-07, 07-14):** "Os anfitriões que receberem seu pedido verão suas avaliações de estadias anteriores."
**Termos:** 3 checkboxes independentes, **nenhum marcado de início**: "Li e aceito o **Contrato de locação por temporada**", "…o **Termo de serviço de limpeza**" e "…os **Termos de uso da plataforma**" (versões geridas na spec-05). Cada link abre o texto num **modal** (foco preso, Esc fecha, o foco volta ao link) sem perder os dados. A reserva grava a **versão aceita** de cada termo. Até a D14, o modal mostra o texto provisório "[Texto do termo, versão a definir na D14]".
**Pagamento:** um bloco informativo diz "Nenhum pagamento é feito agora. Depois da aprovação, você terá 24 h (ou 4 h, se o check-in estiver próximo) para pagar." Mostra também o aviso em destaque "Pagamento direto ao anfitrião, sem a proteção casalivre" *[decidido, parecer item 17]*, com o texto do parecer: "No piloto, o pagamento é feito diretamente ao anfitrião, pela chave exibida na reserva. A casalivre não recebe nem guarda esse valor e não reembolsa pagamentos feitos fora da chave exibida. A casalivre verifica a identidade do anfitrião e mantém canal de denúncia." Ao lado, o link visível **"Denunciar anúncio ou pedido de pagamento suspeito"** (canal de denúncia, spec-10 2.2b). (ícone e texto, com tokens de alerta).
**Botão:** "Reservar" (instantânea) ou "Enviar solicitação" (manual).
**Estados:** enviando (botão desabilitado com "Enviando…", impedindo envio duplo); erro de validação (resumo no topo com links para cada campo e foco no primeiro); **preço mudou** ("O valor mudou de R$ X para R$ Y. Revise antes de continuar."); datas ficaram indisponíveis (volta ao detalhe com aviso); erro de rede ("Não conseguimos enviar. Seus dados foram mantidos. Tentar novamente").
**Critérios de aceite**
1. Dado um CPF com dígito verificador errado, quando saio do campo, então aparece o erro inline e o envio fica impedido.
2. Dado que algum dos 3 termos não está marcado, quando envio, então o erro aponta o termo pendente e nada é enviado.
3. Os 3 checkboxes vêm desmarcados. Cada link abre o modal do termo sem apagar o formulário, e a versão aceita fica gravada na reserva.
4. Dado que não estou logado, quando abro o checkout, então não sou desviado para o login: a identificação está dentro da página.
4a. Dado que informo e-mail, celular e CPF válidos, quando confirmo o OTP correto, então a conta é criada e sigo para os termos sem recarregar a página.
4b. Dado que o e-mail, o celular ou o CPF já têm conta, então me oferecem entrar ali mesmo, e datas, hóspedes e campos ficam preservados.
4c. Não dá para enviar a reserva sem o OTP confirmado.
5. O total do checkout é igual ao do detalhe. Se mudar, o usuário precisa ver o novo valor antes de enviar.
6. Não existe campo de pagamento nem menção a cobrança imediata.
7. Clique duplo no botão gera uma única solicitação.

## 5. Confirmação e página da reserva: UC-H14, UC-H15, UC-H17, UC-H18, UC-H19, UC-C10
**Objetivo:** deixar claro o status, o prazo e o próximo passo. A tela de confirmação e a página da reserva (em Viagens) usam os mesmos blocos. O título é o primeiro foco e a região de status tem role=status.
**Estados**
- **Aguardando aprovação** (manual): "Solicitação enviada. O anfitrião tem até [dd/mm, hh:mm] para responder." Prazo padrão de **24 h**, configurável, que cai para **4 h** se o check-in for em menos de 72 h. Mostra o **tempo restante em texto**: "Faltam 18 h 20 min". Esse texto fica atualizado na tela sem aria-live a cada minuto, só anuncia quando falta 1 h e quando expira, e sempre vem acompanhado da data e hora absolutas. Linha do tempo: Enviada → Aprovada → Paga → Confirmada.
- **Aguardando pagamento** (depois da aprovação, ou **logo depois do envio se a reserva for instantânea**, com as datas já bloqueadas): as instruções vêm em **duas linhas**, cada uma com seu valor e sua chave: **"Estadia: R$ 2.000 · Pix [chave do recebedor do imóvel]"** e **"Limpeza: R$ 250 · Pix [chave do prestador]"**. Cada linha tem um botão "Copiar chave", que anuncia "Chave copiada". O prazo segue a regra 24 h / 4 h ("Pague até [dd/mm, hh:mm] · faltam 3 h 10 min"). O aviso em destaque diz **"Pagamento direto ao anfitrião, sem a proteção casalivre"**, mais "Depois do prazo, a reserva expira e as datas são liberadas." O cancelamento é **gratuito para os dois lados** e libera as datas: o botão "Cancelar sem custo" abre uma confirmação. O anfitrião confirma a diária e a limpeza separadamente (spec-02). *(provisório, revisão Negócios)*
- **Confirmada:** "Reserva confirmada. Código CL-1234." Se o imóvel exigir, aparece a tarefa **"Envie a lista de hóspedes para a portaria"** (nome e documento de cada hóspede; prazo antes do check-in [estimativa]; dados acessíveis só ao proprietário e aos delegados de check-in; spec-06, 06-2). O formulário mostra a **finalidade**: "Usamos estes dados só para o controle de acesso do condomínio. A portaria recebe nome e documento, sem foto, e os dados são apagados até 90 dias depois do check-out (ou mais, se houver um incidente aberto)." A spec-01 coleta **só os campos que a portaria exige** (06-3). O prazo é de 48 h antes do check-in (configurável por imóvel), com lembretes em 72 h e 24 h. Se atrasar, aparece o aviso **"A portaria pode recusar a entrada sem a lista."** A reserva não é cancelada *[E]*. Libera o endereço exato e as instruções de chegada, e mostra o botão "Ver em Viagens".
- **Recusada:** "O anfitrião não aceitou. Veja imóveis parecidos."
- **Expirada:** "O prazo de [aprovação/pagamento] acabou e as datas foram liberadas. Veja outras opções."
**Viagens:** cada reserva tem um **selo de status** (texto, ícone e token de cor) com os estados acima. Toda mudança de estado dispara um **gancho de e-mail**, que fica inativo até a D3. Sem ela, o hóspede acompanha só por Viagens, e a tela não promete e-mail nem SMS.
**Critérios de aceite**
1. Cada estado tem título, ícone e texto próprios, e nenhum depende só de cor.
2. Dado um check-in daqui a menos de 72 h e aprovação manual, quando envio, então o prazo mostrado é de 4 h. Caso contrário, é o valor configurado (24 h por padrão).
3. Dado Aguardando pagamento, então vejo o valor igual ao total do checkout, a chave ou os dados do imóvel, o prazo absoluto e o restante, e o aviso "sem a proteção casalivre".
4. Dado que o prazo (24 h ou 4 h) passou sem o pagamento ser marcado, então o estado vira Expirada e as datas voltam a ficar disponíveis na busca.
5. O tempo restante é lido corretamente por leitor de tela e não gera um anúncio por minuto.
6. Atualizar a página não reenvia a reserva.
7. O endereço exato só aparece em Confirmada.

**Fora desta spec (vai para a spec-02, do operador/gestor):** aprovar ou recusar, e o botão "Marcar como pago" com upload opcional de comprovante.

## 5b. Antibloqueio e confiabilidade *(provisório, revisão Negócios)*
- **Uma reserva em Aguardando pagamento por hóspede verificado.** No checkout, se já houver outra, o botão é substituído pela mensagem "Você já tem uma reserva aguardando pagamento ([imóvel], até [data]). Pague ou cancele para reservar outra.", com o link para ela.
- **Expirações seguidas:** depois de **3 expirações seguidas**, o hóspede fica **7 dias** sem poder criar reservas pendentes *[estimativa, calibrar no piloto; provisório, revisão Negócios]* e vê "Suas últimas reservas expiraram sem pagamento. Você poderá reservar de novo em [data]." Isso aparece no checkout e num banner em Viagens.
- **Cancelamentos em série** (pelo hóspede): limite igual, com a mensagem "Você atingiu o limite de cancelamentos. Fale com o suporte."
- **Recusa:** em Viagens, o hóspede vê a mensagem neutra "Esta reserva não pôde ser aceita", sem o motivo, e **imóveis parecidos disponíveis nas mesmas datas**, com prioridade para a mesma organização e o mesmo canal.
**Critérios de aceite:** (1) dado um hóspede com uma reserva em Aguardando pagamento, quando tenta outro checkout, então vê a mensagem e o link, e o envio fica bloqueado no servidor; (2) a mensagem de limite traz a data em que o bloqueio acaba; (3) a recusa nunca expõe o motivo e mostra até 6 sugestões das mesmas datas; (4) as mensagens de limite usam role=alert e texto, não só cor.

## 6. Abas sem conteúdo até a S9: UC-H11, UC-C09
- **Favoritos:** "Os imóveis que você salvar vão aparecer aqui." com o botão "Explorar imóveis". O coração **fica oculto** nos cards até a S9, sem selo de "em breve".
- **Mensagens:** "Suas conversas com anfitriões vão aparecer aqui." Quando a aba existir, telefone, e-mail e links digitados viram "[contato oculto]", com a nota "Por segurança, os contatos ficam ocultos até [regra a definir]".
- **Critério:** os estados vazios têm título, texto e uma ação, e nenhuma aba leva a página de erro.

## 7. WCAG 2.2 AA (vale para todas as telas)
- 1.4.3 e 1.4.11: contraste de 4,5:1 para texto e 3:1 para componentes, **validado em cada tema de canal**.
- 1.3.1 e 3.3.2: campos com rótulo visível. Os preços ficam numa lista estruturada (linha e valor).
- 3.3.1 e 3.3.3: erros em texto, com a sugestão de correção, e não só em cor.
- 3.3.7 (entrada redundante): o checkout reaproveita os dados da busca e da conta.
- 3.3.8 (autenticação acessível): o login permite colar a senha e usar gerenciador de senhas, sem captcha cognitivo.
- 2.4.3 e 2.4.11: ordem de foco lógica, e o foco não fica coberto por barras fixas.
- 2.5.7 e 2.5.8: alternativa ao arrastar no mapa e no carrossel. Alvos de no mínimo 24 px (padrão 44pt/48dp).
- 4.1.3: aria-live para totais, "Mostrar mais" e status de envio.
- 1.4.10 e 1.4.4: sem rolagem horizontal a 320 px e com texto ampliado a 200%.
- 2.3.3: respeitar prefers-reduced-motion.

## Pendências para Produto
Os itens pendentes não bloqueiam o restante da spec.
1. [pendente: Rafael] D3: provedor de WhatsApp, SMS e e-mail. Bloqueia o código de confirmação e os avisos.
2. [pendente: Negócios] Calibrar no piloto os limites de expiração (3 seguidas, 7 dias) e definir o limite de cancelamentos em série do hóspede.
3. [pendente: Rafael + Jurídico e Tributário] D14: textos finais dos termos (o parecer traz as cláusulas mínimas). O CDC já foi decidido no J35.
4. [pendente: Negócios] Revisão das decisões provisórias.
