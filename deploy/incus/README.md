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
| `incusbr-int` | `10.10.20.1/24` | **no** | Air-gapped; `security.acls=acl-internal`, DNS off. |

`acl-internal` allows only TCP `5432` from `10.10.20.11/32` to `10.10.20.10/32` and drops everything
else. `db-pg` has no default gateway and never gets a DMZ NIC after provisioning.

## Layout

| Path | Purpose |
|---|---|
| `networks/` | Declarative Incus bridges. |
| `acls/acl-internal.yaml` | Strict app→db ACL for `incusbr-int`. |
| `profiles/` | Hardened base + per-role profiles (limits, NIC filtering, volumes). |
| `containers/db-pg/` | PostgreSQL `provision.sh` and `postgresql.conf` / `pg_hba.conf`. |
| `containers/app-php/` | PHP-FPM `provision.sh` and `php.ini` / `www.conf`. |
| `scripts/00-init-networks.sh` | Creates ACL, networks, storage volumes and profiles. |
| `scripts/01-deploy-db.sh` | Deploys `db-pg`; installs packages via a temporary DMZ NIC, then air-gaps it. |
| `scripts/02-deploy-app.sh` | Deploys `app-php`, configs, users, permissions and PHP-FPM. |
| `scripts/03-upgrade-pg-major.sh` | Snapshot + clone-and-swap major PostgreSQL upgrade (gated). |
| `scripts/04-jumpstart-app.sh` | Pushes app code + `.env`, seeds empty data, publishes static/tiles, installs workers. |
| `scripts/05-dev-live.sh` | Binds the host git tree into the app/edge for live development (`on`/`off`/`status`). |
| `scripts/valkey-setup.sh` | Installs/repairs `valkey` + `php8.4-redis`, renders the ACL/secret and starts the socket service. |
| `scripts/logs-setup.sh` | Installs logrotate + `casalivre-logrotate` for the file logs PHP-FPM/alerts write directly. |
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

This creates/updates `incusbr-dmz`, `incusbr-int`, `acl-internal`, the `casalivre-db` and
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

Installs `php8.4` + `php8.4-fpm` + `php8.4-pgsql` (pdo_pgsql) + `php8.4-gd` + `php8.4-intl`
(opcache/mbstring/xml/curl come from the base `php8.4` package), creates the unprivileged
`appuser`, makes `/srv/app` read-only to PHP workers (`root:appuser`, `0750/0640`), keeps
`/var/lib/casalivre/media` writable, and enables `/var/service/php-fpm` listening on
`10.10.10.20:9000` for `10.10.10.10,10.10.10.11` only.

> The application code is **not** copied by this script. Deploy it (and its `.env`) into `/srv/app`
> through your normal release process; keep it read-only. Writable state belongs under
> `/var/lib/casalivre/media`.

After the edge exists, `04-jumpstart-app.sh` does that release step and the data bootstrap in one
idempotent command (see section 6).

### Valkey (queues and rate limiter)

`02-deploy-app.sh` calls `scripts/valkey-setup.sh`, which is also re-run by `04-jumpstart-app.sh`.
It installs `valkey` + `php8.4-redis`, creates the `_valkey` service user, generates
`secrets/valkey-app.secret` (mode `0600`, never committed), renders the ACL (password hash only),
and starts the runit service `casalivre-valkey`:

- Unix socket only: `/run/casalivre-valkey/valkey.sock` (`port 0`, mode `0660`);
- data directory: `/var/lib/casalivre/media/valkey` (persisted on the media volume);
- ACL user `cl_app` with keys/channels restricted to `cl:*`, no Lua/streams, `default` off;
- the app reads `/etc/casalivre/valkey-app.secret` (`root:appuser 0640`); `php.ini` allows
  `/etc/casalivre` in `open_basedir` and loads `redis.so`.

The deployment `.env` must set:

```ini
QUEUE_DRIVER=valkey
VALKEY_SOCKET=/run/casalivre-valkey/valkey.sock
VALKEY_USER=cl_app
VALKEY_PASSWORD_FILE=/etc/casalivre/valkey-app.secret
```

The mail/image runit workers use the same `cl_app` credentials (configured by
`04-jumpstart-app.sh`). `QUEUE_DRIVER=db` remains supported as a fallback.

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
**cannot** reach `10.10.20.10:5432`, and no maintenance NIC remains on `db-pg`. It also checks the
`casalivre-valkey` service, the Unix socket, an authenticated `PING` as `cl_app`, that PHP loaded the
`redis` extension, and the log services (`casalivre-logrotate`, the Valkey log service and the edge
nginx logrotate policy).

## 6. Jumpstart the application (code, data, statics, workers)

```bash
cp .env.example deploy/incus/secrets/app.env   # fill DB_DSN/DB_USER/DB_PASSWORD/LOG_* ; chmod 0600
sudo ./scripts/04-jumpstart-app.sh
```

Run it after `stack-up.sh` (or after `00`+`01`+`02`+edge `00..03`). It is idempotent and:

1. applies `ops/dev-env/pg-roles.sql` and sets the app/log/retention passwords from `secrets/app.env`;
2. loads `database/schema.pgsql.sql` only when `public.users` is missing, then grants the runtime roles;
3. allows `cl_logw`/`cl_retention_job` over TLS from `10.10.20.11` and reloads PostgreSQL;
4. streams the repo into `/srv/app` (excluding `.git`, `.env*`, `deploy/`, `tests/`, `storage/`) as
   `root:appuser` read-only and pushes `secrets/app.env` as `0640`;
5. runs `migrate.php`; loads the 1.2M CEPs only if the table is empty; seeds demo rows only when
   `SEED_DEMO=1` **and** there are no properties; processes the image queue once;
6. ensures Valkey is installed/current (`valkey-setup.sh`) and restarts php-fpm so `redis.so` loads;
7. installs/refreshes the daily `logrotate` service (`logs-setup.sh`);
8. installs the runit workers/one-shots and enables them (mail/image carry the `cl_app` credentials);
9. publishes `public/assets`, `public/uploads`, the app media uploads and `storage/tiles/brasil.pmtiles`
   to the active edge's `/srv/static`, then reloads nginx.

Environment knobs: `REPO_DIR`, `APP_ENV_FILE`, `TILES_FILE`, `SEED_DEMO=0`, `FORCE_TILES=1`,
`DB`, `APP`, `EDGE`, `PHP_MEMORY`.

> The tiles archive is 1.5G; it is copied only when missing on the edge (or `FORCE_TILES=1`).
> Secrets live in `deploy/incus/secrets/` (gitignored) and are never printed by the script.

## 7. Live development overlay

For day-to-day edits, bind the host git tree into the running tiers instead of republishing it:

```bash
sudo ./scripts/05-dev-live.sh on
# edit the repo and refresh the browser
sudo ./scripts/05-dev-live.sh off    # restore the copies, php.ini and edge config
./scripts/05-dev-live.sh status
```

`on` is idempotent and:

- binds the repo at `/srv/app` in `app-php`, `public/` at `/srv/static` on the active edge
  (read-only for nginx), and `storage/tiles/` at `/srv/tiles` (referenced by an alias in the dev
  copy of the tiles location);
- grants the container's mapped `appuser` (host uid = `volatile.idmap.base` + `id -u appuser`) an
  ACL on the tree, keeping the host user as owner;
- sets `opcache.validate_timestamps=1` / `revalidate_freq=0` and restarts php-fpm once, so a saved
  PHP file is picked up on the next request;
- disables browser caching for `/assets/` and `/uploads/`;
- backs up `php.ini` and the edge `default.conf` under `artifacts/dev-backups/`;
- preflights the repo `.env`: refuses a `DB_DSN` pointing at localhost (the DB is `db-pg` at
  `10.10.20.10`) unless `ALLOW_LOCAL_DB=1`, and warns on a loopback `APP_URL`; when
  `QUEUE_DRIVER=valkey`, verifies `VALKEY_SOCKET`, `VALKEY_USER`, `VALKEY_PASSWORD_FILE` and that the
  socket exists in `app-php`;
- smoke-tests `/`, `/busca` (DB-backed) and `/assets/app.css`, plus the tiles range.

Caveats:

- Development only: the application user can write the working tree (uploads, cache, and code).
- `04-jumpstart-app.sh` refuses to run while the overlay is active; `ALLOW_LIVE_DEV=1` overrides it.
- Re-run `on` after an edge rebuild (devices live on the container), and `off` before a normal
  release. `off` restores `php.ini`/edge config; run `04` to republish the current tree.
- Migrations still go through the app container:
  `incus exec app-php -- su -s /bin/sh appuser -c 'cd /srv/app && php scripts/migrate.php'`.

> Full reference (devices, idmap/ACL math, state files, troubleshooting): [Appendix A](#appendix-a-05-dev-live-sh-reference).

## 8. Major PostgreSQL upgrade

```bash
sudo NEW_PG_VERSION=18 OLD_PG_VERSION=17 ./scripts/03-upgrade-pg-major.sh
# review the clone + restore, then:
sudo CONFIRM=1 NEW_PG_VERSION=18 OLD_PG_VERSION=17 ./scripts/03-upgrade-pg-major.sh
```

The script snapshots `casalivre-db`, builds a new container on a new volume with the new major
version, restores a logical `pg_dumpall`, verifies it, and (only with `CONFIRM=1`) freezes the app,
swaps the containers/volumes and keeps the old ones for rollback.

## Logs

Three layers, mirroring the reference container:

- **Worker logs:** each `casalivre-*` runit service has a `log/run` using `casalivre-svlogd`
  (`/var/log/casalivre/<service>/`, rotation `s100000000 n14 t86400` = 100MB/day, 14 files). ALERT
  lines are paged to `/var/log/casalivre/alerts.log` by `casalivre-alert`.
- **System logs:** PHP-FPM, cronie, PostgreSQL and the base services use `vlogger` →
  `socklog-unix` (`svlogd -ttt`); PostgreSQL runs with `log_destination=stderr` and
  `logging_collector=off`, so its logs are in `/var/log/socklog/daemon/current`.
- **Direct file logs:** `scripts/logs-setup.sh` installs `logrotate` and the
  `casalivre-logrotate` runit service (daily). It rotates `/var/log/php-fpm/{access,error,slow}.log`,
  `/var/log/php-fpm.log` and `/var/log/casalivre/alerts.log` with `copytruncate`.
- **Edge:** `system/logrotate-nginx` is installed by `02-deploy-green.sh` to
  `/etc/logrotate.d/nginx`; Alpine’s daily `logrotate` (crond) rotates `/var/log/nginx/*.log` and
  reopens them with `kill -USR1`.
- **Application data:** `casalivre-log-retention` (runit, daily) prunes `storage/error.log`,
  `storage/logs/api/*` and the DB audit partitions according to the retention policy.

`verify-stack.sh` checks that `casalivre-logrotate` runs, that Valkey has a log service, and that the
edge nginx logrotate policy is installed. Useful commands:

```bash
incus exec app-php -- sv status /var/service/casalivre-logrotate
incus exec app-php -- logrotate -d /etc/logrotate.d/casalivre
incus exec nginx-prod-green -- logrotate -d /etc/logrotate.d/nginx
incus exec db-pg -- tail -n 20 /var/log/socklog/daemon/current
```

## Secrets

- `APP_DB_PASSWORD` (env) or `secrets/app_db_password` (file, mode 0600).
- `secrets/db-pg.crt` / `secrets/db-pg.key` are generated on first deploy.
- `secrets/valkey-app.secret` is generated by `valkey-setup.sh` (mode 0600) and copied to the
  container as `/etc/casalivre/valkey-app.secret` (`root:appuser 0640`); the ACL stores only its
  sha256. Back it up with the other secrets.
- `secrets/` and all `*.key`/`*.crt` are gitignored and never committed.

## Hardening notes

- Both containers are unprivileged, isolated idmaps, no nesting, NIC anti-spoofing.
- `db-pg` is protected against deletion and has no internet after provisioning.
- No SSH server, compiler, `curl` or `wget` is installed; the provision scripts remove them if the
  base image shipped them.
- PostgreSQL uses `scram-sha-256` + TLS only (`hostssl`); PHP-FPM accepts FastCGI only from the two
  edge IPs.
- `php.ini` disables dangerous functions, enables OPcache, and pins a narrow `open_basedir`
  (including `/etc/casalivre` for the Valkey secret).
- Valkey listens on a Unix socket only (no TCP), runs as `_valkey:appuser`, and its ACL disables the
  default user, restricts `cl_app` to `cl:*`, and keeps Lua/streams/module admin off.

## PostgreSQL version note

The scripts default to `PG_VERSION=17` as specified, but the CasaLivre schema calls `uuidv7()`,
which is built into **PostgreSQL 18+**. Provision with `PG_VERSION=18` for any deployment that loads
the application schema (or use `03-upgrade-pg-major.sh` to move from 17 to 18). The infrastructure and
`verify-stack.sh` work with either major.

## Commit

```bash
git add deploy/incus
git commit -m "feat(deploy): add 3-tier Incus infrastructure (networks, profiles, db/app)"
```

---

## Appendix A: 05-dev-live.sh reference

Detailed reference for the live-development overlay. The short workflow lives in
[section 7](#7-live-development-overlay); this appendix documents the exact state it creates, the
ownership model, and how to diagnose it.

### A.1 Mental model

The overlay is a set of **Incus disk devices that bind a host directory into a running container**.
The host directory is the source of truth; containers see the same files at a different path. No
copying and no rebuild:

| Host source | Container | Target | Mode |
|---|---|---|---|
| `/srv/dev/casalivre` (default `REPO_DIR`) | `app-php` | `/srv/app` | read-write |
| `<repo>/public` | active `nginx-prod-*` | `/srv/static` | read-only |
| `<repo>/storage/tiles` | active `nginx-prod-*` | `/srv/tiles` | read-only |

```text
host git tree ──rw──► app-php /srv/app ──FastCGI──► edge /srv/static (public/)
     │                                                   └──/tiles alias──► /srv/tiles
     └── storage/tiles ───────────────────ro──────────────────────────────────┘
```

The database (`db-pg`) is not mounted and does not change; both modes use the same
`DB_DSN`/credentials from `/srv/app/.env`.

### A.2 Requirements

- `incus` and `setfacl` on the host; run `on`/`off` with `sudo` (device operations and the
  root-owned backups need it). `status` works as the normal user if it can talk to Incus.
- The source is a git working tree: `<repo>/.git`, `<repo>/public` and `<repo>/storage/tiles` must
  exist. The script refuses otherwise (`REPO_DIR=...` overrides the location).
- `app-php` and one `nginx-prod-*` container must be `RUNNING`.

### A.3 Commands

```bash
sudo ./scripts/05-dev-live.sh on       # apply (idempotent, safe to re-run)
sudo ./scripts/05-dev-live.sh off      # remove and restore php.ini/nginx.conf
./scripts/05-dev-live.sh status        # show mode, devices, opcache, cache headers
```

Overrides (environment): `REPO_DIR`, `APP`, `EDGE`, `STATE_DIR`, `BACKUP_DIR`, `ALLOW_LOCAL_DB`.
`EDGE` defaults to the active slot recorded by the edge module, then to the running
`nginx-prod-blue`/`nginx-prod-green`.

### A.4 What `on` does, step by step

1. **Resolve** `APP`, the active `EDGE`, `REPO_DIR` and `APPUID` (see A.6), then **preflight the
   repo `.env`**: it must exist, `DB_DSN` must not point at `127.0.0.1`/`localhost`/a local socket
   (the 3-tier stack uses `db-pg` at `10.10.20.10`; `ALLOW_LOCAL_DB=1` overrides), and a loopback
   `APP_URL` produces a warning. With `QUEUE_DRIVER=valkey`, the required `VALKEY_SOCKET`,
   `VALKEY_USER` and `VALKEY_PASSWORD_FILE` are checked, and the socket must exist in `app-php`
   (plus the secret must be readable by `appuser`).
2. **Back up once** (only when no marker exists):
   - `app-php:/etc/php8.4/php.ini` → `artifacts/dev-backups/php.ini.<UTC timestamp>`
   - `<edge>:/usr/local/nginx/conf/sites-enabled/default.conf` → `artifacts/dev-backups/default.conf.<UTC timestamp>`
   - writes the marker `artifacts/state/dev-live` (edge name, backup paths, creation time).
   - backups are root-owned (created under `sudo`) and the directory is gitignored.
3. **ACL** on the host tree:
   - `setfacl -R -m u:$APPUID:rwX <repo>`
   - `find <repo> -type d -exec setfacl -m d:u:$APPUID:rwX {} +`
   The host user stays the owner; the container’s mapped `appuser` gets read/write, and new files
   inherit the ACL.
4. **Devices** (added only when missing; an existing device with a different source aborts):

   ```text
   app-php        dev-live-src     /srv/dev/casalivre          -> /srv/app
   nginx-prod-*   dev-live-static  /srv/dev/casalivre/public   -> /srv/static   (readonly)
   nginx-prod-*   dev-live-tiles   /srv/dev/casalivre/storage/tiles -> /srv/tiles (readonly)
   ```

5. **PHP hot reload**: copies the *original* backup, then forces
   `opcache.validate_timestamps=1` and `opcache.revalidate_freq=0`, pushes it back as
   `root:root 0644`, and restarts `php-fpm` once.
6. **Edge cache**: copies the *original* edge config and rewrites:
   - `expires 30d;` → `expires off;` + `Cache-Control: no-store` for `/assets/` and `/uploads/`;
   - the tiles block to `alias /srv/tiles/brasil.pmtiles;` (removes the old `root`/`try_files`).
   Pushes it back as `root:nginx 0640`, runs `nginx -t`, reloads nginx.
7. **Smoke test**: `GET /` and `/assets/app.css` must be 200; if the tile file exists,
   `Range: bytes=0-1023` on `/tiles/brasil.pmtiles` must be 206.

`on` is idempotent: with the marker present it reuses the original backups and re-applies the same
patches. Consequence: manual edits to `php.ini` or the edge `default.conf` made **while ON** are
discarded by a later `on`.

### A.5 State files

| Path (relative to `deploy/infra-nginx-hardened/`) | Purpose | Git |
|---|---|---|
| `artifacts/state/dev-live` | marker: `MARKER_EDGE`, `BACKUP_PHP`, `BACKUP_NGINX`, `CREATED` | ignored |
| `artifacts/dev-backups/php.ini.<ts>` | original app `php.ini` | ignored |
| `artifacts/dev-backups/default.conf.<ts>` | original edge site config | ignored |

`off` removes the marker but **keeps the backups** (a later `on` creates a fresh pair). Do not
delete the backup referenced by an active marker.

### A.6 Ownership and idmap (why an ACL, not `shift=true`)

The containers are unprivileged; inside `app-php`, `appuser` is uid `996`, but on the host that uid
is offset by the container idmap base:

```bash
BASE=$(incus config get app-php volatile.idmap.base)   # e.g. 1131072
UID=$(incus exec app-php -- id -u appuser)             # e.g. 996
echo $((BASE + UID))                                   # host uid, e.g. 1132068
getfacl /srv/dev/casalivre | grep 1132068
```

The ACL entry is for that **host** uid, so DAC checks pass when the container writes. Files created
by the app on the host are owned by the mapped uid (e.g. `1132068:1132067`); the host user still owns
the directories and can delete/rename them, and `git` keeps working.

`shift=true` was not used because it makes the host see app-created files under the mapped uid,
forcing `sudo` for routine edits. Note the trade-off: the app user can write the whole tree, not
just uploads/cache (acceptable for development only).

### A.7 Why tiles are a second mount

`/srv/static/tiles` would be a mount **nested** under the `/srv/static` bind. Incus cannot hot-plug
that (“Failed to add mount for device inside container”), and a container restart would be needed.
Instead tiles are mounted at the top-level `/srv/tiles` and the dev edge config uses an `alias` for
the exact file. Keep this shape if you adapt the script.

### A.8 What is live, and what is not

| Change | Live immediately? | Notes |
|---|---|---|
| PHP under `src/`, `conta/`, `views/`, `scripts/` | yes | next request; OPcache revalidates every request |
| `public/assets/*.css,*.js` | yes | edge serves the bind; `no-store` avoids browser cache |
| `public/uploads/*` | yes | the app writes into the repo; the edge serves the same files |
| `storage/tiles/brasil.pmtiles` | yes | served via the `/srv/tiles` alias, Range supported |
| `php.ini` / `nginx.conf` | no | restart `php-fpm` / `nginx -t && reload` manually |
| DB schema/migrations | no | run `migrate.php` in the container |
| CEP/place data | no | `rm storage/cache/cep.json` then reload/seed |
| Composer dependencies | no | run `composer install` where PHP is available |

Migrations command (unchanged by the overlay):

```bash
incus exec app-php -- su -s /bin/sh appuser -c 'cd /srv/app && php scripts/migrate.php'
```

> **Valkey:** CasaLivre’s queue and rate limiter use a Unix-socket Valkey (`VALKEY_SOCKET`, ACL user,
> password file; see `ops/dev-env/` and the `casalivre-valkey` service). The 3-tier stack provisions
> it in `app-php` via `02`/`04` and runs `QUEUE_DRIVER=valkey`; `db` remains a supported fallback.
> The live-dev preflight validates the socket/secret when the driver selects Valkey.

`.env` is not modified by the script: `APP_ENV`, `APP_URL`, `DB_DSN` and secrets stay exactly as
committed in the host file. Set `APP_ENV=dev` yourself if you want dev-only links/behaviour, keeping
in mind that it exposes activation/reset links in responses.

### A.9 Interaction with the other scripts

- **`04-jumpstart-app.sh`** refuses to run while the marker exists or `dev-live-src` is present,
  because it would extract/chown/chmod the host tree through the bind. `ALLOW_LIVE_DEV=1` bypasses
  the guard only for a scratch tree.
- **Edge rebuild (`02-deploy-green.sh` / `03-cutover.sh`)** creates a new container without the dev
  devices and loses the dev config. Re-run `sudo ./scripts/05-dev-live.sh on` afterwards. If the
  active edge changed, `on` reuses the old marker/backups; for a clean switch run `off` before the
  rebuild and `on` after it.
- **`02-deploy-app.sh`** rewrites `php.ini`; if you re-provision the app tier, run `off` (if a marker
  exists) and `on` again to re-establish the dev settings.

### A.10 Troubleshooting

| Symptom | Check / fix |
|---|---|
| `05` says “device already exists with a different source” | an old bind remains. `incus config device remove <ct> <name>` (or fix `REPO_DIR`) then re-run `on`. |
| `05 on` refuses: “DB_DSN points to a local database” | the repo `.env` is a leftover single-container env. Copy `deploy/incus/secrets/app.env`, or set `ALLOW_LOCAL_DB=1` only if a local DB really exists. |
| PHP edits do not show | `incus exec app-php -- php -i \| grep opcache.validate_timestamps`; must be `On`. Re-run `on` (restarts FPM). |
| `403`/`404` on assets or uploads | `incus config device show <edge>`; `/srv/static` must point to `<repo>/public`. Re-run `on`. |
| Tiles 404 | the `/srv/tiles` device must exist and the config must contain `alias /srv/tiles/brasil.pmtiles;`. |
| Uploads fail with permission denied | ACL missing for the mapped uid: recompute A.6 and re-run `on`; check `getfacl`. |
| `04` exits: “live-dev overlay is active” | expected. `sudo ./scripts/05-dev-live.sh off` first. |
| After `off`, `/srv/app` looks old | expected: the pre-overlay deployed copy reappears. Run `04` to republish. |
| After `off`, users are logged out | the overlay used `<repo>/storage/sessions`; `off` returns to the media volume sessions. |
| Backups unreadable as your user | they are root-owned (`sudo`). Use `sudo`, or re-run `on` after `off`. |

### A.11 Security notes

- Development only. The app user has write access to the working tree; never enable this on a
  production deployment.
- The edge mounts are read-only; dotfiles (`.env`, `.git`) remain blocked by the edge’s
  `location ~ /\.(?!well-known)` rule.
- `.env` is host-owned `0600`; the ACL grants the mapped app user read (and write) access.
- Secrets are not copied, printed or committed by the script; backups contain only `php.ini` and the
  edge site config.

### A.12 Example session

```bash
cd /srv/dev/casalivre
sudo ./deploy/incus/scripts/05-dev-live.sh on
# edit src/... or public/assets/... and refresh https://10.10.10.11:8443
./deploy/incus/scripts/05-dev-live.sh status
sudo ./deploy/incus/scripts/05-dev-live.sh off
sudo env APP_ENV_FILE=/srv/dev/casalivre/.env ./deploy/incus/scripts/04-jumpstart-app.sh
```

### A.13 Full reset

If the script is interrupted and state looks inconsistent:

```bash
sudo ./deploy/incus/scripts/05-dev-live.sh off      # removes devices, restores backups
# if a device or marker is stuck:
incus config device remove app-php dev-live-src
incus config device remove nginx-prod-green dev-live-static
incus config device remove nginx-prod-green dev-live-tiles
rm -f deploy/infra-nginx-hardened/artifacts/state/dev-live
```

To recover a lost `php.ini`/edge config, re-run `02-deploy-app.sh` and the edge
`01-build-artifact.sh` → `02-deploy-green.sh` → `03-cutover.sh` sequence, then `04` to republish the
application tree.

