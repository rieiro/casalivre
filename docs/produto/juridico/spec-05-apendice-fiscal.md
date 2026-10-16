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
| Parceria | comissão casalivre | casalivre | proprietário, ou parceiro [pendente: Rafael] | parcela da casalivre | 10.05 |
| Franquia | corretor 4% + fundo 3% = 7% | franqueado | proprietário | 7% | 10.05 |
| Franquia | royalty 3% | casalivre | **franqueado** | 3% (base do contrato de franquia) | **17.08** (franquia) |
| Franquia | repasse do fundo 3% | — (sem NFS-e; repasse com extrato) | fundo regional | — | — |
| Manual | comissão do canal manual, se houver [pendente: Rafael] | casalivre (e franqueado, se a franquia cobrar no manual) | proprietário | valor da comissão | 10.05 |

Sem comissão no canal manual, não há nota. Mensalidade de software, se for adotada, é outro serviço (LC 116 1.03 ou 1.05) [CONFERIR com o município].

### A.3 Royalty
- O royalty de **3%** (2% ou 3% no OTA, D12) é **cobrado do franqueado**, pela casalivre, com NFS-e no **item 17.08**. Nunca é cobrado do proprietário: cobrado dele, deixaria de ser royalty e viraria comissão 10.05, com natureza errada (CTN art. 118; risco alto).
- Na fatura do proprietário **não aparece linha de royalty**. O royalty entra numa **fatura mensal casalivre → franqueado**, calculada sobre as reservas do mês da franquia.
- ISS sobre franquia: STF, RE 603.136 (Tema 300, 2020) [CONFERIR].
- Contrato de franquia e COF (Lei 13.966/2019 art. 2º): royalty, base, vencimento e emissão da NFS-e pela casalivre [CONFERIR o texto do art. 2º].

### A.4 Fundo regional
- O franqueado fatura os **3% do fundo dentro dos 7%** da NFS-e dele (o fundo integra o preço do serviço do franqueado) e **repassa ao fundo** por obrigação do contrato de franquia.
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

### A.7 CPF do tomador e DIMOB
- O CPF/CNPJ do tomador (proprietário, e franqueado no royalty) fica **só em `fiscal_parties`** (documento cifrado com chave própria e `cpf_hmac` UNIQUE; `users.fiscal_party_id` pode ser NULL). Telas e faturas mostram o documento mascarado.
- **Purga:** em **1º de janeiro do ano do último pagamento + 7** (ex.: último pagamento em 2026 → purga em 01/01/2033). Isso cobre os 6 anos corridos da guarda fiscal e a DIMOB. Um legal hold ativo suspende a purga.
- **DIMOB** (declaração de atividades imobiliárias à Receita; IN RFB 1.115/2010, que alcança a intermediação de locação [CONFERIR se a casalivre é obrigada como plataforma e a redação vigente]): o export anual usa `fiscal_parties`, o imóvel, os valores e as datas do ledger.

### A.8 Guarda das NFS-e
- XML e PDF de cada NFS-e emitida, o espelho da fatura e o comprovante da baixa: **6 anos corridos** (CTN arts. 173 e 174; item 16), estendidos enquanto houver auto de infração, processo ou legal hold.
- Imutáveis depois de emitidos; cancelamento ou substituição só por novo documento vinculado.

### A.9 Critérios de aceite (fiscais)
11. Cada fatura e NFS-e tem um único emissor e contém só as parcelas dele; a NFS-e da casalivre ao proprietário nunca traz parcela de fundo, franqueado ou royalty.
12. A NFS-e da comissão sai com o item 10.05; a do royalty, com 17.08 e o franqueado como tomador. Uma regra que configure royalty com tomador proprietário é recusada ao salvar o modelo de split.
13. O royalty do mês é igual à soma de 3% (ou o percentual da D12) das reservas `fora` concluídas da franquia no mês, numa fatura casalivre → franqueado.
14. O extrato do fundo mostra cada repasse do franqueado com data, valor e referência do extrato bancário; não existe lançamento de fundo recebido pela casalivre.
15. Um recebedor franqueado ou parceiro sem CRECI ativo não pode ser salvo como emissor; quando a consulta periódica mostra CRECI inativo, novas reservas do nó são bloqueadas e o aviso é registrado no log.
16. O CPF/CNPJ do tomador só existe em `fiscal_parties`; não aparece em claro em log, export (exceto DIMOB e informe de IR do próprio titular) nem fatura.
17. O job de purga apaga o registro de `fiscal_parties` em 1º de janeiro do ano do último pagamento + 7 e pula registros com legal hold; o log registra só a contagem.
18. NFS-e, espelho e baixa ficam disponíveis por 6 anos corridos e não podem ser alterados nem apagados pelo usuário da aplicação.
19. O ledger permite exportar, por ano e por anfitrião, documento, valor bruto, parcelas e datas (LC 214 art. 22 e DIMOB).
20. Mês sem reservas `fora`: nenhuma NFS-e é emitida e nenhuma fatura é gerada.

### A.10 Pendências do apêndice (substituem a anterior)
- [pendente: Rafael] Comissão do canal manual (e se a franquia cobra no manual).
- [decidido: Produto] Na parceria, o tomador da NFS-e da casalivre é configurável por modelo. O padrão é o proprietário: cada um fatura a sua parte ao proprietário. Se o contrato de parceria disser que o parceiro paga a casalivre, o tomador passa a ser o parceiro.
- [pendente: Rafael] Inscrição da casalivre no CRECI-RJ com corretor responsável (custo de anuidade e do responsável).
- [CONFERIR] Alíquota do ISS no município sede, código NBS, art. 6º da Lei 6.530, Tema 300 do STF, obrigatoriedade da DIMOB para a plataforma e atos de 2026 do IBS/CBS.
