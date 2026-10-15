# Mapa: MapLibre GL + PMTiles (basemap Protomaps, recorte do Brasil)

O mapa da busca (`/busca/mapa`) usa MapLibre GL com o protocolo `pmtiles` e o estilo Protomaps light.
Tudo é servido pelo próprio site: bibliotecas em `public/assets/vendor/`, estilo, fontes e sprites em
`public/assets/map/`, tiles em `/tiles/brasil.pmtiles`. A CSP continua `'self'`, sem Google e sem tiles OSM externos.
Os pins vêm de `/api/busca` (coordenada pública do #36) e viram GeoJSON com clusters no navegador.

O arquivo `brasil.pmtiles` **não vai para o git** (`*.pmtiles` está no `.gitignore`; `storage/` também).
Sem ele, o mapa abre só com os pins sobre fundo liso e mostra "Mapa de fundo indisponível…".

## Gerar `brasil.pmtiles`

Fonte: builds diários do Protomaps (`https://build.protomaps.com/AAAAMMDD.pmtiles`, lista em
`https://build-metadata.protomaps.dev/builds.json`). Verificado em 14/10/2026: `20261013.pmtiles`, versão de tiles 4.15.2, cerca de 139 GB.
A extração usa leituras HTTP Range, então **não** baixa o planeta inteiro.

```bash
# 1. CLI pmtiles (go-pmtiles), Linux x86_64
curl -fL -o /tmp/go-pmtiles.tar.gz \
  https://github.com/protomaps/go-pmtiles/releases/download/v1.31.2/go-pmtiles_1.31.2_Linux_x86_64.tar.gz
tar -xzf /tmp/go-pmtiles.tar.gz -C /tmp pmtiles && sudo install /tmp/pmtiles /usr/local/bin/pmtiles
pmtiles version   # pmtiles 1.31.2

# 2. Build mais recente (ou fixe uma data AAAAMMDD de builds.json)
BUILD=$(curl -fsS https://build-metadata.protomaps.dev/builds.json | python3 -c 'import json,sys; print(json.load(sys.stdin)[-1]["key"])')
echo "$BUILD"     # ex.: 20261014.pmtiles

# 3. Recorte do Brasil (bbox oeste,sul,leste,norte; inclui Noronha, Trindade e São Pedro e São Paulo)
mkdir -p storage/tiles
pmtiles extract "https://build.protomaps.com/$BUILD" storage/tiles/brasil.pmtiles \
  --bbox=-74.0,-33.8,-28.6,5.3
#   zoom completo (até 15): cerca de 6,1 GB, 9,2 milhões de tiles (medido com --dry-run no build 20261013).
#   Para um arquivo menor: acrescente --maxzoom=13 (sem detalhe de rua nos zooms máximos).
#   Prévia sem baixar: acrescente --dry-run.

pmtiles show storage/tiles/brasil.pmtiles   # confere bounds, zooms e tipo (mvt)
```

Para desenvolvimento, uma cidade basta (cerca de 2 MB, poucos segundos):

```bash
pmtiles extract "https://build.protomaps.com/$BUILD" storage/tiles/brasil.pmtiles \
  --bbox=-48.65,-27.75,-48.35,-27.45 --maxzoom=13      # Florianópolis
```

## Testar em dev (sem nginx)

Com `APP_ENV=dev` (ou `local`), o PHP atende `GET`/`HEAD /tiles/brasil.pmtiles` (`src/MapTiles.php`) com Range:
206 + `Content-Range`, 416 para faixa fora do arquivo, `Accept-Ranges: bytes`, `HEAD` sem corpo,
`Content-Type: application/vnd.pmtiles`. O caminho é fixo: `storage/tiles/brasil.pmtiles`. A requisição nunca
escolhe o arquivo; `MAP_TILES_FILE` (só ambiente) existe para os testes e só é aceito se o realpath ficar dentro de `storage/tiles/`; caso contrário, 404 e log `map_tiles_file_rejected`.
Fora de dev/local essa rota responde 404.

O ambiente vence o `.env`: `scripts/start.sh` lê o `.env` como `KEY=VALUE`, sem `source` e sem executar nada, e só preenche as chaves que o ambiente não definiu. `Db::loadEnv` faz o mesmo. Então `APP_ENV=dev scripts/start.sh` liga a rota de tiles mesmo com `APP_ENV=production` no `.env` (o padrão do `setup.sh`). Também vale para `PORT`, `BIND_ADDR` etc.

```bash
APP_ENV=dev scripts/start.sh            # ou PORT=8081 APP_ENV=dev scripts/start.sh
curl -sI -H 'Range: bytes=0-511' http://127.0.0.1:8080/tiles/brasil.pmtiles   # 206, Content-Range: bytes 0-511/<total>
# abrir http://127.0.0.1:8080/busca/mapa?localidade=Florianópolis, SC
php tests/standalone/map_tiles_test.php && php tests/standalone/map_front_test.php && php tests/standalone/env_precedence_test.php
```

## Deploy (nginx)

- **Arquivo:** `/srv/casalivre/storage/tiles/brasil.pmtiles`, com dono root, modo 0644 e leitura pelo usuário do nginx. Gere fora do servidor e copie com `rsync --partial`. Para trocar: grave `brasil.pmtiles.new` e faça `mv` atômico.
- **Bloco no `server` do site,** antes do `location /` que vai para o PHP:

```nginx
location = /tiles/brasil.pmtiles {
    alias /srv/casalivre/storage/tiles/brasil.pmtiles;
    default_type application/vnd.pmtiles;     # MIME recomendado pela spec PMTiles v3
    types { }                                 # não deixa mime.types trocar o tipo
    # Range: o módulo static do nginx já responde 206/416 e Accept-Ranges: bytes; nada a ligar.
    # Não comprimir (gzip/brotli quebram as faixas): não incluir este tipo em gzip_types/brotli_types.
    gzip off;
    etag on;
    add_header Cache-Control "public, max-age=86400" always;
    # add_header aqui anula TODOS os add_header herdados do server: repita os de segurança do nível server
    # (mesmos valores de lá; se o server tiver outros, copie-os também).
    add_header Strict-Transport-Security "max-age=31536000; includeSubDomains" always;
    add_header X-Content-Type-Options nosniff always;
    add_header X-Frame-Options DENY always;
    add_header Referrer-Policy same-origin always;
    limit_except GET HEAD { deny all; }
}
```

- **Cache:** 1 dia, porque o nome do arquivo é fixo; a troca do arquivo muda o ETag e o pmtiles.js revalida. Se houver CDN, cache por faixa (`Range` na chave) ou só na origem. Não servir via PHP em produção.
- **Assets:** `/assets/vendor/` e `/assets/map/` seguem a regra de `/assets/` (immutable + `?v=`). `.pbf` e `.json` do mapa podem ir com gzip; o `.pmtiles` não.
- **CSP:** sem mudança, continua `default-src 'self'` e `img-src 'self'`. O worker do MapLibre é arquivo local, então não precisa de `blob:` nem `worker-src`.
- **Atribuição:** "© OpenStreetMap contributors · Protomaps", com link para openstreetmap.org/copyright, sempre visível no canto do mapa.
