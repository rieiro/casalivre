import { expect, Page } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import * as fs from 'node:fs';
import * as path from 'node:path';

// Massa criada pelo fixtures/seed-e2e.php.
export type Massa = {
  senha: string; dono: string; reset: string; senhaErrada: string; csrf: string;
  casaHash: string; casaTitulo: string; cidade: string;
};
export const runDir = () => process.env.E2E_RUN_DIR!;
export const massa = (): Massa => JSON.parse(fs.readFileSync(path.join(runDir(), 'fixture.json'), 'utf8'));

// Consulta direta ao SQLite do app de teste (ex.: link de redefinição, que não é enviado por e-mail).
export function sql(consulta: string, params: string[] = []): string {
  const db = path.join(runDir(), 'app', 'storage', 'db', 'casalivre.sqlite');
  const codigo = `$p=new PDO('sqlite:'.$argv[1]);$s=$p->prepare($argv[2]);$s->execute(array_slice($argv,3));echo (string)$s->fetchColumn();`;
  return execFileSync('php', ['-r', codigo, db, consulta, ...params]).toString();
}

export function emailUnico(prefixo: string): string {
  return `${prefixo}.${Date.now()}${Math.floor(Math.random() * 1000)}@casalivre.test`;
}

export async function entrar(page: Page, email: string, senha: string) {
  await page.goto('/entrar');
  await page.locator('#login-form input[name="email"]').fill(email);
  await page.locator('#login-form input[name="password"]').fill(senha);
  await page.locator('#login-form button[type="submit"]').click();
  await expect(page).toHaveURL(/\/painel/);
}

export async function sair(page: Page) {
  await page.locator('form[action="/sair"] button[type="submit"]').first().click();
}

// Preenche o CEP e espera o autocompletar (rua + cidade) do app.js.
export async function preencherCep(page: Page, formSel: string, cep: string) {
  await page.locator(`${formSel} input[name="cep"]`).fill(cep);
  await expect(page.locator(`${formSel} input[name="street"]`)).not.toHaveValue('', { timeout: 8000 });
}

// ---------------------------------------------------------------------------
// Verificação padrão de página (critérios da rodada final):
//  - nenhuma resposta 5xx; nenhum texto de erro/aviso do PHP no HTML
//  - nenhum link para /busca-novo nem para studio.css
//  - zero erros no console do navegador (e zero exceções de página)
//  - registra as chamadas a /api/cidades e /api/ceps (status) para conferência
//  - screenshot de página inteira em resultados/paginas/<nome>.png
// ---------------------------------------------------------------------------
export type Monitor = { console: string[]; erros5xx: string[]; api: { url: string; status: number }[]; dialogos: string[] };

export function monitorar(page: Page): Monitor {
  const m: Monitor = { console: [], erros5xx: [], api: [], dialogos: [] };
  // Diálogos (alert/confirm) bloqueiam a página: registramos e aceitamos.
  page.on('dialog', (d) => { m.dialogos.push(`${d.type()}: ${d.message()}`); d.accept().catch(() => {}); });
  page.on('console', (msg) => { if (msg.type() === 'error') m.console.push(msg.text()); });
  page.on('pageerror', (err) => m.console.push('pageerror: ' + err.message));
  page.on('response', (r) => {
    if (r.status() >= 500) m.erros5xx.push(`${r.status()} ${r.url()}`);
    if (/\/api\/(cidades|ceps)\b/.test(r.url())) m.api.push({ url: r.url(), status: r.status() });
  });
  return m;
}

const ERRO_PHP = /(Fatal error|Parse error|Warning:|Notice:|Deprecated:|Uncaught |Stack trace|on line \d+)/;

// permitir4xx: cenários negativos em que o app recebe 4xx de propósito (o Chromium registra
// "Failed to load resource" no console). Esses registros são anotados, não reprovam.
export async function conferirPagina(page: Page, m: Monitor, nome: string, opc: { permitir4xx?: boolean } = {}) {
  // Algumas telas fazem fetch sem ler o corpo; networkidle pode nunca chegar: limite de 5 s.
  await page.waitForLoadState('networkidle', { timeout: 5000 }).catch(() => {});
  fs.mkdirSync(path.join(__dirname, '..', 'resultados', 'paginas'), { recursive: true });
  await page.screenshot({ path: path.join(__dirname, '..', 'resultados', 'paginas', `${nome}.png`), fullPage: true });
  const html = await page.content();
  expect.soft(html, 'texto de erro/aviso do PHP na página').not.toMatch(ERRO_PHP);
  expect.soft(await page.locator('a[href*="/busca-novo"]').count(), 'link para /busca-novo').toBe(0);
  expect.soft(await page.locator('link[href*="studio.css"]').count(), 'referência a studio.css').toBe(0);
  expect.soft(m.erros5xx, 'respostas 5xx').toEqual([]);
  const consoleErros = opc.permitir4xx ? m.console.filter((c) => !/status of 4\d\d/.test(c)) : m.console;
  expect.soft(consoleErros, 'erros no console do navegador').toEqual([]);
  expect.soft(m.dialogos.filter((d) => d.startsWith('alert') && /erro|falha|inválid|invalid|negad/i.test(d)), 'alertas de erro exibidos').toEqual([]);
  const ruins = m.api.filter((a) => a.status !== 200);
  expect.soft(ruins, '/api/cidades e /api/ceps devem responder 200').toEqual([]);
}

// Fila de e-mail (PR #32): o worker envia; em APP_ENV=dev sem SMTP o link vai para storage/logs/mail-dev.log.
export const appDir = () => path.join(runDir(), 'app');
export const mailWorker = () => execFileSync('php', ['scripts/mail-worker.php'], { cwd: appDir(), env: { ...process.env, APP_ENV: 'dev' } }).toString();
export function linkDoEmail(email: string, trecho: string): string {
  const arq = path.join(appDir(), 'storage/logs/mail-dev.log');
  const linhas = fs.existsSync(arq) ? fs.readFileSync(arq, 'utf8').split('\n').filter((l) => l.includes(email) && l.includes(trecho)) : [];
  if (!linhas.length) throw new Error(`sem link (${trecho}) para ${email} no mail-dev.log`);
  const u = new URL(JSON.parse(linhas[linhas.length - 1]).link);
  return u.pathname + u.search;
}

// Fila de imagens (PR #39): o image-worker processa um lote (20) e sai.
export const imageWorker = () => execFileSync('php', ['scripts/image-worker.php'], { cwd: appDir(), env: { ...process.env, APP_ENV: 'dev' } }).toString();
// Processa as fotos de um imóvel (ignora a fila dos seeds, que pode ter milhares de itens): põe as do imóvel
// na frente e adianta o backoff, até nenhuma ficar em processing (ou acabarem as rodadas).
export function processarFotos(hash: string, rodadas = 8): void {
  const pid = sql('SELECT id FROM properties WHERE public_hash = ?', [hash]);
  for (let i = 0; i < rodadas; i++) {
    sql(`UPDATE image_queue SET next_attempt_at = CASE WHEN photo_id IN (SELECT id FROM photos WHERE property_id = ?) THEN '2000-01-01 00:00:00' ELSE '2999-01-01 00:00:00' END WHERE status = 'pending'`, [pid]);
    imageWorker();
    if (sql(`SELECT COUNT(*) FROM photos WHERE property_id = ? AND status = 'processing'`, [pid]) === '0') break;
  }
}
export const fixtureFoto = (nome: string) => path.join(__dirname, '..', 'fixtures', 'fotos', nome);
export function limparRateLimit(): void {
  const dir = path.join(appDir(), 'storage', 'cache', 'ratelimit');
  if (fs.existsSync(dir)) for (const f of fs.readdirSync(dir)) fs.rmSync(path.join(dir, f), { force: true });
}
