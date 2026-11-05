import { defineConfig, devices } from '@playwright/test'

// E2E contra Laravel real (scripts/e2e.sh sobe a API isolada em :18482 e banco sigarb_e2e).
export default defineConfig({
  testDir: './e2e',
  fullyParallel: false,
  workers: 1,
  retries: 0,
  reporter: [['list']],
  use: { baseURL: 'http://127.0.0.1:18483', trace: 'retain-on-failure', locale: 'pt-BR' },
  projects: [
    { name: 'desktop', use: { ...devices['Desktop Chrome'] } },
    { name: 'celular', use: { ...devices['Pixel 7'] }, testMatch: /responsivo|acessibilidade/ },
  ],
  webServer: {
    command: 'npx vite --port 18483 --strictPort',
    url: 'http://127.0.0.1:18483',
    reuseExistingServer: false,
    env: { SIGARB_API_URL: process.env.SIGARB_API_URL ?? 'http://127.0.0.1:18482' },
  },
})
