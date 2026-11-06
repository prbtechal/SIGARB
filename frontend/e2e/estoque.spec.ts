import { expect, test } from '@playwright/test'
import { entrar, sair } from './apoio'

test('entrada de lote → detalhe → estorno, com saldo e auditoria coerentes', async ({ page }) => {
  await entrar(page, 'gestor.alfa')
  await page.getByRole('link', { name: 'Registrar entrada' }).click()
  await page.getByLabel(/Local de destino/).selectOption({ label: 'RES-ALFA-01 — Reserva Alfa 01 (fictícia) (UN-ALFA)' })
  await page.getByLabel(/Documento de referência/).fill('DOC-E2E-001')
  await page.getByLabel(/^Origem/).fill('Origem fictícia E2E')
  await page.getByLabel('Material (item 1)').selectOption({ label: 'DEMO-MUN-01 — Cartucho de demonstração (lote)' })
  await page.getByLabel('Código do lote').fill('LOTE-E2E-1')
  await page.getByLabel('Validade').fill('2031-12-31')
  await page.getByLabel('Quantidade').fill('77')
  await page.getByRole('button', { name: 'Revisar entrada' }).click()
  const dlg = page.getByRole('dialog', { name: 'Confirmar entrada' })
  await expect(dlg).toContainText('77 unidade(s)')
  await dlg.getByRole('button', { name: 'Confirmar e registrar' }).click()

  await expect(page.getByRole('heading', { level: 1, name: /Movimentação #\d+/ })).toBeVisible()
  await expect(page.getByText('Entrada #')).toBeVisible()
  const id = (await page.getByRole('heading', { level: 1 }).textContent())!.match(/#(\d+)/)![1]

  await page.goto('/estoque')
  await page.getByLabel('Buscar').fill('LOTE-E2E-1')
  await page.getByRole('button', { name: 'Filtrar' }).click()
  await expect(page.getByRole('cell', { name: '77' }).first()).toBeVisible()

  await page.goto(`/movimentacoes/${id}`)
  await page.getByRole('button', { name: 'Estornar…' }).click()
  const est = page.getByRole('dialog', { name: 'Estornar movimentação' })
  await expect(est.getByRole('button', { name: 'Confirmar estorno' })).toBeDisabled()
  await est.getByLabel('Justificativa').fill('Lançamento de teste E2E')
  await est.getByRole('button', { name: 'Confirmar estorno' }).click()
  await expect(page.getByText(/Estorno #\d+ registrado/)).toBeVisible()
  await expect(page.getByText(/foi estornada pela/)).toBeVisible()
  await expect(page.getByRole('button', { name: 'Estornar…' })).toHaveCount(0)

  await page.goto('/estoque')
  await page.getByLabel('Buscar').fill('LOTE-E2E-1')
  await page.getByRole('button', { name: 'Filtrar' }).click()
  await expect(page.getByText('Nenhum saldo encontrado.')).toBeVisible()
  await sair(page)

  await entrar(page, 'auditor.demo')
  await page.goto('/auditoria')
  await page.getByLabel('Ação (prefixo)').fill('estoque.')
  await page.getByRole('button', { name: 'Filtrar' }).click()
  await expect(page.getByRole('cell', { name: 'estoque.estorno' }).first()).toBeVisible()
  await expect(page.getByRole('cell', { name: 'estoque.entrada' }).first()).toBeVisible()
})

test('escopo: gestor de BRAVO não vê estoque de ALFA nem acessa movimentação por URL', async ({ page }) => {
  await entrar(page, 'gestor.bravo')
  await page.goto('/estoque')
  await expect(page.getByRole('cell', { name: /RES-BRAVO-01/ }).first()).toBeVisible()
  await expect(page.getByText('RES-ALFA-01')).toHaveCount(0)
  await page.goto('/movimentacoes/1') // carga inicial de ALFA
  await expect(page.getByRole('alert')).toContainText('não tem permissão')
})

test('operador não estorna; aprovador não tem ações operacionais; operações bloqueadas mostram pendência', async ({ page }) => {
  await entrar(page, 'operador.alfa1')
  await page.goto('/movimentacoes/2')
  await expect(page.getByRole('heading', { level: 1, name: 'Movimentação #2' })).toBeVisible()
  await expect(page.getByRole('button', { name: 'Estornar…' })).toHaveCount(0)
  await expect(page.getByRole('link', { name: 'Usuários e acessos' })).toHaveCount(0)
  await sair(page)

  await entrar(page, 'aprovador.demo')
  await expect(page.getByRole('link', { name: 'Registrar entrada' })).toHaveCount(0)
  await page.getByRole('link', { name: 'Operações e pendências' }).click()
  await expect(page.getByText('Aguardando decisão institucional P-06/P-07.')).toBeVisible()
  const r = await page.evaluate(async () => {
    const xsrf = decodeURIComponent(document.cookie.match(/XSRF-TOKEN=([^;]+)/)![1])
    const res = await fetch('/api/v1/acautelamentos/requests', { method: 'POST', headers: { 'X-XSRF-TOKEN': xsrf, Accept: 'application/json', 'Content-Type': 'application/json' }, body: '{}' })
    return { s: res.status, b: await res.json() }
  })
  expect(r.s).toBe(409)
  expect(r.b.error.code).toBe('OPERACAO_BLOQUEADA')
})

test('cadastro de militar com validação e matrícula mascarada para operador', async ({ page, browserName }) => {
  const matricula = `E2E-${browserName.slice(0, 3).toUpperCase()}9001` // única por navegador (mesmo banco E2E)
  await entrar(page, 'gestor.alfa')
  await page.goto('/militares/novo')
  await page.getByLabel(/Nome completo/).fill('Pessoa E2E Fictícia')
  await page.getByLabel(/Nome de guerra/).fill('E2E')
  await page.getByLabel(/Matrícula/).fill('x')
  await page.getByLabel(/Posto\/graduação/).selectOption({ label: 'Cabo' })
  await page.getByLabel(/^Unidade/).selectOption({ label: '— SU-ALFA-1 · Subunidade Alfa-1 (fictícia)' })
  await page.getByRole('button', { name: 'Salvar' }).click()
  await expect(page.getByText(/Matrícula: 4 a 30 caracteres/)).toBeVisible()
  await expect(page.getByLabel(/Matrícula/)).toHaveAttribute('aria-invalid', 'true')
  await page.getByLabel(/Matrícula/).fill(matricula)
  await page.getByRole('button', { name: 'Salvar' }).click()
  await expect(page.getByText('Efetivo cadastrado.')).toBeVisible()
  await sair(page)

  await entrar(page, 'operador.alfa1')
  await page.goto('/militares')
  await page.getByLabel('Buscar').fill('E2E')
  await page.getByRole('button', { name: 'Filtrar' }).click()
  await expect(page.getByRole('cell', { name: '•••001' }).first()).toBeVisible()
  await expect(page.getByText(matricula)).toHaveCount(0)
})
