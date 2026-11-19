# Hardened nginx edge (CasaLivre)

Production-ready, internet-facing nginx reverse proxy built as infrastructure-as-code and run inside
an isolated Incus container on the VPS. The design separates build from runtime:

- a **disposable builder container** compiles a hardened nginx (and `headers-more`) and emits a
  versioned tarball plus a `.sha256`;
- a **production container** only receives the compiled artifact and read-only configuration — it
  never gets a compiler, `curl`, `wget`, an SSH server or build tooling;
- upgrades use a **Blue/Green swap**: a new "green" container is staged and validated, the host
  nftables DNAT is atomically repointed, and the old "blue" container is kept (stopped) for an
  instant rollback.

```
Internet :80/:443
        │
        ▼
┌──────────────────────────── VPS host ────────────────────────────┐
│ nftables (inet casalivre_edge): DNAT 80→:8080, 443→:8443          │
│                    │                                             │
│        incusbr-dmz │  ACL casalivre-nginx-dmz                     │
│                    ▼                                             │
│   ┌─────────── nginx-prod-blue/green (prod) ────────────┐        │
│   │ non-root nginx :8080/:8443 · read-only config        │        │
│   │ ACL: 80/443/8080/8443 in · backend + DNS/NTP out      │        │
│   └───────────────────────┬──────────────────────────────┘        │
└───────────────────────────┼───────────────────────────────────────┘
                            ▼
                 app-php 10.10.10.20:9000 (FastCGI)
```

## Layout

| Path | Purpose |
|---|---|
| `build/versions.env` | Pinned nginx version + SHA256, headers-more tag + commit. |
| `build/configure.flags` | Hardened CFLAGS/LDFLAGS and the minimal `--with/--without` set. |
| `build/build.sh` | Runs in the builder: download, verify, compile, package. |
| `incus/profile-prod.yaml` | Hardened production container profile (unprivileged, isolated idmap, NIC filtering, limits). |
| `incus/profile-builder.yaml` | Disposable builder profile (bigger limits, internet access). |
| `incus/acl-dmz.yaml` | DMZ network ACL: HTTP(S) in, backend + DNS/NTP out, everything else dropped. |
| `host/nftables-nginx.conf` | Host DNAT template (80→8080, 443→8443) rendered at cutover. |
| `config/nginx.conf` | Main hardened config (`server_tokens off`, limits, includes). |
| `config/snippets/*.conf` | TLS params, security headers, rate-limit zones. |
| `config/sites-enabled/default.conf` | FastCGI front-controller vhost (8080 redirect + 8443 TLS). |
| `scripts/00-init-host.sh` | Applies profiles, ACL, IP forwarding and nftables skeleton (idempotent). |
| `scripts/01-build-artifact.sh` | Launches the builder, compiles, pulls the artifact, destroys the builder. |
| `scripts/02-deploy-green.sh` | Launches/refreshes the green container, installs config, runs `nginx -t`, starts it. |
| `scripts/03-cutover.sh` | Atomically repoints host DNAT to green, stops blue (kept for rollback). |
| `scripts/04-rollback.sh` | Starts blue and repoints DNAT back. |

## Integration with `/deploy/incus`

This module is the **edge tier** of the 3-tier Incus architecture:

| Tier | Container | Address |
|---|---|---|
| Edge (this module) | `nginx-prod-blue` / `nginx-prod-green` | `10.10.10.10` / `10.10.10.11` on `incusbr-dmz` |
| App | `app-php` | `10.10.10.20:9000` (PHP-FPM) on `incusbr-dmz`; `10.10.20.11` on `incusbr-internal` |
| DB | `db-pg` | `10.10.20.10:5432` on air-gapped `incusbr-internal` |

The edge forwards every dynamic request to `app-php` over FastCGI (`10.10.10.20:9000`). The DMZ ACL
allows only that backend, DNS and NTP, and drops all other DMZ/internal traffic. `app-php` pins
`listen.allowed_clients = 10.10.10.10,10.10.10.11`, which is why Blue and Green use deterministic
static IPs and `02-deploy-green.sh` always stages the inactive slot.

**Order on a fresh host:**

1. `/deploy/incus/scripts/00-init-networks.sh` — creates `incusbr-dmz` and `incusbr-internal`.
2. `sudo ./scripts/00-init-host.sh` — reuses the DMZ bridge, applies the profiles and the strict ACL.
3. `./scripts/01-build-artifact.sh` — builds/verifies the hardened nginx tarball.
4. `sudo PRIMARY_DOMAIN=... TLS_CERT=... TLS_KEY=... ./scripts/02-deploy-green.sh` — stages the inactive slot.
5. `sudo ./scripts/03-cutover.sh` — atomically switches host DNAT to it.

## Prerequisites (host)

- Incus (`incus`) and nftables (`nft`), run as root (the scripts modify sysctl and nftables).
- `openssl` (to create/inspect certificates), `sha256sum`, `curl`.
- A managed bridge for the DMZ (default `incusbr0`) and a storage pool (default `default`).
- A TLS certificate/key for `PRIMARY_DOMAIN` (e.g. Let's Encrypt). Never commit them.

## Environment parameters

| Variable | Used by | Meaning |
|---|---|---|
| `PRIMARY_DOMAIN` | 02 | `server_name` / certificate host (e.g. `example.com`). |
| `TLS_CERT` / `TLS_KEY` | 02 | Paths to the certificate and private key on the host. |
| `DMZ_NETWORK` | 00 | DMZ bridge owned by `/deploy/incus` (default `incusbr-dmz`). |
| `DNS_RESOLVER` / `NTP_SERVER` | 00 | Egress allowed by the ACL (default: bridge gateway). |
| `PUBLIC_IFACE` | 00/03/04 | Host public NIC (default `eth0`). |
| `ALPINE_IMAGE` | 01/02 | Base image (default from `build/versions.env`). |

The nginx container listens on **8080/8443** (unprivileged); the host maps 80/443 to them with nftables.
That keeps the master process running as the dedicated `nginx` user, with no `CAP_NET_BIND_SERVICE`.
Dynamic requests are forwarded to `app-php` over FastCGI (`10.10.10.20:9000`).

## 1. First-time host initialisation

```bash
cd deploy/infra-nginx-hardened
# The DMZ bridge must already exist (created by /deploy/incus/scripts/00-init-networks.sh).
sudo ./scripts/00-init-host.sh
```

This creates/updates the Incus profiles `casalivre-nginx-prod` and `casalivre-nginx-builder`, the ACL
`casalivre-nginx-dmz`, enables persistent IPv4 forwarding, and creates the `inet casalivre_edge`
nftables table. It is idempotent: re-run it after editing a profile or the ACL.

## 2. Build a new nginx version

```bash
./scripts/01-build-artifact.sh
```

The script launches a disposable `casalivre-nginx-builder` container, installs build dependencies,
downloads nginx, **verifies its SHA256**, clones headers-more and **verifies the pinned commit**,
compiles with the hardened flags, packages `artifacts/nginx-<version>-hardened.tar.gz` (+ `.sha256`),
and destroys the builder (even on failure). To bump nginx, edit `build/versions.env` with the new
version and official SHA256 (from <https://nginx.org/download/>), then re-run.

## 3. Stage and verify a Green container

```bash
sudo PRIMARY_DOMAIN=example.com \
     TLS_CERT=/etc/ssl/example.com/fullchain.pem \
     TLS_KEY=/etc/ssl/example.com/privkey.pem \
     ./scripts/02-deploy-green.sh
```

The script verifies the artifact hash, creates the container from the hardened profile, creates the
non-root `nginx` account and runtime directories, installs the binary and configuration, renders the
domain/upstream/TLS tokens, locks permissions (config `0640 root:nginx`), runs **`nginx -t` inside the
container** (aborting on failure), and starts nginx. It prints the green IP; health is
`http://<green-ip>:8080/healthz`. If the container already exists, pass `REPLACE=1` to rebuild it.

## 4. Cut over production traffic

```bash
sudo ./scripts/03-cutover.sh
```

The script health-checks green, remembers the current active container/IP (state in
`artifacts/state/`), then rewrites the DNAT and forward rules in a single atomic `nft -f -`
transaction (80→8080, 443→8443), and stops the previous blue container (kept for rollback).

## 5. Roll back to Blue

```bash
sudo ./scripts/04-rollback.sh
```

Starts the previous container, waits for `healthz`, atomically points the DNAT back, and stops the
green container (kept for inspection). You may also pass `TARGET=<container>` explicitly.

## Retiring a container

Containers carry `security.protection.delete: "true"`. To delete one (after it is no longer needed
for rollback):

```bash
incus config set <container> security.protection.delete=false
incus delete <container> --force
```

## Security notes

- The production container has no compiler, `curl`, `wget` or SSH server; the artifact is built in a
  throwaway container and transferred by Incus.
- nginx runs entirely as the unprivileged `nginx` user on 8080/8443.
- `server_tokens off` plus `more_clear_headers Server` hide the version/fingerprint.
- TLS 1.2/1.3 only, AEAD ciphers, no session tickets, HSTS enabled.
- The DMZ ACL allows only 8080/8443 ingress and FastCGI (`10.10.10.20:9000`) + DNS/NTP egress;
  all other DMZ traffic and the internal/DB tier (`10.10.20.0/24`) are dropped.
- Certificates and build artifacts are gitignored and never committed.

## Commit this structure

After reviewing the files:

```bash
git add deploy/infra-nginx-hardened
git commit -m "feat(deploy): add hardened nginx edge infrastructure as code"
```
