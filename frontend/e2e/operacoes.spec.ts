import AxeBuilder from '@axe-core/playwright'
import { expect, test } from '@playwright/test'
import { entrar } from './apoio'

test('transferência provisória: envio pela origem, pendência e recebimento', async ({ page }) => {
  await entrar(page, 'gestor.alfa')
  await page.getByRole('link', { name: 'Operações e pendências' }).first().click()
  await expect(page.getByText('Regra provisória').first()).toBeVisible()
  await page.getByRole('link', { name: 'Nova operação' }).click()
  await page.getByRole('combobox', { name: 'Operação' }).selectOption('TRANSFERENCIA')
  await page.getByLabel(/Local de origem/).selectOption({ label: 'RES-ALFA-01 — Reserva Alfa 01 (fictícia) (UN-ALFA)' })
  await page.getByLabel(/Local de destino/).selectOption({ label: 'DEP-ALFA1-01 — Depósito Alfa-1 (fictício) (SU-ALFA-1)' })
  await page.getByLabel(/Documento de referência/).fill('DOC-E2E-TRF')
  await page.getByLabel(/Motivo/).fill('Remanejamento de teste E2E')
  await page.getByLabel('Quantidade do lote LOTE-DEMO-01').fill('5')
  const axe = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag22aa']).analyze()
  expect(axe.violations.filter((v) => ['serious', 'critical'].includes(v.impact ?? ''))).toEqual([])
  await page.getByRole('button', { name: 'Revisar operação' }).click()
  await page.getByRole('dialog').getByRole('button', { name: 'Confirmar e registrar' }).click()
  await expect(page.getByRole('heading', { level: 1, name: /Movimentação #\d+/ })).toBeVisible()
  const id = (await page.getByRole('heading', { level: 1 }).textContent())!.match(/#(\d+)/)![1]

  await page.goto('/operacoes')
  await page.getByRole('button', { name: `Registrar recebimento da operação #${id}` }).click()
  await expect(page.getByText(`operação #${id} concluída`)).toBeVisible()
  await expect(page.getByRole('button', { name: `Registrar recebimento da operação #${id}` })).toHaveCount(0)
})
