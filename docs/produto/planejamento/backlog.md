# Backlog de produto priorizado (RASCUNHO v0.5, 16/10/2026 — + spec-03 e Sprint 4)
RICE = Reach × Impact × Confidence ÷ Effort. **Todos os valores são ESTIMATIVAS** do Gerente de Produto; não há dado de uso (não há produção).
- Reach: escala de 1 a 10.
- Impact: de 0,25 a 3.
- Effort: semanas-bot, a validar com o Gerente e a Arquitetura.

Este arquivo é a entrada priorizada para o Gerente; não substitui `prompts/backlog.md`.
Pré-requisitos já aprovados, fora do ranking: Sprint 4 (busca SQL paginada + índice) e a correção de XSS de prioridade ALTA.

| # | Item | R | I | C | E | RICE | MoSCoW | Depende de |
|---|---|---|---|---|---|---|---|---|
| P0 | Receita de infra versionada `ops/dev-env/` (Void musl, nginx, PHP-FPM 8.4, PG 18, Valkey, runit) | 10 | 2 | 70% | 2 | 7,0 | Must | — |
| P1 | Instrumentar funil (+ evento de canal de origem) | 10 | 1 | 90% | 1 | 9,0 | Must | — |
| P2a | Árvore de gestão (profundidade livre) + papéis + canais de vitrine | 9 | 3 | 60% | 3 | 5,4 | Must | Sprint 4 |
| P2b | Recebedores (CPF/CNPJ, regime, KYC) + regras de split + ledger aberto + termos versionados | 9 | 3 | 60% | 3 | 5,4 | Must | P2a |
| P2 | Reserva + checkout sem login (OTP) + contratos de pagamento + piloto "pagamento fora" | 8 | 3 | 60% | 5 | 2,9 | Must | P2b, P0, D3 |
| P3 | Avaliação vinculada à estadia | 6 | 2 | 80% | 1,5 | 6,4 | Must | P2 |
| P4 | Preço total detalhado (diárias + taxa de serviço; limpeza à parte) | 9 | 1 | 80% | 1,5 | 4,8 | Should | P2 |
| P10 | Cadastro de anúncio em assistente (spec-03) | 8 | 2 | 70% | 4 | 2,8 | Must | P2a, P2b, fotos libvips (S5) |
| P5 | Comparar/favoritar | 7 | 1 | 60% | 2 | 2,1 | Should | Sprint 4 |
| P9 | Fatura mensal de comissão ao proprietário (piloto D11) | 3 | 2 | 70% | 1,5 | 2,8 | Should | P2 |
| P6 | Pagamento (ver `pagamentos.md`) | 8 | 3 | 40% | 8 | 1,2 | Must (médio prazo) | P2, D4–D7 |
| P7 | Mensagens com contato mascarado | 6 | 1 | 60% | 3 | 1,2 | Could | P2 |
| P8 | Assistente de IA | 5 | 1 | 30% | 4 | 0,4 | Could/adiar | P4, D9 |

**Ordem por dependência:** P0 → S4 (ADR 001) → (P1 ∥ P2a) → P2b → P10 (com a P2) → P2 → P3 + P4 + P9 → P5 → P7 → P6 → P8.

**O que mudou na v0.5:**
- Nova P10 (spec-03, alvo S5–S7).
- Recomendação de P0 antes do ADR 001 (D19).
- PostgreSQL primeiro decidido (D18).
- Acréscimo percentual por canal entra na P2b (regra de preço por canal) e no total da busca.

**O que mudou na v0.3:**
- Nova P0 (infra), que entra primeiro.
- O esforço da P2 subiu de 4 para 5 semanas [E] por causa do OTP e do checkout sem login; o RICE caiu de 3,6 para 2,9, mas a P2 continua obrigatória e na mesma posição, por dependência.
- A P2 passa a depender da D3 (provedor de OTP/e-mail).

**O que mudou desde a v0.1:**
- P2a (4 semanas) foi dividida em P2a e P2b (3 + 3 semanas): +2 semanas-bot no total [E], porque entraram os canais separados da árvore, os tipos de regra de split, o ledger aberto, o regime tributário e os termos.
- O RICE de cada parte subiu (4,1 → 5,4) por ter escopo menor por item.
- Nova P9: fatura mensal de comissão para o piloto D11.
- O esforço da P2 se manteve (o piloto "fora" é uma variação de estado).

---
## P0 — Receita de infra versionada `ops/dev-env/`
- **Problema:** hoje cada bot testa num ambiente diferente (SQLite, PHP local). Diferenças de ambiente geram falha falsa e retrabalho na subida. Filas e expirações dependeriam de cron.
- **Solução:** receita versionada do container Void Linux musl (nginx, PHP-FPM 8.4 + OPcache, PostgreSQL 18, Valkey, runit, sem Docker), igual nos computadores dos bots, no Incus do Rafael e na ValueHost (bare-metal Void).
- **Critérios de aceite:**
  1. Versões fixadas (pinned) de todos os pacotes, registradas na receita.
  2. `provision.sh` + seed sobem o ambiente do zero, sem passo manual.
  3. A mesma receita sobe nos computadores dos bots (rootless via bwrap) e no Incus do Rafael.
  4. Parada limpa: os serviços terminam corretamente com o sinal do runit (sem processo órfão nem banco corrompido).
  5. DNS vindo do DHCP, não fixo.
  6. `/tmp` com permissão 1777.
  7. Os testes não dependem do ambiente: `GLOB_BRACE` (indisponível no musl), extensão exif, node e `post_max_size` são tratados ou verificados no provisionamento.
  8. A suíte completa roda em PostgreSQL.
  9. Valkey no ar, com um worker básico de fila supervisionado pelo runit (reinicia se cair).
  10. HTTPS de saída funcionando (necessário para OTP, e-mail e PSP).
- **Prioridade:** Must, primeiro item da Sprint 5. Revisões: Arquitetura, Segurança, Banco de Dados.

## P1 — Instrumentar o funil
- **Problema:** não há como medir conversão, tempo até reservar nem abandono.
- **Solução:** eventos anônimos `busca`, `ver_imovel`, `inicio_reserva`, `reserva_enviada`, `reserva_confirmada`, `avaliacao`, com id de sessão em hash e relatório diário.
- **Critérios de aceite:**
  1. Cada evento gera 1 linha com código, timestamp e sessão em hash.
  2. Sem e-mail, IP ou CPF em claro (revisão da Segurança).
  3. O relatório conta os eventos por dia.
  4. A sequência `busca` → `ver_imovel` gera 2 eventos com a mesma sessão.
- **Prioridade:** Must.

## P2a — Árvore de gestão, papéis e canais de vitrine
- **Problema:** os 3 modelos precisam de uma hierarquia de gestão, e um imóvel precisa aparecer em várias vitrines (marketplace, site regional, site do parceiro). Hoje o Grupo junta as duas coisas (vitrine + gestão) e é plano.
- **Solução:**
  - Organização com `parent_id` em profundidade livre; `tipo` é só rótulo.
  - Papéis predefinidos (`admin_plataforma`, `admin_org`, `gestor`, `operador`, `proprietario`) numa matriz fixa.
  - **Canal** como entidade própria (domínio, tema, vitrine), com relação N:N com imóveis. Os grupos, domínios e temas atuais migram para Organização + Canal.
- **Critérios de aceite:**
  1. A árvore aceita qualquer profundidade; ciclo → 422.
  2. Nenhuma regra de negócio lê o `tipo` (teste: trocar o rótulo não muda permissão nem split).
  3. As vitrines `/g/...` e os domínios verificados atuais continuam iguais (regressão).
  4. Um imóvel em 2 canais aparece nos dois; a remoção de um não afeta o outro.
  5. Cada papel acessa só o que a matriz permite (403 por papel).
- **Prioridade:** Must. Revisões: Arquitetura, Banco de Dados, Segurança.

## P2b — Recebedores, regras de split, ledger aberto e termos
- **Problema:** o split depende da árvore e do canal de origem, e há outras receitas no horizonte (SaaS, taxa de serviço, afiliado). Um modelo só de percentual obrigaria refazer tudo.
- **Solução (modelar as portas; só os modelos do lançamento ganham tela):**
  - Recebedor com CPF/CNPJ, regime tributário e status de KYC.
  - Regras de split (percentual | valor fixo | sobre item | mínimo), por organização/imóvel e por **canal**, versionadas.
  - Ledger de partidas dobradas, em centavos e imutável, com contas para comissão, taxa de serviço, mensalidade, recorrência, conta a receber, fundo regional e reserva técnica.
  - Termos versionados: locação, limpeza e plataforma.
  - Seeds com os 4 cenários da D2 (parâmetros, não código).
- **Critérios de aceite:**
  1. CPF/CNPJ inválido → 422; duplicado → erro genérico (Segurança).
  2. Regras percentuais que somam mais de 100% → 422. Uma regra de valor fixo e uma com mínimo calculam certo (teste com valores conhecidos).
  3. Para 1.000, o canal direto gera 940/60 (fora de território), 930/40/30, 900/40/30/30 e 900/50/50, todos com soma zero. **Trocar um percentual na configuração muda o resultado sem deploy.**
  4. A mesma reserva por canal diferente aplica a regra daquele canal.
  5. Linhas do ledger não são alteradas nem apagadas; correção só por estorno.
  6. Um lançamento de mensalidade (SaaS, 0% comissão) e um de conta a receber podem ser registrados sem reserva (teste de porta).
  7. A publicação de nova versão de um termo não altera o aceite já gravado.
  8. **Chave Pix do recebedor (cadastro ou troca):**
     - exige OTP de quem está fazendo a mudança, sem OTP → 403;
     - o proprietário é avisado na hora;
     - a chave nova só vale para reservas novas depois de uma carência de 24–48 h (parâmetro), e até lá as reservas usam a anterior;
     - titular da chave diferente do CPF/CNPJ do recebedor → 422;
     - toda mudança fica na trilha de auditoria (quem, quando, valor anterior e novo, IP);
     - o gestor só altera com delegação explícita do proprietário; sem delegação → 403, e o gestor nunca troca a chave do proprietário por iniciativa própria.
     - **Revisão obrigatória da Segurança.**
  9. **Mover um nó da árvore:** só o `admin_plataforma` pode (outro papel → 403), a mudança é auditada e só afeta reservas novas; as existentes mantêm o snapshot de split, canal e recebedores.
  10. **Acréscimo de preço por canal** (spec-03): parâmetro percentual do canal sobre o preço base único; o anfitrião não edita preço por canal. Teste: base de 2.250 com +15% no canal OTA = 2.587,50 no total desse canal; nos outros canais, sem alteração.
- **Prioridade:** Must. Revisões: Banco de Dados, Arquitetura, Segurança (CPF, LGPD, dado fiscal).

## P2 — Reserva com contratos de pagamento
- **Problema:** a jornada "reservar" não existe; fazê-la sem os contratos de pagamento obrigaria a refazê-la.
- **Solução:**
  - Aprovação `instantanea|manual` por imóvel, delegável a gestor/operador, com expiração.
  - Máquina de estados única (`pagamentos.md`, seção 5).
  - Snapshot: preço em centavos (diárias, taxa de serviço; limpeza como item separado a pagar ao prestador), canal de origem, regra de split, política de cancelamento com a janela de 7 dias do CDC, e versões dos 3 termos aceitos.
  - Registro do check-in.
  - **Checkout sem login prévio:** e-mail + telefone + CPF, com OTP (WhatsApp, com SMS de reserva); conta criada sem cadastro à parte.
  - Taxa de serviço do hóspede: 0% por padrão, parâmetro por canal e organização.
  - Expiração: aprovação manual em 24 h por padrão, 4 h se o check-in for em menos de 72 h. Pagamento fora: 24 h. Tudo agendado no Valkey.
  - Localização aproximada (raio de ~500 m) até a confirmação.
  - Total com a linha "Limpeza — paga ao prestador".
  - **Piloto D11:** `pagamento_modo = fora`. Fluxo: `aceita` → `aguardando_pagamento` (24 h, chave Pix do imóvel exibida, com aviso "sem proteção casalivre") → o anfitrião usa "marcar como pago" → `confirmada`, e a comissão vira conta a receber. Sem marcação em 24 h → `expirada`.
- **Critérios de aceite:**
  1. Datas indisponíveis, abaixo do mínimo de noites ou acima da capacidade → 422.
  2. Instantânea → `aguardando_pagamento` (ver 23). Manual → `solicitada`, e `expirada` no prazo, liberando as datas.
  3. Aprovação delegada só para gestor/operador da organização; alguém de fora → 403.
  4. Duas confirmações concorrentes: só uma vence.
  5. Datas com `origin='reserva'`, presentes no .ics.
  6. Mudar o preço, a política, a regra ou um termo depois não altera o snapshot.
  7. Transição inválida é recusada, e toda transição gera log.
  8. Sem os 3 termos aceitos → 422.
  9. `fora`: a confirmação lança a conta a receber igual à comissão da regra do canal; o modo `fora` só é permitido em organizações marcadas como piloto.
  10. A janela de 7 dias (respeitando o prazo mínimo até o check-in) calcula reembolso de 100%.
  11. Paridade web/API.
  12. Visitante sem conta completa o checkout: OTP correto cria a conta e a reserva; OTP errado 5× → bloqueio temporário; limite de envios por telefone, CPF e IP (contra SMS pumping).
  13. E-mail ou CPF já cadastrado: o OTP vincula a reserva à conta existente, sem revelar que ela existe.
  14. Manual sem resposta: expira em 24 h; com check-in em menos de 72 h, em 4 h (teste com relógio simulado).
  15. Antes da confirmação, a API e a página não expõem coordenadas exatas (deslocamento ≥ raio configurado); depois, mostram o endereço exato.
  16. Taxa de serviço 0% por padrão; configurada em 5% num canal [E], o snapshot traz a linha.
  17. O total soma diárias + taxa + limpeza, com a limpeza marcada como "paga ao prestador".
  18. `fora`: a tela mostra a chave Pix e o aviso; só o anfitrião (ou um delegado) marca como pago; sem pagamento no prazo → `expirada`.
  19. "Marcar como pago" confirma separadamente a **estadia** e a **limpeza**, cada uma com o seu lançamento no ledger; uma pode estar paga e a outra não.
  20. Cada hóspede verificado tem no máximo 1 reserva em `aguardando_pagamento` por vez; a 2ª → 409. Depois de N expirações seguidas (parâmetro [E]: 3), novas reservas ficam bloqueadas por um período (parâmetro).
  21. Cancelamentos do anfitrião entram na métrica de confiabilidade (`metricas.md`). Cancelador em série (hóspede ou anfitrião, acima do limite parametrizado) recebe restrição: hóspede só por aprovação manual; anfitrião sem reserva instantânea e com alerta ao admin.
  22. **Recusa:** o motivo vem de uma lista fixa e não aparece para o hóspede. O hóspede vê uma mensagem neutra e imóveis parecidos disponíveis nas mesmas datas, com prioridade para a mesma rede/canal.
  23. **Reserva instantânea:** vai para `aguardando_pagamento` com as datas já bloqueadas. Prazo de pagamento de 24 h, ou 4 h se o check-in for em menos de 72 h; venceu → `expirada` e as datas são liberadas. Cancelamento gratuito enquanto estiver aguardando pagamento.
- **Prioridade:** Must. Revisões: Arquitetura, Banco de Dados, Segurança, UI.

## P9 — Fatura mensal de comissão (piloto)
- **Critérios de aceite:**
  1. A fatura do mês soma as contas a receber das reservas `fora` concluídas.
  2. Fatura paga gera o lançamento que baixa o recebível.
  3. Reserva cancelada antes do check-in não entra.
- **Prioridade:** Should. Revisões: Banco de Dados, Segurança.

## P10 — Cadastro de anúncio em assistente (spec-03 v1.1)
- **Problema:** o formulário atual não cobre nó da árvore, recebedores, regras estruturadas, políticas, canais e checklist de publicação, que a reserva e o split precisam.
- **Solução:**
  - Assistente em 3 etapas + revisão, com salvamento automático e rascunho.
  - Pin com prévia de ~500 m.
  - Fotos com cômodo obrigatório e texto alternativo (libvips, WebP).
  - Regras da casa estruturadas (usadas como motivos de recusa).
  - Idades e aviso fixo do ECA.
  - Prazo de aprovação herdado do nó, que pode ser sobrescrito.
  - Políticas Flexível, Moderada e Rígida.
  - Interruptor por canal, com prévia de preço somente leitura.
  - Publicação delegável: um gestor sem permissão manda para aprovação do proprietário.
  - Exclusão bloqueada se houver reservas futuras; sem elas, é inativação (soft delete).
- **Critérios de aceite:** os da spec-03 (seções 1–4, com WCAG 2.2 AA), mais:
  1. "Publicar" desabilitado sem recebedor da estadia (e sem o prestador de limpeza, se houver taxa).
  2. A política escolhida vira o snapshot da reserva (P2).
  3. O total da prévia por canal é igual ao total da busca e do checkout naquele canal.
  4. As regras estruturadas são as mesmas da lista fixa de motivos de recusa (P2, 22).
  5. **Inventário de móveis** (Lei 8.245/1991 art. 48; vira o Anexo I do contrato de locação por temporada): lista de móveis e utensílios por cômodo no cadastro do anúncio (S7). Sem inventário, "Publicar" fica desabilitado. Bloqueia o piloto (ver J-PIL).
- **Prioridade:** Must. Revisões: UI, Segurança (contatos bloqueados, upload, PDF do condomínio), Arquitetura (libvips) e Banco de Dados.

## P3 — Avaliação vinculada à estadia
- **Critérios de aceite:**
  1. Só uma reserva `concluida` permite avaliar; sem ela → 403.
  2. Uma avaliação por reserva (409 na segunda).
  3. Prazo de 14 dias após a estadia [E].
  4. E2E que submete uma avaliação.

## P4 — Preço total detalhado
- Na S4 (ADR 001, T5 contrato + T7 front), a busca com datas já mostra o total (diárias + limpeza + taxa; com o acréscimo do canal depois da P2b) como valor principal e o preço por noite como secundário, e o filtro continua por noite. A P4 completa isso com o detalhamento na página do imóvel e abre a **porta futura de filtrar e ordenar pelo total** (P4/P5; exige uma estratégia fora do keyset por `id`, a definir com a Arquitetura).
- **Critérios de aceite:**
  1. O cálculo usa a mesma função do snapshot da P2.
  2. Soma das linhas = total.
  3. Sem datas: "a partir de".
  4. Web = API.
  5. Busca com datas: o total no card é igual ao total da página do imóvel e ao do snapshot da reserva, e o preço por noite aparece como secundário.

## P5 — Comparar/favoritar
- **Critérios de aceite:**
  1. O favorito persiste após novo login.
  2. A comparação aceita de 2 a 3 imóveis.
  3. Imóvel inativo some da lista com aviso.

## P6 — Pagamento (futuro)
Histórias P6.1–P6.7 em `pagamentos.md`, seção 9. Depende das decisões D1–D6.

## P7 — Mensagens
- **Critérios de aceite:**
  1. Só os participantes leem a conversa.
  2. Telefone/e-mail/Pix mascarados até a reserva ser confirmada.

## P8 — Assistente de IA
- **Critérios de aceite:**
  1. Responde só com dados públicos do anúncio; sem dado, diz "não sei".
  2. Não envia dado pessoal ao provedor.
  3. Limite de custo por sessão.
- **Spec pendente (antes de implementar):** spec do assistente de IA com fornecedor, país de processamento dos dados, prazo de guarda das conversas, contrato sem uso das conversas para treinamento, e regra de não prometer nada além do que está no anúncio. O fornecedor está na pauta do Rafael.

## Itens novos do Jurídico (recomendacoes-sem-orientacao-externa.md, 16/10)
- **J-TXT (Must, risco Alto):** escrever o texto dos 3 termos D14, do contrato plataforma × anfitrião, da política de privacidade e da de cancelamento, seguindo o checklist da seção 5 (A a E). Os textos são escritos por Produto e Jurídico durante a S5 e a S6, revisados pelo Negócios e publicados com versão e data na S6, junto com o motor de aceite dos termos. Critério de aceite: todos os itens da seção 5 estão marcados, e cada texto está publicado com versão e data.
- **J-PIL (Must, condição para abrir o piloto, gate de go-live depois da S8):**
  - 100% dos anfitriões com KYC aprovado;
  - chave Pix com o mesmo CPF/CNPJ do recebedor;
  - canal de denúncia funcionando, com remoção rápida do anúncio;
  - aviso de pagamento fora da plataforma no checkout (S7);
  - troca de chave Pix pelo usuário desligada até a D3.
  - inventário de móveis preenchido em 100% dos anúncios do piloto (Lei 8.245 art. 48, Anexo I do contrato; entra na S7 com o cadastro de anúncio, P10-5).
  O piloto não abre se algum item falhar.
- **J-IBS (Should em 2026, Must antes de 01/2027):**
  - o ledger registra por reserva o anfitrião, o CPF/CNPJ, o documento, o valor bruto, a comissão e as datas;
  - relatório anual por anfitrião exportável;
  - cláusula de responsabilidade fiscal e de regresso no contrato do anfitrião;
  - campo no anúncio para cadastro e taxa municipais.
  Os campos do ledger entram na S6. O relatório anual e o aviso municipal entram na S8. A reverificação do regulamento da CBS é em 15/12/2026.

## Competitor gaps (reviewed 2026-10-21 and 2026-10-22)
Origem: pesquisa diária de concorrentes do Produto (`/workspace/produto/competitors.md`, 17 a 22/10/2026, fontes públicas com link) e revisão do Negócios (`/workspace/negocios/review-gaps-g1-g17.md`, 21/10/2026; G18 a G20 na seção "Day 6", 22/10/2026). G6 com a orientação do Jurídico (`/workspace/juridico/g6-cameras.md`, 21/10/2026). RICE = R × I × C ÷ E, **todos [E]** (sem dado de uso), com os valores finais do Negócios. Cada comportamento é um parâmetro de nó, canal ou imóvel com padrão da plataforma; os modelos (direto, franquia, parceiro) mudam só por configuração.

| # | Item | R | I | C | E | RICE | MoSCoW | Alvo | Status |
|---|---|---|---|---|---|---|---|---|---|
| G9 | Bloqueio das datas enquanto a solicitação aguarda aprovação | 10 | 2 | 80% | 0,5 | 32 | Should | S7 | aprovado com mudanças |
| G7 | Forma de check-in | 6 | 1 | 80% | 0,25 | 19,2 | Should | S7 (P10) | aprovado com mudança |
| G1 | Definir "Relevância" | 10 | 2 | 80% | 1 | 16 | Should | antes da frente do ADR 001 (T7) | aprovado com mudança; posição paga e rede decididas pelo Rafael (2026-10-21) |
| G14 | Cancelamento causado pelo anfitrião e iniciado pelo hóspede | 4 | 2 | 80% | 0,5 | 12,8 | Should | antes da S7 | aprovado com mudanças |
| G18 | Regras de disponibilidade | 4 | 2 | 80% | 0,5 | 12,8 | Should | antes de fechar a disponibilidade da busca (restrição já no ADR 001) | aprovado com mudança (I 1 → 2; 6,4 → 12,8) |
| G6 | Câmeras e monitores de ruído | 6 | 2 | 50% | 0,5 | 12 | Should | S7 (P10) | aprovado; orientação do Jurídico recebida |
| G2 | Confiabilidade de "Melhor avaliação" | 6 | 1 | 80% | 0,5 | 9,6 | Should | com G1 | aprovado |
| G10 | Consequência para o anfitrião que deixa expirar | 6 | 1 | 80% | 0,5 | 9,6 | Should | S7 | aprovado com mudança |
| G20 | Reserva dupla entre canais pelo iCal | 3 | 2 | 80% | 0,5 | 9,6 | **Must** | antes da S7 | aprovado com mudança (nota mantida, sobe para Must) |
| G3 | Painel de filtros (UC-H04) | 8 | 1 | 80% | 1 | 6,4 | Should | ADR 001 (construtor SQL) | aprovado |
| G5 | Camas por quarto | 8 | 1 | 80% | 1 | 6,4 | Should | spec de schema | aprovado como restrição de schema |
| G12 | Controles do saldo agendado | 6 | 1 | 50% | 0,5 | 6 | Could | P6 (restrição já na S7) | aprovado como restrição |
| G13 | Estadia longa × repasse | 2 | 1 | 50% | 0,25 | 4 | Could | agora (teto, piloto e P6) | decidido pelo Rafael (2026-10-21): teto de 30 noites |
| G16 | Política de cancelamento sazonal | 4 | 1 | 50% | 0,5 | 4 | Could | depois do piloto (restrição já na S7) | aprovado como restrição, prioridade elevada |
| G15 | Evento disruptivo declarado pela plataforma | 3 | 2 | 50% | 1 | 3 | Should | restrição agora, tela depois | aprovado como restrição |
| G4 | Datas flexíveis | 4 | 0,5 | 50% | 0,5 | 2 | Could | piloto (versão simples) | aprovado (o mais simples) |
| G8 | Tempo de resposta medido do anfitrião | 5 | 0,5 | 50% | 1 | 1,25 | Could | depois do piloto (restrição já na S7) | aprovado só como restrição |
| G11 | Oferta personalizada a partir da conversa | 3 | 0,5 | 50% | 1 | 0,75 | Could | depois do piloto (restrição já na S7) | aprovado só como restrição |
| G19 | Noites vazias entre reservas (gap nights) | 3 | 0,5 | 50% | 1 | 0,75 | Could | piloto (alerta); depois do piloto (relaxamento automático) | alterado pelo Negócios |
| G17 | Tarifa não reembolsável com desconto | 2 | 0,5 | 50% | 1 | 0,5 | Won't (por ora) | — | rejeitado por ora |

### G9 — Bloqueio durante a aprovação (S7)
- **Problema:** nenhuma regra diz se uma solicitação `solicitada` bloqueia as datas; a disponibilidade do ADR 001 §4 lê só `calendar_days.blocked`, e o antibloqueio (spec-01 §5b) não cobre solicitações, então um hóspede pode segurar várias casas por 24 h. Uma OTA pode vender as mesmas noites na janela de 24 h / 4 h.
- **Solução:** a solicitação bloqueia as noites do envio até aprovar, recusar, desistir ou expirar; a liberação é atômica com a mudança de estado (formaliza o critério 2 da P2). O bloqueio também sai nos **feeds iCal** do imóvel. Limite por hóspede no parâmetro `max_pending_requests_overlapping` (padrão da plataforma 2 [E], sobrescrito por canal), reaproveitando o contador "N expirações e bloqueio" da §5b. Modo do bloqueio como parâmetro de canal/organização (`hard` padrão; `none` reservado); só `hard` entra na S7.
- **Critérios de aceite:**
  1. Depois do envio, as noites somem da busca e não aceitam outra solicitação, até a decisão ou a expiração.
  2. Aprovar, recusar, desistir ou expirar libera ou confirma as noites na mesma transação; decisões concorrentes recebem 409.
  3. O feed iCal do imóvel exporta o bloqueio enquanto a solicitação está pendente e o remove ao liberar.
  4. Com `max_pending_requests_overlapping` = 2, a 3ª solicitação do mesmo hóspede com datas sobrepostas é recusada com a mensagem; o canal pode sobrescrever o valor.
  5. Um canal com o modo `hard` bloqueia; nenhum outro modo é oferecido na S7.
- **Prioridade:** Should, S7, junto com G10. Fonte: competitors.md G9 (`/workspace/produto/competitors.md`), revisão do Negócios (`/workspace/negocios/review-gaps-g1-g17.md`).

### G10 — Expiração pelo anfitrião (S7)
- **Problema:** o hóspede é penalizado por expirações de pagamento e o anfitrião por cancelamentos, mas o anfitrião que ignora solicitações não sofre nada, enquanto cada solicitação segura datas (G9).
- **Solução:** N expirações seguidas = parâmetro de nó/canal (padrão 2 [E]). Na primeira, aviso ao anfitrião e ao nó acima (suporte do franqueado); ao atingir N, o anúncio é **pausado da busca** até reativar, nunca excluído nem oculto da plataforma (coerente com a decisão de canais). A contagem vale para quem tem a delegação de aprovar. Banner no painel Hoje.
- **Critérios de aceite:**
  1. Na 1ª expiração, o anfitrião e o nó acima recebem o aviso.
  2. Com N = 2, a 2ª expiração seguida pausa o anúncio da busca, com o banner e o botão "Reativar"; o anúncio continua acessível na plataforma.
  3. A expiração conta para quem tem a delegação de aprovar, e cada pausa fica no log.
- **Prioridade:** Should, S7. Fonte: competitors.md G10 (`/workspace/produto/competitors.md`), revisão do Negócios (`/workspace/negocios/review-gaps-g1-g17.md`).

### G14 — Cancelamento causado pelo anfitrião (antes da S7)
- **Problema:** se o anfitrião não pode receber e pede ao hóspede para cancelar, o cancelamento segue a política do hóspede, o anfitrião escapa da taxa e da métrica, e o hóspede não tem canal antes do check-in.
- **Solução:** (1) motivo do hóspede "O anfitrião não pode receber / pediu para eu cancelar", que não aplica a política e abre uma revisão antes do check-in; (2) aviso curto na tela de cancelar e em Mensagens; (3) troca de imóvel só por pedido de alteração que o hóspede pode recusar, preferindo imóveis do mesmo nó/canal. O cancelamento guarda `initiated_by` e `responsible` separados; taxa, métrica de confiabilidade, reembolso e comissão leem `responsible`. Quem revisa é o nó acima do imóvel na árvore (na franquia M13, o franqueado como suporte nível 1, com permissão predefinida), com recurso ao `admin_plataforma`; o papel é parâmetro da árvore. Jurídico só consultado no texto ao hóspede.
- **Critérios de aceite:**
  1. Com o motivo escolhido, a política não é aplicada e a revisão é aberta para o nó acima do imóvel, com recurso ao `admin_plataforma`.
  2. Confirmado como do anfitrião: reembolso de 100%, nenhuma comissão devida, a regra de taxa de cancelamento do anfitrião se aplica e o cancelamento conta na métrica dele.
  3. O registro tem `initiated_by` = hóspede e `responsible` = anfitrião; taxa, métrica, reembolso e comissão leem `responsible`.
  4. Uma troca de imóvel só acontece por pedido de alteração; se o hóspede recusa, o anfitrião precisa cancelar.
- **Prioridade:** Should, antes da S7. Fonte: competitors.md G14 (`/workspace/produto/competitors.md`), revisão do Negócios (`/workspace/negocios/review-gaps-g1-g17.md`).

### G6 — Câmeras e monitores de ruído (S7, P10)
- **Problema:** spec-03 e spec-01 não falam de câmeras, gravação nem monitores de ruído; o hóspede não sabe o que há no imóvel, e sem a proibição de câmeras internas o risco de responsabilidade solidária da casalivre é alto (Jurídico).
- **Solução (orientação do Jurídico):** campos estruturados no bloco de regras da spec-03 §3. **Câmeras externas** permitidas com divulgação (tipo e local, área externa, se grava). **Monitores de ruído** só de decibéis, sem guardar áudio. **Câmeras internas, gravação de áudio e reconhecimento facial são proibidos pela casalivre**; esse piso vale em todos os canais, que podem apertar mas nunca afrouxar. A divulgação aparece **antes da reserva** (detalhe e resumo) e fica no registro da reserva; mudança depois da reserva é avisada aos hóspedes com reserva. O anfitrião é o controlador das imagens e guarda **no máximo 30 dias** (mais só por incidente concreto); a **casalivre não guarda nenhuma imagem nem áudio**, só os campos de divulgação (enquanto o anúncio existir), salvo material entregue num registro de disputa.
- **Critérios de aceite (spec-03 §3, Jurídico):**
  1. Campos estruturados: câmera externa (sim/não + local) e monitor de ruído (sim/não + "só decibéis"); não existe opção só em texto livre.
  2. A publicação fica bloqueada até o anfitrião confirmar: sem câmeras internas, sem gravação de áudio, sem reconhecimento facial.
  3. A divulgação aparece antes da reserva (página de detalhe + resumo) e no registro da reserva.
  4. O hóspede tem a denúncia "dispositivo não informado"; a denúncia gera um registro no log e coloca o anúncio em revisão.
  5. A casalivre não guarda imagens nem áudio dos anfitriões fora de um registro de disputa.
- **Prioridade:** Should, S7 com o cadastro de anúncio (P10). A regra entra como insumo da revisão final do contrato (sem texto agora). Fontes: competitors.md G6 (`/workspace/produto/competitors.md`), revisão do Negócios (`/workspace/negocios/review-gaps-g1-g17.md`), orientação do Jurídico (`/workspace/juridico/g6-cameras.md`).

### G7 — Forma de check-in (S7, P10)
- **Problema:** só há horários de check-in e check-out; o hóspede não sabe se é presencial, portaria, cofre de chave ou fechadura eletrônica, e o filtro de self check-in (G3) não tem dado.
- **Solução:** enum na spec-03 §3: `portaria` (ligado à lista de hóspedes da portaria), cofre de chave, fechadura eletrônica, presencial, prestador local; uma linha na spec-01 §3; alimenta o filtro de self check-in (G3). Oportunidade (Negócios): check-in presencial por prestador local como item com preço no split (regra por item).
- **Critérios de aceite:**
  1. O anúncio exige uma forma de check-in da lista; a página de detalhe mostra a forma antes da reserva.
  2. Com `portaria`, o anúncio fica ligado à lista de hóspedes da portaria (spec-06).
  3. O filtro de self check-in lista só cofre de chave e fechadura eletrônica.
- **Prioridade:** Should (impacto elevado pelo Negócios: a franquia M13 depende de 90% de operação remota), S7 com P10. Fonte: competitors.md G7 (`/workspace/produto/competitors.md`), revisão do Negócios (`/workspace/negocios/review-gaps-g1-g17.md`).

### G1 — Relevância (antes da frente do ADR 001, T7)
- **Problema:** "Relevância" é a ordenação padrão, mas nenhuma spec a define; o keyset do ADR 001 ordena por `id`, então hoje relevância = ordem de inserção.
- **Solução:** nota guardada e indexável = fatores ponderados, com os **pesos como parâmetro do canal** (cada vitrine ajusta; por exemplo, uma franquia favorece reserva instantânea ou tempo de resposta) e padrões da plataforma. **Decisão do Rafael (2026-10-21):** o ranking aceita dois pesos por canal, **posição paga** e **preferência pela própria rede**, ambos **0 (desligados) por padrão**; todo anúncio cuja posição recebeu impulso pago mostra o rótulo visível **"Patrocinado"**. Ligar qualquer um deles é uma decisão posterior.
- **Critérios de aceite:**
  1. A nota é uma coluna guardada e indexada, atualizada quando o anúncio ou as avaliações mudam; o cursor versionado aceita a ordenação.
  2. Mudar os pesos de um canal muda só a ordem nesse canal.
  3. Cada canal tem os pesos `posição paga` e `preferência da rede`; num canal novo ou sem configuração, os dois valem 0 e a ordem é igual à de um ranking sem eles.
  4. Com o peso de posição paga acima de 0 para um anúncio naquele canal, o card e o detalhe desse anúncio nesse canal mostram o rótulo "Patrocinado" (texto, não só ícone); com 0, o rótulo não aparece. O mesmo anúncio em outro canal com peso 0 não mostra o rótulo.
  5. Só o admin (admin_plataforma) configura os dois pesos; gestores e outros papéis não veem a configuração e o servidor responde 403. Toda mudança de peso fica no log.
  6. Antes de ligar qualquer um dos pesos (valor acima de 0) em produção, o Jurídico revisa o rótulo e a divulgação ao hóspede; ligar é decisão posterior do Rafael.
- **Prioridade:** Should, antes da frente do ADR 001 (T7). Decidido pelo Rafael em 2026-10-21 (pesos por canal, 0 por padrão, rótulo "Patrocinado"). Fonte: competitors.md G1 (`/workspace/produto/competitors.md`), revisão do Negócios (`/workspace/negocios/review-gaps-g1-g17.md`).

### G2 — Confiabilidade de "Melhor avaliação" (com G1)
- **Problema:** um 5,0 com 3 avaliações passa à frente de um 4,9 com 200.
- **Solução:** média bayesiana com parâmetro de prior / contagem mínima, guardada junto com a nota do G1.
- **Critérios de aceite:**
  1. Um imóvel com 3 avaliações 5,0 não passa à frente de um com 200 avaliações e 4,9, com o parâmetro padrão.
  2. O parâmetro muda sem deploy e a nota é recalculada a cada nova avaliação.
- **Prioridade:** Should, com G1. Fonte: competitors.md G2 (`/workspace/produto/competitors.md`), revisão do Negócios (`/workspace/negocios/review-gaps-g1-g17.md`).

### G3 — Painel de filtros (ADR 001)
- **Problema:** o UC-H04 lista filtros (tipo, quartos, pets, acessibilidade, reserva instantânea, cancelamento), mas a spec-01 §2 não tem o painel e o ADR 001 filtra só parte.
- **Solução:** especificar agora como colunas exatas no mesmo construtor SQL do ADR 001: tipo, quartos, camas, banheiros, tipo de reserva, política de cancelamento, pets, e "reembolso total até <data>" calculado da política e das datas.
- **Critérios de aceite:**
  1. Cada filtro é uma condição SQL de coluna exata; nenhum filtro roda em PHP.
  2. "Reembolso total até <data>" lista só anúncios cuja política dá 100% até a data mostrada, para as datas buscadas.
- **Prioridade:** Should, com o construtor SQL do ADR 001. Fonte: competitors.md G3 (`/workspace/produto/competitors.md`), revisão do Negócios (`/workspace/negocios/review-gaps-g1-g17.md`).

### G4 — Datas flexíveis (piloto)
- **Problema:** "Datas flexíveis" aparece no calendário e no estado vazio sem comportamento definido.
- **Solução:** no piloto, limpa as datas e mostra a diária; ±N dias fica para depois.
- **Critérios de aceite:** ao escolher "Datas flexíveis", a busca roda sem datas e os cards mostram a diária, não o total.
- **Prioridade:** Could. Fonte: competitors.md G4 (`/workspace/produto/competitors.md`), revisão do Negócios (`/workspace/negocios/review-gaps-g1-g17.md`).

### G5 — Camas por quarto (spec de schema)
- **Problema:** a capacidade é só contagem; a página não diz quem dorme onde.
- **Solução (restrição de schema):** quartos como linhas (tipo de quarto + tipo e quantidade de camas); "camas" é derivado, não uma segunda fonte.
- **Critérios de aceite:** o total de camas do anúncio é sempre a soma dos quartos; não existe campo `camas` editável à parte.
- **Prioridade:** Should, na spec de schema. Fonte: competitors.md G5 (`/workspace/produto/competitors.md`), revisão do Negócios (`/workspace/negocios/review-gaps-g1-g17.md`).

### G8 — Tempo de resposta medido (restrição)
- **Problema:** a página mostra o prazo configurado, não quanto o anfitrião de fato demora.
- **Solução:** só restrição agora: guardar recebido / respondido / expirado em cada solicitação; calcular por imóvel e por nó depois do piloto. Oportunidade: KPI de franqueado/parceiro na M13.
- **Critérios de aceite:** cada solicitação guarda os três timestamps na própria linha.
- **Prioridade:** Could. Fonte: competitors.md G8 (`/workspace/produto/competitors.md`), revisão do Negócios (`/workspace/negocios/review-gaps-g1-g17.md`).

### G11 — Oferta personalizada (restrição)
- **Problema:** o anfitrião conversa antes da reserva, mas não transforma a conversa numa oferta que o hóspede reserva pela própria conta.
- **Solução:** só restrição agora: a oferta é uma solicitação pré-aprovada (caminho "Aceite pendente" da spec-06 §3b) que leva o **canal de origem**, para o split segui-lo (hóspede vindo da plataforma paga a tarifa cheia, regra do canal manual).
- **Critérios de aceite:** uma oferta guarda o canal de origem, e o split da reserva usa esse canal.
- **Prioridade:** Could, depois do piloto (utilidade para corretores a validar em entrevistas). Fonte: competitors.md G11 (`/workspace/produto/competitors.md`), revisão do Negócios (`/workspace/negocios/review-gaps-g1-g17.md`).

### G12 — Saldo agendado (restrição; P6)
- **Problema:** a P6.4 cobra o saldo em D-30..45 sem lembrete, sem "pagar antes" e sem trocar a forma de pagamento.
- **Solução:** pagamentos como linhas por reserva (vencimento, valor, forma, status); lembretes pelo provedor da D3.
- **Critérios de aceite:** uma reserva tem N linhas de pagamento; o "marcar como pago" do piloto grava uma linha, não um campo na reserva.
- **Prioridade:** Could na P6; a restrição vale já na S7. Fonte: competitors.md G12 (`/workspace/produto/competitors.md`), revisão do Negócios (`/workspace/negocios/review-gaps-g1-g17.md`).

### G13 — Estadia longa × repasse
- **Problema:** a spec-03 não limita as noites máximas, e a P6 libera tudo em check-in + 24 h, com escrow do PSP de no máximo 45 dias; o reembolso na saída antecipada fica indefinido.
- **Solução (decisão do Rafael, 2026-10-21):** a estadia máxima da plataforma é de **30 noites** por enquanto, no piloto e na P6. É um teto `max_nights` da plataforma, gravado no snapshot da reserva, e o `max_nights` do anfitrião não pode passar dele. Com esse teto, as linhas de pagamento mensais para estadias longas (opção b do Negócios) não são necessárias agora. No piloto (D11, pagamento por fora), a comissão sai mensal na fatura da P9.
- **Critérios de aceite:**
  1. O teto `max_nights` da plataforma vale 30 noites; o anúncio não aceita `max_nights` acima dele, e o servidor recusa (422) com a mensagem.
  2. Uma busca ou reserva com mais de 30 noites é recusada com a explicação do limite.
  3. O snapshot da reserva guarda o `max_nights` do anúncio e o teto da plataforma em vigor; mudar o teto não altera reservas existentes.
- **Prioridade:** Could. Decidido pelo Rafael em 2026-10-21 (teto de 30 noites). Fonte: competitors.md G13 (`/workspace/produto/competitors.md`), revisão do Negócios (`/workspace/negocios/review-gaps-g1-g17.md`).

### G15 — Evento disruptivo (restrição)
- **Problema:** força maior é decidida reserva por reserva; um evento regional (evacuação, estrada bloqueada) exige N provas e N decisões.
- **Solução:** registro de evento ligado aos dados por cidade (regras municipais) mais um período; um nó (por exemplo, franqueado) pode propor, só o `admin_plataforma` ativa. As reservas referenciam o evento como motivo; métrica e calculadora de reembolso o leem. Cenários da Região dos Lagos a confirmar, sem dado.
- **Critérios de aceite:**
  1. Só o `admin_plataforma` ativa um evento; um nó só propõe.
  2. Um cancelamento pode referenciar o evento ativo da cidade e do período, sem prova individual.
- **Prioridade:** Should, restrição agora e tela depois. Fonte: competitors.md G15 (`/workspace/produto/competitors.md`), revisão do Negócios (`/workspace/negocios/review-gaps-g1-g17.md`).

### G16 — Política sazonal (restrição)
- **Problema:** uma política por anúncio; o anfitrião de praia usa a mais rígida o ano todo.
- **Solução:** a política é resolvida por (imóvel, **canal**, data de check-in) no snapshot, reaproveitando os períodos de exceção de preço sazonal da S7. Oportunidade: política mais rígida na OTA e mais flexível no canal direto (incentivo à reserva direta, M2).
- **Critérios de aceite:** o snapshot guarda a política resolvida para o canal e a data de check-in da reserva, não um ponteiro para a política atual do anúncio.
- **Prioridade:** Could depois do piloto; a restrição vale já na S7. Fonte: competitors.md G16 (`/workspace/produto/competitors.md`), revisão do Negócios (`/workspace/negocios/review-gaps-g1-g17.md`).

### G17 — Tarifa não reembolsável
- **Rejeitado por ora** (RICE 0,5): o direito de 7 dias do CDC (J35) provavelmente anula o desconto; rever depois do Jurídico sobre o CDC art. 49.

### G18 — Regras de disponibilidade (antes de fechar a disponibilidade da busca)
- **Problema:** a spec-03 §3 cita "mínimo e máximo de noites e antecedência" sem valores, comportamento nem aceite; a spec-06 §3 só tem mínimo de noites por período. Faltam **tempo de preparação** (folga de limpeza entre estadias, importante quando a limpeza é um prestador separado pago pelo hóspede), **janela de reserva** (até quando à frente), **corte no mesmo dia** e **dias da semana sem chegada / sem saída** (por exemplo, sábado a sábado na alta temporada).
- **Solução (aprovada pelo Negócios com mudança):** regras definidas **por imóvel**: mínimo e máximo de noites (dentro do teto de 30 noites, G13), antecedência (0 a 7 dias, com hora de corte no mesmo dia), noites de preparação (0 a 2), janela de reserva (3 a 24 meses) e dias da semana permitidos para chegada e saída. **Modelos no nível do nó:** o franqueado ou parceiro define padrões regionais para os seus imóveis (isso absorve a nota de "conjuntos de regras para vários anúncios"). **Valores sazonais** reaproveitam os períodos de datas da S7 (por exemplo, mínimo maior no Réveillon e no Carnaval; spec-07 B1). Motivo do Negócios: o mínimo de estadia na alta temporada é um controle central de receita na região do piloto, e os modelos por nó reduzem o trabalho de configuração do franqueado.
- **Restrição de velocidade da busca (já agora):** a verificação de disponibilidade da busca precisa aplicar essas regras, junto com mínimo/máximo de noites e o teto de 30 noites, sem quebrar as metas de velocidade do ADR 001 (regra do Rafael de 2026-10-21). **Arquitetura e BD confirmam** que as regras cabem nas metas e dizem se são pré-calculadas nos dados de noites reserváveis ou avaliadas na consulta. A avaliação do mínimo de estadia fica num lugar só (G19 depende disso).
- **Critérios de aceite:**
  1. Cada imóvel tem mínimo e máximo de noites, antecedência (0 a 7 dias, com hora de corte no mesmo dia), noites de preparação (0 a 2), janela de reserva (3 a 24 meses) e dias de chegada e de saída permitidos; nenhum máximo passa de 30 noites.
  2. Um imóvel sem valor próprio herda o modelo do nó acima; o valor do imóvel substitui o do modelo.
  3. Um período sazonal (período de datas da S7) sobrescreve as regras do imóvel nas suas datas; por exemplo, um mínimo de 5 noites no Réveillon vale só nessas datas.
  4. A busca e o checkout recusam datas que violam qualquer regra: antecedência menor que a configurada, check-in depois da janela, chegada ou saída num dia não permitido, estadia fora do mínimo/máximo, e noites dentro da preparação de outra reserva.
  5. Com 1 noite de preparação, a noite seguinte ao check-out de uma reserva não aparece disponível.
  6. Arquitetura e BD registram no ADR 001 como as regras entram na disponibilidade da busca, dentro das metas de velocidade, antes de a frente fechar.
- **Prioridade:** Should, antes de fechar a disponibilidade da busca; a restrição de velocidade vale já no ADR 001. RICE 12,8 (R 4, I 2, C 80%, E 0,5; o Negócios subiu I de 1 para 2). Fonte: competitors.md G18 (`/workspace/produto/competitors.md`, 22/10), revisão do Negócios ("Day 6", `/workspace/negocios/review-gaps-g1-g17.md`). Referências: [Airbnb updating your availability](https://www.airbnb.com/resources/hosting-homes/a/updating-your-availability-708), [Airbnb booking settings](https://www.airbnb.com/help/article/3728), [Airbnb controlling your pricing](https://www.airbnb.co.in/resources/hosting-homes/a/controlling-your-pricing-648), [Booking.com adding and managing restrictions](https://partner.booking.com/en-us/help/reservations/restrictions/adding-and-managing-restrictions), [Booking.com setting reservation restrictions](https://partner.booking.com/en-us/help/reservations/restrictions/setting-reservation-restrictions-your-property).

### G19 — Noites vazias entre reservas (piloto: alerta)
- **Problema:** com mínimo de 3 noites ou mais (comum no Réveillon e no Carnaval), 1 a 2 noites livres entre reservas ficam impossíveis de reservar, e o anfitrião não é avisado.
- **Solução (alterada pelo Negócios):** **no piloto**, um alerta simples ao anfitrião **e ao nó acima** quando sobram 1 ou 2 noites que não podem ser reservadas. **Depois do piloto**, uma regra opcional que relaxa o mínimo de estadia para essas noites (um parâmetro, **desligado por padrão**). Motivo do Negócios: o alerta dá ao franqueado uma ação concreta que preenche noites; a automação pode esperar.
- **Critérios de aceite:**
  1. (Piloto) Com mínimo de 5 noites e 2 noites livres entre duas reservas, o anfitrião e o nó acima recebem o alerta, que mostra as datas e o mínimo que impede a reserva.
  2. (Piloto) Noites livres que podem ser reservadas com o mínimo vigente não geram alerta.
  3. (Depois do piloto) Com a regra ligada, essas noites aceitam uma estadia que as ocupa por inteiro, mesmo abaixo do mínimo; com a regra no padrão (desligada), nada muda.
  4. O cálculo usa a mesma avaliação de mínimo de estadia da G18.
- **Prioridade:** Could; alerta no piloto, relaxamento automático depois do piloto. RICE 0,75 (R 3, I 0,5, C 50%, E 1; mantido). Fonte: competitors.md G19 (`/workspace/produto/competitors.md`, 22/10), revisão do Negócios ("Day 6", `/workspace/negocios/review-gaps-g1-g17.md`). Referências: [Airbnb updating your availability](https://www.airbnb.com/resources/hosting-homes/a/updating-your-availability-708), [Booking.com dynamic restriction rules](https://partner.booking.com/en-gb/help/reservations/restrictions/setting-dynamic-restriction-rules).

### G20 — Reserva dupla entre canais pelo iCal (Must, antes da S7)
- **Problema:** as OTAs leem a exportação da casalivre só a cada 2 a 3 h, e a casalivre importa em intervalos, então uma reserva do Airbnb/Booking e uma da casalivre podem cair nas mesmas noites. As specs não dizem o que acontece quando um bloqueio importado de OTA se sobrepõe a uma solicitação pendente ou reserva confirmada da casalivre (quem é avisado, se o hóspede da casalivre é informado, se conta como cancelamento do anfitrião), nem se o **feed de exportação inclui as datas seguradas** (aguardando aprovação/pagamento, G9).
- **Solução (aprovada pelo Negócios com mudança):** (1) a exportação inclui toda noite que bloqueia uma reserva da casalivre, inclusive solicitações aguardando aprovação ou pagamento; (2) uma importação que se sobrepõe a uma solicitação ou reserva da casalivre gera um **registro de conflito** e um alerta no Hoje e na reserva, e **nunca sobrescreve** a reserva; (3) se o anfitrião então cancela, é cancelamento do anfitrião pela spec-09 §5, com `responsible` = anfitrião (campo da G14), sem força maior; (4) dica ao anfitrião no cadastro do anúncio para sincronizar nos dois sentidos. Mudanças do Negócios: o **intervalo de importação é um parâmetro** (tão curto quanto a origem permitir); o alerta de conflito vai ao anfitrião **e ao nó acima**, com **prazo de resolução**; a **realocação vai primeiro para um imóvel comparável da mesma rede**, para a reserva ficar conosco. Depois: sincronização direta por channel manager ou API, como complemento pago opcional. O Jurídico confirma o remédio do lado do hóspede (só reembolso ou ajuda para realocar). Motivo do Negócios: os proprietários do piloto vão anunciar em vários canais, e uma reserva dupla prejudica a confiança no site direto mais do que a nota mostra.
- **Critérios de aceite:**
  1. O feed iCal exportado do imóvel inclui as noites de reservas confirmadas e de solicitações pendentes de aprovação ou pagamento, e as remove quando liberadas.
  2. Uma importação que se sobrepõe a uma solicitação ou reserva da casalivre cria um registro de conflito, alerta o anfitrião e o nó acima no Hoje e na reserva, com o prazo de resolução, e não altera nem cancela a reserva.
  3. Se o anfitrião cancela depois do conflito, o cancelamento tem `responsible` = anfitrião (G14) e conta na métrica dele; força maior não se aplica.
  4. O intervalo de importação é um parâmetro; mudá-lo muda a frequência sem mudar código.
  5. Ao resolver o conflito, a realocação oferece primeiro imóveis comparáveis da mesma rede.
  6. O cadastro do anúncio mostra a dica de sincronizar nos dois sentidos.
- **Prioridade:** **Must, antes da S7** (RICE 9,6 mantido: R 3, I 2, C 80%, E 0,5). Fonte: competitors.md G20 (`/workspace/produto/competitors.md`, 22/10), revisão do Negócios ("Day 6", `/workspace/negocios/review-gaps-g1-g17.md`). Referências: [Airbnb sync calendars](https://www.airbnb.com/help/article/99), [Booking.com synchronise calendars](https://partner.booking.com/en-gb/help/rates-availability/extranet-calendar/how-synchronise-your-calendars-across-channels), [Booking.com handling double bookings](https://partner.booking.com/en-gb/help/reservations/overbookings-no-shows/handling-overbookings).

**Notas de 22/10 (não são gaps novos):**
- **Níveis de co-anfitrião:** cobertos pela árvore de gestão e pelas listas de permissões predefinidas (spec-02 §0); nenhum gap novo. A regra do Airbnb "o proprietário responde pelos co-anfitriões adicionados por co-anfitriões" é uma nota do Jurídico para os contratos do fim do projeto.
- **Pedidos acima do máximo de noites:** continuam como nota (G13; teto de 30 noites, G-5).
- **Mínimo por dia da semana:** coberto pelos períodos da spec-07 B1 mais os dias de chegada da G18.
- **Conjuntos de regras para vários anúncios:** absorvidos pelos modelos por nó da G18.

### Restrições para a S7
- O cancelamento guarda `initiated_by` e `responsible` em campos separados; taxa, métrica, reembolso e comissão leem `responsible` (G14), com referência opcional a um evento disruptivo (G15).
- O bloqueio de solicitação pendente é visível para a disponibilidade do ADR 001 e sai nos feeds iCal do imóvel; a expiração libera na mesma transação (G9).
- O limite de solicitações pendentes é o parâmetro `max_pending_requests_overlapping` (padrão 2 [E], por canal), e o modo de bloqueio é parâmetro (`hard` só) (G9).
- N expirações seguidas do anfitrião é parâmetro de nó/canal (padrão 2 [E]) (G10).
- Cada solicitação guarda recebido / respondido / expirado na própria linha (G8, G10).
- Pagamentos como N linhas por reserva, não um `paid_at` (G12).
- O snapshot guarda o `max_nights` do anúncio e o teto da plataforma de 30 noites (G13) e a política resolvida por (imóvel, canal, check-in) (G16).
- Uma oferta ou solicitação pré-aprovada leva o canal de origem (G11).
- A exportação iCal inclui as noites seguradas (G9, G20); uma importação que se sobrepõe a uma solicitação ou reserva da casalivre cria um registro de conflito e nunca a sobrescreve; o intervalo de importação é parâmetro (G20).
- Regras de disponibilidade por imóvel, com modelos por nó e valores sazonais pelos períodos de datas, avaliadas num lugar só e dentro das metas de velocidade do ADR 001 (G18, G19).
- Cadastro de anúncio (P10): campos estruturados de divulgação de câmera externa (sim/não + local) e monitor de ruído (sim/não + só decibéis), com a confirmação obrigatória de "sem câmeras internas, sem gravação de áudio, sem reconhecimento facial" para publicar (G6); forma de check-in como enum (G7).

## Itens adiados
- Hóspede estrangeiro sem CPF: document_type/document_country (passaporte) e hmac por tipo/país, depois do J40 com o Jurídico (decisão do Rafael: deixar para depois). Por enquanto o CPF é obrigatório (guest_fiscal_party_id NOT NULL).
  - **Critérios de aceite** (para depois do piloto, com o J40 respondido pelo Jurídico):
    1. Com document_type=passport, o cadastro exige o país emissor.
    2. A DIMOB sai com "NDP" no lugar do CPF.
    3. A NFS-e preenche o NIF do país de origem ou o motivo da ausência.
    4. Nenhum pagamento aceita moeda estrangeira fora do PSP.
    5. O passaporte segue a mesma retenção dos outros documentos.

## Pauta do Rafael (decisões pendentes, 16/10)
- Dados da empresa para os termos: razão social, CNPJ, endereço e DPO (encarregado).
- Teto da caução.
- Valor da limpeza extra.
- Fornecedor de IA (ver P8).
- Inscrição da casalivre no CRECI-RJ com corretor responsável (risco médio no canal direto enquanto não decidir).
- Comissão do canal manual.
- DIMOB (IN RFB 1.115/2010): aceitar a obrigação ou fazer consulta formal à Receita (em aberto); a modelagem com o CPF do hóspede em fiscal_parties continua.
