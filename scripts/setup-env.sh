#!/usr/bin/env bash
# Cria/atualiza o .env da raiz do projeto (chamado por scripts/setup.sh; pode rodar sozinho).
#   uso: scripts/setup-env.sh [PHP_BIN]
# - Sem .env: copia .env.example (se existir) ou gera um mínimo do zero.
# - Com .env: preserva tudo; só mexe no GEO_SALT se faltar ou tiver menos de 32 caracteres.
# - GEO_SALT é obrigatório fora de dev/local (o app não sobe sem ele); trocar o valor move
#   todos os pinos públicos, por isso um sal válido existente nunca é substituído.
# - O .env sempre termina com permissão 600 (contém segredos).
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"
PHP_BIN="${1:-$(command -v php || echo /usr/bin/php)}"
ENV_FILE="$ROOT/.env"
umask 077

if [[ -f "$ENV_FILE" ]]; then
  echo ".env existente mantido: $ENV_FILE"
elif [[ -f "$ROOT/.env.example" ]]; then
  cp "$ROOT/.env.example" "$ENV_FILE"
  # PHP_BIN do exemplo é genérico; usa o detectado nesta máquina.
  tmp="$(mktemp "$ROOT/.env.XXXXXX")"
  awk -v v="$PHP_BIN" 'BEGIN{d=0} /^PHP_BIN=/{print "PHP_BIN=" v; d=1; next} {print} END{if(!d) print "PHP_BIN=" v}' "$ENV_FILE" > "$tmp"
  mv "$tmp" "$ENV_FILE"
  echo ".env criado a partir de .env.example (APP_ENV=production; use dev só em máquina local)"
else
  printf 'APP_ENV=production\nAPP_URL=http://127.0.0.1:8080\nPHP_BIN=%s\nGEO_SALT=\nDB_DRIVER=sqlite\nDB_DSN=\nDB_USER=\nDB_PASSWORD=\nLOG_DRIVER=sqlite\nLOG_MONGO_URI=\nLOG_MONGO_DB=\nLOG_SALT=\nSTRIPE_SECRET_KEY=\nPAGARME_API_KEY=\nAWS_ACCESS_KEY_ID=\nAWS_SECRET_ACCESS_KEY=\n' "$PHP_BIN" > "$ENV_FILE"
  echo ".env criado do zero (sem .env.example): $ENV_FILE"
fi

GEO_SALT_VALUE="$(sed -n 's/^GEO_SALT=//p' "$ENV_FILE" | tail -n 1 || true)"
if [[ ${#GEO_SALT_VALUE} -ge 32 ]]; then
  echo "GEO_SALT mantido (${#GEO_SALT_VALUE} caracteres)"
else
  NEW_SALT="$("$PHP_BIN" -r 'echo bin2hex(random_bytes(32));')"
  tmp="$(mktemp "$ROOT/.env.XXXXXX")"
  awk -v v="$NEW_SALT" 'BEGIN{d=0} /^GEO_SALT=/{if(!d) print "GEO_SALT=" v; d=1; next} {print} END{if(!d) print "GEO_SALT=" v}' "$ENV_FILE" > "$tmp"
  mv "$tmp" "$ENV_FILE"
  if [[ -z "$GEO_SALT_VALUE" ]]; then echo "GEO_SALT gerado (64 caracteres)"; else echo "GEO_SALT com menos de 32 caracteres substituído por um novo (64 caracteres)"; fi
fi
chmod 600 "$ENV_FILE"
echo ".env com permissão 600"
