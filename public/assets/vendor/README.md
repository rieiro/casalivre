# Bibliotecas do mapa (vendorizadas)

Servidas pelo próprio site para a CSP continuar `'self'` (sem CDN, sem `blob:`/`data:`).

| Arquivo | Origem | Versão | Licença |
|---|---|---|---|
| `maplibre-gl/maplibre-gl-csp.js`, `maplibre-gl-csp-worker.js` | npm `maplibre-gl` (`dist/`, build CSP: worker em arquivo, sem `blob:`) | 5.24.0 | BSD-3-Clause (`maplibre-gl/LICENSE.txt`) |
| `maplibre-gl/maplibre-gl.css` + `maplibre-gl/icons/*.svg` | npm `maplibre-gl` `dist/maplibre-gl.css`; os 31 ícones `data:image/svg+xml` foram extraídos para `icons/<sha256-12>.svg` (img-src sem `data:`) | 5.24.0 | BSD-3-Clause |
| `pmtiles/pmtiles.js` | npm `pmtiles` (`dist/pmtiles.js`, IIFE global `pmtiles`) | 4.5.0 | BSD-3-Clause (`pmtiles/LICENSE`) |
| `../map/style-light.json` | gerado de npm `@protomaps/basemaps` 5.7.2: `layers("protomaps", namedFlavor("light"), {lang: "pt"})`; fontes renomeadas `Noto Sans X` -> `NotoSans-X` (URL sem espaço) | 5.7.2 | BSD-3-Clause |
| `../map/fonts/NotoSans-{Regular,Medium,Italic}/{0-255,256-511,8192-8447}.pbf` | github.com/protomaps/basemaps-assets `fonts/` (latim, latim estendido, pontuação) | main 028c18f | SIL OFL 1.1 (`../map/fonts/OFL.txt`) |
| `../map/sprites/light{,@2x}.{json,png}` | github.com/protomaps/basemaps-assets `sprites/v4/` | main 028c18f | MIT (tangrams/icons) |

Os `//# sourceMappingURL` foram removidos (sem .map no repositório).

Atualizar: `npm i maplibre-gl@5 pmtiles@4 @protomaps/basemaps@5` numa pasta temporária, copiar os mesmos
arquivos de `dist/`, refazer a extração dos ícones do CSS e regenerar o estilo (comandos no PR do mapa /
`prompts/mapa-pmtiles.md`), e subir o `?v=` em `views/map.php`.
