**Log de Atividades**

-> Data 04/10/2026
-> Revisão 0.2 

-------------------------------------------------
| Revisão | 0.1 | Inicial | 04/10/2026

-------------------------------------------------
| Revisão | 0.2 | Lista de alterações | 04/10/2026

- Adicionado item 3 - Tabela com lista de ações possíveis do usuário

-------------------------------------------------

1. Cada ação do usuário, esteja ele autenticado, ou não, deve ser gravado em uma tabela, com todas as informações necessárias para entender a ação efetuada, em caso de análise posterior. Por exemplo, tentativa de login sem sucesso, tentativa de login com sucesso, busca por cidade, contendo os parametros utilizados de cidade, raio, etc.. usados na busca, alteração de dados de conta, adicionar fotos, remover fotos, adicionar imovel, remover imovel. Esta lista não é exaustiva. 
2.Cada tipo de ação pode deve ser representada no banco por um índice e sua descrição, ou breve explicação, acrescentados os demais parametros a ele pertinentes. Esta implementação não necessita ser gravada em banco de dados relacional, pela propria natureza do tipo dos registros, que podem sofrer alterações frequentes, conforme a aplicação evolui, e serão utilizadas praticamente só para leitura eventual do time do suporte ao cliente.
3. A lista de todas as ações possíveis do usuário que podem ser logadas deve estar gravada em uma tabela em separado do banco de dados de log de atividades, para facilitar a consulta e operação do sistema pelos times de suporte.
