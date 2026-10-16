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
```

Requisitos no host: Linux com user namespaces sem privilégio, `bwrap`
(bubblewrap), `nsenter`, `setsid`, `curl`, `tar` com xz. Nada de sudo: os três
scripts abortam se rodarem como root.

## Variáveis

| Variável | Default |
|---|---|
| `CL_HOME` | `$HOME/.casalivre-dev` |
| `CL_PORT_HTTP` | `20000 + (UID % 4000) * 10` |
| `CL_PORT_PG` | HTTP + 1 |
| `CL_PORT_VALKEY` | HTTP + 2 |
| `CL_PORT_FPM` | HTTP + 3 |
| `CL_ROOTFS` | `$CL_HOME/rootfs` |
| `CL_CACHE` | `$CL_HOME/cache` (tarball e cache do xbps, fora do rootfs) |
| `CL_ROOTFS_TARBALL` | vazio; aponta para um tarball local em vez de baixar (o sha256 é conferido igual) |

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
base é atualizado com `xbps-install -Su` (rolling).

Atenção: o repo do Void não guarda versões antigas. Quando um pacote travado
avançar no repo, o provision falha com "not found in repository pool"; aí é
atualizar o `versions.lock` (PR) ou apontar `repo=` para um espelho/arquivo.

## Como funciona

- `provision.sh`: confere o sha256 do tarball, extrai em `$CL_ROOTFS`, roda o
  xbps como root falso (`--unshare-user --uid 0`), com o cache em
  `$CL_CACHE/xbps`; acrescenta seu uid/gid ao `passwd`/`group` do rootfs;
  garante `/tmp` 1777; liga `openssl`, `pdo_pgsql`, `pgsql`, `redis` e OPcache
  no `php.ini` e confere com `php -m`; faz um GET https de dentro como usuário
  comum (CA).
- `up-bwrap.sh`: idempotente (PID vivo e do mesmo `CL_HOME` = não sobe outro);
  apaga `postmaster.pid` que sobrou (o ambiente anterior morreu, então o PID
  dele está morto, e PIDs se repetem no namespace novo); renderiza
  `templates/*.in` em `$CL_HOME/etc` com envsubst e lista explícita de
  variáveis; sobe `bwrap --unshare-user --unshare-pid --unshare-ipc --share-net`
  **sem** `--die-with-parent`, com `/etc/resolv.conf` do host em bind somente
  leitura. Grava `<bwrap> <runsvdir> <init do namespace>` em
  `$CL_HOME/run/env.pid`.
- `down.sh`: confere que o PID registrado ainda é o bwrap deste `CL_HOME`;
  para o PostgreSQL primeiro (`sv stop` e `pg_ctl stop -m fast -w`), depois os
  demais serviços (`nsenter` nos namespaces do ambiente); manda HUP ao
  runsvdir; se não sair, TERM no runsvdir e por fim KILL no init do namespace
  e no bwrap. Nunca mata por nome.

## Diferenças da produção

- Kernel do host (não há kernel próprio); limites e sysctl são os do host.
- Sem rede própria (`--share-net`): portas escutam em 127.0.0.1 do host.
- Sem PID 1 de verdade: runit roda só o `runsvdir`, não o `runit` init; não há
  estágios de boot.
- Usuário único: todos os serviços rodam com o seu uid (não há usuários
  `postgres`, `nginx` etc.).
- DNS e CA: `resolv.conf` vem do host; CAs vêm do pacote do Void.
