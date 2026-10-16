# Métricas de sucesso (RASCUNHO v0, 16/10/2026)
Não há produção nem dados de uso: **nenhuma linha de base existe**. Metas abaixo são hipóteses a confirmar após 4 semanas de coleta. Referência externa do plano (não é dado do casalivre): conversão de 2–4% em tráfego pago; ocupação AirDNA 45–48% (Cabo Frio/Búzios/Arraial).

| Métrica | Definição | Como instrumentar | Dado faltante |
|---|---|---|---|
| Conversão busca→reserva | sessões com `reserva_aceita` ÷ sessões com `busca` (e etapa intermediária `reserva_enviada`) | eventos P1 com id de sessão hash; funil diário | não existe evento de reserva (P2) nem de ver imóvel |
| Tempo até reservar | mediana entre 1º `busca` e `reserva_enviada` na mesma sessão/usuário | timestamps dos eventos P1 | ligação entre sessões anônima e login (definir com Segurança) |
| Taxa de abandono | sessões com `inicio_reserva` sem `reserva_enviada` ÷ `inicio_reserva`; também busca sem `ver_imovel` | eventos P1 | evento `inicio_reserva` |
| Aceite do anfitrião | aceitas ÷ solicitações; tempo médio de resposta | estados da tabela de reservas (P2) | P2 |
| Avaliações | nº por estadia concluída; nota média; % estadias avaliadas | tabela reviews vinculada (P3) | hoje avaliações não são ligadas a estadia, dados atuais não servem |
| Confiabilidade do anfitrião | cancelamentos pelo anfitrião ÷ reservas confirmadas (90 dias móveis); idem para o hóspede; nº de expirações seguidas | estados e autor da transição na reserva (P2) | limites iniciais [E] a calibrar com dados do piloto |
| KPIs do plano (após pagamento) | % GBV direto, CAC por reserva direta, ocupação, GBV/imóvel | P6 + relatórios | pagamento e custos de mídia |

Regras: sem dado pessoal em claro nos eventos (LogPrivacy/hash), retenção definida pela Segurança; cada entrega do backlog traz a métrica que deve mover e é revisada 2 semanas após o merge.
