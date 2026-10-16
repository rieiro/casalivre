## Apêndice A: Fatura mensal de comissão: parte fiscal (substitui o item "NFS-e" das Decisões e a pendência do apêndice)
Fontes: `juridico/parecer-J1-J35.md` itens 13 a 16, 50 (J38) e 51 (J39); `juridico/recomendacoes-sem-orientacao-externa.md` §1, §2 e §4; spec-00 §5 e §7. [decidido, parecer J38/J39; Rafael decidiu seguir sem OAB/CRC em 16/10/2026.] O texto de "Faturas separadas por emissor", "Extrato consolidado", vencimento, escalonamento, fechamento, fatura e baixa continua como está.

### A.1 Natureza da atividade
- A casalivre faz **intermediação por plataforma digital** (anúncio, busca, reserva, cobrança da própria comissão). Não faz corretagem no sentido do CC art. 722; nos modelos com corretor, **a corretagem é do franqueado ou do parceiro**, inscrito no CRECI.
- Item da lista da LC 116/2003: **10.05** ("agenciamento, corretagem ou intermediação de bens móveis ou imóveis…"). O código não muda se o CRECI enquadrar a atividade como corretagem.
- NBS: pela tabela de correlação LC 116 × NBS do portal da NFS-e Nacional (nfse.gov.br) [CONFERIR o código].
- ISS no município do estabelecimento do emissor (LC 116 art. 3º), alíquota entre 2% e 5% (art. 8º e 8º-A) [CONFERIR a alíquota no município sede].

### A.2 Quem emite cada NFS-e, por modelo
O **aluguel não gera NFS-e** (locação de bem imóvel não é serviço: Súmula Vinculante 31/STF) e, no piloto, nem passa pela casalivre. Cada emissor da cobrança (seção 2, campo "emissor da cobrança") emite a nota **só da sua parcela**.

| Modelo | Parcela | Emissor | Tomador | Base | LC 116 |
|---|---|---|---|---|---|
| Direto | comissão casalivre | casalivre | proprietário | valor da comissão | 10.05 |
| Parceria | comissão do parceiro | parceiro (CRECI ativo) | proprietário | parcela do parceiro | 10.05 |
| Parceria | comissão casalivre | casalivre | configurável por modelo; padrão: proprietário (parceiro, se o contrato de parceria disser que ele paga a casalivre) | parcela da casalivre | 10.05 |
| Franquia | **comissão da franquia, 10% no padrão** (dela saem a corretagem de 4%, o royalty e o fundo) | franqueado | proprietário | comissão total (10% no padrão) das diárias retidas | 10.05 |
| Franquia | royalty: 3% no padrão, ou o percentual vigente no contrato de franquia (por exemplo, 2,5%), pago pelo franqueado com a comissão que recebe | casalivre | **franqueado** | percentual do royalty (base do contrato de franquia) | **17.08** (franquia) |
| Franquia | repasse do fundo: 3% no padrão, ou o percentual vigente no contrato de franquia | — (sem NFS-e; repasse à conta segregada, com extrato) | fundo regional | — | — |
| Todos | **taxa de cancelamento do anfitrião** (multa contratual: 10%, teto de R$ 300, a partir do 2º cancelamento em 12 meses) | casalivre | anfitrião (proprietário) | valor da multa | **sem NFS-e** (não é serviço): recibo ou nota de débito |
| Manual | comissão do canal manual, se houver [pendente: Rafael] | casalivre (e franqueado, se a franquia cobrar no manual) | proprietário | valor da comissão | 10.05 |

**Franquia (soma):** no padrão, o proprietário paga **10%** ao franqueado, numa só NFS-e no item 10.05. Desses 10%, o franqueado fica com **4%**, paga **3%** de royalty à casalivre (NFS-e da casalivre ao franqueado, item 17.08) e repassa **3%** ao fundo (conta segregada, extrato, sem NFS-e): 4 + 3 + 3 = 10. Os percentuais são **parâmetros do modelo de split**: o royalty e o fundo seguem o percentual vigente no contrato de franquia, e a corretagem do franqueado é o que sobra da comissão total.

**Base da comissão:** a comissão incide **só sobre as diárias efetivamente retidas pelo anfitrião** (diárias pagas menos o reembolso devido). Reembolso total não gera comissão nem nota. O atraso no reembolso gera só as penalidades já previstas (disputa automática, suspensão de reservas novas, perda do pagamento por fora), sem comissão sobre o valor que deveria ter sido devolvido. No OTA, a base são as **diárias brutas da reserva OTA**, excluídas a taxa de limpeza, as taxas e comissões da OTA e os impostos ou taxas municipais; se a OTA cancelar com reembolso parcial, vale a mesma regra do valor retido. Um reembolso é **definitivo** no primeiro destes eventos: (i) o hóspede confirma que recebeu; (ii) passam 72 h sem contestação depois de o anfitrião enviar o comprovante; ou (iii) a disputa é decidida. Um cancelamento sem reembolso devido pela política é definitivo na data do cancelamento, desde que não haja disputa aberta. **Enquanto houver disputa aberta, nada é definitivo.**

**Taxa de cancelamento do anfitrião:** é **multa contratual** (cláusula penal, CC arts. 408 a 416), e não serviço. Por isso, **não tem NFS-e**. Ela é cobrada **pela casalivre** ao anfitrião, em qualquer modelo (inclusive na franquia), **com recibo ou nota de débito**, e não entra na fatura do franqueado.

Sem comissão no canal manual, não há nota. Mensalidade de software, se for adotada, é outro serviço (LC 116 1.03 ou 1.05) [CONFERIR com o município].

### A.3 Royalty
- O royalty de **3% no padrão** (ou o percentual vigente no contrato de franquia, que pode cair para 2,5%; no OTA, 2%: D12, decidido 2026-10-16) é **cobrado do franqueado**, que o paga com a comissão da franquia recebida do proprietário, pela casalivre, com NFS-e no **item 17.08**. Nunca é cobrado do proprietário: cobrado dele, deixaria de ser royalty e viraria comissão 10.05, com natureza errada (CTN art. 118; risco alto).
- Na fatura do proprietário **não aparece linha de royalty**. O royalty entra numa **fatura mensal casalivre → franqueado**, calculada sobre as reservas da franquia concluídas no mês e sobre os valores retidos em cancelamentos com reembolso parcial definitivos no mês. Reembolso total não gera royalty (critério 13).
- ISS sobre franquia: STF, RE 603.136 (Tema 300, 2020) [CONFERIR].
- Contrato de franquia e COF (Lei 13.966/2019 art. 2º): royalty, base, vencimento e emissão da NFS-e pela casalivre [CONFERIR o texto do art. 2º].

### A.4 Fundo regional
- O franqueado fatura o fundo **dentro da comissão total da franquia (10% no padrão)** na NFS-e dele (o fundo integra o preço do serviço do franqueado) e **repassa ao fundo** o percentual vigente no contrato de franquia (3% no padrão), por obrigação desse contrato.
- O repasse vai para uma **conta bancária segregada do fundo**, e o **extrato do repasse** alimenta o extrato do fundo regional (UC-A21; prestação de contas trimestral, formato [pendente: Negócios]).
- A casalivre **não retém** o fundo, não atua como mandatária e não cobra o fundo do proprietário (rejeitado; parecer item 51).
- O tratamento contábil do repasse no franqueado é dele [CONFERIR com o contador do franqueado].

### A.5 CRECI
- **Franqueado e parceiro:** CRECI ativo é obrigatório. No cadastro do recebedor (seção 1), número do CRECI + consulta (J8), com data da consulta e situação. Nova consulta a cada 12 meses e antes do 1º fechamento do ano.
- **CRECI inativo ou não encontrado:** o recebedor fica **bloqueado como emissor da cobrança**. Ele não recebe parcela nova, os anúncios do nó deixam de aceitar reservas novas e o gestor e o admin_plataforma são avisados. As reservas confirmadas são mantidas.
- **casalivre no CRECI-RJ** (PJ com corretor responsável; Lei 6.530/1978 art. 6º [CONFERIR]): [pendente: Rafael]. Risco médio no modelo direto enquanto não houver inscrição.
- A casalivre não usa "imobiliária" nem "corretora" na marca nem nos anúncios.

### A.6 IBS e CBS (EC 132/2023; LC 214/2025)
- **2026:** ano de teste. A NFS-e nacional traz o destaque de CBS 0,9% e IBS 0,1%, compensáveis com PIS/Cofins, sem custo efetivo para quem cumpre as obrigações acessórias [CONFERIR atos da Receita e do Comitê Gestor].
- **2027:** a CBS na alíquota cheia substitui PIS/Cofins em cada nota (comissão, royalty); o IBS segue em 0,1%. Sem redutor para intermediação nem para franquia [CONFERIR anexos da LC 214].
- **2029–2032:** o ISS cai gradualmente e o IBS sobe; em 2033 o ISS acaba.
- **Simples:** IBS/CBS recolhidos pelo DAS, com crédito limitado ao tomador, salvo opção pelo regime regular (LC 214 art. 41).
- **Responsabilidade solidária** da plataforma quando o fornecedor não emite documento fiscal (LC 214 art. 22, redação da LC 227/2026). Por isso o ledger guarda, por reserva e por emissor: anfitrião, documento, valor bruto, parcelas e datas.
- Reverificação: 15/12/2026 e 01/07/2027.

### A.7 CPF do tomador, do locatário e DIMOB (verificado na web em 16/10/2026)
- O CPF/CNPJ do tomador (proprietário; e franqueado no royalty) e, para a DIMOB, o **do locatário (hóspede que reserva)** ficam **só em `fiscal_parties.cpf_enc`** (spec de schema rev. 3, §2.2). Telas e faturas mostram o documento mascarado.
- **Purga:** em **1º de janeiro do ano do último evento fiscal + 7** (fuso America/Sao_Paulo; ex.: último pagamento em 2026 → 01/01/2033). Cobre os 6 anos corridos da guarda fiscal e a DIMOB. Um legal hold ativo suspende a purga.
- **DIMOB:** a **IN RFB 1.115/2010 está em vigor**, alterada pela IN RFB 2.218/2024 (17/09/2024, só as multas). **Não encontrei** revogação nem substituição por EFD-Reinf ou por uma "DIMOB de plataformas".
  - **Quem entrega:** pessoas jurídicas que **intermediarem aquisição, alienação ou aluguel de imóveis**, que sublocarem, ou que administrarem/locarem patrimônio próprio (IN 1.115 art. 1º). Não há exceção para locação por temporada nem para plataforma digital. A Receita diz que a declaração vale para "locações formalizadas por contrato com identificação das partes" (P&R nº 19). **Conclusão mais provável: a casalivre, como PJ que intermedeia aluguel, é obrigada**, inclusive no piloto, e os franqueados PJ também, pela parte deles. Na Câmara, o REQ 16/2025 (CDC) diz que hoje as plataformas não são obrigadas e que a Receita estuda uma obrigação própria: **a questão é controversa** [CONFERIR com uma consulta formal à Receita, ou aceitar o risco: decisão do Rafael].
  - **Dados exigidos na locação:** locador e **locatário** com nome e **CPF/CNPJ** (CPF obrigatório; "NDP" só para estrangeiro não domiciliado; P&R nº 28 a 31), número e data do contrato (o número é opcional), e, **por mês**, o rendimento bruto pago pelo locatário, a comissão e o imposto retido (P&R nº 20 a 23, 33). Um imóvel com vários locadores gera uma linha por locador.
  - **Prazo:** último dia útil de fevereiro do ano seguinte. A multa por informação omitida ou inexata é de 3% do valor, com mínimo de R$ 100 (MP 2.158-35/2001 art. 57 III; redução de 70% no Simples, §1º).
  - **Fontes:** gov.br/receitafederal (DIMOB, Perguntas e Respostas; acesso em 16/10/2026): https://www.gov.br/receitafederal/pt-br/assuntos/orientacao-tributaria/declaracoes-e-demonstrativos/dimob/perguntas-e-respostas ; serviço "Declarar atividades imobiliárias": https://www.gov.br/pt-br/servicos/declarar-atividades-imobiliarias ; IN RFB 2.218/2024 (texto reproduzido em ibet.com.br e cenofisco.com.br; o normas.receita.fazenda.gov.br não carregou pelo fetch) [CONFERIR o texto consolidado no normas.receita.fazenda.gov.br].
- **Consequência:** a modelagem continua valendo: o **CPF do hóspede que reserva** é coletado e guardado em `fiscal_parties`, com o mesmo prazo (base: LGPD art. 7º II, obrigação legal). **A entrega da DIMOB segue pendente do Rafael** (A.10) [CONFERIR]. O valor do aluguel, as datas e a comissão saem do ledger.

### A.7b NFS-e Nacional: identificação do tomador (verificado em 16/10/2026)
- Leiaute em produção: DPS v1.01 (esquemas XSD v1.01). Atualização da Reforma: **NT SE/CGNFS-e nº 009 v1.01 (01/10/2026)** com o Anexo VI de leiaute v1.04.01, oficializada pelo Ato Técnico Conjunto RFB/SUFIS/CGIBS nº 7 (28/09/2026), e a NT 010 (Evento de Manifestação do Adquirente). Fonte: https://www.gov.br/nfse/pt-br/biblioteca/documentacao-tecnica/rtc (atualizada em 02/10/2026); NT 009 v1.01: https://www.gov.br/nfse/pt-br/biblioteca/documentacao-tecnica/rtc/nota-tecnica-009-se-cgnfs-e-v-1-01.pdf .
- **Grupo do tomador (`toma`):** opcional no XSD (minOccurs=0). Quando é informado, o documento é escolhido entre CNPJ, **CPF**, NIF ou motivo de não ter NIF. Para pessoa física residente, o CPF é o identificador. Fonte do minOccurs: documentação de terceiro que cita o XSD v1.01 (docs.engineapi.com.br/api-reference/campos-nfse, revisada em 15/08/2026) [CONFERIR no Anexo I/XSD oficial].
- **2026 (NT 009):** `indDest` e `dest` passam para a estrutura principal da DPS. O endereço/município do tomador e do destinatário **é validado contra o cadastro CPF/CNPJ** e a DPS é rejeitada se divergir. Não encontrei regra que torne o tomador obrigatório em toda nota de serviço [CONFERIR as regras de negócio do Anexo VI v1.04.01].
- **Decisão:** a casalivre **sempre identifica o tomador** pelo CPF/CNPJ e pelo município do cadastro na Receita. Motivos: a DIMOB exige o CPF; o crédito de IBS/CBS do tomador depende da identificação; e a validação de endereço da NT 009 rejeita município errado.

### A.8 Guarda das NFS-e
- XML e PDF de cada NFS-e emitida, o espelho da fatura e o comprovante da baixa: **6 anos corridos** (CTN arts. 173 e 174; item 16), estendidos enquanto houver auto de infração, processo ou legal hold.
- Imutáveis depois de emitidos; cancelamento ou substituição só por novo documento vinculado.

### A.9 Critérios de aceite (fiscais)
11. Cada fatura e NFS-e tem um único emissor e contém só as parcelas dele; a NFS-e da casalivre ao proprietário nunca traz parcela de fundo, franqueado ou royalty.
12. A NFS-e da comissão sai com o item 10.05; a do royalty, com 17.08 e o franqueado como tomador. Uma regra que configure royalty com tomador proprietário é recusada ao salvar o modelo de split.
13. O royalty do mês é de **3% (ou o percentual vigente no contrato de franquia; no OTA, 2%, D12 decidido 2026-10-16)** sobre (a) as reservas `fora` da franquia **concluídas** no mês e (b) os **valores retidos em cancelamentos com reembolso parcial (ou sem reembolso devido pela política) que ficaram definitivos no mês**, numa fatura casalivre → franqueado. **Reembolso total não gera royalty** (regra da P9). Um reembolso é **definitivo** no primeiro destes eventos: (i) o hóspede confirma que recebeu; (ii) passam 72 h sem contestação depois de o anfitrião enviar o comprovante; ou (iii) a disputa é decidida. Um cancelamento sem reembolso devido pela política é definitivo na data do cancelamento, desde que não haja disputa aberta. **Enquanto houver disputa aberta, nada é definitivo.** No OTA, a base são as **diárias brutas da reserva OTA**, excluídas a taxa de limpeza, as taxas e comissões da OTA e os impostos ou taxas municipais; se a OTA cancelar com reembolso parcial, vale a mesma regra do valor retido. A NFS-e do franqueado ao proprietário é da comissão total da franquia (10% no padrão; 8% no OTA).
13a. Uma reserva com reembolso total não gera comissão, royalty nem nota. Na reserva cancelada com retenção parcial, as parcelas incidem só sobre o valor retido.
13b. A taxa de cancelamento do anfitrião sai como recibo ou nota de débito da casalivre, sem NFS-e, e nunca entra na fatura do franqueado.
14. O extrato do fundo mostra cada repasse do franqueado com data, valor e referência do extrato bancário; não existe lançamento de fundo recebido pela casalivre.
15. Um recebedor franqueado ou parceiro sem CRECI ativo não pode ser salvo como emissor; quando a consulta periódica mostra CRECI inativo, novas reservas do nó são bloqueadas e o aviso é registrado no log.
16. O CPF/CNPJ do tomador e do locatário só existe em `fiscal_parties`; não aparece em claro em log, export (exceto DIMOB e informe de IR do próprio titular) nem fatura.
17. O job de purga apaga o registro de `fiscal_parties` em 1º de janeiro do ano do último pagamento + 7 e pula registros com legal hold; o log registra só a contagem.
18. NFS-e, espelho e baixa ficam disponíveis por 6 anos corridos e não podem ser alterados nem apagados pelo usuário da aplicação.
19. O ledger permite exportar, por ano e por anfitrião, documento, valor bruto, parcelas e datas (LC 214 art. 22 e DIMOB).
20. Mês sem reservas `fora`: nenhuma NFS-e é emitida e nenhuma fatura é gerada.
21. A reserva confirmada tem o CPF do hóspede que reservou em `fiscal_parties` (modelagem decidida). O export DIMOB do ano, por locador × locatário, com o rendimento bruto e a comissão por mês, fica **[pendente: Rafael]**: só é ativado se ele aceitar a obrigação da DIMOB (A.7, A.10) [CONFERIR].
22. Toda NFS-e da casalivre identifica o tomador por CPF/CNPJ com o município do cadastro na Receita.

### A.10 Pendências do apêndice (substituem a anterior)
- [pendente: Rafael] Comissão do canal manual (e se a franquia cobra no manual).
- [decidido: Produto] Na parceria, o tomador da NFS-e da casalivre é configurável por modelo. O padrão é o proprietário: cada um fatura a sua parte ao proprietário. Se o contrato de parceria disser que o parceiro paga a casalivre, o tomador passa a ser o parceiro.
- [pendente: Rafael] Inscrição da casalivre no CRECI-RJ com corretor responsável (custo de anuidade e do responsável).
- [pendente: Rafael] DIMOB: aceitar a obrigação (coletar o CPF do hóspede e entregar em fevereiro) ou fazer uma consulta formal à Receita (A.7).
- [CONFERIR] Alíquota do ISS no município sede, código NBS, art. 6º da Lei 6.530, Tema 300 do STF, minOccurs do `toma` no XSD oficial e atos de 2026 do IBS/CBS.
