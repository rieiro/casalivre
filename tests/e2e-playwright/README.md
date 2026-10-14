# E2E (Playwright) — QA

Specs da sprint 2 (fotos assíncronas do #39 e esqueleto do mapa PMTiles do #36).

```bash
cd tests/e2e-playwright
npm ci && npx playwright install chromium
(cd fixtures/fotos && python3 gerar.py)   # gera as imagens de teste (precisa de Pillow)
npx playwright test tests/fotos-assincronas.spec.ts   # APP_DIR=<raiz do app> opcional (padrão: raiz do repo)
```

- Cada execução copia o app para um diretório temporário, recria o banco (setup.sh + seeds) e sobe `php -S` com `APP_ENV=dev`.
- `helpers.ts`: `imageWorker()`, `processarFotos(hash)` (adianta a fila só do imóvel) e `limparRateLimit()`.
- `mapa-pmtiles.spec.ts` está todo em `test.fixme` até o PMTiles/MapLibre entrar.
- Casos não E2E das fotos: `php scripts/extras/fotos-assincronas.php` (com `APP_URL` e `FIX=tests/e2e-playwright/fixtures/fotos`).
