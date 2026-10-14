import { defineConfig, devices } from '@playwright/test';
import { execFileSync, execSync } from 'node:child_process';
import * as path from 'node:path';
import * as os from 'node:os';

// O config é avaliado no processo principal e de novo em cada worker.
// Por isso porta e diretório vão para variáveis de ambiente, que os workers herdam:
// o app é preparado uma única vez.
const raiz = __dirname;
if (!process.env.E2E_PORT) {
  // Pede ao SO uma porta livre.
  process.env.E2E_PORT = execSync(
    `node -e "const s=require('net').createServer();s.listen(0,'127.0.0.1',()=>{console.log(s.address().port);s.close()})"`,
  ).toString().trim();
}
if (!process.env.E2E_RUN_DIR) {
  // APP_DIR: código a testar (padrão: a raiz deste repositório). Banco novo a cada execução.
  const appDir = path.resolve(process.env.APP_DIR || path.join(raiz, '..', '..'));
  process.env.E2E_RUN_DIR = path.join(os.tmpdir(), `casalivre-e2e-${process.env.E2E_PORT}`);
  execFileSync(path.join(raiz, 'scripts', 'prepare-app.sh'), [appDir, process.env.E2E_RUN_DIR], { stdio: 'inherit' });
}
const porta = process.env.E2E_PORT;
const baseURL = `http://127.0.0.1:${porta}`;

export default defineConfig({
  testDir: './tests',
  // Sequencial: os cenários dividem o mesmo banco e o mesmo IP (limite de login por IP).
  fullyParallel: false,
  workers: 1,
  retries: 0,
  timeout: 45_000,
  expect: { timeout: 7_000 },
  reporter: [
    ['list'],
    ['html', { outputFolder: 'relatorio-html', open: 'never' }],
    ['json', { outputFile: 'resultados/resultado.json' }],
  ],
  outputDir: 'resultados/artefatos',
  use: {
    baseURL,
    locale: 'pt-BR',
    screenshot: 'only-on-failure',
    trace: 'retain-on-failure',
    video: 'off',
  },
  projects: [{ name: 'chromium', use: { ...devices['Desktop Chrome'] } }],
  webServer: {
    // Servidor embutido do PHP com o roteador public/index.php.
    // Vários workers do php -S permitem que o app busque o próprio .ics de teste (import iCal).
    command: `php -S 127.0.0.1:${porta} -t public public/index.php`,
    cwd: path.join(process.env.E2E_RUN_DIR!, 'app'),
    env: { APP_ENV: 'dev', PHP_CLI_SERVER_WORKERS: '4', APP_URL: baseURL, LOG_SALT: 'salt-e2e-local-123' },
    url: `${baseURL}/entrar`,
    reuseExistingServer: false,
    timeout: 60_000,
    stdout: 'ignore',
    stderr: 'pipe',
  },
});
