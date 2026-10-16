# CasaLivre: Spec 04, administração da árvore e dos canais (v1, 16/10/2026, alvo S5 (P2a), meta WCAG 2.2 AA)

Base: `backlog.md` P2a (critérios 1 a 5), `sprints.md` (S5), `spec-02` (papéis, árvore de profundidade livre, permissões, auditoria) e `spec-03` (canais no cadastro, acréscimo por canal). A spec usa só tokens de tema.
**Casos de uso:** UC-A01 a UC-A14 (`casos-de-uso-admin-proposta.md`), UC-P33, UC-C08, UC-P09 e UC-P32.
**Layout geral:** é **desktop-first**: barra lateral do modo Gerenciar, área principal e um painel de detalhe à direita. No **mobile**, o acesso é **só de consulta** (ver a árvore, os membros, os canais e o log). Ações de criar, mover, editar tema e vincular em lote mostram "Disponível no computador".

## 1. Árvore de gestão
**Objetivo:** organizar nós de profundidade livre, com papéis e configurações herdadas, sem quebrar reservas.
**Componentes:** uma **árvore** com expandir e recolher (carregamento sob demanda), **busca por nome** com o caminho do resultado ("Plataforma › … › Imobiliária Sol"), rótulo de `tipo` (só texto; nenhuma regra depende dele) e contadores (imóveis, membros, canais). O painel do nó tem as abas **Configurações, Membros, Canais e Log**.
**Ações por papel (spec-02):**
- **Criar nó filho:** o admin do nó pai. Campos: nome, rótulo de tipo e slug.
- **Renomear:** o admin do nó ou o do nó pai.
- **Configurações herdadas:** cada campo mostra o valor e a origem: "24 h · herdado de Rede Lagos", com "Sobrescrever neste nó" e "Voltar ao herdado". Exemplos: prazo de aprovação (24 h / 4 h), taxa de serviço (0%), limites antibloqueio e parâmetros de confiabilidade.
- **Membros e papéis:** convidar por e-mail ou celular (o envio depende da D3; até lá vale o **link de convite copiável**, um paliativo *aprovado*, de **uso único, válido por 72 h e revogável** (04-6)), com o papel (admin, gestor, operador, proprietário) e as **delegações** (publicar, marcar como pago, editar recebedor, aprovar reservas). Cada linha mostra se o papel é "Direto" ou "Herdado de [nó]".
- **Mover nó (só admin_plataforma):** pode arrastar, e tem a **alternativa por teclado e botões**: "Mover para…" abre o seletor de destino com busca. A **prévia de impacto** mostra a quantidade de imóveis, reservas em andamento, membros e canais afetados, as regras herdadas que mudam (antes e depois), e o aviso em destaque **"Reservas existentes mantêm as regras atuais. A mudança vale só para reservas novas."** A pessoa digita o nome do nó e informa um motivo. Destino inválido, como mover um nó para dentro de um descendente dele (ciclo), mostra "Não é possível mover um nó para dentro dele mesmo".
- **Desativar nó:** só sem reservas futuras e sem filhos ativos. Senão, aparece "Este nó tem 3 reservas futuras e 2 filhos ativos. Resolva antes de desativar.", com links. Desativar tira os imóveis de todos os canais, mantém o histórico, é reversível ("Reativar") e fica no log.
**Estados:** árvore carregando (skeleton); busca sem resultado ("Nenhum nó com esse nome no seu acesso"); sem permissão (estado da spec-02); erro ao salvar ("Não salvamos. Suas alterações continuam aqui. Tentar novamente"); nó desativado (selo "Inativo" com texto).
**Critérios de aceite**
1. A árvore aceita qualquer profundidade, e um movimento que criaria um ciclo é recusado com a mensagem (o servidor responde 422).
2. Trocar o rótulo de `tipo` não muda nenhuma permissão, configuração nem split.
3. Cada valor herdado mostra a origem, e quando sobrescrito tem "Voltar ao herdado".
4. Só o admin do nó pai vê "Criar nó filho", e só o admin_plataforma vê "Mover". Os outros papéis não veem as ações e o servidor responde 403.
5. A prévia de impacto aparece antes de confirmar o movimento, e as reservas existentes mantêm o snapshot (verificável na reserva).
6. Mover funciona inteiro sem arrastar.
7. Desativar fica bloqueado enquanto houver reservas futuras ou filhos ativos.
8. Todas as ações (criar, renomear, sobrescrever, convidar, alterar papel, mover, desativar) geram um registro no log.

## 2. Canais de vitrine
**Objetivo:** criar e manter vitrines (marketplace, site regional, site do parceiro) com domínio, tema e imóveis, sem misturar com a árvore.
**Lista:** nome, domínio, nó dono, status (Rascunho, Publicado, Despublicado), quantidade de imóveis e acréscimo.
**Criar e editar:** nome, **slug** (`/g/slug`; minúsculas, números e hífen; validado na hora: "Disponível" ou "Já em uso") e **domínio próprio** opcional, com os estados **"Aguardando DNS"** (com as instruções de registro), **"Verificando…"**, **"DNS ok · emitindo SSL"**, **"Ativo (HTTPS)"** e **"Erro de DNS/SSL"** (com o motivo e "Verificar de novo"). Também o **nó dono**. As vitrines `/g/...` e os domínios atuais continuam funcionando.
**Texto de marca** *(requisito de tela, Produto; regras fiscais com o Jurídico e Tributário)*: nenhum texto de tela usa **"imobiliária"** ou **"corretora"** para a casalivre (nome do canal, slogan, rodapé, textos do tema e da vitrine). A regra vale **só para textos que descrevem a própria casalivre** (marketplace e textos institucionais); canais e organizações clientes que são de fato imobiliárias podem usar o termo (spec-00, glossário). Nos canais da casalivre, o editor avisa ao salvar: "Não use 'imobiliária' nem 'corretora' para a casalivre." Lista em spec-00, termos proibidos.
**Cadastro de franqueado e parceiro:** o convite/ativação mostra o **campo CRECI com o status de verificação** (Verificado, Em verificação, Não verificado, Inativo; spec-05, KYC). **Só com "Verificado" a pessoa opera como franqueado ou parceiro**: com outro status, o nó fica "Aguardando CRECI verificado", as ações de franqueado/parceiro aparecem desabilitadas com o motivo ("Disponível quando o CRECI estiver verificado") e o servidor recusa a ativação.
**Editor de tema:** logo (upload, com texto alternativo obrigatório), tokens de cor (`primary`, `on-primary`, `surface`, `on-surface`, `danger`, `success`, `warning`) e tokens de fonte. A **prévia ao vivo** mostra card, botão, card de preço, erro e sucesso, com a troca entre desktop e mobile. **Ao salvar**, cada par é validado (texto ≥ 4,5:1, componentes ≥ 3:1). **Abaixo do AA, o tema não é salvo**, e um resumo lista os pares reprovados ("on-primary sobre primary: 3,1:1, mínimo 4,5:1"), com links para os campos. **Quem cria:** os admins e o gestor no nó dele, só com a permissão delegada **"gerenciar canais"** *(aprovado)*. Um domínio próprio precisa estar com o **DNS verificado antes de publicar**. **Quem edita o tema:** os admins e o **gestor só se o canal pertence ao nó dele**. Para os outros, o item fica desabilitado com a explicação.
**Imóveis no canal:**
- Lista com **seleção em lote** ("Adicionar 12 imóveis", "Remover").
- **Interruptor por imóvel**, o mesmo da spec-03.
- Opção **"Incluir automaticamente novos imóveis do nó"** por canal. **Padrão para um imóvel novo:** ele entra **ligado** no marketplace casalivre e no canal da organização ou do nó dele, e **desligado** nos canais de terceiros e de parceiros. O proprietário pode desmarcar qualquer um. O padrão é configurável por organização *(Negócios)*.
**Acréscimo por canal:** um parâmetro percentual de **−20% a +30%** (desconto permitido; faixa parametrizável pela plataforma) *[estimativa; aprovado]* ("+15%"), com **prévia somente leitura** do preço resultante para alguns imóveis ("Base R$ 2.250 → canal R$ 2.587,50"). O **preço base é único** e só é editado no imóvel.
**Publicar e despublicar:** publicar exige domínio ou slug ativo, tema aprovado e pelo menos 1 imóvel. Despublicar tira a vitrine do ar, mantém as reservas e pede confirmação.
**Estados:** canal sem imóveis ("Adicione imóveis para publicar"); slug inválido; DNS pendente; tema reprovado; publicando.
**Critérios de aceite**
1. Um slug ou domínio duplicado é recusado na hora, com a mensagem.
2. Cada estado de DNS e SSL é exibido com texto e ação. Publicar com domínio sem HTTPS fica bloqueado com a explicação.
3. Um tema abaixo do AA não é salvo, e a lista dos pares reprovados fica acessível como texto.
4. Um imóvel em 2 canais aparece nos dois. Removê-lo de um não afeta o outro.
5. A prévia do acréscimo é só de leitura e bate com o total que o hóspede vê naquele canal (spec-01).
6. Um gestor de outro nó não edita o tema e vê a explicação.
7. As vitrines e os domínios atuais continuam iguais depois da migração (regressão).

## 2b. Regras municipais *(Negócios; S8+)*
Entidade **`regra_municipal`**, mantida pelo admin_plataforma (o admin_org vê as cidades do seu nó). Campos:
- **município** (código IBGE) e UF;
- **cadastro obrigatório** (sim/não) e **número do cadastro exibido no anúncio**;
- **taxa de turismo**: **valor por hóspede OU por noite**, **vigência**, **quem recolhe**, e **se entra no total** da reserva;
- **texto do aviso** (mostrado ao anfitrião e, quando aplicável, no anúncio);
- **status**: confirmada / não confirmada;
- **ativa** (sim/não);
- **reverificar em** (data): na data, a regra ganha o selo "Reverificação vencida" na lista do admin_plataforma, sem mudar o efeito nas telas até alguém confirmar ou editar.
Campos extras são aceitos desde que compatíveis (por exemplo, norma de referência e data da última conferência). Cada alteração vai para o log (classe administração). Uma regra inativa não tem efeito nas telas.
**Seeds (dados do Negócios):**
| Município | Cadastro | Taxa de turismo | Status | Ativa | Reverificar em |
|---|---|---|---|---|---|
| Cabo Frio | voluntário (não obrigatório) | não há | confirmada | sim | 01/12/2026 |
| Armação dos Búzios | — | **R$ 14,60 por hóspede**, de **20/12 ao Carnaval + 5 dias** | **não confirmada** | **não** | — |
| Arraial do Cabo | não há | não há | confirmada | sim | 01/12/2026 |
**Porta futura:** **taxa no checkout** (cobrar a taxa de turismo como linha própria quando "entra no total" = sim), desligada agora.

## 3. Log de auditoria
**Objetivo:** rastrear quem fez o quê.
**Layout:** uma tabela com filtros (nó com descendentes, autor, tipo de ação, período). Cada linha mostra data e hora, autor e papel, ação, alvo, e o antes e depois (dados sensíveis mascarados, como a chave Pix). Os dados pessoais ficam mascarados na tela. **Retenção de 5 anos** *[estimativa; aprovado; validação de Segurança e jurídico]*. **Exportar CSV:** o admin_plataforma exporta tudo, e o admin_org só o próprio escopo. A própria exportação fica no log *(aprovado)*.
**Critérios de aceite:** (1) cada ação das seções 1 e 2, mais as da spec-02 (Pix, marcar como pago, força maior), aparece no log; (2) o log é imutável e não tem botão para editar nem excluir; (3) cada papel vê só o log da própria sub-árvore.

## 4. WCAG 2.2 AA
- A árvore segue o padrão Tree View da APG (setas, Home/End, busca digitando), com `aria-expanded`, e o nível é anunciado.
- Arrastar sempre tem alternativa ("Mover para…", botões) (2.5.7). Alvos de no mínimo 24 px (2.5.8).
- Valores herdados e sobrescritos são indicados em texto, não só por cor ou ícone (1.4.1).
- Status de DNS, SSL e publicação usam texto e role=status (4.1.3).
- O validador de contraste lista os erros em texto, com links para os campos (3.3.1). A prévia tem uma descrição textual.
- A confirmação de mover e desativar evita erro (3.3.4), e o foco volta ao nó afetado.
- Tabelas do log com cabeçalhos e filtros rotulados (1.3.1). Sem rolagem horizontal a 320 px na consulta mobile (1.4.10).

## Requisitos de Segurança/LGPD (parecer de 16/10/2026)
Fonte: `/workspace/casalivre-seguranca-evidence/specs-ui-04-06/relatorio.md`. O texto técnico de cada achado foi **transcrito sem alteração**. O mecanismo é com Arquitetura.
**Decisões de tela (Produto):** (a) **retenção por classe de evento** ([decidido, parecer J1, J2, J17]): eventos financeiros e fiscais, **5 anos contados de 1º de janeiro do ano seguinte**; contrato e aceite, **duração do contrato + 5 anos**; administração, **5 anos**; **log de acesso, 6 meses, com o IP cifrado de forma reversível e um índice HMAC** (não truncado), estendido só por requisição de autoridade ou legal hold (04-1). Um pedido de eliminação **pseudonimiza** os dados retidos por obrigação legal (J3). (b) A **chave Pix e o CPF/CNPJ** aparecem **mascarados também dentro do log**, e não só na tela (04-2). (c) **Exportar o log exige reautenticação**, e o arquivo sai **mascarado** igual à tela (04-4). (d) O **log antigo segue o nó da época** (mais o admin_plataforma) quando um nó é movido (04-5). (e) O **link de convite** é de **uso único**, vale **72 h** e é **revogável** (botão "Revogar convite" na lista de membros, com os status Pendente, Usado, Expirado e Revogado) (04-6). (f) O upload do logo usa o pipeline de imagem existente, sem SVG (04-7).

- **04-1** (Média; no parecer: spec-04:51): Prazo único de 5 anos para todos os eventos. Recomendação: separar por classe. (a) Eventos financeiros e fiscais (Pix, marcar como pago, ledger, split) e de contrato: 5 anos, o prazo da spec, a validar com jurídico. (b) Eventos administrativos (árvore, canais, tema, membros): 5 anos aceitável, também a validar. (c) **Logs de acesso** (IP, login, visualização de documento): **6 meses** pseudonimizados (Marco Civil art. 15, regra já decidida), sem estender para 5 anos sem uma base legal.
- **04-2** (Média; no parecer: spec-04:51; `src/ReturningWrite.php` (`audit_hold`)): O "antes e depois" grava valores anteriores com dados pessoais (e-mail, celular do convite, chave Pix, CPF). A spec só mascara **na tela**. Exigir que o log **guarde a chave Pix e o CPF/CNPJ já mascarados ou como hash/token**, nunca em claro. Para os outros dados pessoais, usar uma referência (id) em vez do valor. Isso conflita com a regra "todo UPDATE/DELETE registra o valor anterior": a regra fica valendo, mas para campos pessoais o valor anterior vai **mascarado ou cifrado**, e entra no fluxo de purga (ver item 8).
- **04-3** (Média; no parecer: spec-04:52): "Imutável" (spec-04:52) não pode impedir a purga LGPD. Definir que a única exceção é a **anonimização por job de sistema** (nunca pela UI), registrada como um evento próprio. Para provar a imutabilidade: tabela append-only (sem UPDATE/DELETE para o usuário de aplicação no PG) ou encadeamento de hash.
- **04-4** (Média; no parecer: spec-04:51): Exportar CSV do log (spec-04:51): exige (a) proteção contra CSV injection: prefixar `'` em células que começam com `= + - @`, TAB ou CR; (b) exportação **mascarada** igual à tela; (c) reautenticação ou OTP do admin para exportar; (d) rate limit; (e) o arquivo gerado por streaming, sem ficar em storage público.
- **04-5** (Baixa; no parecer: spec-04:52, :15): Escopo "nó com descendentes": o filtro é aplicado no servidor (a spec cita só o aceite 3). Mover um nó (spec-04:15) muda quem vê o log antigo. Definir se o log segue o nó atual ou o nó da época. Recomendo o nó da época mais o admin_plataforma.
- **04-6** (Baixa; no parecer: spec-04:14): Link de convite copiável (spec-04:14): definir token aleatório de no mínimo 128 bits, uso único, validade curta (ex.: 72 h), revogável, guardado como hash, e papel fixo no token.
- **04-7** (Info; no parecer: spec-04:31-32): O domínio próprio já segue as regras de #63–#65 (TXT `_casalivre`, HTTPS obrigatório para publicar, spec-04:42). O upload de logo precisa usar o pipeline de imagem existente (re-encode, sem SVG).

**Critérios de aceite de Segurança**
1. (04-1) O filtro do log mostra a classe do evento (financeiro/fiscal, contrato/aceite, administrativo, acesso), com o prazo de cada uma. Eventos de acesso com mais de 6 meses não aparecem, exceto com legal hold. O IP aparece mascarado na tela e é decifrável só para cumprir uma ordem.
2. (04-2) Nenhuma linha do log mostra chave Pix ou CPF/CNPJ completos, nem a tela nem a exportação.
3. (04-3) A UI não tem nenhuma ação de editar ou apagar um evento. A anonimização por job aparece como um evento próprio ("Anonimização LGPD").
4. (04-4) Exportar pede reautenticação. O arquivo vem mascarado, e uma célula que começa com = + - @, TAB ou CR é neutralizada.
5. (04-5) Depois de mover um nó, os eventos anteriores continuam visíveis para os admins do nó da época e para o admin_plataforma.
6. (04-6) Um link de convite usado, expirado (72 h) ou revogado mostra "Este convite não é mais válido. Peça um novo a [nome]."
7. (04-7) O upload do logo recusa SVG, com a mensagem.

**Questões jurídicas** (decididas no `juridico/parecer-J1-J35.md`):
- [decidido, parecer J1] **J1**: A base legal para guardar o log por 5 anos (provável art. 7º II/VI e art. 16 I da LGPD, cumprimento de obrigação legal e exercício de direitos). Qual prazo se aplica a cada classe de evento (prescrição civil, CDC art. 27 com 5 anos para fato do produto, guarda fiscal)? **Validar com jurídico**, eu não confirmo os prazos. → **Decisão:** por classe: financeiro e fiscal, **5 anos contados de 1º de janeiro do ano seguinte**; contrato e aceite, **duração do contrato + 5 anos**; administração, **5 anos**; acesso, **6 meses**.
- [decidido, parecer J2] **J2**: Se o log de acesso pode ir além dos 6 meses do Marco Civil art. 15, e com qual justificativa. → **Decisão:** **6 meses**; só se estende por requisição de autoridade ou por legal hold de um caso.
- [decidido, parecer J3] **J3**: Como o art. 16 (conservação após o término) convive com o pedido de eliminação do art. 18 VI. Confirmar que manter o log pseudonimizado basta. → **Decisão:** a eliminação não alcança os dados com obrigação legal; o que fica retido é **pseudonimizado**, e o titular é informado dos itens, do motivo e do prazo; resposta em 15 dias.

## Pendências para Produto
Os itens pendentes não bloqueiam o restante da spec.
1. [pendente: Rafael] D3, o envio dos convites. Até lá vale o link de uso único.
2. [pendente: Arquitetura] Mecanismo de imutabilidade e de anonimização (04-3), o streaming da exportação (04-4) e a cifragem reversível do IP com índice HMAC (J17).
3. [pendente: Negócios] Revisão das decisões provisórias.
