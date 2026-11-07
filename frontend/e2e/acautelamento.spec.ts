import AxeBuilder from '@axe-core/playwright'
import { expect, test, type Page } from '@playwright/test'
import { entrar, sair } from './apoio'

const axe = async (page: Page) => {
  if (page.context().browser()?.browserType().name() === 'firefox') return // axe-core derruba o Firefox sob Playwright (ver responsivo-acessibilidade.spec)
  const r = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag22aa']).analyze()
  expect(r.violations.filter((v) => ['serious', 'critical'].includes(v.impact ?? ''))).toEqual([])
}

test('acautelamento: solicitação, aprovação por outra pessoa, entrega, carteira e desacautelamento', async ({ page }) => {
  await entrar(page, 'gestor.alfa')
  await page.goto('/acautelamentos')
  await page.getByRole('link', { name: 'Nova solicitação' }).click()
  await page.getByLabel(/^Efetivo/).selectOption({ index: 1 })
  const valor = await page.getByLabel(/^Item/).locator('option', { hasText: 'PAT-0002' }).getAttribute('value')
  await page.getByLabel(/^Item/).selectOption(valor!)
  await page.getByLabel(/Justificativa/).fill('Serviço operacional E2E')
  await axe(page)
  await page.getByRole('button', { name: 'Solicitar' }).click()
  await expect(page.getByRole('heading', { level: 1, name: /Acautelamento —/ })).toBeVisible()
  const url = page.url()
  await expect(page.getByRole('button', { name: 'Aprovar' })).toHaveCount(0) // gestor não aprova
  await sair(page)

  await entrar(page, 'aprovador.demo')
  await page.goto(url)
  await page.getByRole('button', { name: 'Aprovar' }).click()
  await expect(page.getByText('Acautelamento aprovado.')).toBeVisible()
  await sair(page)

  await entrar(page, 'gestor.alfa')
  await page.goto(url)
  await page.getByRole('button', { name: 'Registrar entrega' }).click()
  await expect(page.getByText(/Item entregue/)).toBeVisible()
  await axe(page)
  await page.getByRole('link', { name: 'Carteira' }).click()
  await expect(page.getByRole('img', { name: /QR Code de verificação/ })).toBeVisible()
  await expect(page.getByText('PAT-0002')).toBeVisible()
  await page.goto(url)
  await page.getByRole('button', { name: 'Desacautelar' }).click()
  const dlg = page.getByRole('dialog', { name: 'Desacautelar' })
  await dlg.getByLabel('Condição do item').selectOption('BOM')
  await dlg.getByRole('button', { name: 'Confirmar' }).click()
  await expect(page.getByText(/devolvido ao estoque/)).toBeVisible()
  await expect(page.getByText('Encerrado').first()).toBeVisible()
})
