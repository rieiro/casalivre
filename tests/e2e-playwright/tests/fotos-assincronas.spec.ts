import { test, expect, Page } from '@playwright/test';
import { entrar, massa, sql, monitorar, conferirPagina, processarFotos, fixtureFoto, limparRateLimit } from './helpers';

// PR #39: fotos assíncronas (CT-E2E-080..084). Imagens em fixtures/fotos (gerar.py).
// O imóvel da massa E2E (casaHash, dono) recebe as fotos; o image-worker roda via processarFotos().

const contar = (hash: string, status: string) =>
  Number(sql(`SELECT COUNT(*) FROM photos p JOIN properties h ON h.id = p.property_id WHERE h.public_hash = ? AND p.status = ?`, [hash, status]));

async function enviarPelaTela(page: Page, hash: string, arquivos: string[]) {
  await page.goto(`/imoveis/${hash}/editar`);
  const form = page.locator('form:has(input[type="file"][name="fotos[]"])');
  await form.locator('input[type="file"]').setInputFiles(arquivos);
  await Promise.all([page.waitForURL(new RegExp(`/imoveis/${hash}/editar`)), form.locator('button[type="submit"]').click()]);
}

test.describe.configure({ mode: 'serial' });

test('CT-E2E-080 upload pela tela: processando e depois pronta', async ({ page }) => {
  const d = massa(); const m = monitorar(page);
  await entrar(page, d.dono, d.senha);
  const antes = contar(d.casaHash, 'processing');
  await enviarPelaTela(page, d.casaHash, [fixtureFoto('valida.jpg')]);
  expect(contar(d.casaHash, 'processing')).toBe(antes + 1);
  await expect(page.locator('.photo-wait:not(.is-failed)').first()).toContainText('Processando foto');
  processarFotos(d.casaHash);
  await page.reload();
  expect(contar(d.casaHash, 'processing')).toBe(0);
  await expect(page.locator('.photo-wait:not(.is-failed)')).toHaveCount(0);
  const src = await page.locator('.photo-tile img').last().getAttribute('src');
  expect(src).toMatch(/^\/uploads\/fotos\/[0-9a-f-]{36}-320\.webp$/);
  await conferirPagina(page, m, 'e2e-080-fotos-prontas');
});

test('CT-E2E-081 foto que falhou aparece só para o dono', async ({ page, request }) => {
  const d = massa();
  await entrar(page, d.dono, d.senha);
  await enviarPelaTela(page, d.casaHash, [fixtureFoto('corrompida.jpg')]);
  processarFotos(d.casaHash); // 5 tentativas com backoff adiantado -> failed
  expect(contar(d.casaHash, 'failed')).toBeGreaterThan(0);
  await page.reload();
  await expect(page.locator('.photo-wait.is-failed').first()).toContainText('Não foi possível processar esta foto');
  const pub = await (await request.get(`/api/imoveis/${d.casaHash}`)).json();
  expect(pub.photos.length).toBe(contar(d.casaHash, 'ready'));
  expect(pub.photos.every((p: any) => /^\/uploads\/fotos\/[0-9a-f-]{36}-1920\.webp$|^\/uploads\//.test(p.path))).toBe(true);
});

test('CT-E2E-082 página pública não mostra fotos não prontas', async ({ page }) => {
  const d = massa(); const m = monitorar(page);
  await entrar(page, d.dono, d.senha);
  await enviarPelaTela(page, d.casaHash, [fixtureFoto('valida.png')]); // fica em processing (sem worker)
  expect(contar(d.casaHash, 'processing')).toBeGreaterThan(0);
  const prontas = contar(d.casaHash, 'ready');
  await page.context().clearCookies();
  await page.goto(`/imoveis/${d.casaHash}`);
  await expect(page.locator('.gallery img')).toHaveCount(prontas);
  for (const s of await page.locator('.gallery img').evaluateAll((els) => els.map((e) => e.getAttribute('src') || ''))) {
    expect(s).not.toBe('');
    expect(s).not.toContain('placeholder');
  }
  await conferirPagina(page, m, 'e2e-082-publico-so-prontas');
  processarFotos(d.casaHash);
});

test('CT-E2E-083 limites: arquivo > 16 MB avisado na tela; 31º envio pela API dá 429', async ({ page }) => {
  const d = massa();
  await entrar(page, d.dono, d.senha);
  await page.goto(`/imoveis/${d.casaHash}/editar`);
  const form = page.locator('form:has(input[type="file"][name="fotos[]"])');
  await form.locator('input[type="file"]').setInputFiles([fixtureFoto('grande-17mb.png')]);
  await expect(form.locator('[data-upload-error]')).toBeVisible();
  await expect(form.locator('[data-upload-error]')).not.toHaveText('');
  // Limite: 30 envios por 10 min por IP, somando API e formulário web (#39 c25c61c). Os testes anteriores
  // já enviaram pela tela, então zera o balde antes de contar. Envia sem arquivo válido (salvas=0).
  limparRateLimit();
  const r = await page.evaluate(async (hash) => {
    const csrf = (document.querySelector('input[name="_csrf"]') as HTMLInputElement).value;
    const out: { s: number; ra: string | null }[] = [];
    for (let i = 0; i < 31; i++) {
      const fd = new FormData(); fd.append('fotos[]', new Blob(['nao sou imagem'], { type: 'image/jpeg' }), 'x.jpg');
      const resp = await fetch(`/api/casas/${hash}/fotos`, { method: 'POST', body: fd, headers: { 'X-CSRF-Token': csrf }, credentials: 'same-origin' });
      out.push({ s: resp.status, ra: resp.headers.get('Retry-After') });
    }
    return out;
  }, d.casaHash);
  expect(r.slice(0, 30).every((x) => x.s === 201)).toBe(true);
  expect(r[30].s).toBe(429);
  expect(Number(r[30].ra)).toBeGreaterThan(0);
  expect(Number(r[30].ra)).toBeLessThanOrEqual(600);
  // Limpa o estado do limitador (storage/cache/ratelimit) para não afetar os próximos specs.
  limparRateLimit();
});

test('CT-E2E-084 imagens públicas em WebP com srcset dos 3 tamanhos', async ({ page }) => {
  const d = massa(); const m = monitorar(page);
  processarFotos(d.casaHash);
  expect(contar(d.casaHash, 'ready')).toBeGreaterThan(0);
  await page.goto(`/imoveis/${d.casaHash}`);
  const img = page.locator('.gallery img[srcset]').first();
  await expect(img).toHaveAttribute('src', /^\/uploads\/fotos\/[0-9a-f-]{36}-800\.webp$/);
  const srcset = (await img.getAttribute('srcset')) || '';
  for (const w of [320, 800, 1920]) expect(srcset).toMatch(new RegExp(`-${w}\\.webp ${w}w`));
  const resp = await page.request.get((await img.getAttribute('src'))!);
  expect(resp.status()).toBe(200);
  expect(resp.headers()['content-type']).toContain('image/webp');
  await conferirPagina(page, m, 'e2e-084-webp-srcset');
});
