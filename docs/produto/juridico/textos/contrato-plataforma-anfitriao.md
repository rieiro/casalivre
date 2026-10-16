# Contrato de uso da plataforma casalivre pelo anfitrião

> **MINUTA, versão 0.1, de 16/10/2026. Não publicar nem assinar.**
> Primeira versão escrita pelo redator jurídico (bot) com base em `juridico/recomendacoes-sem-orientacao-externa.md` (seções 1, 2, 4 e 5, item D) e `juridico/parecer-J1-J35.md` (J4 a J8, itens 13 a 22, J35), conferida com as specs 00, 02, 03, 05, 06 e 09 e, na parte fiscal, com `juridico/spec-05-apendice-fiscal.md`, que prevalece sobre a spec-05 e a spec-00. **Minuta do Jurídico; Rafael optou por seguir sem revisão externa (ver `juridico/recomendacoes-sem-orientacao-externa.md`).**
> Antes de usar: preencher os campos **[PREENCHER]**, resolver os pontos **[CONFERIR]**, decidir os números pendentes do split (D2 e D12) e obter o "ok" do Rafael.
> Este contrato **não é** o contrato de franquia nem a Circular de Oferta de Franquia (COF) do corretor franqueado, que são documentos separados (Lei 13.966/2019).

**Versão:** [PREENCHER] · **Vigente a partir de:** [PREENCHER] · **Aceite eletrônico:** versão, hash, data, hora e IP registrados pelo sistema.

---

## 1. Partes

1.1. **casalivre:** **[PREENCHER: razão social]**, CNPJ **[PREENCHER]**, sede em **[PREENCHER: endereço]**, e-mail **[PREENCHER]**.

1.2. **Anfitrião:** a pessoa física ou jurídica identificada no cadastro, que é **proprietária do imóvel** ou **tem autorização do proprietário para alugá-lo** e administrá-lo na plataforma. Quando houver gestor, o proprietário também adere a este contrato como responsável pelos recebimentos e pelas obrigações do imóvel. [CONFERIR: como o proprietário adere quando o anúncio é criado pelo gestor (aceite próprio do proprietário no primeiro acesso).]

1.3. **Franqueado do território (interveniente):** quando o imóvel estiver numa rede franqueada, o **corretor franqueado** (inscrito no CRECI) que atende o território do imóvel presta ao Anfitrião o suporte local e a intermediação que lhe cabem, e fatura ao proprietário, em NFS-e própria, a parte dele (cláusula 9). [CONFERIR: se o franqueado deve assinar este contrato como interveniente ou ter contrato próprio com o Anfitrião.]

## 2. Objeto

2.1. A casalivre licencia ao Anfitrião o uso da plataforma para:
- (a) **anunciar** imóveis para **locação por temporada** (Lei 8.245/1991, arts. 48 a 50) no marketplace e nos canais ativos;
- (b) receber solicitações e reservas;
- (c) conversar com hóspedes;
- (d) gerir calendário, preços, equipe e recebedores;
- (e) acompanhar ledger, extratos e faturas.

2.2. A locação é celebrada **entre o Anfitrião (ou o proprietário) e o hóspede**, pelo *Contrato de locação por temporada* do modelo casalivre. A casalivre **não é locadora** nem administradora do imóvel.

2.3. **Natureza da atividade.** A casalivre faz **intermediação por plataforma digital**: anúncio, busca, reserva e cobrança da própria comissão. Ela **não faz corretagem** no sentido do Código Civil, art. 722. Nos modelos com corretor, **a corretagem é do franqueado ou do parceiro**, inscrito no CRECI. A casalivre não usa "imobiliária" nem "corretora" na marca nem nos anúncios. [CONFERIR: a inscrição da própria casalivre no CRECI-RJ, com corretor responsável (Lei 6.530/1978, art. 6º), está pendente do Rafael.]

## 3. Cadastro e verificação de identidade (KYC)

3.1. **Recebedor verificado é obrigatório.** Só um recebedor com KYC **Aprovado** pode ser vinculado a um anúncio publicado. **No piloto, publicar com pagamento por fora exige também a chave Pix vinculada a esse recebedor Aprovado.**

3.2. **Documentos por tipo de recebedor:**

| Tipo | Documentos |
|---|---|
| Pessoa física | Documento com foto, **selfie**, comprovante de endereço de até 90 dias, conta ou chave Pix **no mesmo CPF** |
| Pessoa jurídica | Cartão CNPJ, contrato social, documento com foto e **selfie do sócio-administrador**, conta ou chave **no CNPJ** |
| MEI | CCMEI, documento com foto e **selfie do titular**, conta ou chave no CNPJ ou no CPF do titular |
| Corretor franqueado | O do tipo dele (PF ou PJ), mais o **CRECI ativo** |
| Prestador de limpeza | Simplificado: pessoa física ou MEI |

3.3. **Vínculo com o imóvel.** Para publicar, o Anfitrião envia a **prova de titularidade do imóvel** (escritura, matrícula ou IPTU) ou a **autorização do proprietário** (contrato de administração ou autorização escrita), aceita pela casalivre.

3.4. **Análise.** A análise é **manual**, feita só pela equipe de verificação da casalivre, em até **2 dias úteis**. As validações automáticas conferem o dígito do CPF/CNPJ, a situação do CNPJ na Receita (BrasilAPI) e se **o titular da chave Pix é o mesmo do documento**. O resultado pode ser *Aprovado*, *Pendência* (com motivo e reenvio) ou *Recusado*. O franqueado vê só o status e o resultado das validações, nunca os documentos.

3.5. **Selfie (dado biométrico).** A selfie é tratada com base na **prevenção à fraude e segurança do titular nos processos de identificação e autenticação de cadastro** (LGPD, art. 11, II, "g"), **sem necessidade de consentimento**, e é **apagada 30 dias depois da decisão**. Os documentos dos aprovados, inclusive a imagem, ficam cifrados durante o relacionamento e por mais **5 anos** contados do fim dele; os dos recusados são apagados em 30 dias.

3.6. **Chave Pix.** A chave deve estar registrada **no mesmo CPF ou CNPJ do recebedor verificado**. A plataforma recusa uma chave de outro titular. Qualquer troca de chave:
- (a) exige senha e código enviado ao canal já cadastrado do titular;
- (b) gera aviso ao proprietário por todos os canais;
- (c) tem **carência de 48 horas** ("Pendente até…"), durante a qual pode ser cancelada;
- (d) só vale para **reservas novas**.

Um gestor só troca a chave com delegação expressa e confirmação do proprietário. Enquanto os avisos por e-mail e WhatsApp não estiverem ativos, a troca só é feita pelo suporte da casalivre, por **videochamada ao vivo, não gravada**, com aprovação de uma segunda pessoa, **carência de 7 dias** e no máximo 1 correção a cada 30 dias.

3.7. O Anfitrião declara que os dados e documentos são verdadeiros e que avisará a casalivre de qualquer mudança. Documento falso ou chave de terceiro levam à recusa, à suspensão e à inclusão na lista de bloqueio (cláusula 13).

## 4. Anúncio

4.1. O Anfitrião é responsável pela **veracidade e atualização** de fotos, descrição, capacidade, comodidades, **inventário de móveis e utensílios com o estado de conservação** (Lei 8.245/1991, art. 48, parágrafo único), regras da casa, regras do condomínio, preço, limpeza, caução (se houver), calendário e política de cancelamento.

4.1-A. **Inventário obrigatório.** **O anúncio só é publicado com o inventário preenchido por cômodo** (item, quantidade e estado de conservação). Esse inventário preenche o Anexo I do Contrato de locação por temporada de cada reserva.

4.1-B. **Caução opcional.** O Anfitrião pode exigir caução em dinheiro, com valor definido por ele no anúncio e mostrado antes da reserva, **limitada a 3 meses de aluguel** (Lei 8.245/1991, art. 38, § 2º). A caução é a única garantia da locação (art. 37, parágrafo único). Ela é paga junto com a estadia, pela chave do recebedor verificado.
- O Anfitrião tem **48 horas depois do check-out** para registrar dano na plataforma, **com fotos**.
- Sem registro nesse prazo, **a caução é devolvida** integralmente.
- Com registro, o Anfitrião só retém o valor do dano comprovado e devolve o restante. A divergência vai para a disputa.

[CONFERIR: (1) o art. 38, § 2º, manda depositar a caução em dinheiro em caderneta de poupança, com os rendimentos revertidos ao locatário; definir como isso se aplica a uma temporada curta; (2) como medir "3 meses de aluguel" quando o aluguel é por temporada, e não mensal; (3) o prazo para devolver a caução (sugestão: 5 dias úteis, como o reembolso).]

4.2. É proibido incluir contatos (telefone, e-mail, links) no anúncio. Eles são liberados depois da reserva.

4.3. **Endereço.** O endereço exato só é mostrado ao hóspede com a reserva *Confirmada*. Antes disso, aparece só o bairro e uma área aproximada de cerca de 500 m.

4.4. **Condomínio.** O Anfitrião declara que a **convenção do condomínio permite a locação por temporada** do imóvel e anexa as regras do condomínio quando houver, para aceite do hóspede. Responde perante o hóspede e perante a casalivre se a locação for impedida por proibição que ele conhecia ou devia conhecer. [CONFERIR: REsp 1.819.075/RS (2021).]

4.5. **Prazo máximo.** O anúncio só pode ser usado para locações de **até 90 dias** (Lei 8.245/1991, art. 48).

4.6. **Serviços de hotelaria.** O Anfitrião que oferecer serviços típicos de hospedagem (limpeza diária, recepção, café da manhã) assume as obrigações de meio de hospedagem que couberem (Lei 11.771/2008, arts. 23 e 26) e deve avisar a casalivre.

4.7. **Canais.** Por padrão, o anúncio fica no marketplace casalivre e no canal da rede do imóvel. O Anfitrião pode desligar qualquer canal. Cada canal pode ter um **acréscimo percentual** sobre as diárias, mostrado antes da publicação.

## 5. Reservas e prazos

5.1. **Resposta.** Na reserva sujeita a aprovação, o Anfitrião responde em até **24 horas**, ou **4 horas** se o check-in for em menos de 72 horas (ou no prazo configurado no nó ou no imóvel). Sem resposta, a solicitação expira.

5.2. **Recusa.** Só pelos motivos da lista (datas indisponíveis, regras da casa não atendidas, manutenção, outro). É proibido recusar por motivo discriminatório.

5.3. **Marcar como pago.** O Anfitrião registra separadamente **"Diária recebida"** e **"Limpeza recebida"** (esta, pelo prestador), com comprovante opcional. A reserva só fica *Confirmada* com as duas marcações. Desfazer depois de 5 segundos exige justificativa.

5.4. **Reserva manual.** O Anfitrião pode lançar reservas manuais para clientes próprios, que seguem este contrato e a comissão do canal manual. **O hóspede recebe um link com código de confirmação (OTP) para aceitar o Contrato de locação, o Termo de serviço de limpeza e os Termos de uso.** Até o aceite, a reserva fica com o status **"Aceite pendente"**. [PREENCHER: comissão do canal manual, pendente do Rafael (spec-06, 3b).]

5.5. **Lista da portaria.** O Anfitrião usa a lista **só para o controle de acesso**, envia à portaria **só pelo link seguro da plataforma** (nome e documento, sem foto) e não a copia por e-mail ou WhatsApp. Depois de 7 dias do check-out, só abre a lista com motivo, e ela é apagada aos 90 dias.

5.6. **Recibo e Comprovante.** O Anfitrião fornece ao hóspede **recibo discriminado** dos valores recebidos (Lei 8.245/1991, art. 22, VI). O documento da casalivre chama-se **"Comprovante de reserva"**, e não é recibo nem documento fiscal.

## 6. Pagamento no piloto

6.1. **No piloto, o hóspede paga diretamente ao recebedor**, pela chave Pix exibida na reserva. **A casalivre não recebe, não guarda e não repassa esse valor.** O hóspede vê o aviso: *"Pagamento direto ao anfitrião, sem a proteção casalivre."*

6.2. O Anfitrião se obriga a:
- (a) **nunca pedir ou aceitar pagamento por chave, conta, link ou meio diferente do exibido na reserva**;
- (b) não levar a negociação para fora da plataforma;
- (c) não burlar o mascaramento de contatos.

O descumprimento leva a suspensão e, se houver fraude, a encerramento e lista de bloqueio, sem prejuízo da comissão devida.

6.3. **Fase futura (PSP com split).** Quando a casalivre passar a processar pagamentos por um prestador de serviços de pagamento (PSP), com divisão automática:
- (a) o PSP passa a fazer o KYC, com obrigações próprias de prevenção à lavagem de dinheiro;
- (b) a comissão é retida no split, em vez de faturada;
- (c) o Anfitrião precisará aceitar a nova versão deste contrato e os termos do PSP.

As reservas do piloto seguem as regras do piloto.

## 7. Cancelamento pelo hóspede, arrependimento e reembolso

7.1. O Anfitrião escolhe uma política (**Flexível, Moderada ou Rígida**) e **aceita as regras que valem para todas**, descritas na Política de cancelamento:
- (a) **direito de arrependimento**: reembolso de **100%** se o hóspede cancelar **em até 7 dias contados da confirmação da reserva, desde que antes do check-in**, mesmo com check-in em menos de 7 dias (CDC, art. 49; parecer J35);
- (b) a limpeza e a taxa de serviço não usadas são sempre devolvidas;
- (c) o cancelamento antes do pagamento é gratuito.

7.2. **Reembolso em 5 dias úteis.** No piloto, quem devolve é o Anfitrião (e o prestador de limpeza, na limpeza). O Anfitrião deve **reembolsar o hóspede em até 5 dias úteis** do cancelamento, por Pix, e **anexar na plataforma o comprovante com o ID da transação**. O valor é o calculado pela plataforma a partir da política gravada na reserva.

7.3. **Consequências do atraso.** Se o prazo for perdido:
- (a) abre-se **disputa automática**;
- (b) os anúncios do Anfitrião **deixam de aceitar reservas novas**;
- (c) o proprietário **perde o pagamento por fora**;
- (d) o hóspede recebe orientação sobre o Procon e uma **declaração da casalivre com o histórico da reserva** (dados da reserva, mensagens relevantes, status, datas e valores; do Anfitrião, só o nome e os dados do anúncio).

O atraso gera **só essas penalidades**. Ele não aumenta a comissão: a comissão segue a cláusula 9.1-A e não incide sobre o valor que deveria ter sido devolvido.

7.4. **Regresso.** Se a casalivre for condenada ou fizer acordo para indenizar hóspede por reembolso não feito, golpe, anúncio falso ou outro ato do Anfitrião, ela **tem direito de regresso** contra o Anfitrião pelo valor pago, com correção e despesas.

## 8. Cancelamento pelo Anfitrião

8.1. Se o Anfitrião cancelar uma reserva confirmada, o hóspede recebe **100%**, pelo fluxo de 5 dias úteis. O motivo é obrigatório.

8.2. **Sem força maior:**
- (a) as datas continuam **bloqueadas** e não podem ser revendidas;
- (b) o anúncio **perde o destaque na busca por 90 dias**;
- (c) a partir do **2º cancelamento em 12 meses**, é devida **taxa de cancelamento de 10%, limitada a R$ 300,00** por cancelamento, calculada sobre {valor total da reserva} [CONFERIR: a base de cálculo; o exemplo da spec-09, "taxa de R$ 225 (10%)", indica 10% do total da reserva com a limpeza]. A taxa é cobrada na fatura mensal (cláusula 10);
- (d) com **3 ou mais cancelamentos em 90 dias**, a conta vai para **revisão**.

A tela mostra a consequência **antes** de cada cancelamento.

8.3. **Natureza da taxa.** A taxa é **cláusula penal** proporcional e com teto (Código Civil, arts. 408 a 416). Ela não supera o valor da obrigação principal (art. 412) e pode ser reduzida se for excessiva (art. 413).

8.4. **Força maior.** Não se aplicam as consequências da cláusula 8.2 se o cancelamento decorrer de **caso fortuito ou força maior** (Código Civil, art. 393), como dano no imóvel, evento climático ou problema de saúde, **comprovado em até 7 dias** (foto ou laudo, atestado médico, alerta da Defesa Civil). Quem decide é o admin do nó acima do Anfitrião, com recurso à casalivre. Se houver conflito de interesse, a decisão sobe um nível.

## 9. Comissão

9.1. **Valor.** Pelas reservas confirmadas nos canais da casalivre, o Anfitrião paga comissão conforme o **modelo de split vigente** para o nó, o imóvel e o canal, mostrado no painel e no **Anexo I**. A versão aplicada fica gravada em cada reserva. Uma mudança só vale para reservas novas.

9.1-A. **Base: só o valor retido.** A comissão incide **só sobre o valor das diárias efetivamente retido pelo Anfitrião**, ou seja, as diárias pagas menos o reembolso devido ao hóspede. **Reembolso total não gera comissão** (nem royalty, na franquia). A limpeza fica fora da base.

9.2. **Regra geral: cada um fatura só a sua parte.** Cada parcela da comissão é cobrada **pelo seu emissor**, em NFS-e própria. O proprietário **só recebe faturas e notas de quem efetivamente o fatura**. O aluguel não gera NFS-e, porque locação de imóvel não é serviço.

9.3. **Modelo direto (sem parceiro).** A casalivre fatura ao proprietário a comissão dela, em NFS-e no **item 10.05** da lista da LC 116/2003.

9.4. **Modelo franquia (canal direto).** O proprietário paga ao franqueado a **comissão da franquia**, **10% no modelo padrão**, e o franqueado fatura o valor inteiro numa só NFS-e no **item 10.05**. Desse valor, o franqueado:
- fica com a corretagem: **4%**, ou o percentual vigente no modelo;
- paga o **royalty** à casalivre: **3%**, ou o percentual vigente no contrato de franquia (pode ser, por exemplo, 2,5%);
- repassa o **fundo regional**: **3%**, ou o percentual vigente no contrato de franquia.

Os percentuais são **parâmetros do modelo de split**. O valor aplicado fica gravado em cada reserva e aparece no painel antes da reserva.

| Parcela dos 10% | % | Quem fatura | Tomador | Documento |
|---|---|---|---|---|
| Comissão total da franquia | **10% (padrão)** | Franqueado | **Proprietário** | NFS-e do franqueado, **item 10.05** |
| ↳ fica com o franqueado (corretagem) | 4% (padrão) | — | — | (dentro da NFS-e de 10%) |
| ↳ royalty casalivre | 3% (ou o percentual vigente no contrato de franquia) | casalivre | **Franqueado** | NFS-e da casalivre ao franqueado, **item 17.08** |
| ↳ fundo regional de propaganda | 3% (ou o percentual vigente no contrato de franquia) | — | — | Repasse do franqueado à **conta segregada do fundo**, com extrato, **sem NFS-e** |

- **O proprietário recebe só a fatura e a NFS-e do franqueado (a comissão total da franquia).** A casalivre **não fatura o proprietário** na franquia, e **nenhuma linha de royalty aparece** para ele.
- O **royalty** é pago **pelo franqueado** com a comissão que ele recebe, numa fatura mensal casalivre → franqueado. A base é a das reservas da franquia **concluídas** no mês e a dos **valores retidos em cancelamentos com reembolso parcial definitivos no mês**. **Reembolso total não gera royalty.** O royalty segue o contrato de franquia, que é separado deste.
- O franqueado repassa o fundo por obrigação do contrato de franquia. **A casalivre não recebe, não retém nem administra** o valor do fundo, não age como mandatária do franqueado e não cobra o fundo do proprietário.
- No canal OTA, os percentuais de royalty e de fundo dependem da D12 (Anexo I).

9.5. **Modelo parceria.** O parceiro (com CRECI ativo) fatura ao proprietário a comissão dele, em NFS-e própria, item 10.05. A comissão da casalivre também é faturada em NFS-e no item 10.05, a um **tomador configurável por modelo**. **O padrão é o proprietário**: cada um fatura a sua parte a ele. Se o contrato de parceria disser que o parceiro paga a casalivre, o tomador passa a ser o parceiro, e o proprietário recebe só a fatura do parceiro.

9.6. **Canal manual.** Se houver comissão no canal manual, ela é faturada ao proprietário pela casalivre e, se a franquia cobrar no manual, pelo franqueado, no item 10.05. Sem comissão, não há nota. [PREENCHER: comissão do canal manual e se a franquia cobra no manual, pendentes do Rafael.]

9.7. **CRECI do franqueado e do parceiro.** O franqueado e o parceiro precisam de **CRECI ativo**, consultado no cadastro e de novo a cada 12 meses. Com CRECI inativo ou não encontrado:
- (a) ele fica bloqueado como emissor da cobrança;
- (b) os anúncios do nó deixam de aceitar reservas novas, e as confirmadas são mantidas.

9.8. **A limpeza fica fora da comissão.**

## 10. Fatura mensal, vencimento e atraso (piloto)

10.1. **Faturas separadas por emissor.** No fim de cada mês, **cada emissor gera só a sua fatura**, com só as parcelas que lhe cabem, das reservas pagas por fora e **concluídas** no mês. O **extrato mensal do proprietário** mostra só as cobranças **dirigidas a ele**, agrupadas por emissor: na franquia, só a do franqueado; no direto, só a da casalivre; na parceria, as do parceiro e, se ele for o tomador, a da casalivre. Entram também, **no mês em que o reembolso parcial fica definitivo** (confirmado pelo hóspede, considerado feito após 72 h ou decidido em disputa), as reservas canceladas em que o Anfitrião reteve parte das diárias, com a comissão só sobre o valor retido (cláusula 9.1-A). Reservas com reembolso total não entram e não geram comissão nem royalty.

10.2. **Taxa de cancelamento.** A taxa de cancelamento da cláusula 8 é **multa contratual** (cláusula penal), e não serviço. Por isso, **não tem NFS-e**. Ela é cobrada **pela casalivre** ao Anfitrião, em qualquer modelo (inclusive na franquia), **com recibo ou nota de débito**.

10.3. **Vencimento:** **10 dias** depois da emissão.

10.4. **Atraso.** O valor em atraso tem:
- **multa de 2%**;
- **juros de mora de 1% ao mês, pro rata die**;
- **correção monetária pelo IPCA** (Código Civil, arts. 389, parágrafo único, 395, 406 e 408 a 416; Lei 14.905/2024).

10.5. **Escalonamento, com aviso prévio:**
- **D+1:** lembrete;
- **D+5:** aviso ao gestor;
- **D+15:** os anúncios **deixam de aceitar reservas novas** (as confirmadas continuam);
- **D+30:** o **pagamento por fora fica desabilitado** para o proprietário.

O pagamento integral reverte automaticamente as medidas do D+15 e do D+30.

10.6. **Notas fiscais.** Cada emissor emite NFS-e só sobre a sua parcela: **item 10.05** para as comissões e **item 17.08** para o royalty (este, só ao franqueado). O ISS é devido no município do estabelecimento do emissor. A NFS-e de 2026 traz o destaque de teste de CBS e IBS. [CONFERIR: a alíquota do ISS no município sede, o código NBS e os atos de 2026 do IBS/CBS.]

10.7. **Ajustes.** Depois do fechamento, correções entram como estorno ou ajuste na fatura seguinte. NFS-e, espelho da fatura e comprovante de baixa ficam guardados por 6 anos corridos e não são alterados.

## 11. Obrigações fiscais e municipais do Anfitrião

11.1. O Anfitrião é o único responsável pelos tributos sobre o aluguel e a limpeza que recebe, como o **IRPF por carnê-leão**, quando pessoa física, e os tributos da pessoa jurídica.

11.2. **Reforma tributária (IBS/CBS).** O Anfitrião foi avisado de que:
- (a) a pessoa física que ultrapassar os limites do art. 251 da LC 214/2025 (mais de 3 imóveis e receita de locação acima do limite anual corrigido) pode se tornar contribuinte do IBS e da CBS;
- (b) nesse caso, a locação residencial de até 90 dias segue as regras de **hotelaria** (art. 253);
- (c) a plataforma pode responder solidariamente quando o fornecedor não emitir documento fiscal ou não se inscrever (art. 22).

O Anfitrião se obriga a se inscrever e emitir os documentos quando for contribuinte, a informar a casalivre e a ressarcir a casalivre pelo que ela pagar por omissão dele. [CONFERIR: textos dos arts. 22, 251 e 253 com a redação da LC 227/2026.]

11.3. **Informações às autoridades.** O Anfitrião autoriza a casalivre a guardar e informar à Receita Federal, ao Comitê Gestor do IBS e às prefeituras os dados das operações intermediadas (nome, CPF/CNPJ, imóvel, valores e datas), quando a lei exigir, inclusive na DIMOB. [CONFERIR: se a plataforma é obrigada à DIMOB, IN RFB 1.115/2010.]

11.3-A. **Cadastro fiscal.** O CPF ou CNPJ do tomador das notas (o proprietário e, no royalty, o franqueado) fica **só num cadastro fiscal separado** (`fiscal_parties`), **cifrado**, e aparece **mascarado** em telas e faturas. Ele é apagado em **1º de janeiro do ano do último pagamento + 7** (por exemplo, último pagamento em 2026 → apagado em 01/01/2033), salvo legal hold.

11.4. **Normas municipais.** Cumprir o **cadastro municipal** de imóvel de temporada e a **taxa de turismo**, quando existirem, é obrigação do Anfitrião. [CONFERIR: Cabo Frio (Lei 4.382/2025 e inventário turístico voluntário), Búzios (PLC 22/2025, Taxa de Turismo Sustentável) e Arraial do Cabo.]

11.5. **Informe anual.** A casalivre disponibiliza extrato e informe anual das reservas para a declaração de IR do Anfitrião.

## 12. Avaliações e moderação

12.1. As avaliações são **duplo-cegas**, só de estadias concluídas, em até 14 dias. O Anfitrião pode publicar **uma resposta** por avaliação, editável por 48 horas.

12.2. O Anfitrião avalia o hóspede. Essa avaliação não é pública e só é vista por outros anfitriões enquanto houver pedido pendente desse hóspede. Ela deve ser verdadeira, sem dados pessoais nem ofensas.

12.3. **É proibido pedir, comprar, trocar ou forjar avaliações**, ou pressionar o hóspede a mudar a avaliação. A casalivre **não remove avaliações negativas verdadeiras** (CDC, art. 37). Quem tem vínculo com o imóvel (inclusive o franqueado ou o gestor) não decide a moderação.

## 13. Suspensão, encerramento e lista de bloqueio

13.1. A casalivre pode, com aviso e motivo:
- suspender anúncios;
- restringir funções (como a reserva instantânea);
- desabilitar o pagamento por fora;
- encerrar a conta.

Isso vale nos casos de documento falso, chave de terceiro, pedido de pagamento por fora, anúncio falso, reembolso não feito, inadimplência, cancelamentos reiterados, violação das regras ou ordem de autoridade. Em suspeita de fraude grave, a medida pode ser imediata, com a comunicação logo depois.

13.2. **Lista de bloqueio.** Em fraude comprovada, o CPF e o telefone entram na lista de bloqueio de novo cadastro, **pseudonimizados por HMAC**, por **5 anos**. Nos demais casos, a entrada dura o tempo da penalidade. **Nunca é permanente.**

13.3. **Revisão.** O Anfitrião pode pedir revisão por uma pessoa a qualquer momento, com resposta em até 15 dias corridos.

## 14. Proteção de dados

14.1. **Papéis.** A casalivre é controladora dos dados tratados na plataforma, conforme a Política de Privacidade. O Anfitrião é **controlador independente** dos dados de hóspedes que receber para executar a locação [CONFERIR] e se obriga a:
- (a) usá-los só para a locação e para cumprir a lei;
- (b) não copiá-los para fora da plataforma sem necessidade, nem compartilhá-los, salvo com a portaria pelo link seguro;
- (c) protegê-los com medidas de segurança adequadas (LGPD, art. 46);
- (d) apagá-los quando não forem mais necessários;
- (e) avisar a casalivre em até 24 horas de qualquer incidente com dados recebidos pela plataforma [CONFERIR prazo];
- (f) atender às orientações da casalivre nos pedidos de titulares.

14.2. **Equipe.** O Anfitrião responde pelos gestores e operadores que convidar e só concede a cada um as permissões necessárias.

14.3. **Moderação.** O Anfitrião sabe que as mensagens podem ser revisadas pela moderação da casalivre em caso de denúncia, disputa ou sinal de fraude.

## 15. Responsabilidade

15.1. O Anfitrião responde perante o hóspede pela locação e perante a casalivre pelos prejuízos causados pelo descumprimento deste contrato, inclusive multas, condenações e acordos com hóspedes ou autoridades decorrentes de ato dele, assegurado o direito de regresso da casalivre.

15.2. A casalivre responde pelos serviços que presta e não garante ocupação, receita ou resultado do anúncio.

## 16. Vigência e encerramento

16.1. O contrato vale por prazo indeterminado, a partir do aceite.

16.2. Qualquer parte pode encerrá-lo com aviso de **[PREENCHER: prazo, sugestão 30 dias]**. Reservas confirmadas antes do aviso devem ser cumpridas, e as faturas em aberto continuam devidas.

16.3. O **fim do relacionamento** é registrado na plataforma (último pagamento, desvinculação ou exclusão) e é o marco dos prazos de guarda dos documentos de KYC.

## 17. Alterações

A casalivre pode alterar este contrato com aviso prévio de **[PREENCHER: prazo]**, pela plataforma. Mudanças na comissão ou nas taxas valem só para reservas feitas depois da vigência. Se o Anfitrião não concordar, pode encerrar o contrato sem multa. Mudanças que exijam novo aceite serão pedidas no painel.

## 18. Comunicações

As comunicações são feitas pelo painel e pelos contatos cadastrados. O Anfitrião mantém seus contatos atualizados.

## 19. Foro

Fica eleito o foro da comarca de **[PREENCHER: comarca]**. [CONFERIR: se o Anfitrião pessoa física, que aluga um único imóvel, pode ser tratado como consumidor perante a plataforma; nesse caso, prevalece o foro do domicílio dele (CDC, art. 101, I). A multa de 2% respeita o limite do CDC, art. 52, § 1º, nos dois casos.]

---

## Anexo I: tabela de comissão por modelo e canal

Por R$ 1.000 de diárias, com os **percentuais padrão** (configuráveis no modelo de split). **"Faturado ao proprietário"** é o que aparece no extrato dele. O royalty é uma relação **casalivre → franqueado** e não aparece para o proprietário.

| Cenário | Faturado ao proprietário (emissor, item) | Royalty (casalivre → franqueado, 17.08) | Fundo regional | Status |
|---|---|---|---|---|
| Direto, sem parceiro (940/60) | R$ 60 (casalivre, 10.05) | — | — | [pendente: Rafael, D2] |
| **Franquia, canal direto** | **R$ 100 (franqueado, 10.05): franqueado 4% (R$ 40), royalty 3% (R$ 30), fundo 3% (R$ 30)** | **R$ 30 (3%), pagos pelo franqueado** | R$ 30, repassados pelo franqueado à conta segregada, sem NFS-e | padrão configurável (royalty e fundo conforme o contrato de franquia) |
| Franquia via OTA | R$ 80 (franqueado, 10.05): franqueado 4% (R$ 40), royalty 2% (R$ 20), fundo 2% (R$ 20) | R$ 20 (2%), pagos pelo franqueado | R$ 20 | [pendente: Rafael, D12] |
| Parceria (930/40/30 e 900/50/50) | Parceiro (10.05) e casalivre (10.05; tomador configurável, padrão proprietário) | — | — | [CONFERIR: papéis de cada parcela, spec-05] |
| Canal manual | [PREENCHER] | [PREENCHER] | [PREENCHER] | [pendente: Rafael] |

Base: diárias efetivamente retidas pelo Anfitrião (cláusula 9.1-A). A limpeza fica fora. A taxa da OTA é cobrada pela própria OTA, fora do split.

## Anexo II: resumo de prazos e parâmetros

| Item | Valor |
|---|---|
| Análise de KYC | 2 dias úteis |
| Selfie | apagada 30 dias depois da decisão |
| Carência da troca de chave Pix | 48 horas (7 dias na correção pelo suporte) |
| Resposta à solicitação | 24 h, ou 4 h com check-in em menos de 72 h |
| Prazo de pagamento do hóspede | 24 h, ou 4 h |
| Reembolso ao hóspede | 5 dias úteis, com comprovante; 72 h para o hóspede contestar |
| Comprovação de força maior | 7 dias |
| Taxa de cancelamento do Anfitrião | 10%, teto de R$ 300, a partir do 2º cancelamento em 12 meses; multa sem NFS-e, com recibo ou débito |
| Registro de dano | até 48 h depois do check-out, com fotos; depois disso, a caução é devolvida |
| Caução | opcional, definida pelo Anfitrião, até 3 meses de aluguel |
| Revisão da conta | 3 ou mais cancelamentos em 90 dias |
| Fatura | mensal, uma por emissor, só com as parcelas dele; vencimento em 10 dias; multa de 2%, juros de 1% a.m. pro rata die e IPCA |
| Escalonamento | D+1, D+5, D+15 (sem reservas novas), D+30 (sem pagamento por fora) |
| Lista da portaria | até 90 dias depois do check-out (7 dias se cancelada) |
| Disputa | até 14 dias depois do check-out; 10 dias úteis para resolver |
