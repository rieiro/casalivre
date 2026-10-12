**Calendarios**

-> Data 06/10/2026
-> Revisão 0.6

-------------------------------------------------
| Revisão | 0.1 | Inicial | 

-------------------------------------------------
| Revisão | 0.2 | Lista de alterações |

- A exportação iCal do imóvel passa a ser um link com hash do calendário, visível na edição do calendário.

-------------------------------------------------
| Revisão | 0.3 | Lista de alterações |

- Ao selecionar uma data, o mínimo de noites mostra o valor daquela noite e o título indica a data.

-------------------------------------------------
| Revisão | 0.4 | Lista de alterações |

- Não permitir dois links de calendário externo idênticos no mesmo imóvel.

-------------------------------------------------
| Revisão | 0.5 | Lista de alterações |

- Cada link iCal externo tem um título informado pelo proprietário. O limite continua em 5 links por imóvel.

-------------------------------------------------
| Revisão | 0.6 | Lista de alterações | 06/10/2026

- Na versão desktop, três botões no topo do painel direito alternam o que aparece em cada dia: número, diária ou mínimo de noites.

-------------------------------------------------

1. Cada imóvel terá um calendário associado.
2. O calendário deve seguir o padrão de interface utilizado pelo booking para configurações de imóveis para proprietários.
3. Ele deve poder ser acessado pelo proprietario para, dentre outras ações, alterar valores de diárias de datas específicas, bloquear e desbloquear datas, quantidade mínima de estadias padrão, quantidade mínima de estadias personalizadas em datas especiais.
4. cada imóvel poderá sincronizar com calendários externos de outras plataformas através de links com arquivos .ics. Estes links devem ser gravados no banco de dados, associados ao imóvel em questão, de maneira apropriada.
5. O sistema poderá importar até 5 calendários externos por links .ics por imóvel. O banco de dados deve refletir esta possibilidade. 
6. A cada sincronização bem sucedida, o sistema gravará o status OK da ultima sincronização e a data e hora que foi realizada.
7. Em caso de sincronização mal sucedida, o sistema gravará o status NOK e a data e hora que foi realizada a tentativa.
8. Uma rotina de sincronização será disponibilizada para o sincronismo de calendário de todos os imóveis ativos, que tenham também suas contas ativas.
9. A rotina de sincronização deverá ser facilmente monitorada pela equipe de suporte.
10. A rotina de sincronização deverá ser otimizada para economizar recursos do banco de dados, evitando excesso de escritas e leituras.
11. o proprietário terá visualização de : a) lista de sincronizações configurada; b) do resultado da última tentativa de sincronização de cada link de arquivo .ics configurado, se elas foram bem sucedidas ou não. Estas podem aparecer lado a lado. Estas informações deverão aparecer na página de calendário do imóvel.
12. A exportação de calendário em link iCal deve conter um hash correspondente ao calendário do imóvel exportado. Este link deve estar disponível na página de edição de calendário.
13. Ao selecionar uma data no calendário, o campo de mínimo de noites mostra o valor daquela data. O título da caixa reflete a seleção, por exemplo, para 08/10/2026: "Mínimo de noites a partir de 08-out". Ao salvar, esse valor fica gravado na data selecionada.
14. Na edição de calendário, não é permitido adicionar dois links de calendários externos idênticos ao mesmo imóvel. A segunda tentativa é recusada e a página informa que o link já está nesse imóvel.
15. O proprietário pode adicionar até 5 links de calendários iCal externos. Cada link deve ser acompanhado de um título inserido pelo proprietário. Por exemplo, título: Booking, link: https://ical.booking.com/v1/export?t=... O título aparece junto do link na página de edição do calendário.
16. Somente na versão desktop, no topo do painel de botões à direita na edição de calendário, deve ser possível alternar a visualização das caixas que hoje mostram o número do dia. Os tipos são: 1) apenas o número do dia, como está atualmente; 2) o valor da diária no lugar do número do dia; 3) o número mínimo de noites no lugar do número do dia. Podem ser três botões lado a lado, apenas um ativo de cada vez. 
 