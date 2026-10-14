# Snapshots de caracterização (Sprint 3, PR 1)

Gerados por `tests/standalone/char_snapshot_test.php`. Registram o comportamento ATUAL antes do tema:
HTML (home, busca, mapa, imóvel, entrar, erros 404/419/413/429/500), headers de segurança/CSP,
URLs de assets (com `?v=`), texto dos e-mails (fila + mail-worker + mail-dev.log) e o `style-light.json`.

Partes voláteis viram marcadores: `<CSRF>`, `<NONCE>`, `<TOKEN>`, `<HASH16>`/`<HASH32>`, `<IMOVEL>`,
`<DATA>`, `CL-<REF>`, `http://APP` (porta) e headers Date/Set-Cookie/Retry-After etc.

Atualizar depois de uma mudança intencional (revise o diff dos .txt no commit):

    UPDATE_SNAPSHOTS=1 php tests/standalone/char_snapshot_test.php

Em caso de falha, o conteúdo atual fica em `/tmp/snap-<nome>.atual.txt` para comparar.
