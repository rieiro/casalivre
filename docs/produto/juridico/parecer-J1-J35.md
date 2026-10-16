# Parecer Jurídico — J1 a J35 + itens do Produto (16/10/2026)
Autor: Bot Jurídico do casalivre. Não substitui advogado (OAB) nem contador (CRC). Fontes: relatório da Segurança (`casalivre-seguranca-evidence/specs-ui-04-06/relatorio.md`), `casalivre-gerente/planejamento/sequencia-s4-s9.md`, specs 01–10, `prompts/regrasbots.md`.
Critério pedido pelo Produto: resposta mais provável, sem prudência excessiva. **[OAB]/[CRC]** = validar com profissional. **[Rafael]** = decisão de custo, escopo ou risco.
Onde conferir: Planalto (leis), Receita Federal (fiscal/CBS), BCB (arranjos de pagamento), ANPD (LGPD), prefeituras de Cabo Frio, Búzios, Arraial do Cabo etc. (ISS, cadastro, taxa de turismo).

Conciliação com o planejamento: o `sequencia-s4-s9.md` traz J1–J11 (pauta do Rafael) e J12–J16 (avaliações), além das propostas do Negócios (J5: selfie 30 dias; documentos durante a relação + 5 anos, citando chargeback de 540 dias, CDC 5 anos e COFECI 1.336/2014; J10: portaria até check-out + 90 dias). Não há conflito de numeração com o relatório. As propostas do Negócios estão tratadas em J5/J20 e J10/J21.

Leis de referência: LGPD = Lei 13.709/2018; MCI = Marco Civil, Lei 12.965/2014; CDC = Lei 8.078/1990; CC = Código Civil, Lei 10.406/2002; CTN = Lei 5.172/1966; Lei do Inquilinato = Lei 8.245/1991; ECA = Lei 8.069/1990; PLD = Lei 9.613/1998; Decreto 7.962/2013 (comércio eletrônico).

---
## A. Log e LGPD
### 1. J1 — Prazo de guarda do log por classe
- **Decisão:** aprovado por classe. (a) Eventos financeiros e fiscais (Pix, baixa, ledger, split, fatura): **5 anos contados de 1º de janeiro do ano seguinte ao fato**, para casar com o prazo fiscal (item 6). (b) Contrato e aceite de termos (versão, hash, data, IP do aceite): **duração do contrato + 5 anos**. (c) Administração (árvore, canais, tema, membros): **5 anos**, aceitável como prova (exercício regular de direitos). (d) Log de acesso: **6 meses** (J2).
- **Base legal:** LGPD art. 7º II e VI, art. 16 I; CTN arts. 173 e 174 e art. 195 parágrafo único (guarda de livros e documentos até a prescrição); CDC art. 27 (5 anos para fato do produto/serviço); CC art. 206 §5º I (5 anos para cobrar dívida líquida de instrumento) e art. 205 (10 anos, regra geral). 5 anos cobre o risco principal; os 10 anos do art. 205 não justificam guardar tudo.
- **Prazo:** como acima. Purga automática no vencimento.
- **Risco:** baixo.
- **Constar nos termos (D14):** tabela de retenção por categoria na Política de Privacidade.

### 2. J2 — Log de acesso além de 6 meses
- **Decisão:** 6 meses é o **mínimo** obrigatório para o provedor de aplicação (MCI art. 15). Estender só por (i) requisição de autoridade (art. 15 §2º, guarda cautelar), (ii) legal hold de um caso concreto (incidente, fraude, ação). Não estender de forma geral.
- **Base legal:** MCI art. 15, caput e §2º; Decreto 8.771/2016; LGPD art. 6º III (necessidade).
- **Prazo:** 6 meses; hold pelo tempo do caso.
- **Risco:** baixo.

### 3. J3 — Conservação (art. 16) × eliminação (art. 18 VI)
- **Decisão:** o pedido de eliminação **não** alcança os dados com obrigação legal ou necessários à defesa (art. 16 I e art. 18 VI, que remete ao art. 16). Atender assim: eliminar o que não tem base; para o log retido, **pseudonimizar** (desvincular do perfil, autor = id interno) e informar ao titular quais itens ficaram retidos, por qual motivo e até quando. Pseudonimizar basta para os logs de evento; os dados continuam pessoais (art. 13 §4º), então a segurança continua obrigatória.
- **Base legal:** LGPD arts. 7º VI, 13 §4º, 16 I e II, 18 VI.
- **Prazo:** resposta ao titular em até 15 dias (art. 19 II).
- **Risco:** baixo.
- **Constar nos termos (D14):** "Alguns dados são mantidos após a exclusão da conta para cumprir obrigações legais e defender direitos: [lista e prazos]."

## B. KYC dos recebedores
### 4. J4 — Selfie / biometria
- **Decisão:** a selfie comparada à foto do documento é dado biométrico, logo **sensível** (art. 5º II). Base: **art. 11 II "g"** (prevenção à fraude e segurança do titular em processos de identificação e autenticação de cadastro). **Não precisa de consentimento.** Requisitos: aviso claro na coleta (finalidade, quem vê, prazo de 30 dias), RIPD (art. 38), cifragem e acesso restrito (art. 46). Não usar para outra finalidade.
- **Base legal:** LGPD arts. 5º II, 11 II "g", 9º, 38, 46.
- **Prazo:** ver J5.
- **Risco:** médio (dado sensível; incidente gera dano maior).
- **Constar nos termos (D14):** aviso de privacidade do recebedor com a finalidade antifraude da selfie e a ressalva do art. 11 II "g" ("salvo prevalência de direitos e liberdades fundamentais").

### 5. J5 — Guarda dos documentos KYC (e COFECI/COAF)
- **Verificação:** a **Resolução COFECI 1.336/2014 existe** (altera a Res. COFECI 1.168/2010, conforme a Lei 9.613/98 com a redação da Lei 12.683/2012). Ela se aplica a quem atua com **promoção imobiliária ou compra e venda de imóveis** (Lei 9.613 art. 9º parágrafo único X), exige política de PLD, cadastro e registro de operações **≥ R$ 100 mil** com guarda de **5 anos**, comunicação ao COAF em 24 h de operação suspeita e declaração anual de não ocorrência (1º a 31/jan, no site do COFECI). Fontes: gov.br/coaf (texto da resolução) e cofeci.gov.br/comunicacao-de-nao-ocorrencia.
- **Decisão:** a locação por temporada **não** é, em regra, "compra e venda ou promoção imobiliária", e as operações ficam bem abaixo de R$ 100 mil. A COFECI 1.336 **não obriga a casalivre** a guardar a **imagem** do documento. Ela pode obrigar os **corretores franqueados** (que são inscritos no CRECI) se fizerem venda. Para a casalivre o argumento é a defesa em disputa e fraude e o cadastro do beneficiário dos repasses. Proposta aprovada com ajuste:
  - Selfie/prova de vida: **apagar em 30 dias** após a decisão. ✅
  - Documentos de aprovados: **resultado + metadados** (status, data, quem aprovou, hash, nome, CPF/CNPJ cifrado) por relação + 5 anos. A **imagem** do documento: aceitável guardar durante a relação + 5 anos com base no art. 7º VI e IX (prova em fraude/chargeback, cuja janela citada pelo Negócios é ~540 dias). Alternativa mais enxuta: imagem por relação + 2 anos. **[Rafael]** escolhe; a minha recomendação é relação + 5 anos só para o resultado e **relação + 2 anos para a imagem**.
  - Recusados: apagar imagens em 30 dias; manter resultado e motivo por 6 meses, ou 5 anos se a recusa for por fraude (vai para a lista de bloqueio, item 13).
  - Quando o PSP assumir o KYC (P6): rever; o PSP passa a ter obrigações próprias de PLD (Circular BCB 3.978/2020, guarda de 10 anos; **conferir** a norma vigente no BCB).
- **Base legal:** LGPD arts. 7º VI e IX, 11 II "g", 16 I; Lei 9.613/1998 arts. 9º e 10; Res. COFECI 1.336/2014.
- **Prazo:** como acima; "fim da relação" = evento `ended_at` definido.
- **Risco:** médio. **[OAB]** se houver venda de imóveis pelos franqueados.
- **Constar nos termos (D14):** contrato com o recebedor: obrigação de enviar documentos, finalidade, prazos e que o franqueado corretor responde pelas próprias obrigações de PLD/CRECI.

### 6. J6 — Ledger, informes de IR e notas fiscais
- **Decisão:** **5 anos a contar de 1º de janeiro do exercício seguinte** ao fato gerador (na prática, guardar 6 anos completos simplifica). Se houver processo fiscal em andamento, até o fim dele. Ledger: imutável, preservado após exclusão da conta. NFS-e: ver item 16.
- **Base legal:** CTN art. 173 I (decadência) e art. 174 (prescrição), art. 195 parágrafo único; Decreto 9.580/2018 (RIR) art. 278 (conservação de livros e documentos); LGPD art. 16 I.
- **Prazo:** 5 anos + exercício corrente (recomendo 6 anos corridos).
- **Risco:** baixo. **[CRC]** confirmar com o contador conforme o regime.

### 7. J7 — CPF/CNPJ para BrasilAPI e PSP
- **Decisão:** **CNPJ** na BrasilAPI: permitido (dado público da Receita); para PJ quase não há dado pessoal. **CPF** na BrasilAPI: evitar; ela é um projeto comunitário que repassa a consulta a terceiros, sem contrato de operador. Se usar, base art. 7º II/IX + art. 11 II "g" não se aplica (CPF não é sensível); enviar o mínimo e registrar no RIPD o terceiro. Recomendo **validar CPF só pelo dígito verificador + KYC manual** no piloto, e pela consulta do PSP na P6. **PSP:** compartilhar CPF/CNPJ é necessário à execução do contrato e ao cumprimento das obrigações do PSP (PLD do BCB): art. 7º II e V; exigir contrato com cláusula de proteção de dados (o PSP é controlador independente para o KYC dele).
- **Base legal:** LGPD arts. 7º II, V e IX, 10, 39 (operador), 26/27 (compartilhamento); Lei 9.613/1998.
- **Prazo:** —
- **Risco:** baixo (CNPJ, PSP); médio (CPF na BrasilAPI).
- **Constar nos termos (D14):** lista de categorias de terceiros (PSP, órgãos públicos de consulta) na Política de Privacidade.

### 8. J8 — Consulta ao CRECI
- **Decisão:** permitido. O cadastro de inscritos é público (Lei 6.530/1978; consulta nos sites dos CRECI). Base: art. 7º IX (verificar que o franqueado é corretor habilitado, interesse legítimo da plataforma e do proprietário) e art. 7º §4º (dado tornado manifestamente público). Guardar só o número, situação e data da consulta.
- **Base legal:** LGPD art. 7º IX e §§3º e 4º; Lei 6.530/1978 (exercício da profissão de corretor; intermediação imobiliária remunerada exige inscrição no CRECI, art. 3º).
- **Risco:** baixo.
- **Alerta [OAB][Rafael]:** se a casalivre cobra comissão por **intermediar locação**, pode ser vista como exercendo corretagem (Lei 6.530 art. 3º). Plataformas tipo marketplace sustentam que fazem "anúncio/tecnologia", não corretagem. Risco médio; ver modelo no item 14.

## C. Portaria e menores
### 9. J9 — Coletar e repassar documentos dos hóspedes à portaria
- **Decisão:** base **art. 7º V (execução do contrato)**: sem a lista, o hóspede não entra no condomínio, então é necessário ao uso do imóvel locado. Subsidiariamente art. 7º IX (segurança do condomínio). O condomínio é **controlador independente** do que recebe (aplica o regulamento interno). Coletar só os campos exigidos pela portaria daquele imóvel (nome, documento; foto do documento só se o condomínio exigir). Repassar por canal controlado (link expirável ou envio pelo anfitrião), não por WhatsApp aberto se evitável.
- **Base legal:** LGPD arts. 6º III, 7º V e IX; CC arts. 1.333 e 1.334 (convenção e regimento interno obrigam ocupantes); Lei 8.245/1991 art. 23 X (locatário cumpre a convenção e o regulamento).
- **Risco:** baixo.
- **Constar nos termos (D14):** termo do hóspede: "Os nomes e documentos dos hóspedes podem ser enviados ao condomínio para controle de acesso, quando exigido."

### 10. J10 — Guarda após check-out e ficha de hóspede (Lei 11.771/2008)
- **Decisão:** a **FNRH** (ficha nacional de registro de hóspedes) da Lei 11.771/2008 (art. 26) obriga **meios de hospedagem** (art. 23, com prestação de serviços de hospedagem). A **locação por temporada** (Lei 8.245 art. 48) é contrato de locação, **não** meio de hospedagem: a ficha **não se aplica**, salvo se o anfitrião prestar serviços típicos de hotelaria (limpeza diária, recepção, café). Prazo: ver J21 (90 dias).
- **Base legal:** Lei 11.771/2008 arts. 23 e 26; Lei 8.245/1991 arts. 48 a 50. Atenção: há projetos de lei e normas municipais tentando regular aluguel por aplicativo — **conferir** na prefeitura de cada cidade da Região dos Lagos (cadastro do imóvel, taxa de turismo).
- **Risco:** baixo (federal); **médio** (municipal, a verificar).

### 11. J11 — Dados de menores
- **Decisão:** coletar dado de criança/adolescente **só quando a portaria exigir** e no **melhor interesse** (segurança e acesso). O responsável que faz a reserva informa os dados. O STJ e a ANPD admitem bases do art. 7º/11 para menores (Enunciado CD/ANPD nº 1/2023: dados de crianças e adolescentes podem ser tratados com as bases legais do art. 7º ou 11, observado o melhor interesse) — **conferir** o enunciado no site da ANPD. Consentimento específico do responsável **não** é obrigatório nesse caso; base: execução do contrato do responsável. ECA: menor de 16 anos viajando desacompanhado precisa de autorização (ECA art. 83, redação da Lei 13.812/2019); a plataforma pode exigir declaração do adulto de que acompanha os menores.
- **Base legal:** LGPD art. 14 caput e §6º; ECA arts. 82 (proibição de hospedagem de criança/adolescente desacompanhado em hotel, pensão, motel ou congênere sem autorização) e 83.
- **Risco:** médio.
- **Constar nos termos (D14):** "Quem reserva declara ser maior de 18 anos e responsável pelos menores hospedados, e que eles estarão acompanhados por pais ou responsáveis ou autorizados na forma do ECA."

## D. Cadastro e conta
### 12. Lista de bloqueio (hash de CPF/telefone) — ver também J27
- **Decisão:** **válido** com **HMAC com chave secreta** (hash simples não serve; continua dado pessoal, art. 12 e 13 §4º). Base: **art. 7º IX** (legítimo interesse: segurança da plataforma e dos anfitriões), com teste de balanceamento (LIA) documentado (art. 10). O art. 11 II "g" só se houver biometria. Prazos: o da punição; **5 anos em fraude** (alinhado à prescrição civil/CDC). Guarda permanente: **não**. Garantir: aviso de que existe a lista, revisão humana a pedido (art. 20), direito de oposição analisado (art. 18 §2º), resposta em 15 dias (ver item 32).
- **Base legal:** LGPD arts. 7º IX, 10, 18 §2º, 20; CDC art. 43 (por analogia: banco de dados de consumidor, informação negativa limitada a 5 anos, §1º).
- **Prazo:** punição; fraude 5 anos.
- **Risco:** médio.
- **Constar nos termos (D14):** cláusula de suspensão/bloqueio por violação ou fraude, com prazo e canal de revisão.

## E. Fatura de comissão (P9)
### 13. Fatura única com repasse — risco BACEN
- **Decisão:** cobrar a comissão inteira e repassar ao franqueado e ao fundo **é recebimento de recursos de terceiros**. Isso **não** faz da casalivre uma instituição de pagamento autorizada só por isso: a Lei 12.865/2013 (art. 6º) e a Resolução BCB 80/2021 tratam de quem presta serviços de pagamento (aporte/saque de conta de pagamento, gestão de pagamento, credenciamento etc.); a Res. BCB 80/2021 prevê dispensa de autorização abaixo de limites de volume (art. 4º? — **conferir** artigo e valores no site do BCB; a regra de dispensa por volume existe, os números exatos devem ser verificados). Com o volume do piloto, o risco regulatório é **baixo**, mas o risco **tributário e contábil** é real: o valor do franqueado e do fundo entra na conta da casalivre e pode ser tratado como **receita bruta** (tributando PIS/Cofins/ISS/IRPJ sobre o que não é dela) se não houver contrato e documento de repasse claros.
- **Recomendação:** **faturas separadas por emissor** (casalivre fatura a parte dela; o franqueado fatura a dele; o fundo, se for pessoa jurídica ou conta da franqueadora, a sua). A tela já suporta. Se o Rafael preferir fatura única: contrato de mandato/cobrança em nome dos franqueados (CC arts. 653 e seguintes), repasse documentado e o contador tratando os valores de terceiros como não receita.
- **Base legal:** Lei 12.865/2013 arts. 6º e 9º; Res. BCB 80/2021; Res. BCB 150/2021 (arranjos; conferir); Lei 9.718/1998 art. 3º (receita bruta); CC art. 653.
- **Risco:** médio (tributário); baixo (BACEN no piloto); **alto** se o PSP com split não for usado na P6 e o volume crescer. **[CRC][Rafael]**

### 14. Base da NFS-e
- **Decisão:** a casalivre emite NFS-e só sobre **a parte dela** (comissão/taxa de intermediação), item **10.05** da lista da LC 116/2003 (agenciamento, corretagem ou intermediação de bens móveis ou imóveis… — **conferir** com o contador se o enquadramento é 10.05 ou 10.02/1.03; prefeitura decide o código). ISS é devido no **município do estabelecimento prestador** (LC 116 art. 3º; intermediação não está nas exceções). Alíquota de 2% a 5% (LC 116 art. 8º e 8º-A). O franqueado emite a dele. Se fatura única: NFS-e da casalivre só sobre a parte dela, desde que o repasse esteja documentado.
- **Reforma Tributária:** LC 214/2025 (16/01/2025). Em **2026** há o ano-teste: destaque de **CBS 0,9% e IBS 0,1%** na nota, compensável com PIS/Cofins, com dispensa de recolhimento para quem cumprir as obrigações acessórias (conferir atos da Receita/Comitê Gestor de 2026). A NFS-e passa a seguir o **padrão nacional**. A intermediação é serviço tributado na alíquota geral; as reduções da LC 214 para **operações com bens imóveis** (locação: redução de 70%) valem para a **locação**, não para a comissão da plataforma. As plataformas digitais têm regras próprias de responsabilidade (LC 214 arts. 22 a 25, plataformas digitais como responsáveis solidárias/recolhedoras quando intermediam pagamento) — **conferir** os artigos exatos e o efeito quando o pagamento for fora da plataforma (piloto) × PSP com split (P6). Transição: CBS plena em 2027; IBS cresce de 2029 a 2032; ISS extinto em 2033.
- **Prazo:** emissão no mês da prestação (regra municipal). Guarda: item 16.
- **Risco:** médio. **[CRC]**

### 15. Multa de 2% e juros de 1% a.m.
- **Decisão:** **ok**. Contrato entre empresas/profissionais (proprietário que explora imóveis comercialmente perante a plataforma) não é, em regra, relação de consumo na cobrança da comissão; mesmo que fosse, 2% é o teto do CDC art. 52 §1º. Juros de mora de 1% a.m. são o limite usual (CC art. 406, redação da **Lei 14.905/2024**: sem pactuação, a taxa legal é a Selic menos IPCA; a pactuação é permitida). Acrescentar correção monetária pelo IPCA (CC art. 389 parágrafo único, Lei 14.905/2024).
- **Base legal:** CC arts. 389, 395, 406, 408 a 416, 591; Decreto 22.626/1933 (Lei de Usura, limite de 12% a.a. para juros convencionais entre não instituições financeiras); CDC art. 52 §1º.
- **Risco:** baixo.
- **Constar nos termos (D14):** contrato plataforma–anfitrião: vencimento, multa de 2%, juros de 1% a.m. pro rata die, correção pelo IPCA, suspensão do anúncio após D+15/D+30 com aviso prévio.

### 16. Guarda da NFS-e da fatura de comissão (escopo novo)
- **Decisão:** guardar o XML/PDF da NFS-e e o espelho da fatura por **5 anos contados de 1º de janeiro do ano seguinte à emissão** (CTN art. 173 I), estendendo enquanto houver auto de infração ou processo. Recomendo **6 anos corridos**. Conferir prazo no código tributário do município emissor.
- **Base legal:** CTN arts. 173, 174, 195 parágrafo único; legislação municipal do ISS; LC 214/2025 (documentos de IBS/CBS seguem a mesma lógica de decadência — conferir regulamento).
- **Risco:** baixo. **[CRC]**

## F. Piloto e cancelamento
### 17. Aviso de que não há proteção casalivre no pagamento fora da plataforma
- **Decisão:** **necessário, mas não suficiente sozinho**. A plataforma que anuncia, intermedia e cobra comissão integra a cadeia de fornecimento (CDC arts. 7º parágrafo único, 14, 25 §1º e 34); o STJ tem precedentes responsabilizando plataformas quando há falha do próprio serviço (anúncio falso, fraude facilitada). Cláusula que exonera totalmente é nula (CDC art. 25 e 51 I). O aviso reduz o risco quando acompanhado de: verificação do anfitrião (KYC), chave Pix vinculada ao recebedor verificado, alerta contra pagamento a terceiros, canal de denúncia e remoção rápida de anúncio fraudulento.
- **Base legal:** CDC arts. 6º III, 14, 25, 51 I; Decreto 7.962/2013 arts. 2º e 4º.
- **Risco:** **alto** (golpe de anúncio falso é o risco nº 1 do setor na Região dos Lagos). **[OAB][Rafael]**
- **Constar nos termos (D14):** "No piloto, o pagamento é feito diretamente ao anfitrião, pela chave exibida na reserva. A casalivre não recebe nem guarda esse valor e não reembolsa pagamentos feitos fora da chave exibida. A casalivre verifica a identidade do anfitrião e mantém canal de denúncia." Exibir no checkout, não só no termo.

### 18. Comprovante pode se chamar "recibo"? (J34)
- **Decisão:** **não**. "Recibo" sugere quitação dada por quem recebeu (CC arts. 319 e 320) e a casalivre não recebeu. Usar "**Comprovante de reserva**" com o texto "pagamento informado como feito diretamente ao anfitrião em [data]; não é recibo nem documento fiscal". O recibo de aluguel é do anfitrião.
- **Dados mínimos:** código, imóvel e cidade, datas, valores discriminados e total, nome de quem reservou, nome do anfitrião, política de cancelamento aplicada, data de emissão. Sem CPF completo ou chave Pix completa.
- **Base legal:** CC arts. 319 e 320; CDC art. 6º III e 31; Decreto 7.962/2013 art. 2º.
- **Risco:** baixo.

### 19. Arrependimento de 7 dias (CDC art. 49) e check-in em menos de 7 dias (J35)
- **Decisão (mais provável):** a reserva online é contratação **fora do estabelecimento**, então o art. 49 **se aplica** (CDC art. 49; Decreto 7.962/2013 art. 5º). Não existe exceção legal expressa para hospedagem ou check-in próximo. A Diretiva europeia 2011/83/UE tem essa exceção; o **CDC brasileiro não**. Existe jurisprudência dividida e o PL de atualização do CDC (PL 3.514/2015) discutia exceções — **não está em vigor** (conferir no Planalto/Câmara). Regra prática: **reembolso integral se o hóspede desistir em até 7 dias da reserva E antes do check-in**. Após o início da estadia, o arrependimento não se aplica (serviço já usufruído). Para check-in em menos de 7 dias: vale até o check-in; o risco de recusar é **médio/alto** no Procon.
- **Base legal:** CDC art. 49 e parágrafo único; Decreto 7.962/2013 art. 5º.
- **Prazo:** 7 dias corridos da reserva (ou do recebimento da confirmação).
- **Risco:** médio. **[Rafael]**: aceitar o custo (o anfitrião perde a reserva). Alternativa de mitigação: deixar claro que a casalivre aplica a regra e que o anfitrião aceita no contrato.
- **Constar nos termos (D14):** termo do hóspede e contrato do anfitrião com a regra acima; a regra no servidor muda o cálculo do reembolso.

### 20. Reembolso em 5 dias úteis
- **Decisão:** **ok** como prazo próprio. O CDC exige devolução **imediata** e atualizada no arrependimento (art. 49 parágrafo único; Decreto 7.962 art. 5º §§3º e 4º: comunicação imediata à administradora do cartão). 5 dias úteis é razoável e defensável. No piloto, quem devolve é o anfitrião (o dinheiro está com ele): o contrato do anfitrião precisa obrigar o reembolso em 5 dias úteis.
- **Risco:** baixo (P6); médio no piloto (depende do anfitrião).
- **Constar nos termos (D14):** obrigação do anfitrião de reembolsar em 5 dias úteis; consequência (suspensão, taxa) se não fizer.

### 21. Taxa de cancelamento do anfitrião (10%, teto R$ 300, a partir do 2º em 12 meses)
- **Decisão:** **ok**. Cláusula penal contratual B2B, proporcional, com teto; não supera o valor da obrigação (CC art. 412) e pode ser reduzida pelo juiz se excessiva (art. 413). Informar no contrato e avisar antes de cada cancelamento.
- **Base legal:** CC arts. 408 a 416.
- **Risco:** baixo.
- **Constar nos termos (D14):** contrato plataforma–anfitrião: hipóteses, cálculo, teto, isenção por força maior comprovada (CC art. 393), forma de cobrança (na fatura P9).

### 22. Declaração da plataforma com o histórico para o Procon
- **Decisão:** **ok e recomendável**. É dever de informação e colaboração; atende o direito de acesso do titular (LGPD art. 18 II e art. 19). Conteúdo: dados da reserva, mensagens relevantes, status, datas, valores informados. Excluir dados pessoais de terceiros desnecessários (do anfitrião, só nome e dados do anúncio). Linguagem factual, sem reconhecer culpa.
- **Base legal:** CDC arts. 6º III e VIII; LGPD arts. 18 II, 19.
- **Risco:** baixo.

## G. J12 a J35 (conforme relatório)
### 23. J12 — Avaliação privada do hóspede compartilhada entre anfitriões
- **Decisão:** **art. 7º IX** (legítimo interesse dos anfitriões de avaliar risco), com LIA e RIPD, restrito ao anfitrião com pedido pendente. Transparência: o hóspede é avisado e vê as próprias avaliações e a nota (art. 18 I e II, art. 9º). Oposição (art. 18 §2º): analisada, mas pode ser negada se o interesse prevalecer (a nota é parte do serviço). Se a nota **bloquear** reservas automaticamente: revisão (art. 20).
- **Risco:** médio.
- **Constar nos termos (D14):** "Anfitriões avaliam hóspedes; essa avaliação pode ser vista por anfitriões aos quais você pedir reserva. Você vê e pode contestar suas avaliações."

### 24. J13 — Texto da avaliação após exclusão de conta (autor anonimizado) e responsabilidade
- **Decisão:** manter o texto com o autor "Usuário removido" é **suficiente** (o dado deixa de identificar o autor, art. 12; e o texto também se refere à outra parte/imóvel). Responsabilidade por conteúdo de terceiro: a plataforma só responde se descumprir **ordem judicial** de remoção (MCI art. 19); exceção: nudez/intimidade, notificação privada basta (art. 21). Na prática, moderar avaliações ilícitas por notificação (política própria).
- **Base legal:** LGPD arts. 12, 18 VI; MCI arts. 19 e 21.
- **Risco:** baixo.

### 25. J14 — Avaliações verdadeiras e remoção por moderador com conflito
- **Decisão:** proibido apagar avaliação negativa verdadeira para favorecer anfitrião (publicidade enganosa por omissão: CDC art. 37 §§1º e 3º; prática abusiva). Remoção só por política escrita (ofensa, dado pessoal, conteúdo falso comprovado, sem relação com a estadia), com motivo registrado. Moderador com vínculo com o imóvel (franqueado, gestor) **não pode** decidir; regra de impedimento. Só hóspedes com estadia concluída avaliam.
- **Base legal:** CDC arts. 6º IV, 36, 37; Decreto 7.962/2013 art. 2º.
- **Risco:** médio.
- **Constar nos termos (D14):** política de avaliações pública.

### 26. J15 — Visibilidade após o pedido e retenção das avaliações do hóspede
- **Decisão:** visível ao anfitrião **só enquanto o pedido estiver pendente** (concordo com a Segurança, 07-12). Retenção para o agregado: **24 meses**; depois disso, sai do agregado e é apagada ou anonimizada.
- **Base legal:** LGPD art. 6º III (necessidade), art. 15.
- **Risco:** baixo.

### 27. J16 — Hóspede saber quais anfitriões viram suas avaliações
- **Decisão:** o titular tem direito a saber com **quais entidades** houve uso compartilhado (art. 18 VII). Atender informando a categoria e, a pedido, a lista dos anúncios/anfitriões que acessaram nos últimos 6 meses (prazo do log de acesso). Não precisa exibir em tempo real.
- **Base legal:** LGPD arts. 9º V, 18 VII.
- **Risco:** baixo.

### 28. J17 — Pseudonimização dos logs de acesso × MCI art. 15
- **Decisão:** o MCI exige guardar o **registro** (IP, data/hora de acesso a partir de uma aplicação) e fornecê-lo por ordem judicial (art. 15 §3º; art. 5º VIII). **Truncamento perde o IP → não atende.** Usar **cifragem reversível** (ou HMAC + tabela de correspondência cifrada) do IP completo, com chave separada e acesso só para cumprir ordem; o user-agent pode ser reduzido. HMAC puro sem guardar o original **também não atende**, porque não permite reconstruir o IP.
- **Base legal:** MCI arts. 5º VIII, 13, 15 e 22; Decreto 8.771/2016 art. 13.
- **Prazo:** 6 meses.
- **Risco:** médio se truncar.

### 29. J18 — Ledger, resultado KYC e CPF do recebedor após exclusão
- **Decisão:** manter (art. 16 I): ledger e informes por 5 anos fiscais (item 6, recomendo 6 anos); resultado KYC e CPF/CNPJ cifrado por relação + 5 anos (item 5).
- **Risco:** baixo.

### 30. J19 — Liberar o e-mail durante a carência
- **Decisão:** **não liberar** durante a carência (a conta ainda pode ser restaurada e o e-mail identifica o titular). Liberar após a purga, guardando apenas um HMAC do e-mail se ele estiver na lista de bloqueio. Decisão de produto, sem impedimento legal. **[Rafael]**
- **Risco:** baixo.

### 31. J20 — Base para guardar documentos KYC por relação + 5 anos e recusados
- **Decisão:** ver item 5. Base: art. 7º VI (defesa em processo) e IX, art. 11 II "g" para biometria (selfie só 30 dias). Recusados: imagens 30 dias; resultado 6 meses, ou 5 anos se fraude.
- **Risco:** médio.

### 32. J21 — 90 dias da portaria
- **Decisão:** **aceitável**: cobre o prazo de reclamação por vício aparente de serviço não durável (**30 dias**, CDC art. 26 I) e de serviço durável (**90 dias**, art. 26 II), e incidentes de condomínio. Cancelada: 7 dias. Incidente aberto: legal hold até o fim.
- **Base legal:** CDC art. 26; LGPD arts. 6º III, 15, 16.
- **Risco:** baixo.

### 33. J22 — Gravação do vídeo de suporte (confirmação de Pix)
- **Decisão:** **não gravar** por padrão; registrar no log quem confirmou, quando e o resultado. Se gravar: o rosto em vídeo usado para identificar é **biometria** (art. 11 II "g"), aviso prévio na chamada, guarda de **30 dias** (como a selfie), cifrado; prorrogar só com legal hold.
- **Risco:** médio se gravar; baixo se não gravar.

### 34. J23 — "Excluir agora": o que mais reter
- **Decisão:** sim, reter o **registro do pedido do titular e da resposta** (data, o que foi apagado, o que ficou retido e por quê) como prova do atendimento ao art. 18 (prestação de contas, art. 6º X). Prazo: **5 anos**, com dados mínimos (id, e-mail em HMAC, datas). Reter também: disputas abertas, faturas em aberto, legal hold.
- **Base legal:** LGPD arts. 6º X, 16 I e II, 18, 37.
- **Risco:** baixo.

### 35. J24 — Acesso da moderação às conversas
- **Decisão:** art. 7º IX (segurança, antifraude, evasão da plataforma), restrito a denúncia ou sinal automático, com log. O aviso geral fica na política ("mensagens podem ser revisadas para segurança"); o aviso individual pode ser **adiado** durante investigação de fraude (não há artigo específico na LGPD; justificar pelo art. 7º IX e pelo segredo da investigação; registrar). Atenção: sigilo das comunicações (CF art. 5º XII e MCI art. 7º II e III) protege contra terceiros; a plataforma é parte e tem a ciência do usuário pelos termos.
- **Risco:** médio.
- **Constar nos termos (D14):** aviso de moderação nas mensagens.

### 36. J25 — Retenção das mensagens
- **Decisão:** **12 meses após o check-out** (com reserva) e **90 dias** sem reserva. Mensagens são **conteúdo** (MCI art. 7º III), não registro de acesso: o art. 15 não as alcança. Legal hold por caso.
- **Risco:** baixo.

### 37. J26 — (não listado no relatório com essa numeração; sem item a responder.)

### 38. J27 — Lista de bloqueio
- **Decisão:** ver item 12. Prazos: bloqueio de 7 dias e suspensão pelo prazo da punição; **fraude comprovada: 5 anos**, com revisão; **guarda permanente não**. HMAC = dado pseudonimizado, ainda pessoal.
- **Risco:** médio.

### 39. J28 — Texto original sem máscara por 30 dias
- **Decisão:** **não recomendado** por padrão (art. 6º III, necessidade): guardar só o **tipo** do padrão e a contagem. Se for comprovado necessário, 30 dias, cifrado, acesso só em denúncia, com LIA. **[Rafael]**
- **Risco:** médio se guardar.

### 40. J29 — Retenção das conversas e legal hold
- **Decisão:** igual ao item 36. Legal hold: suspende a purga pelo tempo do caso e é registrado.
- **Risco:** baixo.

### 41. J30 — Manter o texto de titular excluído
- **Decisão:** **ok**, autor anonimizado, até o fim da retenção (item 36); base art. 7º IX e VI (interesse da outra parte e defesa). Pedido específico de eliminação: analisado caso a caso; apagar o que for dado pessoal do requerente sem utilidade para a outra parte.
- **Risco:** baixo.

### 42. J31 — Chave mestra
- **Decisão:** não há exigência legal de KMS específico; a LGPD exige medidas de segurança aptas (art. 46) e a ANPD orienta boas práticas (Guia de Segurança para Agentes de Tratamento de Pequeno Porte). Na P6, o PSP pode exigir contratualmente (e o PCI DSS, se houver dado de cartão — não guardar cartão). Perda da chave = perda de disponibilidade: é incidente de segurança se afetar titulares (art. 48: comunicar ANPD e titulares quando houver risco relevante; Res. CD/ANPD 15/2024, prazo de 3 dias úteis — **conferir**).
- **Risco:** médio.

### 43. J32 — Foto original com EXIF ou só hash
- **Decisão:** guardar o **hash SHA-256 do original + data do upload** basta para o fluxo normal (CPC art. 369, liberdade de meios de prova; o hash comprova só a integridade do original, não da versão re-codificada). Recomendação: guardar o **original cifrado** só quando a disputa envolver valor relevante ou houver chargeback/ação; no restante, só a versão re-codificada + hash.
- **Base legal:** CPC (Lei 13.105/2015) arts. 369 e 411; MP 2.200-2/2001 (documento eletrônico).
- **Risco:** baixo.

### 44. J33 — Retenção das disputas
- **Decisão:** **12 meses após a resolução** para fotos e mensagens; resultado e log pela auditoria (5 anos). Legal hold em chargeback, Procon, ação. (Prescrição de 5 anos do CDC art. 27 para danos; a decisão e o log cobrem isso.)
- **Risco:** baixo.

### 45. J34 — ver item 18.
### 46. J35 — ver item 19.

## H. Itens da Arquitetura (escopo novo)
### 47. 15 dias da revisão: corridos ou úteis?
- **Identificação:** é a **revisão do bloqueio/impedimento de cadastro** (spec-02:41 e critério 5: "o titular pode pedir revisão a qualquer momento, com resposta em até 15 dias"; fila na spec-10, 2.9). Ela combina o art. 20 (revisão de decisão automatizada, que **não fixa prazo**) com o art. 19 II (declaração completa ao titular em **até 15 dias**).
- **Decisão:** **dias corridos**. A LGPD fala em "dias" sem qualificar; prazo de direito material conta em dias corridos (CC art. 132). Agentes de pequeno porte têm **prazo em dobro** para atender o titular (Res. CD/ANPD 2/2022, art. 14 — conferir), mas recomendo **não usar** essa folga.
- **Risco:** baixo.

### 48. A revisão deve ser guardada como prova?
- **Decisão:** **sim**: guardar o pedido, a decisão, o motivo, quem decidiu e a data, por **5 anos** (prestação de contas, art. 6º X; defesa, art. 16 II/7º VI).
- **Risco:** baixo.

### 49. Vários legal holds?
- **Decisão:** **sim**. Um mesmo dado pode estar em mais de um caso (chargeback, Procon, ação, ordem judicial do MCI art. 15 §2º). O dado só volta à purga quando **todos** os holds forem encerrados. Cada hold registra motivo, caso, quem abriu, data e encerramento.
- **Risco:** baixo (médio se um hold encerrado liberar dado preso em outro caso).

---
## Pendências para o Rafael
1. **[Rafael][OAB]** Proteção ao hóspede no piloto (item 17): o aviso não basta; aprovar KYC do anfitrião + chave Pix vinculada + denúncia antes de abrir o piloto. Risco **alto**.
2. **[Rafael][CRC]** Fatura P9: decidir **faturas separadas por emissor** (recomendado) × fatura única com mandato de cobrança (item 13).
3. **[Rafael]** Arrependimento de 7 dias (item 19): aceitar reembolso integral até o check-in dentro dos 7 dias e embutir isso no contrato do anfitrião.
4. **[Rafael]** KYC: imagem do documento por relação + 2 anos (recomendado) ou + 5 anos (item 5).
5. **[OAB]** Modelo de intermediação × corretagem (Lei 6.530/1978) e papel dos franqueados corretores (itens 5 e 8).
6. **[CRC]** Abertura/regime da empresa, código de serviço e município do ISS, preparação da NFS-e nacional e destaques de CBS/IBS de 2026 (LC 214/2025) (item 14).
7. **Verificar** nas prefeituras de Cabo Frio, Búzios, Arraial do Cabo e demais do piloto: cadastro de imóvel de temporada e taxa de turismo (item 10).
8. **Verificar** no BCB os limites de dispensa da Res. BCB 80/2021 antes da P6, e no site da ANPD o Enunciado 1/2023, a Res. 2/2022 e a Res. 15/2024.
9. **[OAB]** Revisão final dos documentos D14 (termos do hóspede, contrato do anfitrião, política de privacidade, de cancelamento e de avaliações).

---
## I. J38–J39 (16/10/2026, Rafael decidiu seguir sem OAB/CRC; valem os pareceres do Bot Jurídico)
Referências: `spec-00-indice-contrato-comum.md` (§5 parâmetros, §7 pendências 1, 2 e 9), `spec-05` §2 e Ap. A, `juridico/recomendacoes-sem-orientacao-externa.md` §2 e §4.

### 50. J38 — Intermediação (plataforma) ou corretagem? CRECI e NFS-e
- **Decisão (mais provável):** a casalivre faz **intermediação por plataforma digital**: anúncio, busca, reserva, cobrança da comissão e ferramentas. Ela **não** procura o negócio por instrução do cliente nem negocia em nome dele, como na corretagem (CC art. 722). **Mas** a cobrança de comissão percentual por locação fechada aproxima o modelo da corretagem (CC art. 725: remuneração devida quando o resultado é obtido). Por isso, o risco de o CRECI-RJ autuar por exercício irregular é **médio**. Quem faz a corretagem de fato no modelo franquia e na parceria é o **corretor inscrito** (franqueado/parceiro), e isso protege o modelo.
- **Base legal:** CC arts. 722 a 729 (corretagem; art. 729: normas especiais prevalecem); Lei 6.530/1978 art. 3º (compete ao corretor a intermediação na compra, venda, permuta e **locação** de imóveis) e art. 6º (inscrição de PJ no CRECI) **[CONFERIR a redação do art. 6º no Planalto: ele exige que a PJ inscrita tenha um corretor inscrito como sócio-gerente ou diretor/responsável]**; Decreto 81.871/1978 (regulamento) **[CONFERIR]**. Jurisprudência sobre plataformas × CRECI: há decisões em sentidos diferentes e **não localizei** precedente do STJ que pacifique o tema. **Não cito acórdão** [CONFERIR antes de usar em defesa].
- **CRECI:** (a) **canal direto, sem corretor:** é onde o risco fica concentrado. A mitigação mais barata é **inscrever a casalivre como PJ no CRECI-RJ** com um corretor inscrito como responsável técnico (pode ser um franqueado contratado para isso) **[Rafael: custo de anuidade e do responsável]**; (b) franquia e parceria: exigir CRECI ativo do franqueado/parceiro (consulta J8) e deixar no contrato que a corretagem é dele; (c) a casalivre não usa "imobiliária" nem "corretora" na marca ou nos anúncios.
- **NFS-e:** **não muda**. O subitem **10.05** da LC 116/2003 ("agenciamento, **corretagem** ou intermediação de bens móveis ou imóveis…") cobre os dois enquadramentos. A diferença está só na inscrição no CRECI, não no código. Royalty de franquia é outro subitem (ver J39).
- **Prazo:** decidir a inscrição antes do piloto com canal direto.
- **Risco:** **médio** (canal direto sem CRECI); **baixo** (franquia e parceria com corretor inscrito).
- **Constar nos termos (D14):** termos de uso: "a casalivre é uma plataforma de anúncios e reservas; quando houver corretor, ele é identificado com o número do CRECI". Contrato com o franqueado/parceiro: corretagem e responsabilidade técnica dele, CRECI ativo como condição do contrato.

### 51. J39 — NFS-e de cada parte do split, por modelo
Premissas: faturas separadas por emissor (item 13); pagamento da locação fora da plataforma no piloto (o aluguel não passa pela casalivre e **não** gera NFS-e: a locação de bem imóvel não é serviço, Súmula Vinculante 31/STF); ISS no município do estabelecimento do prestador (LC 116 art. 3º, caput); base = preço do serviço (LC 116 art. 7º). **NBS:** usar a correlação LC 116 × NBS publicada no portal da NFS-e Nacional (nfse.gov.br) **[CONFERIR o código NBS exato; não informo um número sem confirmar]**.

**Modelo direto (casalivre × proprietário, sem corretor)**
| Parcela | Emite | Para | Base | Código |
|---|---|---|---|---|
| Comissão casalivre | casalivre | proprietário (tomador) | valor da comissão | LC 116 10.05 |

**Parceria (parceiro corretor/imobiliária)**
| Parcela | Emite | Para | Base | Código |
|---|---|---|---|---|
| Comissão do parceiro | parceiro (com CRECI) | proprietário | parte do parceiro | 10.05 |
| Comissão casalivre | casalivre | proprietário | parte da casalivre | 10.05 |
Se o parceiro pagar a casalivre (e não o proprietário), a casalivre emite contra o parceiro; definir no contrato de parceria **[Rafael]**.

**Franquia (900/40/30/30 ‰, decidido em spec-05 Ap. A)**
| Parcela | Emite | Para | Base | Código |
|---|---|---|---|---|
| Corretor 4% + fundo 3% = 7% | franqueado | proprietário | 7% (o fundo integra o preço do serviço do franqueado) | 10.05 |
| Repasse ao fundo 3% | — (sem NFS-e; repasse contratual de franquia, comprovado por extrato) | fundo | — | — **[CONFERIR com o tratamento contábil do franqueado: é despesa dele]** |
| Royalty 3% | casalivre | **franqueado** | 3% (base definida no contrato de franquia) | LC 116 **17.08 (franquia/franchising)** |
**Atenção:** a spec-05 (cenário 900/40/30/30) dá a entender que a casalivre cobra o royalty do valor da reserva, enquanto as recomendações e esta decisão dizem que a cobrança é do **franqueado**. Se a casalivre emitir contra o **proprietário**, aquilo **não é royalty**: é comissão de intermediação (10.05) e o franqueado não paga royalty. Escolher um dos dois e alinhar spec, contrato e COF **[Rafael]**. Recomendo **royalty contra o franqueado, 17.08**. O ISS de franquia é questionado (STF reconheceu a constitucionalidade do ISS sobre franquia no RE 603.136, Tema 300, 2020 **[CONFERIR]**).

**Canal manual (reserva lançada pelo anfitrião)**
| Parcela | Emite | Para | Base | Código |
|---|---|---|---|---|
| Comissão do canal manual (se houver; pendente Rafael) | casalivre (e o franqueado, se a franquia cobrar no manual) | proprietário | valor da comissão | 10.05 |
Sem comissão no canal manual, não há NFS-e. Mensalidade ou assinatura do software (se adotada) é outro serviço: **LC 116 1.05 (licenciamento ou cessão de direito de uso de programas)** ou **1.03 (processamento, armazenamento… plataformas)** **[CONFERIR com o município]**.

**IBS/CBS a partir de 2027 (LC 214/2025):**
- 2026: destaque de teste (CBS 0,9%, IBS 0,1%) na NFS-e nacional, compensável e sem custo efetivo para quem cumpre as obrigações acessórias **[CONFERIR atos da Receita/CGIBS]**.
- 2027: a CBS passa a ser cobrada na alíquota cheia sobre cada uma dessas notas (comissão, royalty), no lugar de PIS/Cofins; o IBS continua em 0,1% até 2028; o ISS cai de 2029 a 2032 e acaba em 2033. **Base:** o valor do serviço de cada emissor; não há redutor para intermediação nem para franquia **[CONFERIR anexos de redução da LC 214]**.
- Quem estiver no **Simples** recolhe IBS/CBS dentro do DAS e só transfere crédito limitado ao tomador, salvo opção pelo regime regular (LC 214 art. 41). Isso afeta o franqueado que toma o royalty: se for contribuinte do regime regular, ele aproveita crédito do IBS/CBS destacado pela casalivre.
- **Plataforma (LC 214 art. 22):** responsabilidade solidária da casalivre quando o fornecedor não emitir documento fiscal; manter o ledger por reserva e emissor (já previsto).
- **Risco:** **médio** (código 17.08 × 10.05 do royalty, alíquota municipal, NBS); **alto** se emitir "royalty" contra o proprietário (natureza errada, CTN art. 118).
- **Prazo:** emissão no mês da prestação; guarda de 6 anos corridos (item 16).
- **Constar nos termos (D14):** contrato com o proprietário: discriminar quem cobra cada parcela e quem emite cada nota. Contrato de franquia/COF (Lei 13.966/2019 art. 2º): royalty de 3%, base, vencimento e NFS-e da casalivre contra o franqueado; obrigação do franqueado de repassar o fundo.

### Pendências novas para o Rafael
10. Inscrição da casalivre no CRECI-RJ com corretor responsável (custo) ou canal direto só com corretor parceiro (J38).
11. Royalty contra o **franqueado** (17.08, recomendado) × contra o proprietário (10.05, deixa de ser royalty). Alinhar a spec-05, o contrato e a COF (J39).
12. Comissão do canal manual e quem paga a casalivre na parceria (J39).

### 52. Verificação de 16/10/2026: NFS-e Nacional (tomador) e DIMOB
- **NFS-e:** DPS v1.01; NT 009 v1.01 (01/10/2026, Ato Técnico Conjunto nº 7, de 28/09/2026). O grupo `toma` é opcional no XSD [CONFERIR no Anexo I oficial]; quando informado, a pessoa física é identificada pelo CPF. A NT 009 valida o município do tomador contra o cadastro CPF/CNPJ. **Decisão:** sempre identificar o tomador. Fontes: gov.br/nfse/pt-br/biblioteca/documentacao-tecnica/rtc e a NT 009 v1.01 (PDF).
- **DIMOB:** a IN RFB 1.115/2010 está **em vigor** (alterada pela IN 2.218/2024, que mexe só nas multas). É obrigatória para PJ que intermedeia aluguel de imóveis, sem exceção para temporada ou plataforma. Exige CPF/CNPJ do locador **e do locatário** e os valores mensais de aluguel e comissão. **Decisão (mais provável):** a casalivre é obrigada. Coletar o CPF do hóspede (LGPD art. 7º II) e guardar em `fiscal_parties` até 1º/jan do ano do último evento + 7. Controverso (REQ 16/2025 na Câmara): [pendente: Rafael] aceitar ou fazer consulta formal. **Risco:** médio (multa de 3% do valor das operações omitidas, reduzida em 70% no Simples). Fontes: gov.br/receitafederal (DIMOB, Perguntas e Respostas) e gov.br/pt-br/servicos/declarar-atividades-imobiliarias, acesso em 16/10/2026.
- **Substitui** os [CONFERIR] sobre a DIMOB em `spec-05-apendice-fiscal.md` (A.7) e em `recomendacoes-sem-orientacao-externa.md`.
