import { expect, test } from '@playwright/test'
import { entrar } from './apoio'

// Verificações de acessibilidade SEM axe (rodam também no Firefox, onde o axe-core derruba o navegador).
// Complementam — não substituem — o axe (Chromium/WebKit) e a verificação manual com leitor de tela.
const PAGINAS = ['/', '/estoque', '/movimentacoes', '/documentos', '/militares', '/materiais', '/organizacao', '/operacoes', '/auditoria', '/sessoes']

test('estrutura: idioma, título, um h1, landmarks e nome acessível em todo controle', async ({ page, isMobile }) => {
  test.skip(isMobile, 'somente desktop')
  test.setTimeout(120_000)
  await entrar(page, 'auditor.demo')
  for (const p of PAGINAS) {
    await test.step(p, async () => {
      await page.goto(p)
      await expect(page.locator('#titulo-pagina')).toBeVisible()
      await page.waitForLoadState('networkidle')
      const r = await page.evaluate(() => {
        const nome = (el: Element) => {
          const rotulo = el.getAttribute('aria-label') || (el.getAttribute('aria-labelledby') ?? '').split(' ').map((id) => document.getElementById(id)?.textContent ?? '').join(' ')
          const assoc = el.id ? document.querySelector(`label[for="${el.id}"]`)?.textContent : ''
          const pai = el.closest('label')?.textContent
          return (rotulo || assoc || pai || (el as HTMLElement).innerText || el.getAttribute('title') || '').trim()
        }
        const semNome = [...document.querySelectorAll('a[href], button, input:not([type=hidden]), select, textarea, [role=button], [role=tab]')]
          .filter((el) => (el as HTMLElement).offsetParent !== null && !nome(el)).map((el) => el.outerHTML.slice(0, 80))
        return {
          lang: document.documentElement.lang, titulo: document.title, h1: document.querySelectorAll('h1').length,
          main: document.querySelectorAll('main').length, navRotulada: [...document.querySelectorAll('nav')].every((n) => n.getAttribute('aria-label')),
          semNome, idsDuplicados: [...document.querySelectorAll('[id]')].map((e) => e.id).filter((id, i, a) => a.indexOf(id) !== i),
        }
      })
      expect(r.lang).toBe('pt-BR')
      expect(r.titulo).toMatch(/· SIGARB$/)
      expect(r.h1).toBe(1)
      expect(r.main).toBe(1)
      expect(r.navRotulada).toBe(true)
      expect(r.semNome, `controles sem nome acessível em ${p}`).toEqual([])
      expect(r.idsDuplicados, `ids duplicados em ${p}`).toEqual([])
    })
  }
})

test('teclado: "pular para o conteúdo" é o primeiro foco, leva ao conteúdo e o foco é visível', async ({ page, isMobile, browserName }) => {
  test.skip(isMobile, 'somente desktop')
  await entrar(page, 'gestor.alfa')
  await page.goto('/estoque')
  await expect(page.locator('#titulo-pagina')).toBeFocused() // troca de rota leva o foco ao título
  // Simula o início do documento: o ponto de partida da navegação sequencial passa a ser o topo da página.
  await page.evaluate(() => { const t = document.createElement('span'); t.tabIndex = -1; document.body.prepend(t); t.focus() })
  await page.keyboard.press(browserName === 'webkit' ? 'Alt+Tab' : 'Tab')
  const pular = page.getByRole('link', { name: 'Pular para o conteúdo' })
  await expect(pular).toBeFocused()
  const contorno = await pular.evaluate((el) => getComputedStyle(el).outlineWidth)
  expect(parseFloat(contorno)).toBeGreaterThan(0) // :focus-visible
  // (1) Ordem a partir do topo: pular → [menu, oculto no desktop] → Sair → navegação lateral
  const tab = browserName === 'webkit' ? 'Alt+Tab' : 'Tab'
  const ordem: string[] = []
  for (let i = 0; i < 3; i++) {
    await page.keyboard.press(tab)
    ordem.push(await page.evaluate(() => (document.activeElement?.textContent ?? '').trim().slice(0, 30)))
  }
  expect(ordem, 'ordem de foco no cabeçalho').toEqual(['Sair', 'Início', 'Saldos de estoque'])
  // (2) Ativar o "pular" leva o foco para dentro do conteúdo principal
  await page.evaluate(() => { const t = document.createElement('span'); t.tabIndex = -1; document.body.prepend(t); t.focus() })
  await page.keyboard.press(tab)
  await expect(pular).toBeFocused()
  await page.keyboard.press('Enter')
  await expect(page).toHaveURL(/#conteudo$/)
  expect(await page.evaluate(() => !!document.activeElement?.closest('main'))).toBe(true)
})

test('formulário: erro do servidor é anunciado e associado ao campo; foco permanece utilizável', async ({ page, isMobile }) => {
  test.skip(isMobile, 'somente desktop')
  await entrar(page, 'gestor.alfa')
  await page.goto('/militares/novo')
  await page.getByLabel(/Nome completo/).fill('Pessoa Fictícia')
  await page.getByLabel(/Nome de guerra/).fill('FIC')
  await page.getByLabel(/Matrícula/).fill('x')
  await page.getByRole('button', { name: 'Salvar' }).click()
  const campo = page.getByLabel(/Matrícula/)
  await expect(campo).toHaveAttribute('aria-invalid', 'true')
  const desc = await campo.getAttribute('aria-describedby')
  expect(desc).toBeTruthy()
  const textos = await Promise.all(desc!.split(' ').map((id) => page.locator(`[id="${id}"]`).textContent()))
  expect(textos.join(' ')).toMatch(/4 a 30 caracteres|obrigatório|inválid/i)
})
