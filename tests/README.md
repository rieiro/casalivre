# Testes do CasaLivre

Ferramentas só de desenvolvimento/homologação (não vão para produção; ver `prompts/deploy.md`).

## Pré-requisitos
- PHP 8.2+ com sqlite3, mbstring, curl; `composer install` (dev) para o PHPUnit 11.
- Banco novo: `scripts/setup.sh` → `php scripts/migrate.php` → seeds (`seed.php`, `seed_massive.php`, `seed-users.php`, `seed-owners.php`).
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
- Casos de ponta a ponta via HTTP: comando acima (`CASOS=novos|todos`).
