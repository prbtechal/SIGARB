import { expect, test, type Page } from '@playwright/test'
import { SENHA, entrar, sair } from './apoio'

// Arquivos SINTÉTICOS gerados em memória.
const PDF = Buffer.from('%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Kids[]/Count 0>>endobj\n% e2e sintetico\ntrailer<</Root 1 0 R>>\n%%EOF\n')
const PDF_ATIVO = Buffer.from('%PDF-1.4\n3 0 obj<</OpenAction<</S/JavaScript/JS(app.alert(1))>>>>endobj\ntrailer<<>>\n%%EOF\n')

async function enviar(page: Page, titulo: string, arquivo: { name: string; mimeType: string; buffer: Buffer }) {
  await page.goto('/documentos/enviar')
  await page.getByLabel(/Tipo de documento/).selectOption({ label: 'Termo' })
  await page.getByLabel(/Unidade responsável/).selectOption({ label: '1CIA · 1ª Companhia' })
  await page.getByLabel(/^Título/).fill(titulo)
  await page.getByLabel(/^Arquivo/).setInputFiles(arquivo)
  await page.getByRole('button', { name: 'Enviar documento' }).click()
}

test('envio, detalhe, download íntegro como anexo, auditoria e isolamento entre unidades', async ({ page, browser, browserName }) => {
  const titulo = `Termo E2E sintético ${browserName}` // único por navegador (mesmo banco E2E)
  await entrar(page, 'gestor.alfa')
  // conteúdo ativo é recusado pelo servidor e o erro aparece no campo
  await enviar(page, 'PDF malicioso E2E', { name: 'ativo.pdf', mimeType: 'application/pdf', buffer: PDF_ATIVO })
  await expect(page.getByText(/conteúdo ativo/)).toBeVisible()
  await expect(page.getByLabel(/^Arquivo/)).toHaveAttribute('aria-invalid', 'true')

  await enviar(page, titulo, { name: 'termo-e2e.pdf', mimeType: 'application/pdf', buffer: PDF })
  await expect(page.getByRole('heading', { level: 1, name: titulo })).toBeVisible()
  await expect(page.getByText('Documento enviado e registrado.')).toBeVisible()
  const codigo = page.url().split('/').pop()!

  const [download] = await Promise.all([page.waitForEvent('download'), page.getByRole('link', { name: /Baixar/ }).click()])
  expect(download.suggestedFilename()).toBe('termo-e2e.pdf')
  const caminho = await download.path()
  const fs = await import('node:fs')
  expect(fs.readFileSync(caminho!).equals(PDF)).toBe(true)

  const r = await page.request.get(`/api/v1/documents/${codigo}/download`)
  expect(r.headers()['content-disposition']).toMatch(/^attachment;/)
  expect(r.headers()['x-content-type-options']).toBe('nosniff')
  expect(r.headers()['content-security-policy']).toContain('sandbox')

  await page.goto('/documentos')
  await expect(page.getByRole('link', { name: titulo })).toBeVisible()
  await sair(page)

  // Outra unidade: não lista, não consulta, não baixa (mesmo com o código em mãos)
  const ctx = await browser.newContext()
  const outra = await ctx.newPage()
  await entrar(outra, 'gestor.bravo')
  await outra.goto('/documentos')
  await expect(outra.getByText(titulo)).toHaveCount(0)
  await outra.goto(`/documentos/${codigo}`)
  await expect(outra.getByRole('alert')).toContainText('não encontrado ou fora do seu escopo')
  expect((await outra.request.get(`/api/v1/documents/${codigo}/download`)).status()).toBe(404)
  await ctx.close()

  await entrar(page, 'auditor.demo')
  await page.goto('/auditoria')
  await page.getByLabel('Ação (prefixo)').fill('documento.')
  await page.getByRole('button', { name: 'Filtrar' }).click()
  for (const a of ['documento.enviado', 'documento.download', 'documento.acesso_negado']) {
    await expect(page.getByRole('cell', { name: a }).first()).toBeVisible()
  }
  await expect(page.getByText(/cadeia de hash íntegra/)).toBeVisible()
})

test('sessão revogada pelo administrador deixa de baixar imediatamente', async ({ page, browser }) => {
  await entrar(page, 'operador.alfa1')
  await page.goto('/documentos/enviar')
  await page.getByLabel(/Tipo de documento/).selectOption({ label: 'Registro fotográfico' })
  await page.getByLabel(/Unidade responsável/).selectOption({ label: '— 1PEL-1CIA · 1º Pelotão da 1ª Companhia' })
  await page.getByLabel(/^Título/).fill('Foto E2E')
  await page.getByLabel(/^Arquivo/).setInputFiles({ name: 'foto.png', mimeType: 'image/png', buffer: Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==', 'base64') })
  await page.getByRole('button', { name: 'Enviar documento' }).click()
  await expect(page.getByRole('heading', { level: 1, name: 'Foto E2E' })).toBeVisible()
  const codigo = page.url().split('/').pop()!
  expect((await page.request.get(`/api/v1/documents/${codigo}/download`)).status()).toBe(200)

  // admin revoga todas as sessões do operador (outro navegador)
  const ctx = await browser.newContext()
  const adm = await ctx.newPage()
  await entrar(adm, 'admin.demo')
  const id = await adm.evaluate(async () => (await (await fetch('/api/v1/users?q=operador.alfa1', { headers: { Accept: 'application/json' } })).json()).data[0].id)
  const rev = await adm.evaluate(async (uid) => {
    const xsrf = decodeURIComponent(document.cookie.match(/XSRF-TOKEN=([^;]+)/)![1])
    return (await fetch(`/api/v1/users/${uid}/sessions/revoke`, { method: 'POST', headers: { 'X-XSRF-TOKEN': xsrf, Accept: 'application/json' } })).status
  }, id)
  expect(rev).toBe(200)
  // ADMIN não tem acesso a documentos pela matriz proposta
  expect((await adm.request.get(`/api/v1/documents/${codigo}/download`)).status()).toBe(404)
  await ctx.close()

  const depois = await page.request.get(`/api/v1/documents/${codigo}/download`, { headers: { Accept: 'application/json' } })
  expect(depois.status()).toBe(401)
  await page.goto(`/documentos/${codigo}`)
  await expect(page).toHaveURL(/\/login$/)
  void SENHA
})
