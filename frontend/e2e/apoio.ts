import { expect, type Page } from '@playwright/test'

export const SENHA = process.env.SIGARB_DEMO_PASSWORD ?? ''
if (!SENHA) throw new Error('Defina SIGARB_DEMO_PASSWORD (use scripts/e2e.sh).')

export async function entrar(page: Page, login: string) {
  await page.goto('/login')
  await page.getByLabel('CPF').fill(login)
  await page.getByLabel(/^Senha/).fill(SENHA)
  await page.getByRole('button', { name: 'Entrar' }).click()
  await expect(page.getByRole('heading', { level: 1, name: 'Início' })).toBeVisible()
}

export async function sair(page: Page) {
  await page.getByRole('button', { name: 'Sair' }).click()
  await expect(page).toHaveURL(/\/login$/)
}
