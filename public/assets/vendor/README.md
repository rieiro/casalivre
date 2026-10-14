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

## Integridade

### Pacotes npm (registry.npmjs.org, `dist.integrity`)

Conferido com `npm view <pacote>@<versão> dist.integrity` em 14/10/2026; igual ao `package-lock.json` da instalação usada para copiar os arquivos.

| Pacote | Tarball | `dist.integrity` |
|---|---|---|
| `maplibre-gl@5.24.0` | https://registry.npmjs.org/maplibre-gl/-/maplibre-gl-5.24.0.tgz | `sha512-ALyFxgtd5R+65UqZ/++lOqwWcC0SNho9c27fYSyLmG7AfnAul2o46F05aDJGPbFU57wos9dgcIySHs0Xe6ia3A==` |
| `pmtiles@4.5.0` | https://registry.npmjs.org/pmtiles/-/pmtiles-4.5.0.tgz | `sha512-CBeD4SoUluFziGdy/8k7FOjQxQy486n+929W/tWophauvMICpkZ26vGBWhDt/6b1FZQWNaf4jFdT/5+q0Wii5w==` |
| `@protomaps/basemaps@5.7.2` (gera o estilo) | https://registry.npmjs.org/@protomaps/basemaps/-/basemaps-5.7.2.tgz | `sha512-K1Yk6bWdULulYg+R2QRVXx4NzJZan5YQhpejEG0c1/sXruJrfPIPZuakpf3jwAgVmjIRVQwAv+yRafDeN0aaUQ==` |

sha256 dos arquivos **como saem do pacote**, antes das mudanças descritas acima:

| Arquivo do pacote | sha256 original |
|---|---|
| `maplibre-gl/dist/maplibre-gl-csp.js` | `a1f1847bac64aa00acbf80fbb79b2c5af24d8eecaaa5e2ad14080fab81f1de95` |
| `maplibre-gl/dist/maplibre-gl-csp-worker.js` | `f950e7b15c49c8b9c7bb52136df2a2df2f6f03b83b8927774573fc98b067f7f0` |
| `maplibre-gl/dist/maplibre-gl.css` | `ab1e70d59ec40465bae7e7030da2f3ccf28133fd502e62bd598eefbadfd7a732` |
| `maplibre-gl/dist/LICENSE.txt` | `ee5fc05a0677eaf69601d2c7db0d9ecd6cc27c3abc1d0733bc9ed34707cf8ef2` |
| `pmtiles/dist/pmtiles.js` | `caf981bc46f6327ee7e65d5dc964d89d38a69f60edca2bd4c5c890c21b554c6c` |

### Outras fontes

- **Glifos, sprites e OFL.txt:** github.com/protomaps/basemaps-assets, commit `028c18f713baecad011301ff7a69acc39bcc2ae7` (31/10/2025).
  - Baixados de `https://raw.githubusercontent.com/protomaps/basemaps-assets/<commit>/fonts/Noto%20Sans%20<Estilo>/<faixa>.pbf` e `.../sprites/v4/light*.{json,png}`.
  - Os 14 arquivos foram reconferidos byte a byte nesse commit (sha256 iguais aos da tabela abaixo).
- **`pmtiles/LICENSE`:** github.com/protomaps/PMTiles `LICENSE` (branch main, commit `aec8fa1341222fdddb3318e9ffa8e18e19b312f7`).
- **`style-light.json`:** gerado localmente de `@protomaps/basemaps@5.7.2` (integridade acima). Não há download direto.

### sha256 dos arquivos versionados (como estão no repositório)

Conferir com: `cd public/assets/vendor && find . -type f ! -name README.md | sort | xargs sha256sum` e `cd public/assets/map && find . -type f | sort | xargs sha256sum`.

| `public/assets/vendor/…` | sha256 |
|---|---|
| `maplibre-gl/LICENSE.txt` | `ee5fc05a0677eaf69601d2c7db0d9ecd6cc27c3abc1d0733bc9ed34707cf8ef2` |
| `maplibre-gl/icons/02dae7fb37db.svg` | `02dae7fb37db1028b94af519c177e61c44c923db4766f8403aaec6ce9da63ac0` |
| `maplibre-gl/icons/0c8e0c273377.svg` | `0c8e0c273377151ed9f05da5042a10702a8e8a8690721d471e1ab477e001a506` |
| `maplibre-gl/icons/0caafdbaef1c.svg` | `0caafdbaef1cadf66eda6645a90728b348a127d4b6a690333eb4c28a509e356a` |
| `maplibre-gl/icons/1136d062460a.svg` | `1136d062460a1962a53e467b4a59362d1dc0917764b5ed90edc9441af3c2e62c` |
| `maplibre-gl/icons/1468accafb4e.svg` | `1468accafb4e8146b690be76226be3401ac1b6f82f8b9e009090fa4339b33102` |
| `maplibre-gl/icons/28e54a4e47e3.svg` | `28e54a4e47e360f8e86fcdf2c5ade2a7891093532529f293aef91a6fed461108` |
| `maplibre-gl/icons/2c36fc87ade8.svg` | `2c36fc87ade838f85537936f783916bc5a201b8d51129d245fe5a6c05cdf2fb4` |
| `maplibre-gl/icons/2d3c6a20e50f.svg` | `2d3c6a20e50fb2b2b5da0dc661f4b2aebefacf203d19c89141c2fa41ad88e911` |
| `maplibre-gl/icons/33cdfb9684f8.svg` | `33cdfb9684f87c4e05a5ca510577fb34b08575646fab939bbfee0985e39b2444` |
| `maplibre-gl/icons/33fc9b4c74cd.svg` | `33fc9b4c74cd9d326029e797af3e537b5704efc9ba989f39ca46bda18503faa8` |
| `maplibre-gl/icons/361daccd8fc5.svg` | `361daccd8fc5f70a2f4063ccd7730af4f5007e6ffb95aec2312b8073c8d0bcf6` |
| `maplibre-gl/icons/44febdc94ac0.svg` | `44febdc94ac02768e5ed6b802202d53789f28c2924707f85be2abadecba79f78` |
| `maplibre-gl/icons/45bc9a265490.svg` | `45bc9a265490656aad5e8ab3ce0b6e2d7801dffe7c32801a86fd7f12bbe43fbf` |
| `maplibre-gl/icons/4ae6c2754290.svg` | `4ae6c2754290595feccbfc20c9ddeb6340f7b05e6fea31c43311a5340499feaf` |
| `maplibre-gl/icons/5a4cf54ceba6.svg` | `5a4cf54ceba6dce18a62d739310d9724292192aea366d97bf645d73fe4915bc9` |
| `maplibre-gl/icons/5ae75e45e993.svg` | `5ae75e45e993ffd827f78df137265fe37cd6dc5565a6e894542a41982754f8c8` |
| `maplibre-gl/icons/6eaecba2e00d.svg` | `6eaecba2e00d1e2968c12ada70e0d354dcd8f0768d425a39a0b7801aaa2cde9d` |
| `maplibre-gl/icons/74a7b2f5876b.svg` | `74a7b2f5876bacea6af5a82445b7d525949d543a098e85a11aae6532f65eedd0` |
| `maplibre-gl/icons/880f33a3c632.svg` | `880f33a3c632593ba807b04d3643df6a049d06e3de3812ffd03817db459bd3ab` |
| `maplibre-gl/icons/9c8065630ccc.svg` | `9c8065630ccc8377535fe57b3a6437556c5bbcbd4613df8d4edef7a94a80b55a` |
| `maplibre-gl/icons/9d7005a1da77.svg` | `9d7005a1da77dd665562648bc8f8b199404810a5ca0a4f4610fc8e3d484fd00b` |
| `maplibre-gl/icons/a7aa0a6ffa24.svg` | `a7aa0a6ffa24068560adccf0764d40a2ca211938eea808c8cad8535185f495d4` |
| `maplibre-gl/icons/a91f0794aa78.svg` | `a91f0794aa781a187600c37e50b8e60935ef8c57ebaf52621a4face9ae0ae14a` |
| `maplibre-gl/icons/a98925678ab0.svg` | `a98925678ab092c954e55d396fee8ee9654765cc2a0d27fff621403ca42c987a` |
| `maplibre-gl/icons/aeabee93bbca.svg` | `aeabee93bbca391ab74d29a32eb09a5e43ea06a8a5b5be8d0527f8e73933f8fb` |
| `maplibre-gl/icons/bd861077e792.svg` | `bd861077e79262e81889feaf56223b716f8dacec379995416bd53e94561f6c1e` |
| `maplibre-gl/icons/bd99076677e3.svg` | `bd99076677e36eb9ff50dd2abd718e0064ee951ee47c60781108a7560e0c7bdb` |
| `maplibre-gl/icons/c720be2f0cd3.svg` | `c720be2f0cd3f4ee594744f5c6ee16b2b9b74a496128e939c68f6b63e1bf77e7` |
| `maplibre-gl/icons/c74893172e86.svg` | `c74893172e8619198c2f2d2d5f4e10e66e5476956c7184db23cf49e1a60eb963` |
| `maplibre-gl/icons/e810606d51e9.svg` | `e810606d51e9475f993a0f9a8f848f036c52ecc3818bc19005705ab19a32c264` |
| `maplibre-gl/icons/e87017910e9e.svg` | `e87017910e9eb230483f08c9822997ea0a9b9f51e114cc3e9e9c2065ec814d44` |
| `maplibre-gl/maplibre-gl-csp-worker.js` | `09ddb4d54b126a6a61050e5f8571341e583fcda60fc61f0d2105841bfacdd1e2` |
| `maplibre-gl/maplibre-gl-csp.js` | `16786767beab3f9ec1603c9e86259c23d7969de6eb4c497a8ee0f1a0b06729eb` |
| `maplibre-gl/maplibre-gl.css` | `fc37702b85d10893cac5e13cce5cf564ca55226630067d6db8d563c73a02ff3b` |
| `pmtiles/LICENSE` | `0371c38f338835f7fc13ed71176f3d92144e22c8b736a31cced57adbbeb647b3` |
| `pmtiles/pmtiles.js` | `ca16879182f180bd18648457701ec7106d2159944dca107fd17afc4110c79056` |

| `public/assets/map/…` | sha256 |
|---|---|
| `fonts/NotoSans-Italic/0-255.pbf` | `43edfca91c285ba1226f09d5e74d68e1473a088c517f02a5212ff6ccb10037dc` |
| `fonts/NotoSans-Italic/256-511.pbf` | `a6f9f6574c86a4a28aba630ca1017857ceadd2370ed32a1293c35f819ab9bd60` |
| `fonts/NotoSans-Italic/8192-8447.pbf` | `5dc4e6680116fef01be024e272859d130262613c7e634409a590e5d50ad124ae` |
| `fonts/NotoSans-Medium/0-255.pbf` | `ba2f0118dd024e3041b158e5f9eb49bc0a658019f53f458e9f5c0b8efcd79b91` |
| `fonts/NotoSans-Medium/256-511.pbf` | `d5e801a1a5b1d409d3298c3a1e1ca76328e2314a751078833a618620e8e66e4d` |
| `fonts/NotoSans-Medium/8192-8447.pbf` | `cc38e4956207f0edba1aaf749b61e8f6f1678ef1ad443d32ee285b21d0eb67aa` |
| `fonts/NotoSans-Regular/0-255.pbf` | `62c6d49b15fa836eb6aa45e259c7ca6762f44b011b09e47776efbe4a6db1b397` |
| `fonts/NotoSans-Regular/256-511.pbf` | `2eca7561f9f566bcacfda5dd04fb5880baec1328ec0f5484678289a13994de8a` |
| `fonts/NotoSans-Regular/8192-8447.pbf` | `8ea977a587352fe31b4159ffdbc9a40be79056f2472017c742ea1e4a931864b9` |
| `fonts/OFL.txt` | `7713cfc8e3c36d5ec4aa3d6cffe7500a1b3310f8a86d914b8ea09c2a9dee7c2d` |
| `sprites/light.json` | `bfac76cf7ed5c2aa2992695904056a1c6b07785b7fd20e6c640cb44fd6244a2e` |
| `sprites/light.png` | `b6a34640917bdc57d0bd080836db33376371a3312ebe7b849045268015de3481` |
| `sprites/light@2x.json` | `1fb5b123fbe35d2e1f6ac171513ce01df89e671273a417ee89c44886a4c132f0` |
| `sprites/light@2x.png` | `23f6e9df27c2e9a14385763980a24e3608966bfc8192a106058bc2ca959ab563` |
| `style-light.json` | `7654b44d2c6500fa68f1e4648a576ae6d9457c22458e5ee1e04df9972e11aeab` |
