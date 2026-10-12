**Imoveis**

-> Data 05/10/2026
-> Revisão 0.4

-------------------------------------------------
| Revisão | 0.1 | Inicial | 

-------------------------------------------------
| Revisão | 0.2 | Lista de alterações |

- Remover um imóvel passa a inativá-lo, com data no banco principal e detalhe no log. Inativos não aparecem na edição nem na busca.

-------------------------------------------------
| Revisão | 0.3 | Lista de alterações |

- Limites dos campos do imóvel: título, descrição, quartos, camas e hóspedes.

-------------------------------------------------
| Revisão | 0.4 | Lista de alterações |

- A diária máxima do imóvel é 999.999,00.

-------------------------------------------------

1. A configuração de horário de check-in e check-out pertence ao imóvel e não está relacionada a nenhuma data específica do calendário.
2. Compilar uma lista das 25 mais relevantes amenidades de um imóvel, dentre os sites de alugueis por temporada, e adicionar à configuração possível durante a edição de imóvel.
3. Fazer uma diagramação de amenidades em duas colunas, para melhor preenchimento da tela e aproveitamento do espaço.
4. Separar as amenidades por categorias, quando aplicável
5. Ao remover um imóvel, ele não é apagado. Seu estado passa a inativo. A inativação é gravada no banco principal com data e hora, e no banco de log com os detalhes do imóvel. Imóveis inativos não aparecem para edição nem retornam como resultado de buscas.
6. Na edição do imóvel, o título pode ter até 25 caracteres e a descrição até 500 caracteres. Quartos, camas e hóspedes aceitam no máximo 999. O valor máximo da diária é 999.999,00.
