import AxeBuilder from '@axe-core/playwright'
import { expect, test, type Page } from '@playwright/test'
import { entrar, sair } from './apoio'

const axe = async (page: Page) => {
  const r = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag22aa']).analyze()
  expect(r.violations.filter((v) => ['serious', 'critical'].includes(v.impact ?? ''))).toEqual([])
}

test('inventário: contagem, envio e ajuste aprovado por outra pessoa; alertas e relatórios acessíveis', async ({ page }) => {
  await entrar(page, 'gestor.alfa')
  await page.goto('/inventarios')
  await page.getByRole('button', { name: 'Abrir inventário' }).click()
  await page.getByRole('dialog').getByLabel(/Local de estoque/).selectOption({ label: 'RES-ALFA-01 — Reserva Alfa 01 (fictícia)' })
  await page.getByRole('dialog').getByRole('button', { name: 'Abrir' }).click()
  await expect(page.getByRole('heading', { level: 1, name: /Inventário — RES-ALFA-01/ })).toBeVisible()
  const linhas = page.locator('tbody tr')
  const n = await linhas.count()
  for (let i = 0; i < n; i++) {
    const esperado = (await linhas.nth(i).locator('td').nth(2).textContent())!.trim()
    await linhas.nth(i).getByRole('spinbutton').fill(i === 0 && Number(esperado) > 1 ? String(Number(esperado) - 1) : esperado)
  }
  await axe(page)
  await page.getByRole('button', { name: 'Salvar contagem' }).click()
  await expect(page.getByText('Contagem salva.')).toBeVisible()
  await page.getByRole('button', { name: /Enviar para aprovação/ }).click()
  await expect(page.getByText('Aguardando aprovação').first()).toBeVisible()
  const url = page.url()
  await sair(page)

  await entrar(page, 'aprovador.demo')
  await page.goto(url)
  await page.getByRole('button', { name: 'Aprovar ajuste' }).click()
  await expect(page.getByText('Ajuste aprovado e aplicado.')).toBeVisible()
  await page.goto('/alertas')
  await expect(page.getByRole('heading', { level: 1, name: 'Alertas' })).toBeVisible()
  await axe(page)
  await sair(page)

  await entrar(page, 'auditor.demo')
  await page.goto('/relatorios')
  const download = page.waitForEvent('download')
  await page.getByRole('link', { name: 'Baixar CSV de Saldos de estoque' }).click()
  expect((await download).suggestedFilename()).toMatch(/^sigarb-saldos-.*\.csv$/)
  await axe(page)
})
