# casalivre — Diretrizes de pagamento (RASCUNHO v0.5, 16/10/2026)
**Status:** documento orientador. Pagamento **não será construído agora** (decisão do Rafael, 16/10). Ele vira a P6 do backlog, no médio prazo. Aqui ficam as regras que as funcionalidades de agora precisam respeitar para o pagamento se encaixar depois.
**Base:** pesquisa do Bot Negócios `plano-negocios-casalivre/pesquisa_pagamentos.md` (tags e números dele: [F] fonte oficial, [E] estimativa, [NV] não verificado; Fn = fonte da pesquisa). Fontes que eu verifiquei estão marcadas [W] e listadas no fim. Nenhum número foi inventado: o que falta está na seção 9.

## 1. Princípios
1. **O casalivre nunca guarda o dinheiro na própria conta.** A custódia fica no PSP (escrow ou subconta), com split automático. Guardar e repassar exige aderir ao arranjo de pagamento (Lei 12.865/2013, art. 6º; Res. BCB 150/2021) [W1][W2][F17][F18]. Parecer jurídico obrigatório.
2. **Fraude zero não existe; a meta é minimizar.** Os meios são 3DS, antifraude, KYC dos recebedores e o modelo sinal + saldo [E].
3. **Nenhum provedor verificado segura dinheiro por meses** [F]. O Asaas segura no máximo 45 dias por subconta (escrow com liberação via API) [F2][F3]. O Stripe BR não retém nada [F15]. Por isso o desenho é: **sinal na reserva + saldo cobrado automaticamente 30–45 dias antes do check-in** [E]. Reservas com check-in em até 45 dias são cobradas integralmente, com escrow até check-in + 24 h [E].
4. **Piloto sugerido pelo Negócios:** Asaas como principal (Pix, boleto, escrow, split) e Pagar.me para cartão (antifraude, 3DS 2.0, cobertura de fraude, `liable` configurável no split) [F5][F7]. Decisão do Rafael.

## 2. Split por modelo e canal (valores [E] da proposta do Negócios; o Rafael decide em D2/D12; tudo em parâmetro)
Por R$ 1.000 de diária, canal direto:

| Modelo | Proprietário | Gestor/parceiro | casalivre | Fundo regional |
|---|---|---|---|---|
| Direto, fora de território franqueado | 940 | — | 60 | — |
| Direto, dentro de território franqueado | 930 | — | 40 | 30 |
| Franquia (M13) | 900 | 40 (corretor) | 30 (royalty) | 30 |
| Parceria simples | 900 | 50 (configurável 0–20%) | 50 | opcional |

- **Canal é dimensão do split:** OTA na franquia = 4% corretor + royalty 2% (D12, decidido 2026-10-16) + 2% fundo, além da taxa da OTA. Os outros modelos no OTA estão pendentes.
- **Limpeza:** o hóspede paga direto ao prestador, com contrato e nota próprios (aprovado pelo Rafael). Fica fora do split da diária. Na P6 o prestador é um recebedor com cobrança própria.
- **Termos no checkout:** locação, limpeza e plataforma, versionados, com o aceite gravado na reserva.
- O fundo regional tem saldo próprio no ledger agora; a subconta real só na P6.
- Taxas do meio de pagamento (MDR) e parcelamento até 6x [F25]: quem absorve fica na D4/D5.

## 3. Fluxos por meio
**Pix (meio preferencial)**
1. A reserva é aceita e gera uma cobrança Pix com QR **com validade curta** (sugestão: 30 min [E]).
2. O webhook do PSP é validado (assinatura/token) e o valor vai para o escrow da subconta.
3. A reserva vira `confirmada`.
4. Check-in + 24 h sem ocorrência: o escrow é liberado e o split é executado.

**Cartão**
1. Tokenização no PSP: o cartão nunca passa pelo nosso servidor (escopo PCI).
2. 3DS 2, depois antifraude, depois autorização.
3. Sinal capturado agora. Saldo cobrado no token 30–45 dias antes do check-in.
4. Liberação após o check-in, como no Pix.

**Boleto**
1. Só se o check-in estiver a **≥ 7 dias** [E][F6].
2. Vencimento em 1–2 dias [E].
3. Confirmação **só depois da compensação**. A liquidação pode ser D+0 se pago até 13h30 e D+1 depois disso [W6]; o banco tem até 3 dias úteis após confirmar [W7].
4. Enquanto isso, as datas ficam em `pre_reserva` com prazo. Venceu sem pagamento: expira e libera as datas.

## 4. Fraude: ameaças e controles
### Cartão
- **Cartão roubado / CNP:** 3DS 2 obrigatório. O **liability shift** só vale com autenticação bem-sucedida (ECI 05 Visa/Elo/Amex, 02 Mastercard). Data Only ou não autenticado deixa o risco conosco [W4]. Antes do 3DS, score antifraude do PSP.
- **Janela de chargeback maior que a liberação:** o padrão é até 120 dias da transação. Em serviço futuro ou viagem pode chegar a **540 dias** [W3][W5]. O "~180 dias" do pedido não foi confirmado: 180 aparece só para UnionPay [W5]. Ou seja, o chargeback chega **depois** do repasse.
  - Controles: regra de **`liable`** no split (quem paga) [F7]; **reserva técnica** (proposta: 5% retida até 60 dias após o check-out [E]; cobre só parte da janela de 120–540 dias, então o restante fica com o `liable` por contrato); cláusula contratual de débito no proprietário ou corretor.
  - Evidências da defesa (representment): aceite dos termos, IP, 3DS, registro do check-in, mensagens.
- **Friendly fraud** (o hóspede se hospedou e contesta): guardar evidência de check-in (código ou confirmação do anfitrião) e as mensagens.
- **Velocidade:** limites por cartão, CPF, IP e dispositivo (ex.: N tentativas por hora; valor a definir [E]). Bloquear vários cartões na mesma conta.
- **Nome/CPF:** o CPF do titular do pagamento é comparado com o do hóspede. Divergência vai para revisão ou para o 3DS desafiado.

### Pix
- **MED:** a vítima pede o MED em até 80 dias da transação; a instituição recebedora analisa em até 7 dias; aceito o pedido, a devolução ocorre em horas [W8][W9]. O **MED 2.0** (obrigatório desde 02/02/2026 [F19][F20]) rastreia o dinheiro além da 1ª conta e pode buscá-lo depois do repasse. Desde 01/09/2026, o recebedor tem 80 dias, contados da devolução, para contestá-la (IN BCB 766) [W8]. O MED não vale para arrependimento nem desacordo comercial [W10].
  - Controle: o escrow até o check-in reduz o risco. Depois do repasse, a regra de `liable` e a **reserva técnica de 5% retida até 60 dias após o check-out** [E, D6, o jurídico valida] valem como no cartão.
- **Conta do pagador invadida (account takeover):** comparar o CPF do pagador (o PSP informa) com o CPF do hóspede. Divergência: reserva em `em_revisao` ou devolução automática.
- **QR vencido ou reutilizado:** `txid` único por cobrança. Recebimento de cobrança expirada ou cancelada é devolvido.
- **Webhook falso ou repetido:** assinatura/token verificados, idempotência por id do evento, e consulta de confirmação na API do PSP antes de mudar o estado.

### Boleto
- Sem chargeback, mas há risco de **boleto falso/phishing** (o hóspede paga a um terceiro). Controles:
  - boleto só gerado e mostrado dentro da área logada;
  - linha digitável conferida na página da reserva;
  - beneficiário identificado como o PSP + casalivre;
  - aviso de que nunca enviamos boleto por WhatsApp.
- Atraso na compensação: confirmar só após a liquidação.

### Fraudes da plataforma
| Ameaça | Controle |
|---|---|
| Anúncio falso | KYC do proprietário (CPF/CNPJ, titularidade da conta de recebimento igual ao CPF), comprovante de vínculo com o imóvel validado pelo franqueado, primeiro repasse só após a 1ª estadia confirmada |
| Fraude do anfitrião (no-show, imóvel diferente) | escrow até check-in + 24 h, canal de problema na chegada que congela a liberação |
| Pagamento por fora | dados de contato mascarados até a confirmação; mensagens com detecção de Pix/telefone; benefício (proteção) só no pagamento pela plataforma |
| Abuso de reembolso | política por escrito no momento da reserva, limite por CPF, histórico de cancelamentos |
| Conluio franqueado–proprietário (reservas fictícias para bônus ou lavagem) | alerta se o CPF do hóspede for igual ao do proprietário ou do corretor, ou se o mesmo cartão/CPF se repetir; auditoria por amostragem; bônus de captação só após estadias reais |
| Lavagem de dinheiro / KYC dos recebedores | KYC e PLD feitos pelo PSP em cada subconta; o casalivre guarda o status; nenhum recebedor ativo sem KYC aprovado |

## 5. Máquina de estados (reserva × pagamento)
```
solicitada ─(24h; 4h se check-in<72h)→ expirada
   │
   ▼
 aceita (manual) ou instantânea (datas bloqueadas) → aguardando_pagamento [prazo 24h; 4h se check-in<72h; cancelamento grátis] ─(expira; piloto "fora": 24h sem "marcar como pago")→ expirada
                          │ webhook confirmado
                          ▼
              sinal_pago ─(saldo em D-30..45 falhou)→ inadimplente → cancelada
                          │ saldo pago (ou pagamento integral)
                          ▼
                     confirmada → em_estadia (check-in) → concluida (check-in+24h: libera escrow, split)
qualquer antes de em_estadia → cancelada (reembolso pela política)
em_estadia/concluida → em_disputa (chargeback/MED/problema) → resolvida
```
- Regras: transição só pelo serviço de domínio, com log; webhook nunca pula estado; datas ficam bloqueadas (`origin='reserva'`) de `aceita` até `cancelada` ou `expirada`.

## 6. Cancelamento e reembolso
- **CDC art. 49:** arrependimento em 7 dias na compra fora do estabelecimento. Tribunais tendem a aplicá-lo a reserva online; o tema não está pacificado no STJ [F21][F22][W11]. Diretriz [E]: reembolso integral em até 7 dias após a reserva, desde que falte prazo razoável até o check-in (prazo a definir com advogado).
- Depois disso vale a política escolhida pelo anfitrião (spec-03), mostrada e aceita antes do pagamento, e gravada na reserva (snapshot):
  - **Flexível:** 100% até 24 h antes do check-in; depois, a 1ª diária não é devolvida.
  - **Moderada:** 100% até 5 dias antes; depois, 50%.
  - **Rígida:** 100% até 14 dias antes; 50% entre 14 e 7 dias; 0% com menos de 7 dias.
  - Vale para todas: limpeza e taxa não usadas são sempre devolvidas; cancelamento pelo anfitrião devolve 100%; em `aguardando_pagamento`, o cancelamento é gratuito.
  - Os textos legais dependem da D14.

| Meio | Como reembolsa |
|---|---|
| Pix | devolução Pix pelo PSP (do escrow, antes do repasse) |
| Cartão | estorno no PSP. Antes da captura, cancelar a autorização; parcelado, estorno total ou parcial conforme o PSP [NV] |
| Boleto | sem estorno nativo: devolução por Pix/TED para uma conta **do mesmo CPF do pagador** |

- Anfitrião cancela: reembolso integral ao hóspede, com penalidade ao anfitrião (a definir).

## 7. Conciliação, webhooks e auditoria
- Webhooks: verificação de assinatura/token, idempotência (tabela de eventos com id único), resposta 2xx rápida e processamento em fila (`JobQueue` já existe), reconsulta no PSP.
- **Livro-razão de partidas dobradas** (ledger) em PostgreSQL:
  - cada movimento é débito/crédito entre contas lógicas (hóspede, escrow, proprietário, corretor, royalty, fundo, limpeza, reserva técnica, PSP-taxas), em centavos inteiros (`money_unit` já existe);
  - linhas imutáveis: correção só por lançamento reverso;
  - soma zero por transação.
- Conciliação diária: o extrato/relatório do PSP é cruzado com o ledger, e divergências viram alerta.
- Auditoria: quem, quando e de onde (sem PAN de cartão; CPF mascarado nos logs, via LogPrivacy). Retenção definida pela Segurança e pela LGPD.

## 8. O que isso exige das funcionalidades anteriores (vale **agora**)
1. **Árvore e canais (P2a):** a gestão (quem administra/recebe) é separada da vitrine (onde aparece). A reserva grava o canal de origem.
2. **Recebedores/split (P2b):** CPF/CNPJ, regime tributário e KYC. Regras por percentual, fixo, item e mínimo, por canal e versionadas.
3. **Ledger (P2b):** partidas dobradas, em centavos e imutável. Contas: comissão, taxa de serviço, mensalidade SaaS (0% comissão), recorrência, conta a receber, fundo regional, reserva técnica, afiliado e recebedor eventual.
4. **Reserva (P2):** estados de pagamento já previstos. Snapshot de preço (limpeza como item próprio), split, política, CDC 7 dias e versões dos 3 termos. Check-in registrado. Modo `fora` para o piloto.
5. **Piloto D11 (P9):** na P2, o estado `aguardando_pagamento` é o mesmo da P6 (chave Pix do imóvel, 24 h, "marcar como pago", aviso de que não há proteção casalivre). comissão como conta a receber do proprietário e fatura mensal. Quando a P6 entrar, o modo `fora` é desligado por configuração, sem migrar reservas.
6. **Mensagens (P7):** contato mascarado.
7. **Filas, webhooks e expirações:** no Valkey com worker no runit (P0), idempotentes; nada em cron.
8. **Identificação:** checkout com e-mail + telefone + CPF + OTP. O CPF serve de base para comparar com o pagador (Pix/cartão) na P6.
9. **Taxa de serviço do hóspede:** 0%, parametrizável por canal/organização, já como item no snapshot e conta no ledger.
10. **Chave Pix do recebedor (P2b 8):** OTP de quem altera, aviso ao proprietário, carência de 24–48 h, titular igual ao CPF/CNPJ, auditoria, gestor só com delegação. Controle contra troca fraudulenta da chave (desvio de pagamento).
11. **Estadia e limpeza** são confirmadas separadamente, com lançamentos próprios (P2 19).
12. **Anti-abuso:** no máximo 1 reserva aguardando pagamento por hóspede, limite de expirações seguidas e restrição para quem cancela em série (P2 20–21).
13. **Preço por canal** (spec-03): preço base único + acréscimo percentual do canal; o split também varia por canal. Total = (diárias × fator do canal) + limpeza + taxa, gravado no snapshot.

## 9. Histórias de usuário (FUTURAS — P6; RICE = ESTIMATIVA)
| # | História | MoSCoW | RICE (R×I×C÷E) |
|---|---|---|---|
| P6.1 | Como hóspede, pago o sinal por Pix e a reserva é confirmada | Must | 8×3×50%÷3 = 4,0 |
| P6.2 | Como plataforma, libero o escrow e faço o split no check-in + 24 h | Must | 8×3×40%÷3 = 3,2 |
| P6.3 | Como hóspede, pago com cartão com 3DS | Must | 6×2×50%÷3 = 2,0 |
| P6.4 | Saldo cobrado automaticamente em D-30..45 | Must | 6×2×40%÷3 = 1,6 |
| P6.5 | Cancelo e recebo o reembolso pela política | Must | 6×2×50%÷2 = 3,0 |
| P6.6 | Conciliação diária PSP × ledger | Should | 3×2×60%÷2 = 1,8 |
| P6.7 | Boleto (≥ 7 dias do check-in) | Could | 3×1×50%÷2 = 0,75 |

Critérios de aceite:
- **P6.1:**
  1. O QR expira no prazo configurado; pagamento após a expiração gera devolução.
  2. Webhook com assinatura inválida → 401 e nenhuma mudança de estado.
  3. O mesmo evento enviado 2× produz 1 lançamento só.
  4. CPF do pagador diferente do hóspede → `em_revisao`.
- **P6.2:**
  1. Nada é liberado antes de check-in + 24 h.
  2. Disputa aberta bloqueia a liberação.
  3. O split segue a regra do canal de origem gravada no snapshot (ex.: 900/40/30/30 na franquia); 5% de reserva técnica retida até check-out + 60 dias [E]; soma zero no ledger.
- **P6.3:**
  1. Transação sem ECI de autenticação (05/02/06/01) não é capturada acima do limite configurado.
  2. O número do cartão nunca toca o servidor (tokenização).
  3. Mais de N tentativas por CPF/hora → bloqueio.
- **P6.4:**
  1. A cobrança roda uma vez só (idempotente).
  2. Falha → e-mail/aviso, nova tentativa em 48 h e, depois, `inadimplente` → `cancelada` pela política.
- **P6.5:**
  1. Cancelamento em até 7 dias da reserva, respeitando o prazo mínimo até o check-in → reembolso de 100%.
  2. O valor segue a política do snapshot.
  3. Boleto → devolução só para conta do mesmo CPF.
- **P6.6:**
  1. Divergência de 1 centavo gera alerta.
  2. Relatório diário com contagem e soma por meio.
- **P6.7:**
  1. Check-in a menos de 7 dias → opção boleto oculta/recusada.
  2. Datas liberadas no vencimento sem pagamento.

## 10. Decisões para o Rafael e dados faltantes
**Decisões:**
1. PSP do piloto (Asaas + Pagar.me?) e cotação formal (as taxas são promocionais [F1][F5]).
2. Percentual do sinal (o Negócios sugere 20–30% [E]) e a data do saldo (D-30 ou D-45).
3. Chargeback/MED: proposta de 5% de reserva técnica por 60 dias após o check-out + proprietário `liable` [E] (D6; o jurídico valida).
4. Split dos modelos: proposta do Negócios em D2; royalty OTA de 2% (D12, decidido 2026-10-16).
5. Repassar o MDR do cartão ao hóspede ou absorvê-lo; parcelamento até 6x.
6. Boleto entra no piloto?
7. Parecer jurídico: arranjo/Voto BCB 111/2025, CDC art. 49, tributação da limpeza MEI.

**Dados faltantes:**
- taxa de fraude e de chargeback esperada no setor (não encontrada);
- custo da cobertura de fraude do Pagar.me;
- 3DS/antifraude do Asaas [NV];
- custo de mensalidade por subconta no Asaas;
- volume esperado por meio (não há produção);
- prazo de resposta a chargeback no PSP escolhido.

## Fontes verificadas nesta rodada [W] (as Fn estão em `pesquisa_pagamentos.md`)
- W1 BCB, arranjos (Lei 12.865 art. 6º): https://www.bcb.gov.br/content/estabilidadefinanceira/Documents/sistema_pagamentos_brasileiro/Forum_SPB/Sistema_de_Pagamentos_Brasileiro-Arranjos_de_Pagamentos.pdf
- W2 Res. BCB 150/2021 (subcredenciador) e Visa marketplace / Res. BCB 522/2025: https://legismap.com.br/leis-e-normas/resolucao-bcb-n-150-de-06-10-2021 · https://www.visa.com.br/parceiros-visa/informacoes-para-parceiros/market-place.html
- W3 Prazos de chargeback 120/540 dias: https://suporte.celcoin.com.br/hc/pt-br/articles/42782573168155-Qual-o-prazo-para-o-recebimento-de-um-chargeback
- W4 3DS e liability shift (ECI): https://developercielo.github.io/manual/3ds
- W5 Prazos por bandeira: https://docs.adyen.com/risk-management/understanding-disputes/dispute-timeframes
- W6 Febraban, boleto D+0 até 13h30: https://portal.febraban.org.br/noticia/4072/pt-br/
- W7 Compensação de boleto até 3 dias úteis: https://ajuda.zoop.com.br/hc/pt-br/articles/5029450153243-Boletos-Pagamento-e-compensa%C3%A7%C3%A3o
- W8 MED, 80 dias e IN BCB 766: https://agenciabrasil.ebc.com.br/economia/noticia/2026-09/nova-regra-do-pix-amplia-prazo-para-contestar-golpe
- W9 Prazos operacionais do MED 2.0: https://suporte.celcoin.com.br/hc/pt-br/articles/49481520101147-Quais-s%C3%A3o-os-prazos-do-MED-2-0 · BCB: https://www.bcb.gov.br/content/estabilidadefinanceira/pix/MED/MED_2-0_Circuito_Pix-Dia_2.pdf
- W10 MED não cobre desacordo comercial: https://juristas.com.br/noticias/banco-central-amplia-de-30-para-80-dias-prazo-para-contestar-devolucoes-do-pix-pelo-med/
- W11 CDC art. 49 e hospedagem: https://jus.com.br/duvidas/684195/cdc-art-arrependimento-7-dias-se-aplica-a-cancelamento-de-hospedagem
