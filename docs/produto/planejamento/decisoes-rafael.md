# Decisões que precisam do Rafael (RASCUNHO v0.5, 16/10/2026)
Enviadas pelo Bot Gerente Geral. Cada uma traz a recomendação do Gerente de Produto; valores marcados [E] são estimativa.

| # | Decisão | Por que importa | Recomendação | Prazo (bloqueia) |
|---|---|---|---|---|
| D1 | Aprovar o núcleo: **árvore de gestão de profundidade livre + canais de vitrine separados** (Grupo → Organização + Canal) | Evita refazer: um imóvel em vários canais, split pelo canal | Aprovar | Sprint 5 |
| D2 | **Split por modelo** (proposta do Negócios [E], por R$ 1.000 no canal direto): direto fora de território 940/60 casalivre; direto dentro de território 930/40 casalivre/30 fundo; franquia 900/40/30/30; parceria 900/50 parceiro (0–20% configurável)/50 casalivre, fundo opcional | Seeds da P2b; tudo parametrizado, nada fixo | Aprovar como valores iniciais (mudam por configuração) | Sprint 6 (pode chegar no meio da sprint) |
| D3 | **OTP e e-mail** (cotação do Negócios, `cotacao_d3_d9.md`) | Checkout sem login depende de OTP | WhatsApp (Meta Cloud API, autenticação ~R$ 0,035/mensagem) como principal, SMS Zenvia como reserva, e-mail Resend no piloto e depois SES; ~R$ 50–70/mês no piloto [E]. Precisa de verificação do Business Manager da Meta e DPA (LGPD) | **Bloqueia a Sprint 7** |
| D4 | **PSP do piloto** | P6 | Asaas principal + Pagar.me cartão (pesquisa do Negócios), após cotação formal | P6 |
| D5 | **Sinal e data do saldo** | Limite de custódia de 45 dias (Asaas) | Sinal de 20–30% [E] e saldo em D-30 [E] | P6 |
| D6 | **Chargeback/MED após o repasse** | Chargeback em até 120–540 dias | Proposta do Negócios [E]: reserva técnica de **5% retida até 60 dias após o check-out**, mais o proprietário como `liable` por contrato; o jurídico valida | P6 |
| D7 | **Parecer jurídico** (arranjo/Voto BCB 111/2025, CDC art. 49, limpeza MEI, franquia/fundo, Lei 13.966) | Risco regulatório e de consumidor | Contratar antes da P6; a regra dos 7 dias já entra na P2 | P6 (CDC: Sprint 7) |
| D8 | **Exigir CPF validado do hóspede para reservar** | KYC e antifraude; o campo `users.cpf` já existe | Sim, com tratamento LGPD definido pela Segurança | Sprint 6 |
| D9 | **Assistente de IA** | Custo e LGPD | Cotação ~R$ 60/mês por 1 mil conversas [E]. Adiar para depois da P4 | — |
| D10 | **Boleto no piloto** | Mais fraude/atraso, público menor [NV] | Não no piloto; avaliar depois com dado de uso | P6 |
| D11 | **Piloto antes da P6** | Receita e controle sem PSP | Grupo controlado; reserva registrada na plataforma com pagamento marcado "fora"; o ledger lança a comissão como conta a receber do proprietário, cobrada por fatura mensal (P9); vale até a P6 | Sprint 7 |
| D12 | **Royalty no canal OTA: 2% ou 3%** (franquia; demais modelos pendentes). **Decidido 2026-10-16: royalty e fundo de 2% na OTA.** | Margem estreita (plano: alavanca principal) | Negócios recomenda; parâmetro, não bloqueia o código | P6/channel manager |
| D13 | **Limpeza paga direto ao prestador** (contrato e nota próprios) | Tributário e split | **Já aprovada pelo Rafael**; registrar só para constar | — |
| D14 | **Texto dos 3 termos** (locação, limpeza, plataforma) | Aceite no checkout desde a P2 | Jurídico (D7) redige a v1 antes da Sprint 7 | Sprint 7 |
| D15 | **Regime tributário/NFS-e dos recebedores** | Campo já na P2b; emissão só no futuro | Guardar o dado agora e decidir a emissão (prefeitura/provedor) depois | — |
| D16 | **Hospedagem e infra** | — | **DECIDIDA pelo Rafael (16/10):** container Void Linux musl (nginx, PHP-FPM 8.4, PostgreSQL 18, Valkey, runit) nos bots, no Incus do Rafael e na ValueHost (bare-metal Void). Sai da lista de decisões abertas | — |
| D17 | **Decisões da spec-01** (checkout sem login + OTP, taxa de serviço 0% parametrizável, pagamento fora com 24 h, aprovação em 24 h/4 h, localização ~500 m, limpeza no total) | — | **DECIDIDAS (UI + Negócios)**; incorporadas à P2. Confirmar com o Rafael só se ele quiser rever | — |
| D18 | **PostgreSQL primeiro** | Evita otimizar dois bancos | **DECIDIDA pelo Rafael (16/10):** o SQLite continua funcionando (testes de paridade funcional); índices finos, EXPLAIN e medição só no PostgreSQL 18 | — |
| D19 | **Ordem P0 × Sprint 4** | Medir o ADR 001 uma vez só, no ambiente final | Recomendação: P0 antes do ADR 001 ou no início da S4 (aguarda o Gerente e o Rafael) | S4 |
