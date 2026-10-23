# ops/dev-env — ambiente dev em container Void Linux (bwrap, sem root)

Etapa P0-1 da spec `spec-p0-ambiente-bwrap.md`: base, provision, templates e
up/down. Serviços (initdb, schema, seed, `sv` de nginx/php-fpm/PG/Valkey) chegam
em P0-2; por enquanto o `runsvdir` sobe supervisionando `$CL_HOME/sv`, que
começa vazio.

## Uso

```sh
ops/dev-env/provision.sh      # baixa/verifica o rootfs, instala as versões travadas
ops/dev-env/up-bwrap.sh -d    # sobe em segundo plano (setsid + nohup); sem -d fica em primeiro plano
ops/dev-env/down.sh           # para os serviços e mata só o PID registrado
ops/dev-env/up.sh [--bwrap|--incus] [args]   # P0-5: one entry point; CL_RUNTIME=bwrap|incus, default bwrap
```

### Incus (P0-5)

`up-incus.sh` (or `up.sh --incus`) runs the same env in an unprivileged Incus container. Only the
entry layer changes; the rootfs, configs and `sv/` are the ones `up-bwrap.sh` uses:

- the rootfs is `$CL_ROOTFS` from `provision.sh`, packed as-is into a local image
  `cl-rootfs-<hash of versions.lock + .provisioned>` (no build, no installs; re-imported only when the
  rootfs changes);
- mounts: the repo and `$CL_HOME/{run,etc,log,sv,data}`, the same as bwrap. `$CL_HOME/admin` (cl_admin
  secret) is never mounted;
- `raw.idmap` maps your uid/gid 1:1; PID 1 is `incus-init.sh` (brings `lo` up, runs
  `runsvdir -P $CL_HOME/sv` as your uid, and on SIGPWR from `incus stop` stops PG first and then the
  rest); PG, Valkey
  and FPM stay on sockets in `$CL_RUN`; the only port is a proxy device on `127.0.0.1:$CL_PORT_HTTP`;
- `down.sh` stops PG cleanly and then the container (`$CL_RUN/incus.name`).

Host requirements: the `incus` client, `envsubst` (gettext), `tar`, and a daemon that allows
`raw.idmap` for your uid (`/etc/subuid` and `/etc/subgid` with `root:<uid>:1`). Known differences from
bwrap: the container root is the image copy, writable (not `--ro-bind`); a running container is left
alone and a stopped one is recreated (state lives in the mounted dirs).

Requisitos no host: Linux com user namespaces sem privilégio, `bwrap`
(bubblewrap), `nsenter`, `setsid`, `curl`, `tar` com xz. Nada de sudo: os três
scripts abortam se rodarem como root.

## Variáveis

`CL_HOME`, `CL_PORT_HTTP`, `CL_ROOTFS`, `CL_CACHE`
e `CL_ROOTFS_TARBALL` (tarball local em vez de baixar; o sha256 é conferido
igual). Defaults e a fórmula da porta HTTP (derivada do UID) ficam num lugar
só: `cl_defaults` em `lib.sh`. É a única porta TCP: PostgreSQL, Valkey e
PHP-FPM escutam só em socket Unix em `$CL_HOME/run`. Dois ambientes do mesmo
uid precisam de `CL_PORT_HTTP` diferente.

Cada usuário (bot) tem seu bloco de portas e seu `CL_HOME`, então dois
ambientes rodam juntos sem conflito e o `down.sh` de um não toca o outro.
Sockets Unix (FPM, PG, Valkey) ficam em `$CL_HOME/run`.

Layout de `$CL_HOME`: `rootfs/`, `cache/`, `run/` (sockets, `env.pid`), `etc/`
(templates renderizados), `log/`, `sv/` (serviços runit), `data/`. Dentro do
container `run etc log sv data` e o repo aparecem no mesmo caminho absoluto do
host.

## Versões

`versions.lock` trava o rootfs (nome + sha256) e o `pkgver` exato de php8.4,
php8.4-fpm, php8.4-pgsql, php8.4-redis, postgresql18(-client), valkey e nginx.
O provision instala exatamente esses e falha se o instalado divergir.
`ca-certificates` e `gettext` (envsubst) vêm do repo sem trava; o resto da
base é atualizada com `xbps-install -Su` só no primeiro provision (sem
`$CL_HOME/.provisioned`; re-provision não roda `-Su`): rolling de propósito, então o build
não é reproduzível bit a bit. O xbps confere a assinatura RSA dos repos com as
chaves que vêm no rootfs (verificado por sha256); se aparecer chave nova, o
provision falha em vez de aceitá-la.

Os pacotes travados também ficam em hold (`xbps-pkgdb -m hold`), então nem um
`-Su` os move; a reprodutibilidade é parcial (base,
ca-certificates e gettext rolling).

Atenção: o repo do Void não guarda versões antigas. Quando um pacote travado
avançar no repo, o provision falha com "not found in repository pool". Por
enquanto o pin é atualizado por PR no `versions.lock`. Trabalho futuro: um
espelho local de `.xbps` indexado com `xbps-rindex -a` (com sha256 de cada
pacote no lock), apontado em `repo=`.

## Como funciona

- `provision.sh`: confere o sha256 do tarball, extrai em `$CL_ROOTFS`, roda o
  xbps como root falso (`--unshare-user --uid 0`), com o cache em
  `$CL_CACHE/xbps`; acrescenta seu uid/gid ao `passwd`/`group` do rootfs;
  garante `/tmp` 1777; liga `openssl`, `pdo_pgsql`, `pgsql`, `redis` e OPcache
  no `php.ini` e confere com `php -m`; faz um GET https de dentro como usuário
  comum (CA).
- `up-bwrap.sh`: idempotente (PID vivo e do mesmo `CL_HOME` = não sobe outro);
  apaga `postmaster.pid` que sobrou só se nenhum postgres vivo usa aquele
  PGDATA (senão aborta), porque PIDs se repetem no namespace novo; renderiza
  `templates/*.in` em `$CL_HOME/etc` com envsubst e lista explícita de
  variáveis; sobe `bwrap --unshare-user --unshare-pid --unshare-ipc --share-net`
  **sem** `--die-with-parent`, com `/etc/resolv.conf` do host em bind somente
  leitura. Grava `<bwrap> <runsvdir> <init do namespace>` em
  `$CL_HOME/run/env.pid`.
- `down.sh`: confere que o PID registrado ainda é o bwrap deste `CL_HOME`;
  para o PostgreSQL primeiro (`sv stop`; o serviço do P0-2 deve parar em modo
  fast), depois os demais serviços (`nsenter` nos namespaces do ambiente); manda HUP ao
  runsvdir; se não sair em 5 s, TERM no runsvdir; mais 5 s e KILL no init do
  namespace e no bwrap (pior caso ~10 s). O ambiente conta como vivo se o
  bwrap externo **ou** o init do namespace estiver vivo: se o externo morrer,
  o `up` não sobe outro e o `down` mata o init. Nunca mata por nome.

## Isolamento (máquina compartilhada)

- `umask 077`; `$CL_HOME`, `run/`, `etc/`, `log/`, `sv/`, `data/` e `cache/`
  em 0700, conferidos com `stat` (o script falha se não estiverem).
- Todo bwrap usa `--unshare-all --share-net --new-session --clearenv`: só
  entram `HOME`, `PATH`, `LANG`, `TZ` e `CL_*`; nada de `SSH_AUTH_SOCK`,
  tokens etc. O php-fpm usa `clear_env = yes`.
- No runtime o rootfs é somente leitura e `/tmp` é tmpfs 1777; graváveis só
  `run etc log sv data` do `CL_HOME` e o repo.
- Risco residual: `--share-net` também compartilha os sockets unix abstratos
  do host (dbus, X11 etc.) e a porta HTTP em 127.0.0.1 é alcançável por
  outros uids locais (PG e Valkey ficam só em socket). Não há seccomp.
- O provision imprime `ERROR: failed to create system group ...` (_phpfpm,
  postgres, _valkey, nginx): é o root falso do xbps, inofensivo; os serviços
  rodam com o seu uid.

## Diferenças da produção

- Kernel do host (não há kernel próprio); limites e sysctl são os do host.
- Sem rede própria (`--share-net`): portas escutam em 127.0.0.1 do host.
- Sem PID 1 de verdade: runit roda só o `runsvdir`, não o `runit` init; não há
  estágios de boot.
- Usuário único: todos os serviços rodam com o seu uid (não há usuários
  `postgres`, `nginx` etc.).
- DNS e CA: `resolv.conf` vem do host; CAs vêm do pacote do Void.

## Serviços e banco (P0-2)

```sh
ops/dev-env/up-bwrap.sh -d     # instala $CL_HOME/sv (services.sh) e sobe
ops/dev-env/db-setup.sh        # createdb, schema.pgsql.sql, migrate, CEPs e seed
ops/dev-env/db-setup.sh --no-seed
```

- `sv/` (versionado): corpo dos `run`; o `services.sh` copia para
  `$CL_HOME/sv/` com um cabeçalho que carrega `$CL_HOME/etc/dev-env.env`
  (0600). Os serviços não herdam o env do host.
- **log-retention**: roda `scripts/log-retention.php` uma vez por dia como
  `cl_retention_job` (peer no socket, mapa `casalivre`, como `casalivre_dev`;
  o mesmo papel que a produção usa em `LOG_JOB_DB_USER`): cria as partições do
  log (uma por transação), move linhas achadas numa DEFAULT para a folha certa,
  roda o passo de retenção e o `audit.log_partitions_health()`. Dry-run (não
  remove partições) a menos que `CL_LOG_RETENTION_APPLY=1` em
  `$CL_HOME/etc/log-retention.conf`.
- **postgresql**: `initdb -U postgres` no primeiro start, só socket Unix
  (`listen_addresses=''`; `port=5432` só nomeia o socket `.s.PGSQL.5432`).
  Socket em `$CL_RUN` (0700), modo 0770 com o grupo do bot; `timezone=UTC`.
  `sv stop` usa `control/t` = `pg_ctl stop -m fast -w`; o `finish` repete se
  sobrar processo. Duas subidas seguidas sem "not properly shut down".
- Papéis (desenho do Bot BD, `pg-roles.sql`, idempotente, rodado como
  `postgres` pelo `db-setup.sh`): NOLOGIN `casalivre_app`,
  `casalivre_access_writer`, `casalivre_ops_judicial`, `casalivre_contract`,
  `casalivre_retention`, `casalivre_fiscal_owner`; `casalivre_dev` LOGIN
  CREATEDB NOSUPERUSER NOCREATEROLE, membro de todos `WITH INHERIT FALSE, SET
  TRUE`; timezone UTC. Extensões de superusuário vão no `template1`
  (`CL_PG_EXTENSIONS`, hoje vazia). `casalivre` é `OWNER casalivre_dev`. App,
  testes, schema, migrate e seed conectam como `casalivre_dev`
  (`PGUSER`, `DB_DSN ...;user=casalivre_dev`); o db-setup confere
  `rolsuper = false`. CREATEDB só no dev/teste, nunca na produção.
- `pg_hba`: `postgres` peer `map=admin`, `casalivre_dev` peer `map=casalivre`,
  o resto `reject`. Sem root não há usuário do SO `postgres`: o `pg_ident`
  liga o uid do bot a `postgres` (admin) e o do bot/FPM (mesmo uid aqui) a
  `casalivre_dev`.
- **php-fpm**: `clear_env = yes`; o app recebe só `APP_ENV=dev`,
  `DB_DSN` (socket), `VALKEY_SOCKET`, `VALKEY_USER`, `VALKEY_PASSWORD_FILE` e `QUEUE_DRIVER=valkey` via `etc/php-fpm-env.conf` (0600).
- **nginx**: espera o socket do FPM; só ele escuta em porta (`CL_PORT_HTTP`).
- **valkey**: `--port 0` (só socket, 0660), `--aclfile etc/valkey.acl` (0600,
  fora do `valkey.conf`), ACL de `programador-notas/p0-4-valkey-acl.md`:
  `default` off; `cl_app` só `~cl:*`/`&cl:*`, `-@all` + allowlist (strings,
  hash, listas, zset, set, multi, ping/hello/auth/select 0), sem streams e sem
  Lua; `cl_admin` separado. The allowlist includes RENAME, HLEN and HEXISTS
  (ADR 001 §11, demand-log flush), still limited to `cl:*` keys. Passwords are generated once, never in env:
  `etc/valkey-app.secret` (0440; production `root:casalivre`), read by the app
  (`VALKEY_USER=cl_app`) via `VALKEY_PASSWORD_FILE`; `admin/valkey-admin.secret`
  (0400, dir 0700). `admin/` is never bound into the runtime, so the FPM and the
  worker (same uid in dev) cannot read the cl_admin secret (P0-4). The socket is
  0660. `valkey-acl-check.sh` checks the socket mode, that the admin secret is
  not visible inside the env, that `cl_app` does LPUSH/RPOP on `cl:*`, and that
  `keys *`, `config get *`, `get x`, XADD and EVAL fail.
- **queue-worker** (P0-4): `scripts/queue-worker.php` in a loop, with
  `QUEUE_DRIVER=valkey` (lists under `cl:q:mail:*`/`cl:q:image:*`, phpredis,
  `cl_app` allowlist only); runit restarts it if it dies.
- Seed: credenciais em `storage/seed-users.txt` (0600, fora do `public/`),
  sem eco no terminal. CEPs: `bz2` só na chamada do `load-ceps.php`; o
  `php.ini` não muda.
- Uploads, iguais à produção (`prompts/deploy.md`): `php.ini` com
  `post_max_size = 20M`, `upload_max_filesize = 16M`, `max_file_uploads = 20`
  (provision); nginx `client_max_body_size 1m` no `server` e `20m` só em
  `^/(api/casas|imoveis)/<16 hex>/fotos$`. `exif` vem no pacote `php8.4` (não há
  `php8.4-exif` no repo musl); o provision liga e confere. `nodejs` travado no
  `versions.lock`. O provision roda o `check-test-deps.sh` (do #111) no rootfs.
