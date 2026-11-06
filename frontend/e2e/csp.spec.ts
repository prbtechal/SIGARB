import { expect, test } from '@playwright/test'
import { CSP_PRODUCAO } from '../csp'
import { entrar } from './apoio'

const PAGINAS = ['/', '/estoque', '/movimentacoes', '/documentos', '/militares', '/materiais', '/organizacao', '/operacoes', '/auditoria', '/sessoes']

test('build de produção roda sob CSP estrita sem nenhuma violação', async ({ page, isMobile }) => {
  test.skip(isMobile, 'somente desktop')
  const violacoes: string[] = []
  page.on('console', (m) => { if (/Content.Security.Policy|CSP/i.test(m.text())) violacoes.push(m.text()) })
  await page.addInitScript(() => document.addEventListener('securitypolicyviolation', (e) => console.error(`CSP violation: ${e.violatedDirective} ${e.blockedURI}`)))
  const r = await page.goto('/login')
  const csp = r!.headers()['content-security-policy']
  expect(csp).toBe(CSP_PRODUCAO)
  expect(csp).not.toContain('unsafe-inline')
  expect(csp).not.toContain('unsafe-eval')
  await entrar(page, 'auditor.demo')
  for (const p of PAGINAS) {
    await page.goto(p)
    await expect(page.locator('#titulo-pagina')).toBeVisible()
  }
  expect(violacoes).toEqual([])
  // Sonda negativa: prova que a política está ATIVA e que o detector funciona.
  const executou = await page.evaluate(async () => {
    const s = document.createElement('script')
    s.textContent = 'window.__sondaCsp = true'
    document.body.appendChild(s)
    await new Promise((r) => setTimeout(r, 200))
    return (window as unknown as { __sondaCsp?: boolean }).__sondaCsp === true
  })
  expect(executou).toBe(false)
  await expect.poll(() => violacoes.length).toBeGreaterThan(0)
})

test('API responde com CSP que impede renderização', async ({ request }) => {
  const r = await request.get('/api/v1/me', { headers: { Accept: 'application/json' } })
  expect(r.headers()['content-security-policy']).toContain("default-src 'none'")
})
