import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'
import App from '../App'
import { agoraMaceio } from './NovaEntradaPage'

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
      'POST /api/v1/auth/login': () => json(401, { error: { code: 'CREDENCIAIS_INVALIDAS', message: 'CPF ou senha inválidos.' } }),
    })
    window.history.pushState({}, '', '/login')
    render(<App />)
    const u = userEvent.setup()
    await u.type(await screen.findByLabelText(/^CPF/), 'fulano')
    await u.type(screen.getByLabelText(/Senha/), 'errada')
    await u.click(screen.getByRole('button', { name: 'Entrar' }))
    expect(await screen.findByRole('alert')).toHaveTextContent('CPF ou senha inválidos.')
    expect(screen.getByLabelText(/Senha/)).toHaveValue('')
  })

  it('login com verificação em duas etapas pede o código antes de entrar', async () => {
    let codigo: unknown
    roteador({
      '/api/v1/me': () => json(401, { error: { code: 'NAO_AUTENTICADO', message: 'x' } }),
      'POST /api/v1/auth/login': () => json(200, { mfa_requerido: true }),
      'POST /api/v1/auth/mfa': (init) => { codigo = JSON.parse(String(init?.body)).codigo; return json(200, perfil([])) },
      '/api/v1/dashboard': () => json(200, {}),
    })
    window.history.pushState({}, '', '/login')
    render(<App />)
    const u = userEvent.setup()
    await u.type(await screen.findByLabelText(/^CPF/), '52998224725')
    await u.type(screen.getByLabelText(/Senha/), 'Qualquer-1')
    await u.click(screen.getByRole('button', { name: 'Entrar' }))
    await u.type(await screen.findByLabelText(/Código de verificação/), '123456')
    await u.click(screen.getByRole('button', { name: 'Verificar e entrar' }))
    await waitFor(() => expect(codigo).toBe('123456'))
  })

  it('senha vencida abre "Crie a sua senha" com o motivo e checklist ao vivo', async () => {
    const p = perfil([])
    roteador({ '/api/v1/me': () => json(200, { ...p, usuario: { ...p.usuario, nome: 'Joana Barbosa', senha_expirada: true } }) })
    render(<App />)
    expect(await screen.findByRole('heading', { name: 'Crie a sua senha' })).toBeInTheDocument()
    expect(screen.getByText(/trocada a cada 90 dias/)).toBeInTheDocument()
    const u = userEvent.setup()
    await u.type(screen.getByLabelText(/^Nova senha/), 'Barbosa-2026')
    const lista = screen.getByRole('list', { name: 'Requisitos da nova senha' })
    expect(lista).toHaveTextContent('Pelo menos 8 caracteres: atendido')
    expect(lista).toHaveTextContent('Não contém o seu nome: pendente')
    expect(screen.getByRole('button', { name: /Salvar senha/ })).toBeDisabled()
  })

  it('Meu perfil: posto vazio = civil e salva só os dados funcionais', async () => {
    let corpo: Record<string, unknown> | undefined
    const dados = { nome: 'Teste Fictício', login: '52998224725', posto_graduacao: null, cargo: null, matricula: null, telefone: null, militar: null, postos: [{ id: 3, sigla: 'SD', nome: 'Soldado' }] }
    roteador({
      '/api/v1/me': () => json(200, perfil([])),
      'GET /api/v1/me/profile': () => json(200, dados),
      'PUT /api/v1/me/profile': (init) => { corpo = JSON.parse(String(init?.body)); return json(200, { ...dados, cargo: 'Auxiliar Administrativo' }) },
    })
    window.history.pushState({}, '', '/perfil')
    render(<App />)
    const u = userEvent.setup()
    expect(await screen.findByLabelText(/Posto\/graduação/)).toHaveDisplayValue('Civil / sem posto')
    await u.type(screen.getByLabelText(/Cargo/), 'Auxiliar Administrativo')
    await u.click(screen.getByRole('button', { name: 'Salvar' }))
    await waitFor(() => expect(corpo).toEqual({ posto_graduacao_id: null, cargo: 'Auxiliar Administrativo', telefone: null }))
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

  it('Sobre o Sistema abre para qualquer usuário autenticado, sem permissão', async () => {
    roteador({ '/api/v1/me': () => json(200, perfil([])) })
    window.history.pushState({}, '', '/sobre')
    render(<App />)
    expect(await screen.findByRole('heading', { level: 1, name: 'Sobre o Sistema' })).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Sobre o sistema' })).toBeInTheDocument()
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
    expect(JSON.parse(String(enviado!.body))).not.toHaveProperty('ocorrido_em') // não alterada: o servidor usa o momento do registro
    expect(await screen.findByText('Obrigatório.')).toBeInTheDocument()
  })

  it('nova entrada: data/hora vem preenchida (Maceió) e a retroativa exige justificativa e é enviada com fuso', async () => {
    let corpo: Record<string, unknown> | undefined
    roteador({
      '/api/v1/me': () => json(200, perfil(['estoque.ver', 'estoque.entrada'])),
      '/api/v1/locations': () => json(200, { data: [{ id: 5, codigo: 'RES-1', nome: 'Reserva', tipo: 'RESERVA', ativo: true, unidade: { id: 1, sigla: 'UN', nome: 'U' } }] }),
      '/api/v1/items': () => json(200, { data: [{ id: 8, codigo: 'LOT', nome: 'Lote', modo_controle: 'LOTE', categoria: { id: 1, nome: 'c', natureza: 'MUNICAO' } }], meta: { page: 1, per_page: 100, total: 1, last_page: 1 } }),
      'POST /api/v1/movements': (init) => { corpo = JSON.parse(String(init?.body)); return json(422, { error: { code: 'VALIDACAO', message: 'x', fields: {} } }) },
    })
    window.history.pushState({}, '', '/movimentacoes/nova-entrada')
    render(<App />)
    const u = userEvent.setup()
    const quando = await screen.findByLabelText(/Data e hora da entrada/)
    expect(quando).toHaveValue(agoraMaceio())
    expect(agoraMaceio(new Date('2026-11-11T02:30:00Z'))).toBe('2026-11-10T23:30')
    await u.selectOptions(screen.getByLabelText(/Local de destino/), '5')
    await u.selectOptions(screen.getByLabelText(/Material \(item 1\)/), '8')
    await u.type(screen.getByLabelText(/Documento de referência/), 'DOC-1')
    await u.type(screen.getByLabelText(/^Origem/), 'Origem fictícia')
    await u.type(screen.getByLabelText(/Código do lote/), 'L1')
    fireEvent.change(quando, { target: { value: '2026-01-02T08:15' } })
    expect(screen.getByRole('button', { name: 'Revisar entrada' })).toBeDisabled()
    await u.type(screen.getByLabelText(/Justificativa da entrada retroativa/), 'Recebida no plantão anterior')
    await u.click(screen.getByRole('button', { name: 'Revisar entrada' }))
    expect(await screen.findByText(/02\/01\/2026 08:15/)).toBeInTheDocument()
    await u.click(screen.getByRole('button', { name: 'Confirmar e registrar' }))
    await waitFor(() => expect(corpo).toBeDefined())
    expect(corpo).toMatchObject({ ocorrido_em: '2026-01-02T08:15:00-03:00', justificativa: 'Recebida no plantão anterior' })
  })
})
