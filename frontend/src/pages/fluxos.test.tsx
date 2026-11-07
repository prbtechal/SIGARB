import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'
import App from '../App'

const json = (status: number, body: unknown) => new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } })
const perfil = (permissoes: string[]) => ({
  usuario: { id: 1, nome: 'Teste Fictício', login: 't', militar: null }, papeis: [{ papel: 'OPERADOR', papel_nome: 'Operador', unidade: { id: 1, sigla: 'UN' } }],
  permissoes, operacoes: [{ codigo: 'ENTRADA', estado: 'HABILITADA', pendencia: 'PE-01', rotulo: 'Entrada' }, { codigo: 'ACAUTELAMENTO', estado: 'BLOQUEADA', pendencia: 'P-06', rotulo: 'Acautelamento' }], ambiente: 'DEMONSTRACAO',
})

function roteador(rotas: Record<string, (init?: RequestInit) => Response>) {
  return vi.spyOn(globalThis, 'fetch').mockImplementation(async (url, init) => {
    const u = String(url).replace(/\?.*$/, '')
    const h = rotas[`${init?.method ?? 'GET'} ${u}`] ?? rotas[u]
    return h ? h(init) : new Response(null, { status: 204 })
  })
}

afterEach(() => { vi.restoreAllMocks(); window.history.pushState({}, '', '/') })

describe('fluxos', () => {
  it('login inválido mostra mensagem do servidor e limpa a senha', async () => {
    roteador({
      '/api/v1/me': () => json(401, { error: { code: 'NAO_AUTENTICADO', message: 'x' } }),
      'POST /api/v1/auth/login': () => json(401, { error: { code: 'CREDENCIAIS_INVALIDAS', message: 'Identificador ou senha inválidos.' } }),
    })
    window.history.pushState({}, '', '/login')
    render(<App />)
    const u = userEvent.setup()
    await u.type(await screen.findByLabelText(/Identificador/), 'fulano')
    await u.type(screen.getByLabelText(/Senha/), 'errada')
    await u.click(screen.getByRole('button', { name: 'Entrar' }))
    expect(await screen.findByRole('alert')).toHaveTextContent('Identificador ou senha inválidos.')
    expect(screen.getByLabelText(/Senha/)).toHaveValue('')
  })

  it('menu e rotas respeitam permissões; tela sem permissão mostra acesso negado', async () => {
    roteador({ '/api/v1/me': () => json(200, perfil(['estoque.ver'])), '/api/v1/dashboard': () => json(200, { pendencias_institucionais: 12, operacoes_bloqueadas: 11 }) })
    window.history.pushState({}, '', '/auditoria')
    render(<App />)
    expect(await screen.findByRole('heading', { name: 'Acesso negado' })).toBeInTheDocument()
    const nav = screen.getByRole('navigation', { name: 'Navegação principal' })
    expect(nav).toHaveTextContent('Saldos de estoque')
    expect(nav).not.toHaveTextContent('Auditoria')
    expect(nav).not.toHaveTextContent('Usuários')
  })

  it('operações bloqueadas aparecem com a pendência, sem ação disponível', async () => {
    roteador({ '/api/v1/me': () => json(200, perfil([])) })
    window.history.pushState({}, '', '/operacoes')
    render(<App />)
    expect(await screen.findByText('Indisponível no momento.')).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /acautel/i })).not.toBeInTheDocument()
  })

  it('nova entrada: campos mudam conforme o modo de controle e envia Idempotency-Key', async () => {
    let enviado: RequestInit | undefined
    roteador({
      '/api/v1/me': () => json(200, perfil(['estoque.ver', 'estoque.entrada'])),
      '/api/v1/locations': () => json(200, { data: [{ id: 5, codigo: 'RES-1', nome: 'Reserva', tipo: 'RESERVA', ativo: true, unidade: { id: 1, sigla: 'UN', nome: 'U' } }] }),
      '/api/v1/items': () => json(200, { data: [
        { id: 7, codigo: 'IND', nome: 'Individual', modo_controle: 'INDIVIDUAL', categoria: { id: 1, nome: 'c', natureza: 'ARMAMENTO' } },
        { id: 8, codigo: 'LOT', nome: 'Lote', modo_controle: 'LOTE', categoria: { id: 1, nome: 'c', natureza: 'MUNICAO' } },
      ], meta: { page: 1, per_page: 100, total: 2, last_page: 1 } }),
      'POST /api/v1/movements': (init) => { enviado = init; return json(422, { error: { code: 'VALIDACAO', message: 'Dados inválidos.', fields: { 'itens.0.lote_codigo': ['Obrigatório.'] } } }) },
    })
    window.history.pushState({}, '', '/movimentacoes/nova-entrada')
    render(<App />)
    const u = userEvent.setup()
    await u.selectOptions(await screen.findByLabelText(/Local de destino/), '5')
    await u.selectOptions(screen.getByLabelText(/Material \(item 1\)/), '7')
    expect(screen.getByLabelText(/Identificador patrimonial/)).toBeInTheDocument()
    await u.selectOptions(screen.getByLabelText(/Material \(item 1\)/), '8')
    expect(screen.queryByLabelText(/Identificador patrimonial/)).not.toBeInTheDocument()
    await u.type(screen.getByLabelText(/Documento de referência/), 'DOC-1')
    await u.type(screen.getByLabelText(/^Origem/), 'Origem fictícia')
    await u.click(screen.getByRole('button', { name: 'Revisar entrada' }))
    await u.click(await screen.findByRole('button', { name: 'Confirmar e registrar' }))
    await waitFor(() => expect(enviado).toBeDefined())
    expect((enviado!.headers as Record<string, string>)['Idempotency-Key']).toMatch(/^[0-9a-f-]{36}$/)
    expect(JSON.parse(String(enviado!.body)).itens[0]).toMatchObject({ material_id: 8, quantidade: 1 })
    expect(await screen.findByText('Obrigatório.')).toBeInTheDocument()
  })
})
