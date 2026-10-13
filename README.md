# CasaLivre

Sistema de aluguel por temporada. O backend PHP não renderiza HTML: repositórios (`src/UserRepository.php`, `src/HouseRepository.php`, `src/PhotoRepository.php`) e a API JSON em `/api` concentram o acesso ao banco. O front em `views/` só monta páginas e chama esses serviços.

O banco padrão é SQLite. Para Postgres, crie o schema e aponte o DSN. O código da aplicação não usa SQL específico de um deles.

```bash
psql -d casalivre -f database/schema.pgsql.sql
```

No `.env` ficam o banco e as chaves de serviço. Nada disso é escrito em arquivo PHP.

```bash
DB_DRIVER=pgsql
DB_DSN=pgsql:host=127.0.0.1;dbname=casalivre
DB_USER=usuario
DB_PASSWORD=
LOG_DRIVER=sqlite
STRIPE_SECRET_KEY=
PAGARME_API_KEY=
AWS_ACCESS_KEY_ID=
AWS_SECRET_ACCESS_KEY=
```

O log de atividades fica fora do banco dos imóveis, em `storage/logs/activity.sqlite`. Cada ação tem um código, uma descrição e os parâmetros daquela ação (cidade e raio na busca, e-mail no login, imóvel nas fotos). A senha não é gravada. Para ler:

```bash
php scripts/activity-log.php
php scripts/activity-log.php --codigo=2 --limite=20
```

Para MongoDB depois, sem mudar o resto do sistema:

```bash
LOG_DRIVER=mongodb
LOG_MONGO_URI=mongodb://127.0.0.1:27017
LOG_MONGO_DB=casalivre
```

Isso usa a extensão `mongodb` do PHP (`MongoActivityStore`). O documento tem os mesmos campos da tabela SQLite.

## Subir na máquina local

Requisitos: PHP 8.2+ com `pdo_sqlite` e `mbstring`. No Void o pacote não liga o módulo sozinho:

```bash
ver=$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')
sudo xbps-install -S php php${ver}-sqlite
sudo mkdir -p /etc/php${ver}/conf.d
printf 'extension=pdo_sqlite\n' | sudo tee /etc/php${ver}/conf.d/20-pdo_sqlite.ini
printf 'extension=sqlite3\n' | sudo tee /etc/php${ver}/conf.d/20-sqlite3.ini
php -m | grep pdo_sqlite
```

```bash
bash scripts/setup.sh
bash scripts/start.sh
```

O `start.sh` sobe o limite do PHP para 16 MB por foto e 128 MB por envio. Sem isso, várias fotos juntas estouram o padrão de 8 MB e a sessão quebra. Se o servidor já estava no ar, pare e suba de novo.

Abra http://127.0.0.1:8080 neste computador. O `start.sh` escuta em todas as interfaces: no celular, na mesma rede, use `http://IP-DA-MAQUINA:8080` (o script mostra o endereço ao subir).

- Depois do login o proprietário cai em `/painel`, com a lista dos próprios imóveis.
- Dá para adicionar, editar e remover imóvel, adicionar fotos e apagar fotos.
- Fotos ficam em `public/uploads/users/{id}/imoveis/{id}/`.
- Datas na interface usam dia/mês/ano (`dd/mm/aaaa`).
- No celular o menu abre em "Menu" e os formulários, a busca e o painel continuam disponíveis.
- O idioma fica no canto superior (português, inglês, espanhol). Data, hora e moeda seguem o idioma. Os textos estão na tabela `translations`.
- A busca usa a lista de municípios brasileiros, ignora maiúsculas e acentos, e sugere cidades a partir de 3 letras.
- O cadastro pede a senha duas vezes, grava um link de verificação com prazo e não remove o registro: a conta fica com estado `apagado`, com data e IP.
- Parâmetros, traduções, cidades/estados e CEPs ficam no banco e em cache JSON (`storage/cache/settings.json`, `translations.json`, `cities.json`, `cep.json`). O máximo de fotos por imóvel é o parâmetro `max_photos_per_property`. Depois de alterar o banco: `php scripts/refresh-settings-cache.php`.
- Os CEPs vêm da base aberta OpenCEP 2.0.1 (julho de 2024, cerca de 1,2 milhão de códigos, licença MIT), no arquivo `database/cep.csv.bz2`. Na próxima subida, se a versão mudou, `scripts/start.sh` recarrega a tabela e regrava `storage/cache/cep.json`.
- Contas bloqueadas ou inativas: `php scripts/support-conta.php --email=... --ativar --desbloquear`.
- O catálogo das ações do log fica na tabela `activity_actions`, separado do arquivo `storage/logs/activity.sqlite`.
- A busca pública continua sem login.

Para parar: `bash scripts/stop.sh`

Dados aleatórios para navegar (5 proprietários, cada um com 2 ou 3 imóveis e uma foto, sem depender da extensão GD):

```bash
bash scripts/seed-users.sh
```

`php scripts/seed.php` faz o mesmo. E-mail e senha saem no terminal e em `storage/seed-users.txt`. O login é em `/entrar`.

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
