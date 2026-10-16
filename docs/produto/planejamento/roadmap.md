# casalivre — Visão e roadmap (RASCUNHO v0, 16/10/2026)
Status: proposta do Bot Gerente de Produto. Não aprovado. Escopo/custo/direção → aprovação do Rafael via Gerente Geral.
Base: develop @ 2460ed0 (#99), plano de negócios v1.0 (14/10), casos-de-uso.md v1, prompts/backlog.md.

## Visão
Motor de reserva direta para aluguel de temporada, começando na Região dos Lagos (RJ): o hóspede encontra, compara e reserva com pagamento protegido (Pix em custódia, liberado após o check-in); o proprietário fica com 90% no canal direto, com gestão local por corretor-franqueado. (Fonte: plano de negócios, seções 1–4.)

## Onde estamos (fato, código)
Há vitrine e catálogo: cadastro/login, conta (com exclusão), cadastro de imóvel, fotos (fila, capa), calendário com bloqueio e iCal (import/export), busca por cidade/raio/hóspedes/datas/comodidades, mapa (OSM; PMTiles planejado), página do imóvel com preço total estimado, avaliação simples (anônima), grupos/vitrines por domínio, temas, admin de grupos e temas, i18n PT/EN/ES, API JSON.
Não há: reserva, pagamento, mensagens, comparação/favoritos, avaliação vinculada à estadia, painel admin de operação, assistente de IA, métricas de funil. E-mail desligado (sem provedor).

## Curto prazo (próximos 1–2 ciclos) — "fechar o funil mínimo"
1. Pré-requisitos já aprovados: Sprint 4 (busca SQL paginada + índice) e XSS ALTA.
2. Instrumentação de funil (eventos busca→imóvel→início de reserva).
3. Solicitação de reserva sob aprovação (sem pagamento online): hóspede pede, proprietário aceita/recusa, datas bloqueadas com origin 'reserva'.
4. Avaliação vinculada a estadia concluída.
5. Decisão do Rafael: provedor de e-mail (bloqueia notificações de reserva e abertura do cadastro).

## Médio prazo — "pagar com proteção"
- Pagamento Pix com custódia e split via instituição parceira (escolha do parceiro = decisão Rafael/Negócios, jurídico).
- Reserva instantânea, política de cancelamento e reembolso, recibo.
- Mensagens hóspede↔proprietário/corretor; "minhas viagens".
- Comparar/favoritar imóveis; filtros de preço total.
- Papel corretor-franqueado e site regional (grupos já são a base).

## Longo prazo
- Channel manager Airbnb/Booking via parceiro certificado (hoje só iCal).
- Assistente de IA para visitantes (busca conversacional, dúvidas do imóvel) — depende de custo de IA (Negócios) e LGPD (Segurança).
- Painel do proprietário (ganhos, repasses, ocupação), coanfitrião, rede de prestadores/chamados.

## Dependências e restrições
Stack fixa (PHP 8.4, PDO, PostgreSQL/SQLite). **Infra decidida pelo Rafael (16/10):** ambiente igual ao de produção desde já, em container Void Linux musl com nginx, PHP-FPM 8.4 + OPcache, PostgreSQL 18, Valkey (filas) e runit, sem Docker. Os bots rodam o ambiente nos próprios computadores (rootless via bwrap); o Incus do Rafael é só para os testes dele. Na ValueHost o mesmo container roda em bare-metal Void. Pagamento e IA exigem revisão de Segurança (LGPD, dados de pagamento) e Arquitetura (filas Valkey, webhooks). Sem dados de uso reais: não há produção.

---
## Arquitetura de produto (v0.3, 16/10 — Negócios + spec-01 + infra)
Base: definições do Negócios.
- Modelos obrigatórios no lançamento: proprietário direto, franquia (M13) e parceria simples.
- A aprovação de reserva é configurável por imóvel (instantânea ou manual), pode ser delegada e tem prazo de expiração.
- O fundo regional tem saldo próprio no ledger; a subconta real só na P6.
- A limpeza é paga pelo hóspede direto ao prestador, com contrato e nota próprios (aprovado pelo Rafael).
Regra de escopo: **modelar as portas, não construí-las.** Cada estrutura já aceita as variações futuras como dado, mas só a variação necessária agora tem tela e fluxo.

### Núcleo: duas árvores separadas + canais
1. **Árvore de gestão (quem administra e quem recebe):** organizações em profundidade livre (`parent_id`). O `tipo` (plataforma, rede, gestor, parceiro…) é só um **rótulo**, sem regra embutida. Papéis e permissões valem por organização. Nasce da evolução do `groups`/`group_members`/convites atuais.
2. **Canais de distribuição/vitrine (onde o imóvel aparece):** marketplace casalivre, site regional, site do parceiro e, no futuro, OTA. Cada canal tem domínio e tema (os `group_domains` e temas atuais passam a pertencer ao **canal**). Um imóvel pode estar em vários canais ao mesmo tempo. **Não** vale a regra "o imóvel herda a vitrine da organização mais próxima".
3. **Split = f(árvore de gestão, canal de origem da reserva, configuração).** A reserva grava o canal de origem, e a regra aplicada depende dele.

### Regras de split e ledger (portas modeladas)
- Tipos de regra: percentual, valor fixo, sobre um item específico (diárias, taxa de serviço do hóspede…), com mínimo. Versionadas e com vigência. Parâmetros sempre em dado, **nada fixo no código**.
- Recebedores: pessoa ou organização com CPF/CNPJ, regime tributário (para NFS-e futura) e status de KYC. Tipos possíveis: proprietário, gestor/franqueado, parceiro, casalivre, fundo regional, prestador de limpeza, afiliado/indicação por link, recebedor eventual.
- Ledger de partidas dobradas aberto a outras receitas: comissão de reserva, taxa de serviço do hóspede, mensalidade SaaS/white-label (comissão 0%), cobranças recorrentes, contas a receber (ex.: comissão de reserva paga "fora", faturada por fatura mensal).
- Termos versionados aceitos no checkout: **locação** (hóspede × proprietário), **limpeza** (hóspede × prestador) e **plataforma** (uso do casalivre). A reserva grava qual versão de cada um foi aceita.

### Modelos de negócio como configuração (valores por R$ 1.000 de diária; **[E] proposta do Negócios, o Rafael decide**)
| Configuração | Proprietário direto fora de território franqueado | Proprietário direto dentro de território franqueado | Franquia (M13) | Parceria simples |
|---|---|---|---|---|
| Árvore de gestão | plataforma > imóvel | plataforma > rede > imóvel (sem corretor) | plataforma > rede (franqueado) > imóvel | plataforma > parceiro > imóvel |
| Split canal direto | 940 proprietário / 60 casalivre | 930 / 40 casalivre / 30 fundo regional | 900 / 40 corretor / 30 royalty / 30 fundo | 900 / 50 parceiro (configurável 0–20%) / 50 casalivre; fundo opcional |
| Split canal OTA | pendente | pendente | 4% corretor + royalty **2% (D12, decidido 2026-10-16)** + 2% fundo, além da taxa da OTA | pendente |
| Limpeza | hóspede → prestador direto (contrato e nota próprios); fora do split da diária | idem | idem | idem |
| Quem aprova a reserva | dono | dono | dono ou franqueado (delegável) | dono ou parceiro (delegável) |
| Canais | marketplace; site próprio opcional | marketplace + site regional | marketplace + site regional | marketplace + site do parceiro opcional |
| KYC dos recebedores | dono | dono + fundo | dono + franqueado (PJ) + fundo | dono + parceiro |
| Termos no checkout | locação + limpeza + plataforma | idem | idem | idem |

Piloto antes da P6 (D11): grupo controlado, reserva registrada na plataforma com pagamento marcado "fora". O ledger lança a comissão como **conta a receber do proprietário**, cobrada por fatura mensal. Vale até a P6.

### Princípios anti-retrabalho
1. Modelos de negócio são **dado** (árvore + canal + regras + termos), nunca `if (modelo)`.
2. A árvore de gestão e os canais de vitrine são independentes. O `tipo` da organização é só rótulo, e a profundidade é livre.
3. Evoluir, não duplicar: Grupo → Organização + Canal; `calendar_days.origin='reserva'`; `JobQueue`.
4. Contratos estáveis primeiro: estados da reserva, snapshot (preço, split, política, termos, canal) e ledger já com as portas de pagamento e de outras receitas.
5. Centavos inteiros e ledger imutável desde o 1º registro de valor.
6. Portas modeladas ≠ funcionalidade construída: só ganha tela o que a sprint precisa.
7. Paridade web/API.
8. Integração externa (PSP, e-mail, IA, NFS-e) só depois da decisão do Rafael, atrás de uma interface.
9. Toda funcionalidade traz o seu evento de métrica (P1).
11. **Filas, expirações e notificações usam Valkey desde o início** (worker supervisionado pelo runit). Nada de solução provisória com cron que precise ser refeita.
12. **Desenvolver no ambiente igual ao de produção** (receita `ops/dev-env/`): testes não dependem da máquina, e a suíte roda em PostgreSQL.
10. Revisões: schema → Arquitetura + Banco de Dados; dinheiro/dado pessoal → Segurança; jornada → UI.

### Decisões da spec-01 (UI + Negócios, 16/10)
- **Checkout sem login prévio:** identificação por e-mail + telefone + CPF, com código OTP; a conta é criada sem pedir cadastro à parte.
- **Taxa de serviço do hóspede:** 0% por padrão, ajustável por canal e organização.
- **Piloto com pagamento fora da plataforma:**
  - estado `aguardando_pagamento`, com 24 h para pagar via chave Pix do imóvel;
  - o anfitrião usa "marcar como pago";
  - aviso explícito de que o pagamento não tem a proteção casalivre;
  - comissão como conta a receber, cobrada por fatura mensal.
- **Prazo da aprovação manual:** 24 h por padrão; 4 h se o check-in for em menos de 72 h.
- **Localização aproximada** (raio de ~500 m) até a confirmação.
- **Limpeza no total**, como linha "paga ao prestador".
