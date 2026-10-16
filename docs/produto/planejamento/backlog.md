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

## Itens novos do Jurídico (recomendacoes-sem-orientacao-externa.md, 16/10)
- **J-TXT (Must, risco Alto):** escrever o texto dos 3 termos D14, do contrato plataforma × anfitrião, da política de privacidade e da de cancelamento, seguindo o checklist da seção 5 (A a E). Os textos são escritos por Produto e Jurídico durante a S5 e a S6, revisados pelo Negócios e publicados com versão e data na S6, junto com o motor de aceite dos termos. Critério de aceite: todos os itens da seção 5 estão marcados, e cada texto está publicado com versão e data.
- **J-PIL (Must, condição para abrir o piloto, gate de go-live depois da S8):**
  - 100% dos anfitriões com KYC aprovado;
  - chave Pix com o mesmo CPF/CNPJ do recebedor;
  - canal de denúncia funcionando, com remoção rápida do anúncio;
  - aviso de pagamento fora da plataforma no checkout (S7);
  - troca de chave Pix pelo usuário desligada até a D3.
  O piloto não abre se algum item falhar.
- **J-IBS (Should em 2026, Must antes de 01/2027):**
  - o ledger registra por reserva o anfitrião, o CPF/CNPJ, o documento, o valor bruto, a comissão e as datas;
  - relatório anual por anfitrião exportável;
  - cláusula de responsabilidade fiscal e de regresso no contrato do anfitrião;
  - campo no anúncio para cadastro e taxa municipais.
  Os campos do ledger entram na S6. O relatório anual e o aviso municipal entram na S8. A reverificação do regulamento da CBS é em 15/12/2026.
