import { render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router'
import { afterEach, describe, expect, it, vi } from 'vitest'
import App from '../App'
import type { CartaoGrupo, EfetivoPendente, PainelMaterial } from '../api/tipos'
import { haQuanto } from '../components/quando'
import { resumoPendente } from './DevolucoesPage'
import { DisponivelNaBase, OperacaoDoDia, linhaSecundaria } from './inicio/PainelMaterial'
import { textoVazio } from './pedidos/PedidosMaterial'

const json = (status: number, body: unknown) => new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } })
const meta = (total: number) => ({ page: 1, per_page: 15, total, last_page: 1 })
// Decisão 2026-11-18: registrar entrada é da gestão — o armeiro não tem estoque.entrada nem estoque.depositos.
const ARMEIRO = ['estoque.ver', 'estoque.entregar', 'estoque.baixar', 'pedidos.gerir', 'relatorios.entregas', 'suporte.abrir']
const perfil = (permissoes = ARMEIRO) => ({
  usuario: { id: 7, nome: 'Armeiro Fictício', login: 'a', militar: null }, papeis: [{ papel: 'ARMEIRO', papel_nome: 'Armeiro', unidade: { id: 2, sigla: 'POCO' } }],
  permissoes, operacoes: [{ codigo: 'ENTREGA', estado: 'HABILITADA', pendencia: 'Decisão', rotulo: 'Entrega' }], ambiente: 'DEMONSTRACAO',
})
type Rota = (url: string, init?: RequestInit) => Response
function roteador(rotas: Record<string, Rota>) {
  const pedidos: { url: string; metodo: string; corpo?: unknown }[] = []
  vi.spyOn(globalThis, 'fetch').mockImplementation(async (url, init) => {
    const metodo = init?.method ?? 'GET'
    pedidos.push({ url: String(url), metodo, corpo: init?.body ? JSON.parse(String(init.body)) : undefined })
    const u = String(url).replace(/\?.*$/, '')
    const h = rotas[`${metodo} ${u}`] ?? rotas[u]
    return h ? h(String(url), init) : new Response(null, { status: 204 })
  })
  return pedidos
}
afterEach(() => { vi.restoreAllMocks(); window.history.pushState({}, '', '/') })

const g = (chave: CartaoGrupo['chave'], rotulo: string, extra: Partial<CartaoGrupo> = {}): CartaoGrupo => ({ chave, rotulo, disponiveis: 0, vencidos: 0, vencendo: 0,
  tem_validade: false, com_efetivos: 0, efetivos: 0, entradas_hoje: 0, saidas_hoje: 0, entradas_7_dias: 0, saidas_7_dias: 0, ...extra })
const painel = (extra: Partial<PainelMaterial> = {}): PainelMaterial => ({
  gerado_em: '2026-11-18T12:00:00Z', validade_dias: 30,
  grupos: [g('ARMA_FOGO', 'Armas de fogo', { disponiveis: 12, com_efetivos: 3, efetivos: 3 }),
    g('MUNICAO', 'Munição', { disponiveis: 1450, com_efetivos: 90, entradas_hoje: 500, saidas_hoje: 60, entradas_7_dias: 500, saidas_7_dias: 210 }),
    g('COLETE', 'Coletes', { disponiveis: 40, vencidos: 2, vencendo: 5, tem_validade: true }), g('TASER', 'Tasers', { disponiveis: 4 }),
    g('CARTUCHO_TASER', 'Cartuchos de Taser', { disponiveis: 100 }), g('RADIO', 'Rádios'), g('ALGEMA', 'Algemas', { disponiveis: 30 }), g('OUTROS', 'Outros materiais', { disponiveis: 7 })],
  operacao: { entregas_atrasadas: 2, pedidos_pendentes: 3, pedidos_aprovados: 1, pendente_mais_antigo: new Date(Date.now() - 3 * 86_400_000).toISOString() }, ...extra,
})
const pedido = (codigo: string, extra: Record<string, unknown> = {}) => ({
  codigo, rotulo: `PED-${codigo.slice(-6)}`, status: 'PENDENTE', versao: 1, efetivo: { id: 3, nome_guerra: 'FICTO', nome: 'Pessoa Fictícia', posto: 'RR', categoria_efetivo: 'RR', apto_arma_fogo: true },
  unidade: { id: 2, sigla: 'POCO' }, retirada_prevista: '2026-11-20', local_retirada: { id: 1, codigo: 'RES-POCO', nome: 'Reserva' }, itens_resumo: 'Colete × 1, Algema × 1', tem_letal: false,
  criado_em: new Date(Date.now() - 4 * 86_400_000).toISOString(), decidido_em: null, entregue_em: null, acoes: { decidir: true, entregar: false }, ...extra,
})

describe('Início por tipo de material (2026-11-18)', () => {
  it('cards objetivos: número grande, linha secundária e link para a lista filtrada', () => {
    render(<MemoryRouter><DisponivelNaBase p={painel()} titulo="Disponível na base" /></MemoryRouter>)
    const arma = screen.getByRole('article', { name: 'Armas de fogo' })
    expect(within(arma).getByText('12')).toBeInTheDocument()
    expect(within(arma).getByText('3 com 3 efetivos')).toBeInTheDocument()
    expect(within(arma).getByRole('link', { name: /Ver saldos de Armas de fogo/ })).toHaveAttribute('href', '/estoque?grupo=ARMA_FOGO')
    expect(within(arma).getByRole('link', { name: /Com efetivos/ })).toHaveAttribute('href', '/entregas?grupo=ARMA_FOGO')
    const mun = screen.getByRole('article', { name: 'Munição' })
    expect(within(mun).getByText(/1\.450/)).toBeInTheDocument()
    expect(within(mun).getByText(/Hoje: \+500 entrada · −60 saída · 7 dias: \+500 \/ −210/)).toBeInTheDocument()
    const colete = screen.getByRole('article', { name: 'Coletes' })
    expect(within(colete).getByText('2 vencidos')).toBeInTheDocument()
    expect(within(colete).getByText('5 vencem em até 30 dias')).toBeInTheDocument()
    expect(within(screen.getByRole('article', { name: 'Rádios' })).queryByRole('link', { name: /Com efetivos/ })).not.toBeInTheDocument()
    expect(screen.getAllByRole('article')).toHaveLength(8)
  })

  it('estado vazio e operação do dia com destaque em texto (não só cor)', () => {
    const vazio = painel({ grupos: painel().grupos.map((x) => ({ ...x, disponiveis: 0, com_efetivos: 0 })) })
    const { unmount } = render(<MemoryRouter><DisponivelNaBase p={vazio} titulo="Disponível na base" /></MemoryRouter>)
    expect(screen.getByText('Nenhum material na base ainda.')).toBeInTheDocument()
    unmount()
    render(<MemoryRouter><OperacaoDoDia p={painel()} pedidos /></MemoryRouter>)
    const pend = screen.getByRole('article', { name: 'Pedidos pendentes de aprovação' })
    expect(within(pend).getByText('3')).toBeInTheDocument()
    expect(within(pend).getByText('Mais antigo há 3 dias')).toBeInTheDocument()
    expect(within(pend).getByText('Requer ação')).toBeInTheDocument()
    expect(within(pend).getByRole('link', { name: 'Revisar pedidos' })).toHaveAttribute('href', '/pedidos?status=PENDENTE')
    expect(within(screen.getByRole('article', { name: 'Entregas com devolução atrasada' })).getByRole('link')).toHaveAttribute('href', '/entregas?atrasados=1')
    expect(linhaSecundaria(g('ALGEMA', 'Algemas'))).toBe('Nenhum com efetivos')
    expect(haQuanto(new Date(Date.now() - 90 * 60_000).toISOString())).toBe('há 1 h')
  })

  it('armeiro: Início sem os indicadores gerais, com entrega como ação principal', async () => {
    const pedidos = roteador({ '/api/v1/me': () => json(200, perfil()), '/api/v1/dashboard': () => json(200, { gerado_em: '2026-11-18T12:00:00Z', pendencias_institucionais: 12, operacoes_bloqueadas: 0,
      estoque: { locais: 1, itens_individuais_disponiveis: 5, lotes_com_saldo: 1, quantidade_em_lotes: 1, lotes_validade_90_dias: 0, movimentacoes_7_dias: 1, por_natureza: [] } }),
    '/api/v1/dashboard/materials': () => json(200, painel()), '/api/v1/alerts': () => json(200, { data: [] }) })
    render(<App />)
    expect(await screen.findByRole('heading', { level: 2, name: 'Disponível na base' }, { timeout: 10_000 })).toBeInTheDocument()
    expect(screen.getByRole('heading', { level: 2, name: 'Operação do dia' })).toBeInTheDocument()
    expect(screen.queryByRole('heading', { level: 2, name: 'Indicadores gerais' })).not.toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Registrar entrega' })).toHaveClass('btn-pri')
    expect(screen.queryByRole('link', { name: 'Registrar entrada' })).not.toBeInTheDocument()
    await waitFor(() => expect(pedidos.some((p) => p.url.includes('/api/v1/dashboard/materials'))).toBe(true))
  }, 30_000)

  it('gestor: Registrar entrada aparece no Início, em Saldos e em Movimentações; armeiro não vê em nenhum', async () => {
    const dash = () => json(200, { gerado_em: '2026-11-18T12:00:00Z', pendencias_institucionais: 12, operacoes_bloqueadas: 0 })
    const vazio = () => json(200, { data: [], meta: meta(0) })
    const rotas = (perms: string[]) => roteador({ '/api/v1/me': () => json(200, perfil(perms)), '/api/v1/dashboard': dash, '/api/v1/dashboard/materials': () => json(200, painel()),
      '/api/v1/alerts': () => json(200, { data: [] }), '/api/v1/locations': () => json(200, { data: [] }), '/api/v1/stock/balances': vazio, '/api/v1/movements': vazio })
    const GESTOR = ['estoque.ver', 'estoque.entrada', 'estoque.depositos', 'movimentacoes.ver']
    for (const [perms, ve] of [[GESTOR, true], [ARMEIRO, false]] as const) {
      for (const rota of ['/', '/estoque', '/movimentacoes']) {
        rotas([...perms])
        window.history.pushState({}, '', rota)
        const { unmount } = render(<App />)
        if (ve) expect(await screen.findByRole('link', { name: 'Registrar entrada' }, { timeout: 10_000 })).toBeInTheDocument()
        else {
          await screen.findByRole('heading', { level: 1 }, { timeout: 10_000 })
          expect(screen.queryByRole('link', { name: 'Registrar entrada' })).not.toBeInTheDocument()
        }
        unmount(); vi.restoreAllMocks()
      }
    }
  }, 60_000)
})

describe('Pedidos de material — painel do armeiro (2026-11-18)', () => {
  it('abre em Pendentes com contadores, filtra sem botão e destaca pedido parado', async () => {
    const pedidos = roteador({
      '/api/v1/me': () => json(200, perfil()), '/api/v1/notifications': () => json(200, { nao_lidas: 0, data: [] }),
      '/api/v1/material-requests/summary': () => json(200, { pendentes: 1, aprovados: 2, pendente_mais_antigo: null, por_status: { PENDENTE: 1, APROVADO: 2, ENTREGUE: 5, RECUSADO: 0, CANCELADO: 1 } }),
      '/api/v1/material-requests': () => json(200, { data: [pedido('01JF00000000000000000ABCDE')], meta: meta(1) }),
    })
    window.history.pushState({}, '', '/pedidos')
    render(<App />)
    const abas = await screen.findByRole('group', { name: 'Situação do pedido' }, { timeout: 10_000 })
    expect(within(abas).getByRole('button', { name: /Pendentes/ })).toHaveAttribute('aria-pressed', 'true')
    await waitFor(() => expect(within(abas).getByRole('button', { name: /Todos/ })).toHaveTextContent('9'))
    expect(await screen.findByText('PED-0ABCDE')).toBeInTheDocument()
    expect(screen.getByText(/Parado há 4 dias/)).toBeInTheDocument()
    expect(screen.getByText('Pessoa Fictícia')).toBeInTheDocument()
    expect(pedidos.some((p) => p.url.includes('/api/v1/material-requests?') && p.url.includes('status=PENDENTE') && p.url.includes('sort=id'))).toBe(true)
    expect(screen.queryByRole('button', { name: 'Buscar' })).not.toBeInTheDocument()

    const u = userEvent.setup()
    await u.type(screen.getByRole('searchbox', { name: /Buscar por efetivo/ }), 'ficto')
    await waitFor(() => expect(pedidos.some((p) => p.url.includes('/material-requests/summary?') && p.url.includes('q=ficto'))).toBe(true), { timeout: 3000 })
    await u.click(within(abas).getByRole('button', { name: /Entregues/ }))
    await waitFor(() => expect(pedidos.some((p) => p.url.includes('status=ENTREGUE') && p.url.includes('sort=-id'))).toBe(true))
  }, 30_000)

  it('aprovar direto da lista manda a versão; recusar exige motivo', async () => {
    const cod = '01JF00000000000000000ABCDE'
    const pedidos = roteador({
      '/api/v1/me': () => json(200, perfil()), '/api/v1/notifications': () => json(200, { nao_lidas: 0, data: [] }),
      '/api/v1/material-requests/summary': () => json(200, { pendentes: 1, aprovados: 0, pendente_mais_antigo: null, por_status: { PENDENTE: 1, APROVADO: 0, ENTREGUE: 0, RECUSADO: 0, CANCELADO: 0 } }),
      '/api/v1/material-requests': () => json(200, { data: [pedido(cod, { versao: 4 })], meta: meta(1) }),
      [`POST /api/v1/material-requests/${cod}/decision`]: () => json(200, pedido(cod, { status: 'APROVADO' })),
    })
    window.history.pushState({}, '', '/pedidos')
    render(<App />)
    const u = userEvent.setup()
    await u.click(await screen.findByRole('button', { name: /Recusar o pedido PED-0ABCDE/ }, { timeout: 10_000 }))
    let dialogo = screen.getByRole('dialog')
    expect(within(dialogo).getByRole('button', { name: 'Recusar pedido' })).toBeDisabled()
    await u.click(within(dialogo).getByRole('button', { name: 'Voltar' }))
    await u.click(screen.getByRole('button', { name: /Aprovar o pedido PED-0ABCDE/ }))
    dialogo = screen.getByRole('dialog')
    await u.click(within(dialogo).getByRole('button', { name: 'Aprovar como pedido' }))
    await waitFor(() => expect(pedidos.find((p) => p.metodo === 'POST')?.corpo).toEqual({ decisao: 'APROVAR', versao: 4 }))
  }, 30_000)

  it('estado vazio explica o fluxo; pendentes vazios é mensagem positiva', () => {
    expect(textoVazio('PENDENTE', false)).toBe('Nenhum pedido aguardando aprovação. Fila em dia.')
    expect(textoVazio('PENDENTE', true)).toBe('Nenhum pedido com estes filtros.')
    expect(textoVazio('TODOS', false)).toBe('Nenhum pedido feito pelos efetivos ainda.')
  })
})

describe('Devolução de material já aberta (2026-11-18)', () => {
  const ef = (id: number, nome_guerra: string, extra: Partial<EfetivoPendente> = {}): EfetivoPendente => ({
    militar: { id, posto: 'RR', nome_guerra, nome: `Pessoa ${nome_guerra}`, categoria_efetivo: 'RR', apto_arma_fogo: true },
    entregas: 2, itens: 2, lotes: 1, quantidade_lotes: 30, mais_antiga: '2026-11-10T10:00:00Z', proxima_previsao: '2026-11-15', atrasadas: 1, atrasado: true, ...extra })

  it('lista quem está com material sem buscar, atrasado com selo, e expande as entregas', async () => {
    const pedidos = roteador({
      '/api/v1/me': () => json(200, perfil()),
      '/api/v1/stock/linked/recipients': () => json(200, { data: [ef(3, 'FICTO'), ef(4, 'OUTRO', { atrasado: false, atrasadas: 0, lotes: 0, quantidade_lotes: 0, entregas: 1, itens: 1 })], meta: meta(2) }),
      '/api/v1/stock/linked': () => json(200, { data: [{ custodia: 'ENTREGA', origem: '1183', desde: '2026-11-10T10:00:00Z', entrega_id: 1183, pedido: null,
        militar: { id: 3, posto: 'RR', nome_guerra: 'FICTO' }, unidade: { id: 2, sigla: 'POCO' }, local: null, previsao_retorno: '2026-11-15', atrasado: true, total_itens: 1, quantidade_total: 1,
        conservacao: { BOM: 1 }, pode_receber: true, itens: [] }], meta: meta(1) }),
    })
    window.history.pushState({}, '', '/devolucoes')
    render(<App />)
    const botao = await screen.findByRole('button', { name: /RR FICTO/ }, { timeout: 10_000 })
    expect(screen.getByText(/^2 efetivos com material pendente/)).toBeInTheDocument()
    expect(within(botao).getByText('Atrasado')).toBeInTheDocument()
    expect(within(screen.getByRole('button', { name: /RR OUTRO/ })).getByText('No prazo')).toBeInTheDocument()
    expect(within(botao).getByText('2 entregas · 2 itens · 1 lote (30 unid.)')).toBeInTheDocument()
    expect(botao).toHaveAttribute('aria-expanded', 'false')
    const u = userEvent.setup()
    await u.click(botao)
    expect(botao).toHaveAttribute('aria-expanded', 'true')
    expect(await screen.findByRole('button', { name: /Receber devolução da entrega #1183/ })).toBeInTheDocument()
    expect(pedidos.some((p) => p.url.includes('/api/v1/stock/linked?') && p.url.includes('militar_id=3'))).toBe(true)

    await u.type(screen.getByRole('searchbox', { name: /Efetivo, matrícula, CPF/ }), 'outro')
    await waitFor(() => expect(pedidos.some((p) => p.url.includes('/stock/linked/recipients?') && p.url.includes('q=outro'))).toBe(true), { timeout: 3000 })
  }, 30_000)

  it('vazio da base e resumo do pendente', async () => {
    roteador({ '/api/v1/me': () => json(200, perfil()), '/api/v1/stock/linked/recipients': () => json(200, { data: [], meta: meta(0) }) })
    window.history.pushState({}, '', '/devolucoes')
    render(<App />)
    expect(await screen.findByText('Nenhum material com efetivos na base.', {}, { timeout: 10_000 })).toBeInTheDocument()
    expect(resumoPendente(ef(1, 'X', { entregas: 1, itens: 0, lotes: 2, quantidade_lotes: 1500 }))).toBe('1 entrega · 2 lotes (1.500 unid.)')
  }, 30_000)
})
