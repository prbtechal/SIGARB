import AxeBuilder from '@axe-core/playwright'
import { expect, test } from '@playwright/test'
import { entrar } from './apoio'

const PAGINAS = ['/', '/estoque', '/movimentacoes', '/documentos', '/militares', '/materiais', '/organizacao', '/operacoes', '/usuarios', '/auditoria', '/sessoes', '/suporte', '/suporte/novo']

const axe = (page: import('@playwright/test').Page) => new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa'])

test('axe: nenhuma violação séria/crítica WCAG 2.x A/AA nas telas principais', async ({ page, browserName }) => {
  // LIMITAÇÃO CONHECIDA (docs/desenvolvimento/ACESSIBILIDADE.md): no Firefox 159 + Playwright 1.65 o processo de
  // conteúdo cai durante a injeção do axe-core (modos runPartial e legacy). Sem o axe: 30 navegações, 0 crashes.
  // A varredura automática roda em Chromium e WebKit; no Firefox valem os fluxos funcionais e os testes de teclado/zoom.
  test.skip(browserName === 'firefox', 'axe-core derruba o Firefox 159 sob Playwright 1.65 (problema da ferramenta)')
  test.setTimeout(180_000)
  await page.goto('/login')
  const login = await axe(page).analyze()
  expect(login.violations.filter((v) => ['serious', 'critical'].includes(v.impact ?? ''))).toEqual([])
  await entrar(page, 'auditor.demo')
  for (const p of PAGINAS) {
    await test.step(`axe ${p}`, async () => {
      await page.goto(p)
      await expect(page.locator('#titulo-pagina')).toBeVisible()
      await page.waitForLoadState('networkidle')
      const r = await axe(page).analyze()
      const graves = r.violations.filter((v) => ['serious', 'critical'].includes(v.impact ?? ''))
      expect(graves.map((v) => `${p}: ${v.id} (${v.nodes.length})`)).toEqual([])
    })
  }
})

test('responsivo: menu em gaveta, tabelas em cartões e sem rolagem horizontal', async ({ page, isMobile }) => {
  test.skip(!isMobile, 'somente no projeto celular')
  await entrar(page, 'gestor.alfa')
  const menu = page.getByRole('button', { name: 'Menu', exact: true })
  await expect(menu).toHaveAttribute('aria-expanded', 'false')
  await menu.click()
  await expect(menu).toHaveAttribute('aria-expanded', 'true')
  await page.getByRole('link', { name: 'Saldos de estoque' }).click()
  await expect(page.getByRole('heading', { level: 1, name: 'Saldos de estoque' })).toBeVisible()
  await expect(page.locator('thead')).toHaveCSS('position', 'absolute')
  const td = page.locator('td[data-rotulo="Disponível"]').first()
  await expect(td).toBeVisible()
  const larguras = await page.evaluate(() => [document.documentElement.scrollWidth, window.innerWidth])
  expect(larguras[0]).toBeLessThanOrEqual(larguras[1])
})

test('zoom 200%: conteúdo continua utilizável sem rolagem horizontal', async ({ page, isMobile }) => {
  test.skip(isMobile, 'somente desktop')
  await page.setViewportSize({ width: 640, height: 800 }) // equivale a 1280px a 200%
  await entrar(page, 'gestor.alfa')
  await page.goto('/movimentacoes/nova-entrada')
  await expect(page.getByLabel(/Local de destino/)).toBeVisible()
  const [sw, iw] = await page.evaluate(() => [document.documentElement.scrollWidth, window.innerWidth])
  expect(sw).toBeLessThanOrEqual(iw)
})

test('diálogo: foco vai para dentro, Esc fecha e devolve o foco ao gatilho', async ({ page, isMobile }) => {
  test.skip(isMobile, 'somente desktop')
  await entrar(page, 'gestor.alfa')
  await page.goto('/materiais')
  const gatilho = page.getByRole('button', { name: 'Cadastrar material' })
  await gatilho.focus()
  await page.keyboard.press('Enter')
  const dlg = page.getByRole('dialog', { name: 'Cadastrar material' })
  await expect(dlg).toBeVisible()
  expect(await dlg.evaluate((d) => d.contains(document.activeElement))).toBe(true)
  await page.keyboard.press('Escape')
  await expect(dlg).toBeHidden()
  await expect(gatilho).toBeFocused()
})
