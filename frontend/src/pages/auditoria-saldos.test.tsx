import { render, screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'
import App from '../App'

const json = (status: number, body: unknown) => new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } })
const perfil = (permissoes: string[]) => ({
  usuario: { id: 1, nome: 'Teste Fictício', login: 't', militar: null }, papeis: [{ papel: 'GESTOR', papel_nome: 'Gestor', unidade: { id: 1, sigla: 'UN' } }],
  permissoes, operacoes: [], ambiente: 'DEMONSTRACAO',
})
const meta = { page: 1, per_page: 20, total: 1, last_page: 1 }
function roteador(rotas: Record<string, (init?: RequestInit) => Response>) {
  return vi.spyOn(globalThis, 'fetch').mockImplementation(async (url, init) => {
    const u = String(url).replace(/\?.*$/, '')
    const h = rotas[`${init?.method ?? 'GET'} ${u}`] ?? rotas[u]
    return h ? h(init) : new Response(null, { status: 204 })
  })
}
afterEach(() => { vi.restoreAllMocks(); window.history.pushState({}, '', '/') })

const evento = {
  id: 7, ocorrido_em: '2026-11-10T13:05:00+00:00', acao: 'usuario.atualizado', acao_rotulo: 'Usuário alterado', resultado: 'SUCESSO',
  frase: 'Ana Admin alterou o usuário: Usuário José Filho (CPF ***.***.***-25)', recurso_rotulo: 'Usuário José Filho (CPF ***.***.***-25)', recurso_link: null,
  unidade_rotulo: null, usuario: { id: 3, nome: 'Ana Admin', login: '52998224725' }, recurso_tipo: 'usuario', recurso_id: 9, unidade_id: null,
  ip: '10.0.0.1', request_id: 'req-1', dados: { antes: { nome: 'José' }, depois: { nome: 'José Filho' }, justificativa: 'Correção do nome' }, hash: 'abc123',
}

describe('auditoria legível', () => {
  it('mostra a frase, a legenda e o painel com Quando/Quem/O quê/Dados e JSON recolhido', async () => {
    roteador({
      '/api/v1/me': () => json(200, perfil(['auditoria.ver'])),
      '/api/v1/audit-events': () => json(200, { data: [evento], meta }),
      '/api/v1/audit-events/actions': () => json(200, { data: [{ codigo: 'usuario.atualizado', rotulo: 'Usuário alterado' }] }),
      '/api/v1/audit-events/integrity': () => json(403, { error: { code: 'PROIBIDO', message: 'x' } }),
    })
    window.history.pushState({}, '', '/auditoria')
    render(<App />)
    const linha = await screen.findByRole('button', { name: /Ana Admin alterou o usuário/ })
    expect(screen.getByLabelText('Legenda dos resultados')).toHaveTextContent('Negado')
    expect(screen.getByRole('option', { name: 'Usuário alterado' })).toBeInTheDocument()
    await userEvent.setup().click(linha)
    const painel = await screen.findByRole('dialog')
    for (const h of ['Quando', 'Quem', 'O quê', 'Dados']) expect(within(painel).getByRole('heading', { name: h })).toBeInTheDocument()
    expect(painel).toHaveTextContent('***.***.***-25')
    expect(painel).not.toHaveTextContent('52998224725')
    expect(painel).toHaveTextContent('usuario.atualizado') // código técnico original, pequeno
    expect(within(painel).getByRole('row', { name: /Nome José José Filho/ })).toBeInTheDocument()
    expect(painel).toHaveTextContent('Justificativa')
    expect(within(painel).getByText('Dados técnicos').closest('details')).not.toHaveAttribute('open')
  })
})

describe('detalhe do material no estoque', () => {
  const material = {
    id: 5, codigo: 'PST-01', nome: 'Pistola fictícia', modelo: 'M1', modo_controle: 'INDIVIDUAL', unidade_medida: 'UN', ativo: true,
    categoria: { id: 1, nome: 'Armamento', natureza: 'ARMAMENTO' }, fabricante: { id: 1, nome: 'Fab' }, calibre: { id: 1, nome: '.40' }, tipo: { id: 1, nome: 'Pistola', perfil: 'ARMA' },
    saldos: [{ local: { id: 2, codigo: 'RES-A', nome: 'Reserva A' }, unidade: { id: 1, sigla: 'UN' }, material: { id: 5, codigo: 'PST-01', nome: 'Pistola fictícia', modo_controle: 'INDIVIDUAL', categoria: 'Armamento', natureza: 'ARMAMENTO', unidade_medida: 'UN' }, lote: null, quantidade: 3, reservada: 0, disponivel: 3 }],
    total: { quantidade: 3, reservada: 0, disponivel: 3 },
  }
  const rotas = (pode: Record<string, boolean>) => ({
    '/api/v1/me': () => json(200, perfil(['estoque.ver'])),
    '/api/v1/stock/materials/5': () => json(200, { ...material, pode }),
    '/api/v1/stock/individual-items': () => json(200, { data: [{ id: 11, identificador: 'ID-1', numero_serie: 'SER-9', situacao: 'DISPONIVEL', estado_conservacao: 'BOM', local: { id: 2, codigo: 'RES-A' }, unidade: { id: 1, sigla: 'UN' } }], meta }),
    '/api/v1/movements': () => json(200, { data: [{ id: 1182, tipo: 'ENTRADA', ocorrido_em: '2026-11-10T13:05:00Z', responsavel: { id: 1, nome: 'Ana' }, local_origem: null, local_destino: { id: 2, codigo: 'RES-A' }, documento_referencia: null, itens_count: 1, estornada: false, estorno_id: null }], meta }),
  })

  it('mostra catálogo, saldo, itens e histórico com link; só leitura para quem não opera', async () => {
    roteador(rotas({ editar_catalogo: false, entrada: false, operar: false, inventario: false }))
    window.history.pushState({}, '', '/estoque/materiais/5')
    render(<App />)
    expect(await screen.findByRole('heading', { level: 1, name: 'Pistola fictícia' })).toBeInTheDocument()
    expect(screen.getByText('.40')).toBeInTheDocument()
    expect(screen.getByRole('table', { name: 'Saldo por local' })).toHaveTextContent('RES-A')
    expect(await screen.findByText('SER-9')).toBeInTheDocument()
    expect(await screen.findByRole('link', { name: '#1182' })).toHaveAttribute('href', '/movimentacoes/1182')
    expect(screen.queryByRole('button', { name: /Editar catálogo/ })).not.toBeInTheDocument()
    expect(screen.queryByRole('link', { name: /Registrar entrada/ })).not.toBeInTheDocument()
  })

  it('mostra atalhos e edita o catálogo quando o servidor permite', async () => {
    const put = vi.fn(() => json(200, material))
    roteador({ ...rotas({ editar_catalogo: true, entrada: true, operar: true, inventario: false }), 'PUT /api/v1/items/5': put })
    window.history.pushState({}, '', '/estoque/materiais/5')
    render(<App />)
    const u = userEvent.setup()
    await u.click(await screen.findByRole('button', { name: /Editar catálogo/ }))
    expect(screen.getByRole('link', { name: /Registrar entrada/ })).toHaveAttribute('href', '/movimentacoes/nova-entrada')
    expect(screen.getByRole('link', { name: /Nova operação/ })).toBeInTheDocument()
    const nome = screen.getByLabelText(/^Nome/)
    await u.clear(nome); await u.type(nome, 'Pistola nova')
    await u.click(screen.getByRole('button', { name: 'Salvar' }))
    expect(put).toHaveBeenCalled()
    expect(JSON.parse(String((put.mock.calls[0] as unknown as [RequestInit])[0].body))).toEqual({ nome: 'Pistola nova', modelo: 'M1' })
  })

  it('/saldos redireciona para a tela de saldos e o material é um link', async () => {
    roteador({
      '/api/v1/me': () => json(200, perfil(['estoque.ver'])), '/api/v1/locations': () => json(200, { data: [] }),
      '/api/v1/stock/balances': () => json(200, { data: material.saldos, meta }),
    })
    window.history.pushState({}, '', '/saldos')
    render(<App />)
    expect(await screen.findByRole('link', { name: 'Pistola fictícia' })).toHaveAttribute('href', '/estoque/materiais/5')
    expect(window.location.pathname).toBe('/estoque')
  })
})
