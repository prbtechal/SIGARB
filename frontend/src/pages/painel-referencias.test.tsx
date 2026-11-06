import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'
import App from '../App'

const json = (status: number, body: unknown) => new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } })
const perfil = (permissoes: string[]) => ({ usuario: { id: 1, nome: 'Teste', login: 't', militar: null }, papeis: [], permissoes, operacoes: [], ambiente: 'DEMONSTRACAO' })
function rotas(m: Record<string, (init?: RequestInit) => Response>) {
  return vi.spyOn(globalThis, 'fetch').mockImplementation(async (url, init) => {
    const u = String(url).replace(/\?.*$/, '')
    return (m[`${init?.method ?? 'GET'} ${u}`] ?? m[u])?.(init) ?? new Response(null, { status: 204 })
  })
}
afterEach(() => { vi.restoreAllMocks(); window.history.pushState({}, '', '/') })

describe('painel e listas de referência', () => {
  it('painel mostra só os blocos devolvidos pelo servidor, com gráfico acessível e alerta de integridade', async () => {
    rotas({
      '/api/v1/me': () => json(200, perfil(['estoque.ver', 'auditoria.ver'])),
      '/api/v1/dashboard': () => json(200, { gerado_em: '2026-11-06T00:00:00Z', pendencias_institucionais: 12, operacoes_bloqueadas: 11,
        estoque: { locais: 2, itens_individuais_disponiveis: 3, lotes_com_saldo: 1, quantidade_em_lotes: 500, lotes_validade_90_dias: 0, movimentacoes_7_dias: 4, por_natureza: [{ rotulo: 'MUNICAO', valor: 500 }] },
        ultimas_movimentacoes: [], auditoria: { negados_24h: 2, falhas_integridade_documento_30_dias: 1, cadeia_integra: false, atividade_recente: [] } }),
    })
    render(<App />)
    expect(await screen.findByRole('figure', { name: 'Quantidade física por natureza' })).toBeInTheDocument()
    expect(screen.getByRole('row', { name: /Munição/ })).toHaveTextContent('100%')
    expect(screen.getByText(/Cadeia de auditoria inconsistente/)).toBeInTheDocument()
    expect(screen.getByText(/1 falha\(s\) de verificação de arquivo/)).toBeInTheDocument()
    expect(screen.queryByRole('figure', { name: /Documentos por situação/ })).not.toBeInTheDocument()
    expect(screen.queryByText(/acautel/i)).not.toBeInTheDocument()
  })

  it('listas: desativar exige justificativa e informa registros em uso; menu só com permissão', async () => {
    let enviado: RequestInit | undefined
    rotas({
      '/api/v1/me': () => json(200, perfil(['referencias.gerir'])),
      '/api/v1/reference-lists': () => json(200, { data: [{ tipo: 'tipos-documento', rotulo: 'Tipos de documento', possui_codigo: true }] }),
      '/api/v1/reference-lists/tipos-documento': () => json(200, { data: [{ id: 3, codigo: 'OFICIO', nome: 'Ofício', ativo: true, em_uso: 4, desativado_em: null }], meta: { page: 1, per_page: 20, total: 1, last_page: 1 } }),
      'POST /api/v1/reference-lists/tipos-documento/3/deactivate': (init) => { enviado = init; return json(200, {}) },
    })
    window.history.pushState({}, '', '/referencias')
    render(<App />)
    const u = userEvent.setup()
    await u.click(await screen.findByRole('button', { name: /Desativar Ofício/ }))
    const dlg = await screen.findByRole('dialog')
    expect(dlg).toHaveTextContent('4 registro(s) que já o usam não são alterados')
    const confirmar = screen.getByRole('button', { name: 'Confirmar' })
    expect(confirmar).toBeDisabled()
    await u.type(screen.getByLabelText(/Justificativa/), 'Substituído pelo tipo oficial')
    await u.click(confirmar)
    await waitFor(() => expect(enviado).toBeDefined())
    expect(JSON.parse(String(enviado!.body))).toEqual({ justificativa: 'Substituído pelo tipo oficial' })
    expect(screen.getByRole('navigation', { name: 'Navegação principal' })).toHaveTextContent('Listas de referência')
  })
})
