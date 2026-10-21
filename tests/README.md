# Testes do CasaLivre

Ferramentas só de desenvolvimento/homologação (não vão para produção; ver `prompts/deploy.md`).

## Pré-requisitos
- PHP 8.2+ com pdo_pgsql, mbstring, curl; um PostgreSQL (o do ambiente dev, `ops/dev-env/`); `composer install` (dev) para o PHPUnit 11.
- Banco novo: `psql -f database/schema.pgsql.sql` → `php scripts/migrate.php` → seeds (`seed.php`, `seed_massive.php`, `seed-users.php`, `seed-owners.php`).
- `LOG_SALT` de teste no `.env` (ex.: `LOG_SALT=salt-de-teste-local-123`).

## APP_ENV=dev (obrigatório para os casos de teste)
`scripts/run-casos-de-teste.php` e `scripts/casos/api-ical-log.php` leem o link de verificação devolvido pela API,
que só é exposto com `APP_ENV=dev` (ou `local`). Os scripts recusam rodar sem isso (saída 2, mensagem clara).
O **servidor de teste** também precisa subir com `APP_ENV=dev`:

```bash
APP_ENV=dev PORT=8080 BIND_ADDR=127.0.0.1 scripts/start.sh &
APP_ENV=dev APP_URL=http://127.0.0.1:8080 CASOS=todos php scripts/run-casos-de-teste.php
```

Nunca use `APP_ENV=dev` em produção.

## Suítes
- Unitários: `vendor/bin/phpunit --testsuite Unit`
- Completo (com servidor no ar): `APP_URL=http://127.0.0.1:8080 vendor/bin/phpunit`
- Standalone: `php tests/standalone/<arquivo>_test.php`
- Standalone (P0-3): `TEST_PG_DSN='pgsql:host=<socket>;port=<porta>;dbname=postgres' php tests/standalone/run.php`.
  Cada teste cria um banco `casalivre_t_<pid>_<rand>`, carrega `database/schema.pgsql.sql`, migra e apaga só esse banco
  (o usuário precisa de `CREATEDB`). No ambiente dev: `ops/dev-env/test-pg.sh` (usa o PG do ambiente só por socket em `$CL_RUN`, como `casalivre_dev`, com o php8.4 do container por padrão (`CL_PHP=<php do host>` roda no host) e roda antes
  `ops/dev-env/check-test-deps.sh`: sem `GLOB_BRACE`, exif, node, `post_max_size`/`upload_max_filesize` >= 16M).
- Casos de ponta a ponta via HTTP: comando acima (`CASOS=novos|todos`).
