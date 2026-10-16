# Contrato de locação por temporada (modelo casalivre)

> **MINUTA, versão 0.1, de 16/10/2026. Não publicar.**
> Primeira versão escrita pelo redator jurídico (bot) com base em `juridico/recomendacoes-sem-orientacao-externa.md` (seção 5, item B) e `juridico/parecer-J1-J35.md` (J9 a J11, J21, itens 17 a 20 e J35), conferida com as specs 01, 03, 06 e 09. **Minuta do Jurídico; Rafael optou por seguir sem revisão externa (ver `juridico/recomendacoes-sem-orientacao-externa.md`).**
> É um dos 3 termos D14 aceitos no checkout ("Li e aceito o **Contrato de locação por temporada**", spec-01, seção 4). Os campos entre chaves `{...}` são preenchidos pelo sistema com os dados do snapshot da reserva. Os campos **[PREENCHER]** e **[CONFERIR]** precisam ser resolvidos antes de publicar.
> **Nota de glossário:** o glossário da spec-00 evita a palavra "locador" nas telas. Neste contrato ela é necessária, porque é o termo da Lei 8.245/1991; o texto define que o anfitrião ou o proprietário ocupa essa posição.

**Versão:** [PREENCHER] · **Código da reserva:** {código, ex.: CL-1234} · **Canal:** {canal} · **Data do aceite:** {data e hora}

---

## 1. Partes

1.1. **LOCADOR:** {nome do proprietário ou da empresa recebedora da estadia}, {CPF/CNPJ mascarado}, aqui chamado de **Anfitrião**. Quando o anúncio for operado por um gestor, ele atua **em nome do proprietário**, que é o locador. [CONFERIR: confirmar quem figura como locador quando o recebedor da estadia é um gestor ou empresa, e não o proprietário; o contrato deve trazer o titular do direito de alugar.]

1.2. **LOCATÁRIO:** {nome de quem reservou}, {CPF mascarado}, {e-mail e celular cadastrados}, aqui chamado de **Hóspede**, que contrata por si e como responsável pelos acompanhantes indicados na reserva.

1.3. **Intermediária:** a **casalivre** ([PREENCHER: razão social e CNPJ]) fornece este modelo, a plataforma e os serviços de apoio descritos nos Termos de uso. **A casalivre não é parte desta locação**, não é locadora e, no piloto, não recebe nem guarda os valores aqui previstos. Isso não afasta a responsabilidade da casalivre pelos serviços que ela mesma presta (Termos de uso, item 3.3).

## 2. Imóvel

2.1. **Imóvel:** {título do anúncio}, {tipo}, em {bairro}, {cidade}/RJ. O **endereço completo** é liberado na plataforma quando a reserva fica *Confirmada* e passa a integrar este contrato: {endereço, preenchido na confirmação}.

2.2. **Capacidade:** {n} hóspedes, {n} quartos, {n} camas, {n} banheiros. Bebês de 0 a 2 anos {contam / não contam} na capacidade, conforme o anúncio.

2.3. **Mobília e utensílios.** O imóvel é entregue mobiliado. **A descrição dos móveis e utensílios que o guarnecem, e o estado em que se encontram, consta do Anexo I** (Lei 8.245/1991, art. 48, parágrafo único). [CONFERIR com Produto: a spec-03 tem comodidades, mas **não tem um campo de inventário com o estado dos itens**. Sem esse anexo, o contrato não cumpre o art. 48, parágrafo único. É preciso criar o campo "Inventário" no anúncio, ou a lista de comodidades com estado, antes do piloto.]

2.4. **Comodidades e regras** do anúncio, vigentes na data da reserva, integram este contrato.

## 3. Finalidade e prazo

3.1. A locação é **para temporada**, destinada à residência temporária do Hóspede para {lazer / outra finalidade informada}, nos termos do art. 48 da Lei 8.245/1991. É proibido usar o imóvel para outra finalidade, como moradia permanente, comércio ou evento não autorizado.

3.2. **Prazo:** de {data de check-in}, a partir das {hora de check-in}, até {data de check-out}, às {hora de check-out}, num total de {n} noites. O prazo **não pode passar de 90 dias** (art. 48).

3.3. **Desocupação.** O Hóspede deve deixar o imóvel até a hora do check-out. A permanência depois disso depende de acordo prévio com o Anfitrião, pela plataforma, com o pagamento das diárias adicionais. [CONFERIR: a spec não prevê multa nem cobrança automática por permanência; não incluir valor sem decisão do Rafael.]

3.4. **Prorrogação pela lei.** Se, terminado o prazo, o Hóspede permanecer no imóvel **sem oposição do Anfitrião por mais de 30 dias**, a locação passa a ser considerada **prorrogada por tempo indeterminado**, e o pagamento antecipado do aluguel e dos encargos deixa de ser exigível. Nesse caso, o Anfitrião só pode denunciar o contrato depois de 30 meses do início ou nas hipóteses do art. 47 da Lei 8.245/1991 (art. 50 e parágrafo único). **Para evitar isso, o Anfitrião deve se opor por escrito à permanência**, pela plataforma, logo depois do fim do prazo.

## 4. Preço e pagamento

4.1. **Valor da locação:** {composição do snapshot}:
- Diárias: {n} × {valores por noite} = {R$};
- Descontos: {R$, se houver};
- **Aluguel total da temporada: {R$}**.

A **taxa de serviço**, quando maior que 0%, não é aluguel: ela remunera o recebedor indicado na plataforma e aparece como linha própria no checkout ({R$}). No piloto, ela é de 0%. [CONFERIR: quem recebe a taxa de serviço e como ela é paga quando for maior que 0%; spec-05, seção 2.]

A **limpeza** ({R$}) é contratada com o prestador de limpeza pelo *Termo de serviço de limpeza* e paga diretamente a ele.

4.2. **Pagamento antecipado.** O aluguel e os encargos da temporada são pagos **de uma só vez e antecipadamente** (Lei 8.245/1991, art. 49), **por Pix, para a chave exibida na reserva**, que pertence ao recebedor da estadia, **com identidade verificada e registrada no mesmo CPF ou CNPJ desse recebedor**. O prazo é de **24 horas** depois da aprovação (ou do envio, na reserva instantânea), ou de **4 horas** se o check-in for em menos de 72 horas. Sem pagamento no prazo, a reserva expira e este contrato fica sem efeito, sem ônus para as partes.

4.3. **Proibição de pagamento por outro meio.** O Anfitrião **não pode pedir** e o Hóspede **não deve fazer** pagamento por chave, conta, link ou meio diferente do exibido na reserva. Pedido desse tipo deve ser denunciado na plataforma.

4.4. **Aviso do piloto.** **No piloto, o pagamento é feito diretamente ao Anfitrião, pela chave exibida na reserva. A casalivre não recebe nem guarda esse valor e não reembolsa pagamentos feitos fora da chave exibida. A casalivre verifica a identidade do Anfitrião e mantém canal de denúncia.**

4.5. **Quitação.** A reserva fica *Confirmada* quando o Anfitrião registra o recebimento na plataforma. O **Anfitrião deve fornecer recibo discriminado** dos valores recebidos, sem quitação genérica (Lei 8.245/1991, art. 22, VI). O *Comprovante de reserva* emitido pela casalivre **não é recibo nem documento fiscal**.

4.6. **Encargos incluídos.** Salvo indicação diferente no anúncio, o aluguel inclui água, luz, gás, internet e taxa de condomínio do período. [CONFERIR com Produto: não há campo no anúncio para encargos cobrados à parte; se não houver, manter "incluídos".]

4.7. **Tributos e taxas municipais.** Taxa de turismo ou cadastro municipal, quando existirem, são de responsabilidade do Anfitrião, salvo se o anúncio indicar, antes da reserva, que a lei municipal manda o Hóspede pagar. [CONFERIR: taxa de turismo sustentável de Búzios (PLC 22/2025), com sanção e responsável pelo recolhimento ainda não confirmados.]

## 5. Garantia

5.1. **No piloto, não é exigida garantia** (caução, fiança, seguro ou outra) pela plataforma.

5.2. Se a casalivre passar a permitir garantia, ela será uma só das modalidades do art. 37 da Lei 8.245/1991 (art. 49), mostrada no anúncio antes da reserva. A caução em dinheiro não pode passar de três meses de aluguel e segue o art. 38, § 2º. [CONFERIR com o Rafael: decidir se haverá caução para danos; a caução em dinheiro tem regra de depósito em poupança que dificulta o uso em temporada.]

## 6. Ocupantes, menores e portaria

6.1. **Só podem ficar no imóvel** o Hóspede e os acompanhantes indicados na reserva, dentro da capacidade. Visitas seguem as regras da casa.

6.2. **Responsável maior de idade.** O Hóspede declara ter **18 anos ou mais** (ou a idade mínima exigida no anúncio: {18 / 21 / 25}) e ser **responsável pelos menores hospedados**, que estarão **acompanhados pelos pais ou responsáveis, ou autorizados na forma do ECA** (Lei 8.069/1990, arts. 82 e 83).

6.3. **Lista da portaria.** Quando o condomínio exigir, o Hóspede envia pela plataforma, até **48 horas antes do check-in** (ou no prazo indicado na reserva), o **nome e o documento de cada hóspede**, incluindo menores. A foto do documento só é pedida se o condomínio exigir. Os dados são enviados à portaria só para o controle de acesso, por link seguro, e apagados até **90 dias depois do check-out** (7 dias se a reserva for cancelada), salvo incidente aberto. A falta da lista pode levar a portaria a recusar a entrada, sem que isso seja culpa do Anfitrião.

6.4. **O Anfitrião** usa esses dados **só para o controle de acesso** e não os copia para fora da plataforma, nem os envia por e-mail ou aplicativo de mensagens.

## 7. Regras da casa e do condomínio

7.1. O Hóspede, os acompanhantes e as visitas devem cumprir as **regras da casa** ({pets, fumo, festas, silêncio, visitas, check-in e check-out, piscina, churrasqueira, vagas}) e, quando houver, as **regras do condomínio** do **Anexo II**, aceitas no checkout com um checkbox próprio (Lei 8.245/1991, art. 23, X; Código Civil, arts. 1.333 e 1.334).

7.2. O Anfitrião declara que a **convenção do condomínio permite a locação por temporada** do imóvel, inclusive por plataformas digitais, e responde perante o Hóspede se a locação for impedida por proibição condominial que ele conhecia ou devia conhecer. [CONFERIR: o STJ admite que a convenção proíba a locação de curta duração por aplicativos (REsp 1.819.075/RS, 2021); conferir a ementa e a jurisprudência atual.]

## 8. Obrigações do Anfitrião

O Anfitrião se obriga a (Lei 8.245/1991, art. 22):
- (a) entregar o imóvel **limpo, em estado de servir ao uso**, conforme o anúncio e o Anexo I;
- (b) garantir o **uso pacífico** do imóvel durante a temporada;
- (c) responder pelos vícios ou defeitos anteriores à locação;
- (d) dar as instruções de chegada e de acesso até o check-in;
- (e) fornecer recibo discriminado dos valores recebidos;
- (f) estar disponível, ou indicar alguém, para resolver problemas urgentes durante a estadia;
- (g) **reembolsar o Hóspede em até 5 dias úteis** sempre que houver cancelamento com reembolso, anexando o comprovante Pix na plataforma (cláusula 11);
- (h) não entrar no imóvel durante a temporada sem aviso prévio e concordância do Hóspede, salvo emergência.

## 9. Obrigações do Hóspede

O Hóspede se obriga a (Lei 8.245/1991, art. 23):
- (a) pagar o aluguel antecipadamente, no prazo e pela chave exibida na reserva;
- (b) usar o imóvel só para a finalidade de temporada, **tratando-o com o mesmo cuidado como se fosse seu**;
- (c) **restituir o imóvel**, no check-out, **no estado em que o recebeu**, salvo as deteriorações do uso normal;
- (d) avisar imediatamente o Anfitrião de qualquer dano ou defeito cuja reparação caiba a ele;
- (e) **reparar ou indenizar os danos causados por si, pelos acompanhantes ou pelas visitas**;
- (f) não modificar o imóvel, não sublocar, não ceder e não emprestar o imóvel;
- (g) cumprir as regras da casa e do condomínio;
- (h) enviar a lista da portaria quando exigida.

## 10. Vistoria de entrada e de saída; danos

10.1. **Entrada.** O Hóspede pode registrar o estado do imóvel no check-in. **Problemas na entrada** (imóvel diferente do anúncio, sujeira, defeito, acesso negado) devem ser relatados pela plataforma em **até 24 horas depois da entrada, com fotos**. Sem esse registro, presume-se que o imóvel foi recebido conforme o Anexo I, admitida prova em contrário. [CONFERIR: presunção relativa; avaliar se cabe em relação de consumo.]

10.2. **Saída.** O Anfitrião faz a vistoria de saída e registra na plataforma, com fotos, eventual dano ou falta de item do Anexo I. [CONFERIR com Produto: definir o prazo para o Anfitrião registrar o dano depois do check-out; a disputa pode ser aberta até 14 dias depois do check-out, spec-09, seção 6.]

10.3. **Danos.** Constatado dano causado pelo Hóspede, pelos acompanhantes ou pelas visitas, as partes tentam acordo pela plataforma. Sem acordo, qualquer delas pode abrir **disputa**, mediada pelo responsável local e, depois, pela casalivre (Termos de uso, item 14). **No piloto, a casalivre não retém valores e não cobra danos em nome do Anfitrião.** A mediação não impede o recurso ao Procon ou à Justiça.

## 11. Cancelamento, arrependimento e reembolso

11.1. **Política aplicável:** **{Flexível / Moderada / Rígida}**, gravada na reserva, com estas **datas-limite calculadas para esta reserva**: {ex.: "100% até 06/12, 23:59 · 50% até 13/12 · depois disso, sem reembolso"}. As regras completas estão na **Política de cancelamento** da casalivre, que integra este contrato.

11.2. **Direito de arrependimento.** O Hóspede pode desistir com **reembolso de 100%** em **até 7 dias a partir da reserva, desde que antes do check-in**, inclusive quando o check-in for em menos de 7 dias (CDC, art. 49; Decreto 7.962/2013, art. 5º). O Anfitrião declara conhecer e aceitar essa regra.

11.3. **Sempre:** a limpeza e a taxa de serviço não usadas são devolvidas. Se o **Anfitrião cancelar**, o Hóspede recebe **100%**.

11.4. **Prazo do reembolso.** O Anfitrião faz o reembolso **em até 5 dias úteis** da data do cancelamento, por Pix para o Hóspede, e anexa na plataforma o **comprovante com o ID da transação**. O Hóspede confirma "Recebi" ou contesta; sem resposta em 72 horas, o reembolso é considerado feito. Se o prazo não for cumprido, abre-se disputa automática, e o Hóspede recebe orientação sobre o Procon e uma declaração da plataforma com o histórico da reserva.

## 12. Força maior

12.1. Nenhuma das partes responde por prejuízo causado por caso fortuito ou força maior (Código Civil, art. 393), como dano grave no imóvel, evento climático ou alerta oficial da Defesa Civil.

12.2. Se o imóvel ficar impróprio para uso antes do check-in por força maior, a reserva é cancelada com **reembolso de 100%** ao Hóspede. O Anfitrião deve comprovar a força maior na plataforma em até 7 dias, para fins do contrato dele com a casalivre.

12.3. [CONFERIR: a força maior do lado do Hóspede (doença, bloqueio de estrada) ainda não tem regra; está como porta futura na spec-02, seção 4c. Até lá, vale a política de cancelamento.]

## 13. Proteção de dados

Cada parte usa os dados pessoais da outra só para executar esta locação e cumprir a lei (Lei 13.709/2018). O Anfitrião não usa os dados do Hóspede para outra finalidade, não os compartilha com terceiros (salvo com a portaria, quando exigido) e os apaga quando não forem mais necessários. O tratamento feito pela casalivre está na Política de Privacidade.

## 14. Comunicação entre as partes

As comunicações sobre esta locação são feitas **pelas mensagens da plataforma**, que servem de registro. Depois da confirmação, os contatos são liberados no cartão da reserva para urgências.

## 15. Foro

15.1. Fica eleito o **foro do domicílio do Hóspede** quando a relação for de consumo (CDC, art. 101, I). Nos demais casos, vale o **foro do lugar do imóvel**, {cidade}/RJ (Lei 8.245/1991, art. 58, II). [CONFERIR: se a locação por temporada feita por anfitrião profissional, via plataforma, é relação de consumo entre anfitrião e hóspede; a regra adotada protege o Hóspede nos dois casos.]

15.2. As partes podem tentar a mediação da plataforma e os órgãos de defesa do consumidor antes de ir à Justiça, sem que isso seja obrigatório.

---

## Anexo I: inventário de móveis e utensílios e estado de conservação

{Gerado a partir do anúncio: item, quantidade, cômodo, estado (novo, bom, regular), observação e foto de referência.}
[CONFERIR com Produto: campo inexistente nas specs; ver cláusula 2.3.]

## Anexo II: regras do condomínio

{PDF anexado pelo Anfitrião no anúncio (spec-03), aceito pelo Hóspede no checkout, com a versão e a data do aceite.}

## Anexo III: resumo da reserva

{Código, datas, hóspedes por faixa etária, composição do preço, chave Pix mascarada do recebedor, política de cancelamento com as datas-limite, data e hora do aceite.}
