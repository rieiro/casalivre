**Busca Por Imovel**

-> Data 05/10/2026
-> Revisão 0.4

-------------------------------------------------
| Revisão | 0.1 | Inicial | 04/10/2026

-------------------------------------------------
| Revisão | 0.2 | Lista de alterações | 04/10/2026

- inseridos itens 3,4,5

-------------------------------------------------
| Revisão | 0.3 | Lista de alterações | 05/10/2026

- alterado item 5
- inserido item 6

-------------------------------------------------
| Revisão | 0.4 | Lista de alterações | 05/10/2026

- inseridos itens 7, 8, 9 e 10

-------------------------------------------------

1. O cliente, ao fazer uma busca por imóvel, deve ser capaz de aplicar os seguintes filtros aos imóveis disponíveis: Data disponível ( data de entrada combinada com data de saída ), localidade aproximada, em 5,10 e 25km, valor da diária, e score de avaliações de clientes.
2. O cliente final deverá poder filtrar o imóvel pelo número máximo de hóspedes
3. A lista de cidades brasileiras deverá constar dentro do Banco de Dados
4. Para tornar a busca mais imune a erros de digitação, o sistema transformará o texto inserido pelo usuário e comparará com a lista de cidades. O sistema deverá ser capaz de fazer o match de cidades inseridas pelo usuário com cidades no banco de dados mesmo com diferença entre maiúsculas e minúsculas e mesmo com ou sem acentuação. Por exemplo, sao paulo como item de busca faz o match com a cidade do banco de dados listada como São Paulo. 
5. A caixa de seleção de cidade deve se comportar como na seleção do site booking.com. Ao inserir ao menos 3 caracteres, o elemento se comporta como uma list box com resultados sugeridos. Exemplo: digitado "sao", a list box sugere os possíveis matches no formato Cidade, Estado: São Paulo, SP; São Vicente, SP. Sempre nesse formato: Cidade, Estado.
6. As caixas de data de entrada e de saída se comportam como no site booking.com. Ao clicar na seleção de datas, abrem-se caixas visuais de calendário, com pelo menos dois meses visíveis de cada vez.
7. Na versão desktop do resultado da busca de imóvel inicial, mostrar os resultados no formato horizontal.
8. Remover da página de busca inicial os filtros raio, diária máxima, diária mínima e nota.
9. Na tela de busca inicial, adicionar a descrição, visível a todo momento, das caixas de localidade, data de entrada, data de saída e hóspedes.
10. A busca de imóveis se dará em duas fases. Após a tela de busca inicial, já com os resultados, será mostrada uma segunda tela, com os resultados da busca e todos os filtros aplicáveis aos imóveis, imitando o design do site booking.com. Respeitar as diferenças de diagramação entre desktop e celular.
