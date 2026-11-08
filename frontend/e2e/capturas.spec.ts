import { test } from '@playwright/test'
import { entrar } from './apoio'

// Capturas de tela para documentação/PR (antes/depois). Só roda com SIGARB_CAPTURAS=<diretório>.
const DIR = process.env.SIGARB_CAPTURAS
const TELAS: [string, string, string][] = [
  ['admin.demo', '/', 'painel'],
  ['gestor.alfa', '/estoque', 'saldos'],
  ['gestor.alfa', '/movimentacoes', 'movimentacoes'],
  ['gestor.alfa', '/militares', 'militares'],
  ['gestor.alfa', '/documentos', 'documentos'],
  ['admin.demo', '/organizacao', 'organizacao'],
  ['admin.demo', '/referencias', 'referencias'],
  ['auditor.demo', '/auditoria', 'auditoria'],
  ['gestor.alfa', '/suporte', 'suporte'],
  ['admin.demo', '/suporte/atendimento', 'suporte-atendimento'],
  ['gestor.alfa', '/operacoes', 'operacoes'],
  ['gestor.alfa', '/operacoes/nova?tipo=TRANSFERENCIA', 'nova-operacao'],
  ['gestor.alfa', '/acautelamentos', 'acautelamentos'],
  ['gestor.alfa', '/acautelamentos/novo', 'novo-acautelamento'],
  ['gestor.alfa', '/inventarios', 'inventarios'],
  ['gestor.alfa', '/alertas', 'alertas'],
  ['gestor.alfa', '/materiais', 'materiais'],
  ['gestor.alfa', '/militares/4', 'perfil-efetivo'],
  ['admin.demo', '/organizacao', 'organizacao'],
  ['auditor.demo', '/relatorios', 'relatorios'],
  ['gestor.alfa', '/estoque/itens', 'itens-validades'], // CP-12
  ['gestor.alfa', '/estoque/vinculados', 'material-vinculado'],
  ['gestor.alfa', '/movimentacoes/nova-entrada', 'nova-entrada'],
  ['armeiro.alfa', '/dados', 'importar-exportar'], // CP-13
  ['armeiro.alfa', '/operacoes', 'operacoes-armeiro'],
  ['armeiro.alfa', '/operacoes/nova?tipo=BAIXA', 'baixa-armeiro'], // CP-14
  ['admin.demo', '/usuarios', 'usuarios-delegacao'],
]
for (const [vw, nome] of [[{ width: 1440, height: 900 }, 'desktop'], [{ width: 390, height: 844 }, 'celular']] as const) {
  test(`capturas ${nome}`, async ({ browser }) => {
    test.skip(!DIR, 'defina SIGARB_CAPTURAS')
    test.setTimeout(240_000)
    const login = await browser.newContext({ viewport: vw, locale: 'pt-BR' })
    const lp = await login.newPage(); await lp.goto('/login'); await lp.screenshot({ path: `${DIR}/${nome}-login.png` }); await login.close()
    for (const usuario of [...new Set(TELAS.map((t) => t[0]))]) {
      const ctx = await browser.newContext({ viewport: vw, locale: 'pt-BR' })
      const page = await ctx.newPage()
      await entrar(page, usuario)
      for (const [, rota, arq] of TELAS.filter((t) => t[0] === usuario)) {
        await page.goto(rota)
        await page.locator('#titulo-pagina').waitFor()
        await page.waitForLoadState('networkidle')
        await page.screenshot({ path: `${DIR}/${nome}-${arq}.png`, fullPage: nome === 'desktop' })
        if (arq === 'suporte-atendimento' && await page.getByRole('link', { name: /^SUP-/ }).count()) {
          await page.getByRole('link', { name: /^SUP-/ }).first().click()
          await page.getByRole('list', { name: 'Conversa do chamado' }).waitFor()
          await page.waitForLoadState('networkidle')
          await page.screenshot({ path: `${DIR}/${nome}-chamado.png`, fullPage: nome === 'desktop' })
        }
      }
      await ctx.close()
    }
  })
}
