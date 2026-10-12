**Arquitetura do Sistema**

\-&gt; Data 07/10/2026 -&gt; Revisão 0.4

---

| Revisão | 0.1 | Inicial |

---

| Revisão | 0.2 | Lista de alterações |

- adicionado item 4

---

| Revisão | 0.3 | Lista de alterações | 05/10/2026

- inserido item 5

---

| Revisão | 0.4 | Lista de alterações | 07/10/2026

- inseridos itens 6 e 7: pipeline em cebola, mitigação OWASP, hash nativo de senha, segredos só no .env, upload com UUID e finfo

---

1. Arquitetura: PHP + backend; O backend deve ser desacoplado do front end, adotando o padrão MVC. Deve ser possível, sem esforço, migrar para outro banco de dados, como postgres, por exemplo.
2. Implementar CRUD para elementos básicos: Proprietarios, Casas.
3. O site deverá poder ser visualizado, sem perda de funcionalidade, tanto em desktops quanto em celulares.
4. O sistema deverá armazenar todos os valores padronizáveis em tabela separada no banco de dados, para fácil acompanhamento do time do suporte e para o fácil entendimento do código. Por exemplo, número máximo de retries de login incorreto, número de meses default a partir da data atual onde é possível gerenciar calendário. Esta lista não é exaustiva. Evidentemente, estes valores devem ser consultados cada vez q o sistema necessitar deles. Para evitar consultas ao banco desnecessárias, pode existir um armazenamento cache com estes valores padrão. O time de suporte deverá ter uma ferramenta para atualizar o cache, no caso em que estes valores padronizados no banco forem alterados.
5. Traduções ficam em um arquivo de cache separado, storage/cache/translations.json. Cidades e estados ficam em outro arquivo separado, storage/cache/cities.json. O mesmo script de atualização de cache relê o banco e regrava todos os arquivos JSON de cache.
6. .Temos um arquivo de roteador único que recebe as requisições, interage com repositórios e renderiza HTML ou JSON diretamente. Quero refatorar essa estrutura para adotar o padrão de Pipeline/Middlewares (estilo Onion/Cebola), mantendo o projeto leve e sem dependências externas. Por favor, forneça uma implementação objetiva contendo:

i.A interface de Middleware. ii.A classe executora do Pipeline (MiddlewarePipeline). iii.Dois middlewares práticos de exemplo: um de autenticação simples (checando $\_SESSION) e um de sanitização de inputs contra XSS (htmlspecialchars). iv. Um exemplo prático de como injetar esses middlewares no meu roteador (index.php) antes de chamar a lógica do repositório.Seja direto no código, evite sugerir frameworks ou configurações de servidores como Nginx/Apache.

7.Preciso implementar um sistema de segurança robusto que previna as principais vulnerabilidades do (OWASP Top 10). Quero que você crie uma estrutura organizada, modular e de fácil manutenção, implementando explicitamente as seguintes mitigações:

i.Contra SQL Injection (Injeção de SQL): Demonstre um exemplo prático de classe de repositório (ex: UserRepository) utilizando Prepared Statements com PDO de forma segura. ii.Contra CSRF (Falsificação de Solicitação Entre Sites): Crie um CsrfMiddleware que gere tokens criptograficamente seguros para a sessão e valide automaticamente esses tokens em requisições de escrita (POST/PUT/DELETE). iii.Contra XSS (Cross-Site Scripting): Crie um SanitizeMiddleware que limpe recursivamente dados de entrada ($\_POST/$\_GET) usando htmlspecialchars. iv.Contra Vazamento de Informações Sensíveis: Configure um manipulador global de exceções (set_exception_handler) que intercepte erros em produção, salve o stack trace real em um arquivo de log seguro e exiba apenas uma tela genérica/amigável ao usuário. v.Contra Configuração Insegura de Cookies de Sessão: Forneça a configuração ideal do session_start() blindando os cookies com HttpOnly, Secure e SameSite=Lax. vi.Contra Broken Object Level Authorization (BOLA / IDOR): Demonstre no repositório ou em uma camada de serviço como validar se o ID do recurso solicitado pertence de fato ao usuário logado na sessão antes de entregar os dados. vii.Contra Ataques de Força Bruta (Rate Limiting): Crie um RateLimitMiddleware simples que utilize uma tabela no banco de dados (ou array em memória/arquivo) para bloquear temporariamente (por IP) requisições consecutivas na tela de login após 5 tentativas falhas.

viii.Restrições Estritas:

Não utilize frameworks (Laravel, Symfony, etc.). Não utilize bibliotecas externas via Composer (mantenha tudo em PHP nativo). Não inclua configurações de servidores web (Nginx/Apache), foque estritamente na lógica do código PHP.

ix. use funcoes nativas e atualizadas do php para fazer hash como password_hash() com o algoritmo mais seguro disponível (atualmente o Argon2id ou Bcrypt). Substitua todas as criações de hash do código, se eles não estiverem desta maneira.

x. Não manter chaves criptograficas de credenciais de APIs (Stripe, Pagar.me, AWS), chaves criptográficas e senhas do banco de dados escritas diretamente nos arquivos de código PHP (config.php). Armazene-as em arquivos .env.

xi. Nos eventos de upload de arquivo, por exemplo, fotos, certifique-se: -&gt; Mude o nome do arquivo original para um ID aleatório (ex: uuid()), e -&gt; Nunca confie apenas na extensão do arquivo (.jpg), valide o Mime-Type real lendo os bytes do arquivo (usando a extensão finfo do PHP).