#!/usr/bin/env bash
# End-to-end verification of the 3-tier stack. Prints PASS/FAIL per check and exits non-zero on failure.
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
EDGE_DIR="$(cd "$ROOT_DIR/../infra-nginx-hardened" && pwd)"

DB=db-pg
APP=app-php
PASS=0
FAIL=0

ok() { PASS=$((PASS + 1)); printf 'PASS  %s\n' "$*"; }
bad() { FAIL=$((FAIL + 1)); printf 'FAIL  %s\n' "$*"; }
require() { command -v "$1" >/dev/null 2>&1 || { printf 'FAIL  required tool not found: %s\n' "$1"; exit 2; }; }

require incus

state_of() { incus list "$1" -cs --format csv 2>/dev/null | head -n1; }
ips_of() { incus list "$1" -c4 --format csv 2>/dev/null | tr -d '"' | tr ',' '\n' | grep -Eo '^[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+' | sort -u; }
has_ip() { ips_of "$1" | grep -qx "$2"; }

echo "== containers and static IPs =="
for c in "$DB" "$APP"; do
  if [ "$(state_of "$c")" = "RUNNING" ]; then ok "$c is RUNNING"; else bad "$c is not RUNNING"; fi
done
if has_ip "$DB" 10.10.20.10; then ok "db-pg has 10.10.20.10"; else bad "db-pg is missing 10.10.20.10"; fi
if ips_of "$DB" | grep -q '^10\.10\.10\.'; then bad "db-pg still has a DMZ address"; else ok "db-pg has no DMZ address"; fi
for ip in 10.10.10.20 10.10.20.11; do
  if has_ip "$APP" "$ip"; then ok "app-php has $ip"; else bad "app-php is missing $ip"; fi
done

edge="$(cat "$EDGE_DIR/artifacts/state/active-container" 2>/dev/null || true)"
if [ -z "$edge" ]; then
  for c in nginx-prod-blue nginx-prod-green; do
    if [ "$(state_of "$c")" = "RUNNING" ]; then edge="$c"; break; fi
  done
fi
if [ -n "$edge" ] && [ "$(state_of "$edge")" = "RUNNING" ]; then
  ok "active edge is $edge"
  if has_ip "$edge" 10.10.10.10 || has_ip "$edge" 10.10.10.11; then
    ok "edge has its DMZ static IP"
  else
    bad "edge is missing its DMZ static IP"
  fi
else
  bad "no active nginx-prod-* container is running"
fi

echo "== runit services =="
for c in "$DB" "$APP"; do
  case "$c" in
    "$DB") svc=postgresql ;;
    *) svc=php-fpm ;;
  esac
  out="$(incus exec "$c" -- sv status "/var/service/$svc" 2>&1 || true)"
  if printf '%s\n' "$out" | grep -q '^run:'; then
    ok "$c $svc is running"
  else
    bad "$c $svc is not running: $out"
  fi
done

echo "== ota verification =="
if incus exec "$APP" -- test -e /var/service/casalivre-ota-verify >/dev/null 2>&1; then
  if incus exec "$APP" -- sv status /var/service/casalivre-ota-verify 2>&1 | grep -q '^run:'; then
    ok "app-php casalivre-ota-verify is running"
  else
    bad "app-php casalivre-ota-verify is not running"
  fi
  if incus exec "$APP" -- sv status /var/service/casalivre-ota-verify 2>&1 | grep -q 'log:'; then
    ok "casalivre-ota-verify has a log service"
  else
    bad "casalivre-ota-verify has no log service"
  fi
fi
if incus exec "$DB" -- su - postgres -c \
  "PGHOST=/run/postgresql /usr/bin/psql -d casalivre -tAc \"SELECT to_regclass('public.users') IS NOT NULL\"" 2>/dev/null \
  | grep -qx t; then
  if incus exec "$DB" -- su - postgres -c \
    "PGHOST=/run/postgresql /usr/bin/psql -d casalivre -tAc \"SELECT to_regclass('public.property_external_verifications') IS NOT NULL\"" 2>/dev/null \
    | grep -qx t; then
    ok "property_external_verifications table is present (schema 35+)"
  else
    bad "property_external_verifications table is missing"
  fi
  if incus exec "$DB" -- su - postgres -c \
    "PGHOST=/run/postgresql /usr/bin/psql -d casalivre -tAc \"SELECT to_regclass('public.ota_credit_ledger') IS NOT NULL\"" 2>/dev/null \
    | grep -qx t; then
    ok "ota_credit_ledger table is present (schema 36)"
  else
    bad "ota_credit_ledger table is missing"
  fi
  if incus exec "$DB" -- su - postgres -c \
    "PGHOST=/run/postgresql /usr/bin/psql -d casalivre -tAc \"SELECT to_regclass('public.property_ical_exports') IS NOT NULL\"" 2>/dev/null \
    | grep -qx t; then
    ok "property_ical_exports table is present (schema 38)"
  else
    bad "property_ical_exports table is missing"
  fi
  if incus exec "$DB" -- su - postgres -c \
    "PGHOST=/run/postgresql /usr/bin/psql -d casalivre -tAc \"SELECT count(*) FROM information_schema.columns WHERE table_name='calendar_feeds' AND column_name IN ('status','verified_at')\"" 2>/dev/null \
    | grep -qx 2; then
    ok "calendar_feeds has the unified status/verified_at columns (schema 39)"
  else
    bad "calendar_feeds is missing the unified status/verified_at columns"
  fi
  if incus exec "$DB" -- su - postgres -c \
    "PGHOST=/run/postgresql /usr/bin/psql -d casalivre -tAc \"SELECT count(*) FROM information_schema.columns WHERE table_name='calendar_feeds' AND column_name IN ('verification_mode','canonical_listing_id','review_status')\"" 2>/dev/null \
    | grep -qx 3; then
    ok "calendar_feeds has the platform-bound OTA columns (schema 40)"
  else
    bad "calendar_feeds is missing the platform-bound OTA columns"
  fi
  if incus exec "$DB" -- su - postgres -c \
    "PGHOST=/run/postgresql /usr/bin/psql -d casalivre -tAc \"SELECT count(*) FROM information_schema.columns WHERE table_name='ota_credit_ledger' AND column_name='canonical_listing_id'\"" 2>/dev/null \
    | grep -qx 1; then
    ok "ota_credit_ledger has canonical_listing_id (schema 40)"
  else
    bad "ota_credit_ledger is missing canonical_listing_id"
  fi
fi

echo "== valkey =="
if incus exec "$APP" -- sv status /var/service/casalivre-valkey 2>&1 | grep -q '^run:'; then
  ok "app-php casalivre-valkey is running"
else
  bad "app-php casalivre-valkey is not running"
fi
if incus exec "$APP" -- test -S /run/casalivre-valkey/valkey.sock >/dev/null 2>&1; then
  ok "valkey socket exists"
else
  bad "valkey socket is missing"
fi
if incus exec "$APP" -- su -s /bin/sh appuser -c \
  'valkey-cli -s /run/casalivre-valkey/valkey.sock --user cl_app --pass "$(cat /etc/casalivre/valkey-app.secret)" --no-auth-warning PING' \
  2>/dev/null | grep -q PONG; then
  ok "valkey authenticated PING"
else
  bad "valkey authenticated PING failed"
fi
if incus exec "$APP" -- /usr/sbin/php -r 'exit(extension_loaded("redis") ? 0 : 1);' >/dev/null 2>&1; then
  ok "php redis extension is loaded"
else
  bad "php redis extension is not loaded"
fi

echo "== pgbouncer =="
if incus exec "$APP" -- sv status /var/service/casalivre-pgbouncer 2>&1 | grep -q '^run:'; then
  ok "casalivre-pgbouncer is running"
else
  bad "casalivre-pgbouncer is not running"
fi
if incus exec "$APP" -- /bin/sh -c 'ss -ltn 2>/dev/null | grep -q "127.0.0.1:6432"'; then
  ok "PgBouncer listens on 127.0.0.1:6432"
else
  bad "PgBouncer is not listening on 127.0.0.1:6432"
fi
if incus exec "$APP" -- su -s /bin/sh appuser -c \
  'cd /srv/app && /usr/sbin/php -r "require \"src/Db.php\"; Db::loadEnv(); Db::pdo(); echo \"ok\";"' \
  2>/dev/null | grep -q ok; then
  ok "app PDO connects through PgBouncer"
else
  bad "app PDO cannot connect through PgBouncer"
fi

echo "== logs =="
if incus exec "$APP" -- sv status /var/service/casalivre-logrotate 2>&1 | grep -q '^run:'; then
  ok "casalivre-logrotate is running"
else
  bad "casalivre-logrotate is not running"
fi
if incus exec "$APP" -- sv status /var/service/casalivre-valkey 2>&1 | grep -q 'log:'; then
  ok "casalivre-valkey has a log service"
else
  bad "casalivre-valkey has no log service"
fi
if incus exec "$APP" -- sv status /var/service/casalivre-pgbouncer 2>&1 | grep -q 'log:'; then
  ok "casalivre-pgbouncer has a log service"
else
  bad "casalivre-pgbouncer has no log service"
fi
if [ -n "${edge:-}" ] && incus exec "$edge" -- test -f /etc/logrotate.d/nginx >/dev/null 2>&1; then
  ok "edge nginx logrotate policy is installed"
else
  bad "edge nginx logrotate policy is missing"
fi

echo "== postgres stats =="
if incus exec "$DB" -- su - postgres -c \
  "PGHOST=/run/postgresql /usr/bin/psql -tAc \"SHOW shared_preload_libraries\"" 2>/dev/null \
  | grep -q pg_stat_statements; then
  ok "PostgreSQL preloads pg_stat_statements"
else
  bad "PostgreSQL does not preload pg_stat_statements"
fi
if incus exec "$DB" -- su - postgres -c \
  "PGHOST=/run/postgresql /usr/bin/psql -d casalivre -tAc \"SELECT 1 FROM pg_extension WHERE extname = 'pg_stat_statements'\"" 2>/dev/null \
  | grep -qx 1; then
  ok "pg_stat_statements extension is installed"
else
  bad "pg_stat_statements extension is missing"
fi

echo "== isolation and reachability =="
if incus exec "$DB" -- sh -c 'ping -c 1 -W 2 1.1.1.1 >/dev/null 2>&1'; then
  bad "db-pg reached the internet"
else
  ok "db-pg has zero internet access"
fi

if incus exec "$APP" -- bash -c 'exec 3<>/dev/tcp/10.10.20.10/5432' >/dev/null 2>&1; then
  ok "app-php reaches db-pg 10.10.20.10:5432"
else
  bad "app-php cannot reach db-pg 10.10.20.10:5432"
fi

edge_connect() {
  local host="$1" port="$2"
  incus exec "$edge" -- sh -c \
    "if command -v nc >/dev/null 2>&1; then printf '' | nc -w 3 $host $port; elif command -v busybox >/dev/null 2>&1; then printf '' | busybox nc -w 3 $host $port; else exit 127; fi" \
    >/dev/null 2>&1
}
if [ -n "$edge" ]; then
  if edge_connect 10.10.10.20 9000; then
    ok "edge reaches app-php 10.10.10.20:9000"
  else
    bad "edge cannot reach app-php 10.10.10.20:9000"
  fi
  if edge_connect 10.10.20.10 5432; then
    bad "edge reached db-pg 10.10.20.10:5432"
  else
    ok "edge cannot reach db-pg 10.10.20.10:5432"
  fi
fi

echo "== hardening =="
if incus config device list "$DB" 2>/dev/null | grep -q '^maint-nic'; then
  bad "db-pg still has the maintenance NIC"
else
  ok "db-pg has no maintenance NIC"
fi
for c in "$DB" "$APP"; do
  for tool in curl wget gcc make sshd; do
    if incus exec "$c" -- /bin/sh -c "command -v $tool >/dev/null 2>&1"; then
      bad "$c has a forbidden tool: $tool"
    fi
  done
done

echo
echo "== incus list -c ns4p =="
incus list -c ns4p
echo
printf 'RESULT: %d passed, %d failed\n' "$PASS" "$FAIL"
[ "$FAIL" -eq 0 ]
