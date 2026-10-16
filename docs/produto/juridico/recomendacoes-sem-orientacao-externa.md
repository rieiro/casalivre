# casalivre: recomendações jurídicas e tributárias sem orientação externa

Bot Jurídico, 16/10/2026. Complementa `parecer-J1-J35.md`.

> **Aviso.** Este documento **não substitui advogado (OAB) nem contador (CRC)**. O Rafael decidiu seguir sem profissional externo e **assumiu esse risco** (decisão comunicada pelo Gerente Geral em 16/10/2026). Aqui está a resposta mais provável, com base em normas conferidas na web nesta data. O que não consegui confirmar está marcado como **[CONFERIR]**.
>
> **Gatilhos para contratar um profissional mesmo assim:**
> 1. Abertura da empresa ou troca de regime (CNAE, Simples ou Presumido, inscrição no IBS/CBS): contador.
> 2. Primeira autuação, notificação fiscal ou intimação da prefeitura ou da Receita: contador e advogado tributarista.
> 3. Primeiro processo judicial, reclamação no Procon ou na ANPD, ou ofício do MP: advogado.
> 4. Volume de repasse (fatura única ou recursos de terceiros) acima de cerca de R$ 50 mil/mês, ou entrada de PSP com split: advogado regulatório (BACEN).
> 5. Assinatura do primeiro contrato de franquia ou entrega da primeira COF: advogado de franquias.
> 6. Incidente de segurança com dados pessoais (LGPD art. 48): advogado.
> 7. Receita bruta perto de R$ 3,6 milhões/ano (sublimite do ISS no Simples) ou expansão para fora da Região dos Lagos.

Escala de risco: **Alto** = provável autuação, multa ou nulidade, com impacto relevante. **Médio** = possível, com impacto moderado. **Baixo** = improvável ou de impacto pequeno.

---

## 1. Reforma Tributária: LC 214/2025 (IBS/CBS)

| Tema | Resposta mais provável | Base |
|---|---|---|
| Cronograma | 2026: teste com CBS de 0,9% e IBS de 0,1%, compensáveis com PIS/Cofins, sem custo efetivo para quem cumpre as obrigações acessórias. 2027: começa a CBS cheia, PIS/Cofins acabam e o IBS segue em 0,1%. 2029 a 2032: ISS e ICMS caem gradualmente (90/80/70/60%) e o IBS sobe. 2033: modelo pleno, sem ISS. | EC 132/2023 (20/12/2023), ADCT arts. 125 a 129; LC 214/2025 (16/01/2025), arts. 343 e seguintes **[CONFERIR os artigos das disposições transitórias]** |
| Comissão da casalivre (intermediação) | Serviço tributado pela alíquota padrão do IBS/CBS sobre a comissão, sem redutor. Enquanto estiver no Simples, a casalivre recolhe pelo DAS, salvo opção pelo regime regular (LC 214, art. 41). | LC 214, arts. 4º e 41; LC 123/2006 |
| Plataforma digital: responsabilidade | A plataforma responde solidariamente com o fornecedor residente quando ele não emite documento fiscal ou é contribuinte que não se inscreveu; ela precisa prestar as informações do § 5º. Redação alterada pela LC 227/2026 (13/01/2026). | LC 214, art. 22, II e §§ 1º, 5º e 10 (Planalto, conferido em 16/10/2026) |
| Locação por temporada | Locação residencial de até 90 dias ininterruptos feita por contribuinte do regime regular segue as **regras de hotelaria** (redutor de 40%), não as de locação (redutor de 70%). A pessoa física só é contribuinte se ultrapassar os limites do art. 251 (mais de 3 imóveis e receita de locação acima de R$ 240 mil/ano, valores corrigidos). Os §§ 1º e 2º foram incluídos pela LC 227/2026. | LC 214, arts. 251, 253, 261 e 277 a 281 |

- **Decisão:** a casalivre se cadastra como plataforma digital; emite documento fiscal da comissão em 100% dos casos; guarda e entrega à Receita ou ao Comitê Gestor os dados das operações intermediadas (anfitrião, CPF/CNPJ, valor, datas); avisa no contrato do anfitrião que ele pode virar contribuinte do IBS/CBS (art. 251) e que, nesse caso, a casa é tributada como hotelaria (art. 253).
- **Risco:** **Alto** a partir de 2027, por causa da solidariedade do art. 22 se o anfitrião contribuinte não emitir nota. **Baixo** em 2026.
- **Critério de aceite:** (a) o ledger registra por reserva o anfitrião, o documento, o valor bruto, a comissão e as datas; (b) o relatório anual por anfitrião pode ser exportado; (c) o contrato do anfitrião tem a cláusula de responsabilidade fiscal própria e de regresso.
- **Conferir:** o texto compilado da LC 214 no Planalto (lcp214.htm), os regulamentos da CBS (Receita Federal) e do IBS (Comitê Gestor), e as alíquotas de referência de 2027 (Resolução do Senado). **Reverificação: 15/12/2026 e 01/07/2027.**

## 2. ISS: código, alíquota, NFS-e e municípios

- **Código LC 116:** **item 10.05** ("agenciamento, corretagem ou intermediação de bens móveis ou imóveis, não abrangidos em outros itens ou subitens"). O item 10.02 trata de títulos, valores mobiliários e contratos, e **não cabe**. A locação em si não é serviço e não paga ISS (Súmula Vinculante 31/STF), mas a hospedagem (item 9.01) é. Por isso, a casalivre fatura **só a intermediação**.
- **Alíquota:** mínima de 2% e máxima de 5% (LC 116/2003, art. 8º, II, e art. 8º-A). A do item 10.05 em Cabo Frio, Búzios e Arraial do Cabo está **[CONFERIR]** no Código Tributário de cada município. **Não encontrei a alíquota na web**; o mais provável é que fique entre 2% e 5%.
- **Local da incidência:** em regra, o estabelecimento do prestador, ou seja, o município sede da casalivre (LC 116, art. 3º). A intermediação imobiliária não está entre as exceções.
- **NFS-e:** emitir pelo padrão **NFS-e Nacional**. A LC 214 obriga os municípios a aderir ao padrão nacional a partir de 2026 **[CONFERIR o artigo e a adesão do município sede no portal nfse.gov.br]**. MEI e Simples já usam o emissor nacional.
- **Municípios (verificado na web em 16/10/2026):**
  - **Cabo Frio:** há um cadastro **voluntário** de imóveis de temporada no inventário turístico da Secretaria de Turismo (turismo.cabofrio.rj.gov.br/inventario, aberto em 2021) e a Lei Municipal 4.382/2025 (03/01/2025), sobre a política de turismo sustentável. **Não encontrei** cadastro obrigatório nem taxa por hóspede. [CONFERIR o texto da Lei 4.382/2025.]
  - **Búzios:** PLC 22/2025, Taxa de Turismo Sustentável de R$ 14,60 por hóspede, cobrada de 20/12 até 5 dias depois do Carnaval. Na notícia oficial, foi aprovado em 1º turno. **Não confirmei** a sanção, o número da lei nem quem recolhe (hospedagem ou anfitrião). [CONFERIR em buzios.rj.gov.br e na Câmara.]
  - **Arraial do Cabo:** encontrei só a taxa de veículos de turismo (Decreto 3.506/2021). **Não encontrei** cadastro nem taxa para locação por temporada.
- **Decisão:** NFS-e de intermediação no item 10.05, no município sede. Até confirmar as normas municipais, um campo no anúncio para "cadastro municipal / taxa de turismo" e um aviso ao anfitrião de que cumprir essas regras é obrigação dele.
- **Risco:** **Médio**. Se a taxa de Búzios estiver em vigor e couber à hospedagem, o risco do anfitrião passa a ser alto.
- **Critério de aceite:** NFS-e com o código 10.05 e a alíquota do município sede; o anúncio mostra o aviso municipal quando houver; o contrato do anfitrião atribui a ele o cadastro e a taxa local.
- **Reverificação: 01/12/2026** (antes da alta temporada) e a cada janeiro.

## 3. Simulação de regimes (estimativa; não substitui cálculo contábil)

Premissas: alíquotas nominais da LC 123/2006 (Anexos III e V, art. 18), ISS de 5% no Presumido, IRPJ e CSLL presumidos a 32%, PIS/Cofins cumulativos (3,65%) e sem considerar a transição do IBS/CBS. **Os valores são aproximados.**

**casalivre (serviço de intermediação/plataforma)**

| Receita bruta 12 m | Simples Anexo III (fator R ≥ 28%) | Simples Anexo V (fator R < 28%) | Lucro Presumido* |
|---|---|---|---|
| até R$ 180 mil | ~6,0% | ~15,5% | ~16,3% |
| R$ 360 mil | ~8,5% | ~16,9% | ~16,3% |
| R$ 720 mil | ~11,0% | ~17,6% | ~16,3% a 16,9% |
| R$ 1,8 milhão | ~14,0% | ~19,1% | ~17,0% |
| R$ 3,6 milhões | ~16,4% | ~20,6% | ~17,2% |

*Presumido: IRPJ 4,8% (mais o adicional de 10% sobre o que passar de R$ 60 mil por trimestre), CSLL 2,88%, PIS/Cofins 3,65% e ISS de 2% a 5%.

- **Decisão:** **Simples com fator R ≥ 28%** (pró-labore e folha somando pelo menos 28% da receita) é o mais barato até cerca de R$ 3,6 milhões. Sem folha, fica no Anexo V, e a partir de R$ 360 mil o Presumido empata ou fica mais barato. Se o fator R ficar abaixo de 28%, refazer a conta. O enquadramento no Anexo III ou V depende do CNAE e do fator R (LC 123, art. 18, §§ 5º-J e 5º-M) **[CONFERIR na Resolução CGSN 140/2018, Anexo VI]**.
- **Franqueados (corretores):** **MEI não cabe** (a corretagem de imóveis não está nas ocupações permitidas, Resolução CGSN 140/2018, Anexo XI). Para a pessoa física, o carnê-leão segue a tabela progressiva, com até 27,5% de IRPF mais ISS como autônomo, o que pesa acima de cerca de R$ 5 mil/mês. Para a PJ no Simples, a corretagem de imóveis fica no Anexo III, com ~6% na faixa inicial **[CONFERIR LC 123, art. 18, § 5º-B]**. **Recomendação:** corretor com mais de cerca de R$ 4 a 5 mil/mês de comissão deve abrir uma PJ no Simples.
- **Risco:** **Médio** (por erro de enquadramento ou fator R mal calculado).
- **Critério de aceite:** no fechamento mensal, o fator R é calculado e registrado; a escolha do regime é revista em janeiro; o contrato de franquia obriga o franqueado a ter CRECI e regime fiscal próprio.
- **Reverificação:** em janeiro de cada ano (opção pelo Simples até o último dia útil de janeiro) e quando chegar a 2027 (Simples × IBS/CBS "por fora", LC 214, art. 41).

## 4. Fundo regional de propaganda (franquia M13)

- **Decisão (consolidada):** o franqueado emite ao proprietário NFS-e da **comissão total da franquia, 10% no padrão** (item 10.05). Desses 10%, ele fica com 4% de corretagem, paga **3% de royalty** à casalivre (NFS-e da casalivre ao franqueado, item 17.08) e repassa **3% ao fundo** por obrigação do contrato de franquia (conta segregada, sem NFS-e). Os percentuais são parâmetros do modelo; o royalty segue o percentual vigente no contrato de franquia. **Rejeitado:** cobrar o fundo diretamente do proprietário por mandato. O proprietário não participa da relação de franquia, então a cobrança seria tratada como preço do serviço (CTN, art. 118, que manda considerar a natureza do fato e não a forma; LC 116, art. 7º, base de cálculo pelo preço do serviço). O risco disso é **alto**, com multa de ofício.
- **Lei 13.966/2019 (26/12/2019):** o art. 2º, VIII, exige que a COF informe as taxas periódicas, inclusive o fundo de propaganda; o art. 2º, § 1º, exige que a COF seja entregue pelo menos 10 dias antes da assinatura ou de qualquer pagamento; o art. 2º, § 2º, prevê anulabilidade e devolução dos valores se isso não for cumprido. A lei **não exige** conta segregada nem uma forma específica de prestação de contas; isso fica no contrato **[CONFERIR o texto do art. 2º no Planalto]**.
- **Recomendado no contrato e na COF:** valor (3% da comissão), base, vencimento, finalidade, **conta bancária segregada** em nome da casalivre como gestora, prestação de contas pelo menos semestral com extrato e notas, conselho consultivo de franqueados (opcional) e regra para o saldo quando o contrato terminar.
- **Tratamento na casalivre:** o mais provável é que o fundo recebido do franqueado entre como receita da casalivre e seja tributado, salvo se o contrato der a ela o papel de mera gestora com conta segregada e aplicação integral do dinheiro. Mesmo assim, o tratamento fiscal é controverso. **Risco médio.**
- **Critério de aceite:** a COF e o contrato trazem o fundo; o ledger mostra o fundo separado; a prestação de contas é publicada a cada semestre; o recibo de entrega da COF é guardado com data.
- **Reverificação:** antes da primeira COF (aqui vale o gatilho 5).

## 5. Revisão dos 3 termos (D14) antes do piloto

**Situação encontrada:** os 3 termos (**Contrato de locação por temporada**, **Termo de serviço de limpeza** e **Termos de uso da plataforma**; spec-01 §Termos e spec-05) existem **só como placeholder** ("[Texto do termo, versão a definir na D14]"). A política de cancelamento (spec-03) e a de privacidade **também não têm texto**. Lacuna: **100% do texto ainda precisa ser escrito**; as cláusulas abaixo vêm de `parecer-J1-J35.md` e das specs 01, 03, 05 e 09.

**A. Termos de uso da plataforma**
- [ ] Identificação da casalivre: razão social, CNPJ, endereço e e-mail (Decreto 7.962/2013, art. 2º, I a III).
- [ ] Papel de intermediária: a locação é entre o anfitrião e o hóspede, e a casalivre responde pelo que lhe cabe como fornecedora (CDC, arts. 7º, parágrafo único, 14 e 25; não usar cláusula de isenção total, que é nula pelo art. 51, I).
- [ ] **Piloto:** "o pagamento é feito diretamente ao anfitrião, pela chave Pix exibida na reserva, vinculada a um recebedor com identidade verificada (KYC). A casalivre não recebe esse valor. Não pague por outra chave nem fora da reserva. Canal de denúncia: [link]". Mostrar também **no checkout**, não só no termo.
- [ ] Preço total destacado, com diárias, limpeza e taxas antes do pagamento (CDC, arts. 6º, III, e 31; Decreto 7.962, art. 2º, IV e V; Decreto 5.903/2006).
- [ ] Resumo do contrato antes da contratação, confirmação imediata do aceite e meio para corrigir erros (Decreto 7.962, art. 4º, I a III).
- [ ] Atendimento eletrônico com resposta em até 5 dias (Decreto 7.962, art. 4º, V e parágrafo único).
- [ ] Arrependimento (CDC, art. 49; Decreto 7.962, art. 5º): explicar o meio de exercer o direito. Regra adotada: 100% em 7 dias a partir da reserva. Exceção para check-in em menos de 7 dias: a tese é controversa e não está pacificada no STJ; a recomendação do parecer J é manter o prazo de 7 dias, valendo até o check-in, conforme `parecer-J1-J35.md`.
- [ ] Suspensão ou bloqueio por fraude, com prazo e canal de revisão; avaliações; moderação de mensagens; uso de IA no atendimento (aviso de que é automatizado e de que é possível pedir atendimento humano; LGPD, art. 20).
- [ ] Foro do domicílio do consumidor (CDC, art. 101, I) e versão com data e registro da versão aceita.

**B. Contrato de locação por temporada (anfitrião × hóspede; a casalivre fornece o modelo)**
- [ ] Partes, imóvel, mobília e utensílios **descritos** (Lei 8.245/91, art. 48, parágrafo único).
- [ ] Prazo de até 90 dias e finalidade de temporada (art. 48, caput).
- [ ] Pagamento antecipado do aluguel e encargos e garantia permitida (art. 49).
- [ ] Vistoria de entrada e de saída e regra de danos.
- [ ] Regra de prorrogação tácita: passados 30 dias do fim do prazo sem oposição, a locação vira por prazo indeterminado (art. 50) **[CONFERIR: a redação é sobre o locatário que permanece por mais de 30 dias]**.
- [ ] Regras da casa e do condomínio em anexo, com aceite próprio (o STJ admite que a convenção proíba aluguel por aplicativo: REsp 1.819.075/RS, 2021 **[CONFERIR]**).
- [ ] Hóspedes nominados; envio dos documentos à portaria quando exigido; declaração de maioridade e de responsabilidade pelos menores (ECA, arts. 82 e 83; LGPD, art. 14).
- [ ] Política de cancelamento escolhida, com as datas calculadas; reembolso pelo anfitrião em **5 dias úteis**.

**C. Termo de serviço de limpeza**
- [ ] Prestador, valor e que o pagamento vai direto ao prestador (D13); devolução da limpeza não usada em caso de cancelamento.
- [ ] O que está incluído, horário e responsabilidade por danos.

**D. Contrato plataforma × anfitrião (fora dos 3, mas obrigatório antes do piloto)**
- [ ] **KYC obrigatório** (documento, selfie com finalidade antifraude e prova de titularidade do imóvel ou autorização), e chave Pix **com o mesmo CPF/CNPJ** do recebedor verificado.
- [ ] Obrigações fiscais próprias (carnê-leão, IBS/CBS do art. 251, cadastro e taxa municipal).
- [ ] Comissão, vencimento, multa de 2%, juros de 1% ao mês pro rata e IPCA; suspensão após aviso.
- [ ] Taxa de cancelamento de 10%, limitada a R$ 300, a partir do 2º cancelamento em 12 meses, com isenção por força maior (CC, art. 393).
- [ ] Reembolso ao hóspede em 5 dias úteis, sob pena de suspensão; o anfitrião responde perante a casalivre (direito de regresso).

**E. Política de privacidade (LGPD, arts. 6º, 9º e 18)**
- [ ] Controlador, encarregado (DPO) e canal; finalidades e bases legais por dado; tabela de retenção; terceiros (PSP, BrasilAPI, condomínio); direitos do titular e prazo de resposta; transferência internacional, se houver.

- **Risco:** **Alto**. Abrir o piloto sem esses textos e sem o KYC deixa a casalivre exposta a responsabilidade solidária por golpe de Pix (CDC, arts. 7º e 14) e a autuação pelo Procon (Decreto 7.962).
- **Critério de aceite:** os 3 termos, o contrato do anfitrião, a política de privacidade e a de cancelamento estão publicados com versão e data; todos os itens acima estão marcados; o checkout mostra o aviso de pagamento fora da plataforma; o piloto só abre com 100% dos anfitriões aprovados no KYC e o canal de denúncia funcionando.
- **Reverificação:** antes da S7 e a cada mudança de funcionalidade de pagamento.

## 6. Marco Civil, art. 15: guarda de logs de acesso

- **Decisão (validada à Segurança):** guardar os registros de acesso a aplicações (data, hora, IP e **porta de origem**) por **6 meses**, sob sigilo e em ambiente seguro, **incluindo acessos anônimos**. Os 6 meses também são o **teto**, salvo: pedido de autoridade policial, administrativa ou do MP para guardar por mais tempo (art. 15, § 2º, com 60 dias para entrar com o pedido judicial, § 4º), ordem judicial (§ 3º e art. 22) ou legal hold aberto para defesa em processo. Entrega só com ordem judicial (art. 10, § 1º).
- **Porta de origem:** o STJ, no REsp 1.784.156/SP (3ª Turma, Rel. Min. Marco Aurélio Bellizze, julgado em 05/11/2019), entendeu que o provedor de aplicação deve guardar e fornecer a porta lógica de origem. **[CONFERIR a ementa no portal do STJ]**.
- **Técnica:** IP e porta **cifrados de forma reversível** (por exemplo, AES-GCM com chave em cofre e rotação), mais um índice HMAC para busca; a decifração só acontece com ordem judicial registrada, com dupla aprovação e trilha de auditoria. Um HMAC sozinho não basta, porque não permite cumprir a ordem de entrega.
- **Risco:** **Médio** (multa e sanções do art. 12 se não houver guarda ou se os dados não puderem ser entregues).
- **Critério de aceite:** a retenção de 6 meses é aplicada por job com exceção de legal hold; o teste de decifração com ordem fictícia é registrado em auditoria; o acesso à chave é restrito e logado; a guarda de logs aparece na política de privacidade.
- **Reverificação:** 01/04/2027 (jurisprudência do STJ e eventual regulamentação).

---

### Resumo das reverificações
| Data | O quê | Onde |
|---|---|---|
| 01/12/2026 | Taxa de Búzios, normas de Cabo Frio e Arraial do Cabo, alíquota do ISS 10.05 | prefeituras e Câmaras |
| 15/12/2026 | Regulamento da CBS e alíquotas de 2027 | Receita Federal, Planalto, Senado |
| 31/01/2027 | Opção pelo Simples e fator R | Receita (Portal do Simples) |
| antes do piloto | Termos D14, KYC e canal de denúncia | interno |
| antes da 1ª COF | Lei 13.966, art. 2º | Planalto |
| 01/04/2027 | Marco Civil e STJ | Planalto, STJ |
