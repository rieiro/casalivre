**Gerenciamento Conta usuários**

-> Data 07/10/2026
-> Revisão 0.5

-------------------------------------------------
| Revisão | 0.1 | Inicial | 

-------------------------------------------------
| Revisão | 0.2 | Lista de alterações |

- adicionado itens 1, 2 , 3 , 4 e 5

-------------------------------------------------
| Revisão | 0.3 | Lista de alterações | 05/10/2026

- alterado item 2

-------------------------------------------------
| Revisão | 0.4 | Lista de alterações | 05/10/2026

- inserido item 6: senhas de baixa complexidade são recusadas

-------------------------------------------------
| Revisão | 0.5 | Lista de alterações | 07/10/2026

- inserido item 7: a sessão expira após 60 minutos, parâmetro session_minutes no cache settings.json

-------------------------------------------------

1. Na tela de cadastro proprietário, o sistema deverá pedir a senha em duas caixas de diálogo separadas, e só deixar a conta ser criada se as senhas forem iguais.
2. Quando a conta de um usuário é apagada, seu registro não é apagado do banco. Seu registro ficará sinalizado como apagado, e não inativo, e será registrada a data de apagamento, a hora e também o ip de origem.
3. Contas de usuários inativas não podem ser recriadas pelos usuários novamente, necessitando de intervenção manual do time de suporte.
4. Ao criar uma conta de usuario nova, é gravado no banco de dados um link de verificação de email com o link a ser clicado pelo usuário para ativar seu registro. Este link terá <x> minutos para ser clicado e corresponderá a um hash criado especialmente para esta autenticação. 
5. O sistema deverá permitir a re-definição de senha. Para tanto, é gravado um link no banco de dados, corresponderá a uma página do sistema e hash criado especificamente para esta ação. Este link terá <y> minutos para ser clicado para efeturar a operação. Após <y> minutos o usuário deverá pedir novamente para redefinir senha.
6. O sistema não deve permitir o cadastro de senhas de baixa complexidade, por exemplo 123456 ou abc123. A mesma regra vale na redefinição de senha. A senha continua precisando ter pelo menos 8 caracteres e misturar letras, números ou símbolos. Sequências, repetições e combinações curtas de letras seguidas só de números são recusadas.
7. A sessão do usuário deve expirar após 60 minutos. Este prazo é o parâmetro session_minutes, gravado no banco e publicado no cache storage/cache/settings.json. Depois desse tempo o proprietário precisa entrar de novo. 
