import { test, expect, Page } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import * as fs from 'node:fs';
import * as path from 'node:path';
import { sql, monitorar, conferirPagina, appDir, limparRateLimit } from './helpers';

// PR #40: mapa MapLibre GL + PMTiles (CT-E2E-090..094; cobre CT-MAPA-005/007..012).
// Pré-requisito: recorte de Florianópolis (~1,8 MB) em PMTILES_FILE (padrão fixtures/tiles/brasil.pmtiles),
// gerado com: pmtiles extract https://build.protomaps.com/<build> brasil.pmtiles --bbox=-48.65,-27.75,-48.35,-27.45 --maxzoom=13
// O .pmtiles não vai para o git. Seletores do PR: .map-cluster[data-count], .map-price[data-href], #map-status, #result-map[data-points].
const GOOGLE = /googleapis\.com|gstatic\.com|google\.com|fonts\.g/;
const PMTILES = process.env.PMTILES_FILE || path.join(__dirname, '..', 'fixtures', 'tiles', 'brasil.pmtiles');
const LOC = 'localidade=' + encodeURIComponent('Florianópolis, SC');
let hashes: string[] = [];

test.describe.configure({ mode: 'serial' });

test.beforeAll(() => {
  test.skip(!fs.existsSync(PMTILES), `sem recorte PMTiles em ${PMTILES}`);
  fs.mkdirSync(path.join(appDir(), 'storage', 'tiles'), { recursive: true });
  fs.copyFileSync(PMTILES, path.join(appDir(), 'storage', 'tiles', 'brasil.pmtiles'));
  const out = execFileSync('php', [path.join(__dirname, '..', 'fixtures', 'seed-mapa-floripa.php'), appDir()]).toString();
  hashes = JSON.parse(out).hashes;
  limparRateLimit();
});

type Rede = { externas: string[]; google: string[]; tiles: number[] };
async function abrirMapa(page: Page): Promise<Rede> {
  const r: Rede = { externas: [], google: [], tiles: [] };
  page.on('request', (q) => { const u = new URL(q.url()); if (!['127.0.0.1', 'localhost'].includes(u.hostname) && !u.protocol.startsWith('data')) r.externas.push(q.url()); if (GOOGLE.test(q.url())) r.google.push(q.url()); });
  page.on('response', (s) => { if (s.url().includes('/tiles/brasil.pmtiles')) r.tiles.push(s.status()); });
  await page.addInitScript(() => { (window as any).__csp = []; document.addEventListener('securitypolicyviolation', (e: any) => (window as any).__csp.push(e.violatedDirective + ' ' + e.blockedURI)); });
  await page.goto('/busca/mapa?' + LOC);
  await expect(page.locator('#result-map')).toHaveAttribute('data-points', String(hashes.length), { timeout: 15000 });
  await page.waitForLoadState('networkidle', { timeout: 8000 }).catch(() => {});
  return r;
}
const somaPins = async (page: Page) => {
  const c = await page.locator('.map-cluster').evaluateAll((els) => els.reduce((s, e) => s + Number((e as HTMLElement).dataset.count || 0), 0));
  return c + (await page.locator('.map-price').count());
};

test('CT-E2E-090 clusters em zoom baixo (soma = total)', async ({ page }) => {
  const m = monitorar(page);
  await abrirMapa(page);
  await expect(page.locator('.map-cluster').first()).toBeVisible();
  expect(await somaPins(page)).toBe(hashes.length);
  await expect(page.locator('#map-status')).toContainText(String(hashes.length));
  await conferirPagina(page, m, 'e2e-090-mapa-clusters', { permitir4xx: true });
});

test('CT-E2E-091 cluster expande ao clicar; pins com link para o imóvel', async ({ page }) => {
  await abrirMapa(page);
  const cl = page.locator('.map-cluster').first();
  const antes = await page.locator('.map-cluster').count();
  const n = Number(await cl.getAttribute('data-count'));
  await cl.click();
  await expect.poll(async () => (await page.locator('.map-price').count()) > 0 && (await page.locator('.map-cluster').count()) <= antes, { timeout: 10000 }).toBe(true);
  await page.waitForTimeout(1200);
  expect(await page.locator('.map-price').count()).toBeGreaterThan(0);
  expect(n).toBeGreaterThan(1);
  for (const h of await page.locator('.map-price').evaluateAll((els) => els.map((e) => (e as HTMLElement).dataset.href || ''))) {
    expect(h).toMatch(/^\/imoveis\/[a-f0-9]{16}(\?|$)/);
  }
  // Teclado: Enter abre o popup; Esc fecha e devolve o foco ao pin.
  const pin = page.locator('.map-price').first();
  await pin.focus(); await page.keyboard.press('Enter');
  await expect(page.locator('.maplibregl-popup')).toBeVisible();
  await expect(page.locator('.maplibregl-popup a[href^="/imoveis/"]')).toBeVisible();
  await page.keyboard.press('Escape');
  await expect(page.locator('.maplibregl-popup')).toHaveCount(0);
  expect(await pin.evaluate((e) => e === document.activeElement)).toBe(true);
});

test('CT-E2E-092 atribuição OSM visível com link', async ({ page }) => {
  await abrirMapa(page);
  const at = page.locator('.maplibregl-ctrl-attrib');
  await expect(at).toBeVisible();
  await expect(at).toContainText('OpenStreetMap');
  await expect(at.locator('a[href*="openstreetmap.org"]')).toHaveCount(1);
});

test('CT-E2E-093 zero CSP, zero Google, nada externo; tiles via 206', async ({ page }) => {
  const r = await abrirMapa(page);
  await page.waitForTimeout(1500);
  expect(await page.evaluate(() => (window as any).__csp)).toEqual([]);
  expect(r.google).toEqual([]);
  expect(r.externas).toEqual([]);
  expect(r.tiles.length).toBeGreaterThan(0);
  expect(r.tiles.filter((s) => s === 206).length).toBeGreaterThan(0);
  await expect(page.locator('#map-notice')).toBeHidden();
  expect(await page.locator('canvas.maplibregl-canvas').count()).toBe(1);
});

test('CT-E2E-094 marcadores usam a coordenada pública (300–500 m), nunca a exata', async ({ page, request }) => {
  await abrirMapa(page);
  const api = await (await request.get('/api/busca?' + LOC)).json();
  for (const it of api.items) {
    const lat = Number(sql('SELECT lat FROM properties WHERE public_hash = ?', [it.id]));
    const lng = Number(sql('SELECT lng FROM properties WHERE public_hash = ?', [it.id]));
    const R = 6371000, r = Math.PI / 180;
    const a = Math.sin((it.lat - lat) * r / 2) ** 2 + Math.cos(lat * r) * Math.cos(it.lat * r) * Math.sin((it.lng - lng) * r / 2) ** 2;
    const d = 2 * R * Math.asin(Math.sqrt(a));
    expect(d, `distância do ${it.id}`).toBeGreaterThanOrEqual(300);
    expect(d, `distância do ${it.id}`).toBeLessThanOrEqual(500);
  }
  const html = await page.content();
  for (const h of hashes) {
    const lat = Number(sql('SELECT lat FROM properties WHERE public_hash = ?', [h])).toFixed(4);
    expect(html).not.toContain(lat);
  }
});
