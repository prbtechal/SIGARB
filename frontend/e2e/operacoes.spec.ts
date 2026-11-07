import AxeBuilder from '@axe-core/playwright'
import { expect, test } from '@playwright/test'
import { entrar } from './apoio'

test('transferência provisória: envio pela origem, pendência e recebimento', async ({ page, browserName }) => {
  await entrar(page, 'gestor.alfa')
  await page.goto('/operacoes')
  await expect(page.getByText('Habilitada').first()).toBeVisible()
  await page.getByRole('link', { name: 'Nova operação' }).click()
  await page.getByRole('combobox', { name: 'Operação' }).selectOption('TRANSFERENCIA')
  await page.getByLabel(/Local de origem/).selectOption({ label: 'RES-1CIA — Reserva de Armamento da 1ª Cia (1CIA)' })
  await page.getByLabel(/Local de destino/).selectOption({ label: 'DEP-1PEL — Depósito do 1º Pelotão (1PEL-1CIA)' })
  await page.getByLabel(/Documento de referência/).fill('DOC-E2E-TRF')
  await page.getByLabel(/Motivo/).fill('Remanejamento de teste E2E')
  await page.getByLabel('Quantidade do lote LT-2026-01').fill('5')
  const axe = browserName === 'firefox' ? { violations: [] } : await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag22aa']).analyze()
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
