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
base é atualizada com `xbps-install -Su`: rolling de propósito, então o build
não é reproduzível bit a bit. O xbps confere a assinatura RSA dos repos com as
chaves que vêm no rootfs (verificado por sha256); se aparecer chave nova, o
provision falha em vez de aceitá-la.

Os pacotes travados ficam em hold (`xbps-pkgdb -m hold`), então o `-Su` de
um re-provision não os move; a reprodutibilidade é parcial (base,
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
