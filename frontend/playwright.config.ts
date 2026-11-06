import { defineConfig, devices } from '@playwright/test'

// E2E contra Laravel real (scripts/e2e.sh sobe a API isolada em :18482 e banco sigarb_e2e).
export default defineConfig({
  testDir: './e2e',
  fullyParallel: false,
  workers: 1,
  retries: 0,
  expect: { timeout: 10_000 },
  reporter: [['list']],
  use: { baseURL: 'http://127.0.0.1:18483', trace: 'retain-on-failure', locale: 'pt-BR' },
  // Chromium e Firefox rodam no host. WebKit exige bibliotecas de sistema: roda na imagem oficial
  // do Playwright (scripts/e2e-navegadores.sh define SIGARB_E2E_WEBKIT=1 dentro do container).
  projects: process.env.SIGARB_E2E_WEBKIT
    ? [
        { name: 'webkit', use: { ...devices['Desktop Safari'] } },
        { name: 'celular-webkit', use: { ...devices['iPhone 14'] }, testMatch: /responsivo|acessibilidade|suporte/ },
      ]
    : [
        { name: 'desktop', use: { ...devices['Desktop Chrome'] } },
        { name: 'celular', use: { ...devices['Pixel 7'] }, testMatch: /responsivo|acessibilidade|suporte/ },
        ...(process.env.SIGARB_E2E_FIREFOX ? [{ name: 'firefox', use: { ...devices['Desktop Firefox'] } }] : []),
      ],
  webServer: {
    // Build de produção servido pelo preview (sem compilação sob demanda; testa o artefato entregue).
    command: 'npm run build && npx vite preview --port 18483 --strictPort',
    timeout: 180_000,
    url: 'http://127.0.0.1:18483',
    reuseExistingServer: false,
    env: { SIGARB_API_URL: process.env.SIGARB_API_URL ?? 'http://127.0.0.1:18482' },
  },
})
