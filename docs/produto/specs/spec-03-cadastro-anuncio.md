# CasaLivre: Spec 03, cadastro de anúncio (v1.1, 16/10/2026, **aprovada pelo Negócios com ajustes**, alvo S5 a S7, meta WCAG 2.2 AA). Todos os valores são *[estimativa]* e parametrizáveis.

Base: `spec-telas-busca-mapa-upload-fotos.md` (v1, upload de fotos), `spec-01` (o que o hóspede vê), `spec-02` (papéis, recebedores e canais), `roadmap.md` v0.2 e `casos-de-uso.md`. A spec é **neutra quanto ao tema** e usa só tokens. Itens *(provisório)* aguardam a revisão do Negócios.
**Casos de uso:** UC-P01 a UC-P10, UC-P12 a UC-P14, UC-P29, UC-P33 e UC-C08.

## 0. Estrutura geral
**Objetivo:** publicar um anúncio completo e correto em vários canais, sem perder trabalho.
**Assistente em 4 etapas mais a revisão:** (1) O espaço, (2) Fotos e descrição, (2b) **Inventário de móveis e utensílios**, (3) Preço, regras e publicação, e por fim Revisar e publicar.
**Layout:** no desktop, um indicador de etapas no topo ("Etapa 2 de 4 · Fotos e descrição") e, à esquerda, a lista de seções da etapa, que dá para navegar livremente. À direita fica um painel com o **checklist de qualidade** ao vivo. No mobile, uma seção por tela, com barra de progresso, "Voltar" e "Avançar" fixos no rodapé, e o checklist num painel que sobe da parte de baixo da tela.
**Rascunho e salvamento automático:** salva a cada campo ao sair dele, com o status "Salvo agora", "Salvando…" ou "Sem conexão, vamos tentar de novo". O usuário pode sair e retomar em "Anúncios › Rascunhos" ("Continuar de onde parou · Etapa 2"). Avançar não bloqueia por campos faltando, que viram pendências no checklist. Só a **publicação** exige o checklist completo.
**Quem faz o quê (spec-02):** o proprietário e o gestor criam e editam. O gestor publica se tiver a permissão delegada "publicar". Sem ela, o anúncio vai para **"Aguardando aprovação do proprietário"** *(provisório)*. O operador **não** vê Anúncios. Os admins só visualizam. Itens sem permissão ficam desabilitados com a explicação ("Só o proprietário vincula o recebedor").

## 1. Etapa 1, O espaço: UC-P01, UC-P04
**Campos:** tipo (casa, apartamento, chalé…), **nó da árvore** a que o imóvel pertence (o seletor da spec-02, já preenchido com o contexto ativo), capacidade (hóspedes, quartos, camas, banheiros) e **endereço**.
**Localização:** CEP com autocompletar, seguido do número e do complemento, e de um **mapa para ajustar o pin** (que **começa no centro do bairro** do CEP, e não no centro da cidade, para o anfitrião só ajustar) ("Arraste o pin ou use as setas para posicionar a entrada"). Ao lado, a prévia **"O hóspede vê: bairro [X] e uma área de ~500 m"**, com o círculo, igual à spec-01.
**Estados:** CEP não encontrado ("Não achamos este CEP. Preencha o endereço manualmente."); sem WebGL, sem mapa, com o pin ajustável por campos de latitude e longitude ou pela confirmação do endereço.
**Critérios de aceite**
1. Dado um CEP válido, então rua, bairro e cidade são preenchidos e ficam editáveis.
2. O pin pode ser ajustado sem arrastar, pelas setas ou pelos botões ↑↓←→ (2.5.7), e o endereço exato nunca aparece na prévia do hóspede.
3. O nó vem preenchido com o contexto ativo, e só aparecem os nós em que a pessoa pode criar imóveis.

## 2. Etapa 2, Fotos e descrição: UC-P02, UC-P03, UC-P04
**Fotos:** reaproveita **inteira** a spec v1, seção 2: arrastar ou selecionar, progresso, "Processando…", "Falhou, reenviar", reordenar sem arrastar, capa na 1ª posição e desfazer ao remover. O processamento usa **libvips (S5)** e gera **WebP em 3 tamanhos**. O limite é de **30 envios a cada 10 minutos**. Os formatos de entrada ficam **em aberto com Arquitetura e Programador**, porque dependem do libvips no container. O mínimo para publicar é de **5 fotos** com a capa definida *(provisório)*.
**Cômodo obrigatório por foto:** cada foto recebe um cômodo (sala, quarto, cozinha, banheiro, área externa, vista, outro), e isso gera um **texto alternativo padrão editável** ("Quarto 1 da Casa Azul").
**Título e descrição:** título com até 50 caracteres e contador ("32/50"); descrição com até 1.000 caracteres e dicas ("Fale do que fica perto, da vista e do que a casa tem de especial"). Contatos (telefone, e-mail, links) são bloqueados: "Não inclua contatos. Eles são liberados depois da reserva."
**Comodidades:** checkboxes por grupo (essenciais, cozinha, lazer, segurança, **acessibilidade**), com busca. As comodidades alimentam os filtros da busca (UC-H04).
**Critérios de aceite**
1. Uma foto sem cômodo fica marcada como pendência, e o texto alternativo gerado pode ser editado e é lido pelo leitor de tela.
2. Todos os estados das fotos seguem a spec v1, e o limite de envios mostra a mensagem com o tempo de espera.
3. Um título acima de 50 caracteres não é aceito, e o contador é anunciado só ao chegar perto do limite.
4. Contatos no título ou na descrição geram um erro inline com o motivo.

## 2b. Etapa Inventário de móveis e utensílios *(Produto; BLOQUEIO DO PILOTO)*
**Objetivo:** registrar o que o hóspede encontra no imóvel, para o contrato e para eventuais danos.
**Layout:** uma lista **por cômodo** (os cômodos vêm da Etapa 1 e das tags das fotos; "+ Adicionar cômodo"). Cada linha tem **item**, **quantidade** (inteiro ≥ 1) e **foto opcional** (mesmo componente de upload da Etapa 2). Ações: adicionar, duplicar, remover e reordenar itens; "Copiar de outro imóvel meu".
**Regras:**
- O anúncio **não pode ser publicado sem inventário** (pelo menos 1 cômodo com 1 item); o item entra no checklist ("Inventário de móveis e utensílios").
- O inventário vira o **Anexo I do contrato** e entra no **snapshot da reserva**: a reserva guarda a versão vigente na confirmação, e alterações depois só valem para reservas novas.
- O hóspede vê o inventário no detalhe da reserva (spec-09).
**Estados:** vazio ("Liste os móveis e utensílios de cada cômodo. Isso vira o Anexo I do contrato."); item sem quantidade (erro em linha); upload de foto falhou (a linha fica salva sem foto).
**Critérios de aceite:** (1) publicar sem inventário é bloqueado, com o link para a etapa; (2) a reserva confirmada mostra o inventário da versão do snapshot, mesmo que o anúncio mude depois; (3) cada linha tem item e quantidade, e a foto é opcional; (4) leitores de tela anunciam "Cozinha, 12 itens".

## 3. Etapa 3, Preço, regras e publicação: UC-P05 a UC-P07, UC-P12 a UC-P14, UC-P29
**Cobrança fora do total:** nenhuma cobrança além do total mostrado ao hóspede pode ser feita (nem por Pix nem em dinheiro); taxas de pet ou de hóspede extra só existem como linhas do detalhamento (G40, S8). *(aprovado pelo Negócios em 30/10)*
**Preço:** diária base (única) e **períodos** opcionais (início, fim, preço por noite e mínimo de noites próprios; versão mínima da S7, com a mesma tela da spec-06; **períodos sobrepostos não podem ser salvos**, e aparece um aviso) *[E]*. O preço por dia da semana e os descontos são extensões da spec-07 (S8). Também mínimo e máximo de noites e antecedência. A **taxa de limpeza** tem valor fixo por estadia e o texto "paga ao prestador".
**Recebedores (spec-02, seção 4):** o campo **"Recebedor da estadia"** e o campo **"Prestador de limpeza (recebedor)"** só **escolhem** recebedores já cadastrados no nó. Também há "Cadastrar recebedor", que leva ao fluxo da spec-02 com OTP e carência. Um recebedor em "Pendente até [data]" aparece com esse selo.
**Regras da casa** (por imóvel, campos estruturados e mais um texto livre de até 300 caracteres. Os campos estruturados são usados no motivo de recusa "Não atende às regras da casa" e mostrados ao hóspede antes de reservar; o texto livre não serve como motivo):
- Pets: sim, não ou sob consulta, com porte e quantidade.
- Fumar: não, só na área externa, ou sim.
- Festas: não ou sob consulta.
- Silêncio: das 22h às 8h por padrão, editável.
- Visitas: sim ou não, e até que horas.
- Check-in às 14h e check-out às 11h por padrão, editáveis.
- **Regras do condomínio:** um anexo em PDF que o hóspede **aceita no checkout** (spec-01).
- Piscina, churrasqueira e garagem (número de vagas).
- **Documento de todos os hóspedes para a portaria:** sim ou não. Se sim, a lista é coletada depois da confirmação (spec-01, Viagens).
**Idades:** o responsável tem 18 anos ou mais, e o anfitrião pode exigir 21+ ou 25+. Faixas: bebê de 0 a 2 anos (por padrão não conta na capacidade, configurável), criança de 3 a 11, adulto de 12 em diante. Há um **aviso fixo, que não pode ser desligado**: "Menores só com os pais ou o responsável, ou com autorização (ECA, art. 82)." 
**Tipo de reserva:** "Instantânea" ou "Sob aprovação". Na aprovação manual, o **prazo é herdado do nó** ("Padrão da Rede Lagos: 24 h, 4 h se o check-in for em menos de 72 h") e pode ser **sobrescrito** neste imóvel *(provisório)*.
**Política de cancelamento** (o anfitrião escolhe uma; os textos legais são o placeholder "[texto legal, D14]"):
| Política | Reembolso |
|---|---|
| Flexível | 100% até 24 h antes do check-in. Depois disso, a 1ª diária não é devolvida |
| Moderada | 100% até 5 dias antes. Depois, 50% |
| Rígida | 100% até 14 dias antes, 50% entre 14 e 7 dias, 0% com menos de 7 dias |
Vale para todas: 100% em até 7 dias **a partir da confirmação da reserva, desde que antes do check-in** (CDC art. 49; [decidido, parecer J35]; o anfitrião aceita no contrato); a limpeza e a taxa não usadas são sempre devolvidas; um cancelamento pelo anfitrião devolve 100%. Em Aguardando pagamento, o cancelamento é gratuito (spec-01).
**Canais de vitrine:** a lista dos canais disponíveis no nó. Um anúncio novo começa **só no marketplace casalivre**, que vem **ligado por padrão** (a vitrine própria). **Todos os outros canais começam desmarcados**, inclusive o canal da organização ou do nó do imóvel. O proprietário marca cada canal no próprio formulário, e **essa marcação é o consentimento (adesão)** (Rafael, 2026-10-21; [docs/decisions.md](../../decisions.md); spec-04 §2). Quando o anúncio é criado por outra pessoa (gestor ou franqueado), cada canal marcado vira um **pedido pendente para o proprietário** ("Aguardando o proprietário") e só entra no canal depois que ele aceita. Cada canal tem um **interruptor de visibilidade** ("Mostrar em Marketplace casalivre", "Mostrar em Site Rede Lagos"). Cada canal ativo tem o link **"Ver prévia com o tema do canal"**. O **preço base é único**, com um **acréscimo percentual por canal** (parâmetro do canal, por exemplo +15% na OTA). O cadastro mostra uma **prévia somente leitura por canal** ("Marketplace: R$ 2.250 · OTA (+15%): R$ 2.587,50"), e o anfitrião não edita o preço por canal. O split também varia por canal.
**Critérios de aceite**
1. Dado que não há recebedor da estadia vinculado, então o checklist mostra a pendência e "Publicar" fica desabilitado com a explicação.
2. Cada regra estruturada aparece no detalhe do imóvel (spec-01) com o mesmo texto que o operador vê ao escolher o motivo de recusa.
3. Quando o prazo herdado é sobrescrito, o campo mostra "Personalizado (padrão do nó: 24 h)" e um botão "Voltar ao padrão".
4. Ligar ou desligar um canal não muda o preço nem a visibilidade nos outros canais.
5. A prévia por canal é só para leitura, aplica o acréscimo do canal e bate com o total que o hóspede vê naquele canal.
6. O aviso do ECA aparece sempre e não tem opção para desligar.
7. A política escolhida mostra um exemplo com datas ("Para check-in em 20/12: 100% até 06/12…").

**Caução, saída antecipada e limpeza** *(Produto, requisito de tela)*:
- **Caução** (opcional): valor definido pelo anfitrião ("Caução: R$ 500"). Aparece no anúncio e no checkout como linha informativa, separada do total.
- **Saída antecipada:** regra exibida no anúncio e no contrato: "Saída antes da data: as diárias não usadas não são devolvidas, salvo se a política escolhida disser outra coisa." Pré-preenchida pela política, só leitura.
- **Escopo da limpeza:** campo de texto (termo de limpeza: o que está incluído e o que não está), mostrado no anúncio e no contrato.
- **Danos:** o anfitrião tem **até 48 h depois do check-out** para registrar dano com fotos (spec-06 §3d); depois disso, a caução é devolvida.

**Regra municipal** (spec-04 2b): pelo município do endereço, o wizard mostra o campo **"Número do cadastro municipal"** quando a cidade exige, com o formato da regra. Se a regra marcar o cadastro como obrigatório para publicar, ele entra no checklist ("Informe o cadastro municipal exigido em [cidade]"). O número informado é **exibido no anúncio**. Com uma regra **ativa**, o **texto do aviso** e a taxa de turismo (valor por hóspede ou por noite, vigência, quem recolhe) aparecem ao anfitrião; a cobrança no checkout é porta futura. Regras inativas (ex.: Búzios, não confirmada) não aparecem.

## 4. Revisar e publicar: UC-P09, UC-P10
**Prévia:** o detalhe do imóvel como o hóspede vê (spec-01), com um **seletor de canal** que aplica o tema de cada canal ("Ver como: Marketplace ▾") e a alternância desktop/mobile.
**Checklist de qualidade (obrigatórios para publicar):** fotos mínimas e capa definida; título e descrição; endereço e pin; capacidade; diária e limpeza; **recebedor da estadia vinculado (e o prestador de limpeza, se houver taxa)**; regras de check-in e check-out; política de cancelamento; tipo de reserva; pelo menos 1 canal ativo; **inventário de móveis e utensílios** (Etapa 2b); **requisitos do piloto para pagamento por fora** ([decidido, parecer item 17]): **o recebedor da estadia tem KYC Aprovado e a chave Pix está vinculada a esse recebedor**. Sem isso, a publicação fica bloqueada com "Para receber por fora no piloto, o recebedor precisa estar verificado e com chave Pix vinculada."; **vínculo do proprietário com o imóvel** (escritura, IPTU ou contrato, enviado e aceito) *[E]*. **Recomendados:** mais de 10 fotos, comodidades de acessibilidade e texto alternativo nas fotos.
**Ações:** "Publicar" (com a confirmação "Publicar em 2 canais?"), "Salvar como rascunho" e, depois de publicado, "Pausar" (tira o anúncio da busca e de todos os canais e mantém as reservas), "Reativar" e "Excluir". **Com reservas futuras, não é possível excluir**: aparece "Este anúncio tem reservas futuras. Você pode pausá-lo." Sem reservas futuras, a exclusão é uma **inativação** (soft delete), com confirmação e motivo *(provisório)*.
**Estados:** checklist incompleto ("Faltam 2 itens para publicar", com links para cada um); publicando; publicado ("Seu anúncio está no ar em 2 canais", com "Ver anúncio" e "Abrir calendário"); aguardando aprovação do proprietário ("Enviado para [proprietário] aprovar", e o proprietário vê "Aprovar publicação" ou "Pedir ajustes"); erro ("Não foi possível publicar. Seu rascunho está salvo. Tentar novamente").
**Critérios de aceite**
1. Dado um checklist incompleto, quando clico em "Publicar", então nada é publicado, o foco vai para o resumo e cada item é um link para o campo.
2. A prévia por canal aplica o tema do canal sem alterar o conteúdo e esconde o endereço exato.
3. Pausar tira o anúncio de todos os canais, e cada canal pode ser desligado separadamente.
4. Sair em qualquer etapa e voltar recupera tudo, inclusive as fotos já processadas.
5. Dado um gestor sem a permissão "publicar", quando publica, então o estado vira "Aguardando aprovação do proprietário" e nada vai ao ar.
6. Dado um anúncio com reservas futuras, então "Excluir" fica desabilitado, com a explicação e a oferta de "Pausar".

## 5. WCAG 2.2 AA
- Indicador de etapas com `aria-current="step"`, e o título da página inclui a etapa (2.4.2).
- Salvamento automático anunciado por role=status e sem animação (4.1.3).
- Erros inline com texto e sugestão, além de um resumo com links ao publicar (3.3.1, 3.3.3).
- Entrada redundante: o nó, a capacidade e o endereço são reaproveitados (3.3.7).
- Alternativa ao arrastar no pin do mapa e nas fotos (2.5.7). Alvos de no mínimo 24 px (2.5.8).
- Interruptores de canal com rótulo do canal e estado anunciado. Contraste de 4,5:1 e de 3:1 (1.4.3, 1.4.11).
- Contadores de caracteres anunciados só perto do limite. Sem tempo limite para preencher o assistente (2.2.1).
- Foco visível e não coberto pelo rodapé fixo no mobile (2.4.7, 2.4.11).

## Pendências para Produto
Os itens pendentes não bloqueiam o restante da spec.
1. [pendente: Arquitetura e Programador] Formatos de entrada das fotos (dependem do libvips no container).
2. [pendente: Rafael + Jurídico e Tributário] D14: textos legais das políticas e dos termos. A regra do CDC foi decidida no J35.
3. [pendente: Negócios] Calibrar no piloto os valores [E]: acréscimo por canal, faixas de idade e horários padrão.
