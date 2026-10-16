# Personas e jornadas (RASCUNHO v0, 16/10/2026)
Personas baseadas no plano de negócios (seções 2–3, 6). Não há pesquisa com usuários: **validar com entrevistas** (5–8 por persona) antes de tratar como fato.

## Hóspede — "Família de fim de semana"
- Fonte: plano cita origem dos turistas da Região dos Lagos (43,5% RJ, 22,6% MG, 8,1% SP; pesquisa acadêmica de tamanho não divulgado) e ticket estimado de R$ 1.750 (ESTIMATIVA).
- Objetivo: casa para o grupo, preço total claro, menor que na OTA.
- Dor: medo de golpe com Pix antecipado em anúncio direto (plano, seção 2).
- Falta: faixa etária, dispositivo, antecedência de reserva.

## Anfitrião/proprietário
- Objetivo: receber mais (90% no direto vs 84% Airbnb), sem gerir sozinho.
- Dor: tempo e presença local; taxas das OTAs.
- Hoje no produto: cadastra imóvel, fotos, calendário, iCal.

## Corretor-franqueado (persona do plano; ainda sem papel no código, parcialmente coberta por "gestor de grupo")
- Objetivo: carteira de imóveis com renda de 4% do GBV; site regional.

## Administrador (casalivre)
- Objetivo: gerir grupos, domínios, temas, moderação, e ver indicadores.
- Hoje: /admin/grupos, /admin/tema. Falta moderação, reservas, métricas.

## Jornadas (estado atual: ✅ existe, ⚠️ parcial, ❌ não existe)
| Etapa | Hóspede faz | Estado | Lacuna |
|---|---|---|---|
| Buscar | cidade/CEP/raio, datas, hóspedes, comodidades, lista e mapa | ✅/⚠️ | filtro em PHP sem paginação (Sprint 4); sem filtro de preço/ordenação conferidos |
| Comparar | ver páginas dos imóveis | ⚠️ | sem favoritos e comparação (P5); preço total sem detalhe (P4) |
| Reservar | — | ❌ | P2 |
| Pagar | — | ❌ | P6 (decisão Rafael) |
| Avaliar | formulário de nota 1–10 aberto | ⚠️ | não vinculada à estadia (P3) |

Jornada do anfitrião: cadastrar ✅ → fotos ✅ → calendário/iCal ✅ → receber solicitação ❌ → aceitar ❌ → receber repasse ❌.
Jornada do admin: grupos/domínios/temas ✅ → moderar avaliação ❌ → ver funil ❌.
