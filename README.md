# CasaLivre

Sistema de aluguel por temporada. O backend PHP não renderiza HTML: repositórios (`src/UserRepository.php`, `src/HouseRepository.php`, `src/PhotoRepository.php`) e a API JSON em `/api` concentram o acesso ao banco. O front em `views/` só monta páginas e chama esses serviços.

O banco é o PostgreSQL (decisão de 2026-10-21: só PostgreSQL). Crie o schema e aponte o DSN.

```bash
psql -d casalivre -f database/schema.pgsql.sql   # Postgres novo: carregue o schema PRIMEIRO
php scripts/migrate.php                          # depois migre (a cada deploy, antes do tráfego)
```

No Postgres, a requisição web não migra: só confere `schema_meta.schema_version` (uma consulta) e responde 503 se o banco estiver atrás do código. A migração roda só no `scripts/migrate.php`, sob `pg_advisory_lock` (execuções simultâneas esperam uma pela outra). O `migrate.php` sai com 0 (ok), 1 (versão abaixo da esperada) ou 2 (falha, com a mensagem no STDERR).

No `.env` ficam o banco e as chaves de serviço. Nada disso é escrito em arquivo PHP.

```bash
DB_DSN=pgsql:host=127.0.0.1;dbname=casalivre
DB_USER=usuario
DB_PASSWORD=
STRIPE_SECRET_KEY=
PAGARME_API_KEY=
AWS_ACCESS_KEY_ID=
AWS_SECRET_ACCESS_KEY=
```

O log fica no schema `audit` do PostgreSQL: `audit.activity_log` (classes `security`, 90 dias, e `other`, 14 dias, em partições diárias removidas por DROP) e `audit.audit_log` (classe `admin`, 5 anos). Cada ação tem um código, uma classe (`activity_actions`) e só os parâmetros permitidos daquela ação; o usuário vira um HMAC de 32 bytes (`LOG_SALT`, `LOG_SALT_KEY_VERSION`) e a senha nunca é gravada. O app só tem INSERT; a leitura é pelas funções `audit.activity_recent` / `audit.activity_by_actor`, como um login com `casalivre_ops_logread`, e cada leitura fica registrada no `audit_log`. Retenção: `php scripts/log-retention.php` (dry-run; `--apply` remove). Para ler:

```bash
php scripts/activity-log.php
php scripts/activity-log.php --codigo=2 --limite=20
```

## Subir na máquina local

Requisitos: PHP 8.2+ com `pdo_pgsql` e `mbstring`, e um PostgreSQL. O ambiente de desenvolvimento igual ao de produção (PHP-FPM 8.4, PostgreSQL, Valkey) está em `ops/dev-env/`. No Void o pacote não liga o módulo sozinho:

```bash
ver=$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')
sudo xbps-install -S php php${ver}-pgsql
sudo mkdir -p /etc/php${ver}/conf.d
printf 'extension=pdo_pgsql\n' | sudo tee /etc/php${ver}/conf.d/20-pdo_pgsql.ini
php -m | grep pdo_pgsql
```

```bash
bash scripts/setup.sh
bash scripts/start.sh
```

O `start.sh` sobe o limite do PHP para 16 MB por foto e 128 MB por envio. Sem isso, várias fotos juntas estouram o padrão de 8 MB e a sessão quebra. Se o servidor já estava no ar, pare e suba de novo.

Abra http://127.0.0.1:8080 neste computador. O `start.sh` escuta em todas as interfaces: no celular, na mesma rede, use `http://IP-DA-MAQUINA:8080` (o script mostra o endereço ao subir).

- Depois do login o proprietário cai em `/painel`, com a lista dos próprios imóveis.
- Dá para adicionar, editar e remover imóvel, adicionar fotos e apagar fotos.
- Fotos: o original fica fora do `public/` (`storage/fotos/originais/<uuid>.<ext>`) e os WebP públicos em `public/uploads/fotos/<uuid>-<largura>.webp`, gerados pelo `scripts/image-worker.php`.
- Datas na interface usam dia/mês/ano (dd/mm/aaaa).
- No celular o menu abre em "Menu" e os formulários, a busca e o painel continuam disponíveis.
- O idioma fica no canto superior (português, inglês, espanhol). Data, hora e moeda seguem o idioma. Os textos estão na tabela `translations`.
- A busca usa a lista de municípios brasileiros, ignora maiúsculas e acentos, e sugere cidades a partir de 3 letras.
- O cadastro pede a senha duas vezes, grava um link de verificação com prazo e não remove o registro: a conta fica com estado `apagado`, com data e IP.
- Parâmetros, traduções, cidades/estados e CEPs ficam no banco e em cache JSON (`storage/cache/settings.json`, `translations.json`, `cities.json`, `cep.json`). O máximo de fotos por imóvel é o parâmetro `max_photos_per_property`. Depois de alterar o banco: `php scripts/refresh-settings-cache.php`.
- Os CEPs vêm da base aberta OpenCEP 2.0.1 (julho de 2024, cerca de 1,2 milhão de códigos, licença MIT), no arquivo `database/cep.csv.bz2`. Na próxima subida, se a versão mudou, `scripts/start.sh` recarrega a tabela e regrava `storage/cache/cep.json`.
- Contas bloqueadas ou inativas: `php scripts/support-conta.php --email=... --ativar --desbloquear`.
- O catálogo das ações do log fica na tabela `activity_actions`.
- A busca pública continua sem login.

Para parar: `bash scripts/stop.sh`

Dados aleatórios para navegar (5 proprietários, cada um com 2 ou 3 imóveis e uma foto, sem depender da extensão GD):

```bash
bash scripts/seed-users.sh
```

`php scripts/seed.php` faz o mesmo. E-mail e senha saem no terminal e em `storage/seed-users.txt`. O login é em `/entrar`.

Grupo de exemplo (opcional, só desenvolvimento): depois do seed acima,

```bash
APP_ENV=dev php scripts/seed-grupo-teste.php
```

cria o grupo `teste` (tema contraste) com 3 imóveis ativos de seed já aceitos, e a vitrine `/g/teste/` funciona na hora. Só roda com `APP_ENV=dev` ou `local`; em qualquer outro caso recusa sem tocar no banco. Não torna ninguém admin: se já houver admin (via `php scripts/set-admin.php <email>`), ele vira gestor do grupo; sem admin, o grupo nasce sem gestor e o script avisa. Rodar de novo não duplica nada (só completa o que faltar).

Temas: cada tema é uma pasta `themes/<nome>/` com um `brand.php` (marca, logo, favicon, tokens CSS). `php scripts/build-assets.php` gera `public/assets/themes/` e o manifesto; só os temas do manifesto podem ser escolhidos. A troca é em `/admin/tema`, só para admin. Para tornar uma conta admin, use `php scripts/set-admin.php <email>` (e `--remover` para tirar).

A API, com a sessão autenticada e o token CSRF, expõe o CRUD:

- `POST /api/users`, `GET|PUT|DELETE /api/users/eu`
- `GET|POST /api/casas`, `GET|PUT|DELETE /api/casas/{id}`
- `POST /api/casas/{id}/fotos`, `DELETE /api/casas/{id}/fotos/{foto}`

## Logs de erro e privacidade

- `public/index.php` faz `ini_set('zend.exception_ignore_args', '1')` para que stack traces não incluam valores de argumentos. Em produção, defina também no `php.ini` (ou no pool do PHP-FPM): `zend.exception_ignore_args = On`.
- `ExceptionShield` grava em `storage/error.log` e no log de atividades só o caminho da rota, sem query string, com segmentos de 32 hex (ex.: `ical_hash`) trocados por `{hash}` (`src/Redact.php`).

## Casos de teste

Com o servidor no ar:

```bash
php scripts/run-casos-de-teste.php
```

## Logs: pseudonimização e retenção

- **`LOG_SALT` (obrigatório, sem fallback):** defina no `.env` um valor secreto e aleatório (`php -r 'echo bin2hex(random_bytes(32));'`). Ele é usado para HMAC-SHA256 de usuário, e-mail, user agent e URLs nos logs de requisições (`storage/logs/api/`) e de atividades (`activity_log`). **Sem `LOG_SALT`**, esses identificadores são omitidos e o PHP emite um único aviso via `error_log`, sem dados. Trocar o sal quebra a correlação com registros antigos.
- **IP:** truncado em /24 (IPv4) e /48 (IPv6) nos dois logs.
- **Retenção (14 dias, a confirmar com o Gerente Geral):** rode diariamente `php scripts/log-retention.php [dias]`. Ele cobre `storage/logs/api/api-*`, o log de atividades, `storage/error.log`, `storage/logs/calendar-sync.log` e `storage/php.log`/`storage/logs/php.log`. Cron sugerido:

  ```
  15 3 * * *  php /caminho/casalivre/scripts/log-retention.php
  ```
- **Falhas de escrita** do log de requisições incrementam `RequestLog::$writeFailures` e geram `error_log` sem dados.
- **Query string no log da API:** rotas de iCal (`/api/ical/…`, `/ical/…`, `*.ics`) gravam a query vazia. Nas demais, só as chaves de `RequestLog::QUERY_VALUE_ALLOWLIST` (`q`, `localidade`, `checkin`, `checkout`, `hospedes`, …) têm o valor gravado. As outras chaves aparecem só pelo nome (valor `null`). Em `/api/ceps` só os nomes das chaves são gravados.
- **runit (Void Linux):** o serviço `deploy/runit/casalivre-log-retention/run` roda a retenção e dorme 24h; o runit o reinicia, então ela se repete todo dia.

  ```
  sudo cp -r deploy/runit/casalivre-log-retention /etc/sv/
  # opcional: /etc/sv/casalivre-log-retention/conf com APP_DIR, APP_USER, RETENTION_DAYS, PHP_BIN
  sudo ln -s /etc/sv/casalivre-log-retention /var/service/
  ```
- **Buscar registros de um usuário:** `php scripts/log-lookup.php <user_id>` calcula o HMAC do id com `LOG_SALT` (mesma função dos logs) e lista os registros do `activity_log` e dos `storage/logs/api/api-*`, um JSON por linha. Sem `LOG_SALT` o script se recusa a rodar (código 2). Para outra instalação, use `--root=/caminho`.

