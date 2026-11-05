import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { ErroApi, api } from './client'

const resp = (status: number, body?: unknown) => new Response(body === undefined ? null : JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } })

describe('cliente HTTP', () => {
  beforeEach(() => { document.cookie = 'XSRF-TOKEN=tok%3D1; path=/' })
  afterEach(() => vi.restoreAllMocks())

  it('envia X-XSRF-TOKEN e cookies de mesma origem em escrita', async () => {
    const f = vi.spyOn(globalThis, 'fetch').mockResolvedValue(resp(201, { id: 1 }))
    await api('/militares', { method: 'POST', body: { a: 1 } })
    const [url, init] = f.mock.calls.at(-1)!
    expect(url).toBe('/api/v1/militares')
    expect((init as RequestInit).credentials).toBe('same-origin')
    expect((init as RequestInit).headers).toMatchObject({ 'X-XSRF-TOKEN': 'tok=1', 'Content-Type': 'application/json' })
  })

  it('não envia token CSRF em GET e monta query sem vazios', async () => {
    const f = vi.spyOn(globalThis, 'fetch').mockResolvedValue(resp(200, { data: [] }))
    await api('/items', { query: { q: 'x y', vazio: '', nulo: null, page: 2 } })
    const [url, init] = f.mock.calls[0]
    expect(url).toBe('/api/v1/items?q=x+y&page=2')
    expect((init as RequestInit).headers).not.toHaveProperty('X-XSRF-TOKEN')
  })

  it('renova CSRF e repete uma vez quando recebe 419', async () => {
    let tentativas = 0
    const f = vi.spyOn(globalThis, 'fetch').mockImplementation(async (u) => {
      if (u === '/api/v1/csrf') return resp(204)
      return ++tentativas === 1 ? resp(419, { error: { code: 'CSRF_INVALIDO', message: 'x' } }) : resp(200, { ok: true })
    })
    await expect(api('/auth/logout', { method: 'POST' })).resolves.toEqual({ ok: true })
    expect(tentativas).toBe(2)
    expect(f.mock.calls.some(([u]) => u === '/api/v1/csrf')).toBe(true)
  })

  it('resposta 200 que não é JSON vira erro tratado', async () => {
    vi.spyOn(globalThis, 'fetch').mockResolvedValue(new Response('<b>Fatal error</b>', { status: 200, headers: { 'Content-Type': 'text/html' } }))
    const e = (await api('/me').catch((x) => x)) as ErroApi & { erro: { fields: Record<string, string[]> } }
    expect(e).toBeInstanceOf(ErroApi)
    expect(e.erro.code).toBe('RESPOSTA_INVALIDA')
  })

  it('converte erro padronizado em ErroApi com campos', async () => {
    vi.spyOn(globalThis, 'fetch').mockResolvedValue(resp(422, { error: { code: 'VALIDACAO', message: 'Dados inválidos.', fields: { nome: ['obrigatório'] } } }))
    const e = (await api('/militares').catch((x) => x)) as ErroApi & { erro: { fields: Record<string, string[]> } }
    expect(e).toBeInstanceOf(ErroApi)
    expect(e.status).toBe(422)
    expect(e.erro.fields.nome).toEqual(['obrigatório'])
  })
})
