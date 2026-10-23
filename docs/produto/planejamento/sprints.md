# Proposta de sequência de sprints (RASCUNHO, 16/10/2026)
**Para o Bot Gerente Geral.** É uma proposta de produto, não uma ordem: o Gerente define o como e o quando, e o Rafael aprova o plano semanal.
Premissa: Sprint 4 (ADR 001, T1–T7 + T1b) e a correção de XSS ALTA já aprovadas, e feitas antes. **Ajustes de produto na S4** (`avaliacao-sprint4.md`): vitrine genérica (T3, T6), função única de disponibilidade (T3), preço total + por noite (T5), medição no PG 18 (T1 e no fechamento). **Recomendação (D19): P0 antes do ADR 001 ou no início da S4**, para medir o ADR uma vez só no PG 18 + PHP-FPM. **Só PostgreSQL (decisão do Rafael de 21/10, substitui a D18).**
Duração e esforço são estimativas.

## Sprint 5 — Infra (P0) + árvore de gestão e canais (P2a) + métricas (P1)
- **1º item: P0 — receita versionada `ops/dev-env/`** (critérios P0 1–10). Revisões: Arquitetura, Segurança, Banco de Dados. Os demais itens da sprint já rodam nesse ambiente.
- **Objetivo:** separar gestão de vitrine sem quebrar o que existe; funil instrumentado.
- **Critérios de aceite:** P2a 1–5; P1 1–4 (+ canal de origem nos eventos).
- **Depende de:** Sprint 4.
- **Revisões:** Arquitetura (resolvedor de canal/domínio), Banco de Dados (schema, seeds, recriação do banco), Segurança (papéis, eventos sem dado pessoal).
- **Pré-condição:** D1 aprovada.

## Sprint 6 — Recebedores, split, ledger e termos (P2b)
- **Objetivo:** modelar as portas de dinheiro; só os 4 cenários da D2 configurados.
- **Critérios de aceite:** P2b 1–10 (8 = chave Pix, com revisão obrigatória da Segurança; 9 = mover nó da árvore; 10 = acréscimo por canal).
- **Depende de:** Sprint 5.
- **Revisões:** Banco de Dados, Arquitetura, Segurança.
- **Pré-condição:** D2 (números) e D12 (canal OTA) **podem chegar depois**, porque são parâmetros; os seeds usam a proposta [E].

## Sprint 7 — Reserva (P2) + cadastro de anúncio (P10)
- A P10 vem por fatias: na S5, fotos (libvips) e a etapa 1 (nó, endereço, pin); na S6, recebedores, canais e preço por canal (junto com a P2b); na S7, regras, políticas, checklist e publicação, antes da reserva usar os snapshots.
- **Depende de:** Sprint 6.
- **Revisões:** Arquitetura, Banco de Dados, Segurança, UI.
- **Pré-condições:** D11 aprovada (modo `fora`), D14 (texto dos 3 termos) e **D3 aprovada: bloqueia a Sprint 7**, porque o checkout sem login depende de OTP (WhatsApp/SMS) e de e-mail.
- **Critérios de aceite:** P2 1–23.

## Sprint 8 — Fechar a jornada (P3 + P4 + P9)
- **Objetivo:** avaliação confiável e preço transparente.
- **Critérios de aceite:** P3 1–4; P4 1–4; P9 1–3.
- **Revisões:** + Banco de Dados (P9).
- **Depende de:** Sprint 7.
- **Revisões:** UI e Segurança (formulário de avaliação, XSS).

## Sprint 9 — Comparar e mensagens (P5 + P7)
- **Critérios de aceite:** P5 1–3; P7 1–2.
- **Revisões:** UI, Segurança (mensagens, mascaramento, LGPD).

## Depois — P6 Pagamento
Só depois das decisões D1–D6 e do parecer jurídico. Divide-se em sprints de Pix, liberação/split, cartão, saldo e conciliação (`pagamentos.md`, seção 9).
Revisões: Segurança (webhooks, PCI, dados), Arquitetura (filas Valkey, webhooks), Banco de Dados (ledger).
Antes dela: homologação única pré-produção (regra atual).

## Ritos de produto por sprint
- Antes de cada sprint: alinhar com o Negócios e entregar ao Gerente a lista pronta.
- 2 semanas após cada entrega: ler a métrica que a entrega deveria mover (P1).

## O que mudou (v0.2)
- A Sprint 5 ficou só com árvore + canais + P1.
- A Sprint 6 virou a P2b (portas de dinheiro).
- A P9 entrou na Sprint 8.
- A sequência total não ganhou sprint nova: o esforço extra [E] (+2 semanas-bot) foi absorvido pela divisão. Se o Gerente medir que não cabe, a P9 é a primeira a escorregar para a Sprint 9.

## O que mudou (v0.3)
- A Sprint 5 começa pela P0 (infra).
- A Sprint 7 ficou maior: P2 com OTP e checkout sem login, +1 semana [E]. Se não couber, a P9 vai para a Sprint 9, e as regras de localização aproximada e taxa de serviço podem ser a última fatia da P2 (não há dependência inversa).

## O que mudou (v0.4)
- Regras aprovadas incorporadas: P2b 8–9 e P2 19–23.
- Nova métrica de confiabilidade.
- Esforço estimado [E]: +0,5 semana na P2b e +0,5 na P2, absorvido nas mesmas sprints. Se não couber, a P9 vai para a Sprint 9.

## O que mudou (v0.5)
- spec-03 entrou como P10, fatiada entre a S5 e a S7.
- D18 (PostgreSQL primeiro) decidida.
- D19 (P0 antes da S4) recomendada.
- Esforço [E]: +4 semanas da P10, distribuídas. A S7 continua sendo a mais carregada; se não couber, a P9 vai para a S9 e as fatias recomendadas da P10 (acessibilidade e mais de 10 fotos no checklist) podem ir depois.

## O que mudou (v0.6, 16/10): Jurídico sem orientação externa
- S5 e S6: Produto e Jurídico escrevem os textos (termos D14, contrato do anfitrião, privacidade, cancelamento), sem trabalho de código.
- S6: textos publicados com versão e data no motor de aceite, e os campos do ledger para o IBS/CBS.
- S7: aviso de pagamento fora da plataforma no checkout.
- S8: relatório anual por anfitrião e aviso municipal.
- Gate de go-live do piloto depois da S8 (J-PIL).
