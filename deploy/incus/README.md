# CasaLivre 3-tier Incus infrastructure

Production-ready infrastructure-as-code for the persistence and application tiers of CasaLivre,
complementing `../infra-nginx-hardened` (the Alpine edge). Everything is idempotent and runs on a
single Incus host.

| Tier | Container | Address | Base |
|---|---|---|---|
| Edge (existing) | `nginx-prod-blue` / `nginx-prod-green` | `10.10.10.10` / `10.10.10.11` on `incusbr-dmz` | Alpine (musl) |
| App | `app-php` | `10.10.10.20` (DMZ) + `10.10.20.11` (internal) | Void Linux (glibc) + runit |
| DB | `db-pg` | `10.10.20.10` (internal only) | Void Linux (glibc) + runit |

```
                 internet
                    │  :80/:443
            ┌───────▼────────┐
            │ nginx edge     │  10.10.10.10/.11  (infra-nginx-hardened)
            └───────┬────────┘
      incusbr-dmz   │ FastCGI :9000
                    ▼
            ┌───────────────┐
            │ app-php       │  10.10.10.20 (dmz) + 10.10.20.11 (internal)
            └───────┬───────┘
 incusbr-internal   │ TCP :5432 (ACL: only 10.10.20.11 -> 10.10.20.10)
                    ▼
            ┌───────────────┐
            │ db-pg         │  10.10.20.10 (internal only, no gateway)
            └───────────────┘
```

## Networks and static IPs (must match `infra-nginx-hardened`)

| Network | Address | NAT | Notes |
|---|---|---|---|
| `incusbr-dmz` | `10.10.10.1/24` | yes | Edge and app tiers. |
| `incusbr-internal` | `10.10.20.1/24` | **no** | Air-gapped; `security.acls=acl-internal`, DNS off. |

`acl-internal` allows only TCP `5432` from `10.10.20.11/32` to `10.10.20.10/32` and drops everything
else. `db-pg` has no default gateway and never gets a DMZ NIC after provisioning.

## Layout

| Path | Purpose |
|---|---|
| `networks/` | Declarative Incus bridges. |
| `acls/acl-internal.yaml` | Strict app→db ACL for `incusbr-internal`. |
| `profiles/` | Hardened base + per-role profiles (limits, NIC filtering, volumes). |
| `containers/db-pg/` | PostgreSQL `provision.sh` and `postgresql.conf` / `pg_hba.conf`. |
| `containers/app-php/` | PHP-FPM `provision.sh` and `php.ini` / `www.conf`. |
| `scripts/00-init-networks.sh` | Creates ACL, networks, storage volumes and profiles. |
| `scripts/01-deploy-db.sh` | Deploys `db-pg`; installs packages via a temporary DMZ NIC, then air-gaps it. |
| `scripts/02-deploy-app.sh` | Deploys `app-php`, configs, users, permissions and PHP-FPM. |
| `scripts/03-upgrade-pg-major.sh` | Snapshot + clone-and-swap major PostgreSQL upgrade (gated). |
| `scripts/stack-up.sh` | `00` → `01` → `02` → edge `00-init-host.sh`. |
| `scripts/verify-stack.sh` | End-to-end isolation/IP/service/port checks (PASS/FAIL). |

## Prerequisites

- Incus (`incus`) with a storage pool named `default` (change `STORAGE_POOL` and the profile token if not).
- A host public NIC named `eth0` (override `PUBLIC_IFACE` for the edge module).
- `openssl` on the host (DB TLS certificate generation).
- The `images:voidlinux/current` image (glibc; never musl).
- Root (or equivalent Incus/nftables privileges) for `stack-up.sh` and the edge init.

## 1. Initialize networks, ACLs, volumes and profiles

```bash
cd deploy/incus
./scripts/00-init-networks.sh
```

This creates/updates `incusbr-dmz`, `incusbr-internal`, `acl-internal`, the `casalivre-db` and
`casalivre-media` volumes, and the `base-void-hardened`, `app-php`, `db-pg` profiles. Run it first on
a fresh host; the edge module reuses the DMZ bridge it creates.

## 2. Deploy PostgreSQL

```bash
export APP_DB_PASSWORD='<long random secret>'
./scripts/01-deploy-db.sh
```

`db-pg` is created with only the internal NIC. To install packages it is temporarily attached to
`incusbr-dmz`, then the NIC is **immediately removed** (a trap does this even on failure), leaving the
database 100% air-gapped. The script initializes the cluster, installs a self-signed TLS certificate
(`secrets/`, gitignored), applies `postgresql.conf`/`pg_hba.conf`, enables
`/var/service/postgresql`, and creates the `casalivre_app` role and `casalivre` database.

## 3. Deploy the application tier

```bash
./scripts/02-deploy-app.sh
```

Installs `php8.4-fpm` + `pgsql/pdo_pgsql/opcache/mbstring/intl/xml/curl`, creates the unprivileged
`appuser`, makes `/srv/app` read-only to PHP workers (`root:appuser`, `0750/0640`), keeps
`/var/lib/casalivre/media` writable, and enables `/var/service/php-fpm` listening on
`10.10.10.20:9000` for `10.10.10.10,10.10.10.11` only.

> The application code is **not** copied by this script. Deploy it (and its `.env`) into `/srv/app`
> through your normal release process; keep it read-only. Writable state belongs under
> `/var/lib/casalivre/media`.

## 4. Bring up the edge

```bash
sudo DMZ_NETWORK=incusbr-dmz ./../infra-nginx-hardened/scripts/00-init-host.sh
# build + stage + cut over with that module's 01/02/03 scripts
```

Or use the orchestrator, which runs steps 1–4 in order:

```bash
sudo APP_DB_PASSWORD='<secret>' PRIMARY_DOMAIN=example.com \
  TLS_CERT=/etc/ssl/example.com/fullchain.pem \
  TLS_KEY=/etc/ssl/example.com/privkey.pem \
  ./scripts/stack-up.sh
```

## 5. Verify

```bash
./scripts/verify-stack.sh
```

Checks (PASS/FAIL): exact static IPs and states, `sv status` in both containers, `db-pg` has no
internet, `app-php` can reach `10.10.20.10:5432`, the active edge can reach `10.10.10.20:9000` and
**cannot** reach `10.10.20.10:5432`, and no maintenance NIC remains on `db-pg`.

## 6. Major PostgreSQL upgrade

```bash
sudo NEW_PG_VERSION=18 OLD_PG_VERSION=17 ./scripts/03-upgrade-pg-major.sh
# review the clone + restore, then:
sudo CONFIRM=1 NEW_PG_VERSION=18 OLD_PG_VERSION=17 ./scripts/03-upgrade-pg-major.sh
```

The script snapshots `casalivre-db`, builds a new container on a new volume with the new major
version, restores a logical `pg_dumpall`, verifies it, and (only with `CONFIRM=1`) freezes the app,
swaps the containers/volumes and keeps the old ones for rollback.

## Secrets

- `APP_DB_PASSWORD` (env) or `secrets/app_db_password` (file, mode 0600).
- `secrets/db-pg.crt` / `secrets/db-pg.key` are generated on first deploy.
- `secrets/` and all `*.key`/`*.crt` are gitignored and never committed.

## Hardening notes

- Both containers are unprivileged, isolated idmaps, no nesting, NIC anti-spoofing.
- `db-pg` is protected against deletion and has no internet after provisioning.
- No SSH server, compiler, `curl` or `wget` is installed; the provision scripts remove them if the
  base image shipped them.
- PostgreSQL uses `scram-sha-256` + TLS only (`hostssl`); PHP-FPM accepts FastCGI only from the two
  edge IPs.
- `php.ini` disables dangerous functions, enables OPcache, and pins a narrow `open_basedir`.

## PostgreSQL version note

The scripts default to `PG_VERSION=17` as specified. The CasaLivre schema calls `uuidv7()`, which is
built into **PostgreSQL 18+**. To run the application schema, provision with `PG_VERSION=18` (or use
`03-upgrade-pg-major.sh` to move from 17 to 18). The infrastructure and `verify-stack.sh` work with
either major.

## Commit

```bash
git add deploy/incus
git commit -m "feat(deploy): add 3-tier Incus infrastructure (networks, profiles, db/app)"
```
