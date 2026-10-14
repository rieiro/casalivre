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

| Revisão | 0.5 | Lista de alterações | 14/10/2026

- acrescentada a seção "Implementação atual" (descrição do código; requisitos inalterados)

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

---

**Implementação atual** (descrição do código; não altera os requisitos acima)

- Pipeline em cebola: `seguranca/` — `Middleware` (interface; define `WRITE_METHODS`), `Pipeline` (executor) e `Seguranca::pipeline()`, chamado por `public/index.php` antes do `dispatch()`. Camadas, de fora para dentro:
  1. `ApiRateLimit`: limite por IP (IPv6 por /64) em rotas da API listadas em `public/index.php` (`api_limites`: cidades, ceps e busca 60/min; esqueci a senha 5/15 min; cadastro 10/h; avaliar 10/h; upload de fotos 30/10 min), via `IpRateLimiter` (arquivos em `storage/cache/ratelimit`, limpeza própria e teto de arquivos; falha aberta registrada no error_log). Excesso: 429 com `Retry-After`.
  2. `BodyLimit`: em métodos de escrita não-multipart, `Transfer-Encoding` ou falta de `Content-Length` (exceto DELETE sem corpo) -> 411; corpo acima de 1 MiB (`BodyLimit::MAX_BYTES`) -> 413.
  3. `Sanitize`: `htmlspecialchars` recursivo em `$_GET`/`$_POST` (exceto campos puros: senhas, `_csrf`, URL de iCal).
  4. `Csrf`: token de sessão (`random_bytes`), exigido nos métodos de escrita (campo `_csrf`, cabeçalho `X-CSRF-Token` ou JSON). Recusa: 419.
  5. `RateLimit`: bloqueio de login (`POST /entrar`, `POST /api/sessao`) por `LoginRateLimiter`; 429 com `Retry-After`.
  6. `Auth`: rotas privadas exigem `$_SESSION['user_id']`; web redireciona para `/entrar`, API responde 401.
- Recusas: `casa_recusar()` em `public/index.php` envia a resposta e encerra; na API o corpo é sempre `{"error": "..."}`.
- Erros: `ExceptionShield` (`set_exception_handler`) grava em `storage/error.log` mensagem e trace com `Redact::secrets()` (e-mails, senhas/tokens, hashes e query strings mascarados) e mostra tela genérica com código de referência. `zend.exception_ignore_args` ligado em `public/index.php`.
- Sessão: aberta só com cookie de sessão, em rota autenticada ou quando uma página pede o token CSRF (`csrf_token()`). `SecureSession` -> `SessionGuard` (`conta/Auth/Session.php`): cookie HttpOnly, Secure em HTTPS, SameSite=Lax (ou `SESSION_SAMESITE`). Banco: repositórios com PDO e prepared statements; senhas com `password_hash` (Argon2id, ou Bcrypt se indisponível).

