import { expect, test } from '@playwright/test'
import { SENHA, entrar, sair } from './apoio'

test('login inválido, login válido via teclado e logout encerra a sessão no servidor', async ({ page }) => {
  await page.goto('/')
  await expect(page).toHaveURL(/\/login$/)
  await expect(page.getByRole('heading', { level: 1, name: 'Acesso ao sistema' })).toBeVisible()

  await page.getByLabel('CPF').fill('gestor.alfa')
  await page.getByLabel(/^Senha/).fill('senha-errada-123')
  await page.getByRole('button', { name: 'Entrar' }).click()
  await expect(page.getByRole('alert')).toHaveText('CPF ou senha inválidos.')

  // somente teclado
  await page.getByLabel('CPF').fill('')
  await page.getByLabel('CPF').focus()
  await page.keyboard.type('gestor.alfa')
  await page.keyboard.press('Tab')
  await page.keyboard.type(SENHA)
  await page.keyboard.press('Enter')
  await expect(page.getByRole('heading', { level: 1, name: 'Início' })).toBeVisible()
  await expect(page.getByText(/DEMONSTRAÇÃO/i)).toHaveCount(0) // interface sem rótulos de ambiente de demonstração

  await sair(page)
  const me = await page.request.get('/api/v1/me')
  expect(me.status()).toBe(401)
})

test('rotas da API exigem CSRF em escrita', async ({ request }) => {
  const r = await request.post('/api/v1/auth/login', { data: { login: 'gestor.alfa', password: SENHA }, headers: { Accept: 'application/json' } })
  expect(r.status()).toBe(419)
})

test('auditor vê o evento de login com falha e a cadeia íntegra', async ({ page }) => {
  await entrar(page, 'auditor.demo')
  await page.getByRole('link', { name: 'Auditoria' }).click()
  await expect(page.getByText(/cadeia de hash íntegra/)).toBeVisible()
  await page.getByLabel('Resultado').selectOption('FALHA')
  await expect(page.getByRole('cell', { name: 'auth.login' }).first()).toBeVisible()
})
