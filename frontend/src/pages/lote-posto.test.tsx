import { render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'
import App from '../App'
import { rotuloEfetivo, siglaEfetivo } from '../components/efetivo'
import { SituacaoLinhaLote } from '../components/estoque/EntregaResumo'
import { textoSituacaoLote } from '../components/estoque/situacaoLote'

/** Relato do responsável (2026-11-18): situação do lote entregue, sigla da categoria no lugar de CIV e lista paginada na entrega. */
const json = (status: number, body: unknown) => new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } })
const perfil = (permissoes: string[]) => ({
  usuario: { id: 1, nome: 'Armeiro Fictício', login: 'a', militar: null }, papeis: [{ papel: 'ARMEIRO', papel_nome: 'Armeiro', unidade: { id: 1, sigla: 'UN' } }],
  permissoes, operacoes: [{ codigo: 'ENTREGA', estado: 'HABILITADA', pendencia: 'Decisão', rotulo: 'Entrega' }], ambiente: 'DEMONSTRACAO',
})
type Rota = (url: URL, init?: RequestInit) => Response
function roteador(rotas: Record<string, Rota>) {
  const enviados: { url: string; corpo: unknown }[] = []
  const pedidos: URL[] = []
  vi.spyOn(globalThis, 'fetch').mockImplementation(async (url, init) => {
    const u = new URL(String(url), 'http://x')
    pedidos.push(u)
    if (init?.method && init.method !== 'GET' && init.body) enviados.push({ url: u.pathname, corpo: JSON.parse(String(init.body)) })
    const h = rotas[`${init?.method ?? 'GET'} ${u.pathname}`] ?? rotas[u.pathname]
    return h ? h(u, init) : new Response(null, { status: 204 })
  })
  return { enviados, pedidos }
}
afterEach(() => { vi.restoreAllMocks(); window.history.pushState({}, '', '/') })

describe('sigla do efetivo e situação do lote', () => {
  it('helpers: CIV vira a sigla da categoria; lote mostra entregue − devolvido', () => {
    expect(siglaEfetivo('CIV', 'RR')).toBe('RR')
    expect(siglaEfetivo('CIV', 'PM_ATIVA')).toBe('PM')
    expect(siglaEfetivo('CIV', 'BM_ATIVA')).toBe('BM')
    expect(siglaEfetivo('CIV', 'VIGILANTE')).toBe('VIG')
    expect(siglaEfetivo('CIV', 'R1')).toBe('R1')
    expect(siglaEfetivo('CIV', null)).toBeNull()
    expect(siglaEfetivo('3SGT', 'RR')).toBe('3SGT')
    expect(rotuloEfetivo({ nome_guerra: 'CICERO LIMA', posto_graduacao: { sigla: 'CIV' }, categoria_efetivo: 'RR' })).toBe('RR CICERO LIMA')
    expect(rotuloEfetivo({ nome_guerra: 'JOAO', posto: 'CIV' })).toBe('JOAO')
    expect(rotuloEfetivo({ nome_guerra: 'SILVA', posto: 'SD', categoria_efetivo: 'PM_ATIVA' })).toBe('SD SILVA')
    expect(rotuloEfetivo({ nome_guerra: 'X', posto_graduacao: { sigla: 'CIV' }, sigla_exibicao: 'BM' })).toBe('BM X')
    expect(textoSituacaoLote({ entregue: 10, devolvido: 0 })).toBe('Entregue')
    expect(textoSituacaoLote({ entregue: 10, devolvido: 4 })).toBe('Devolvido parcialmente: 4 de 10')
    expect(textoSituacaoLote({ entregue: 10, devolvido: 10 })).toBe('Devolvido')
    expect(textoSituacaoLote({ entregue: 5, devolvido: 0, situacao: 'CONSUMIDO' })).toBe('Consumido')
    render(<SituacaoLinhaLote i={{ quantidade: 6, quantidade_entregue: 10, quantidade_devolvida: 4 }} />)
    expect(screen.getByText('Devolvido parcialmente: 4 de 10')).toBeInTheDocument()
  })

  it('detalhe "Movimentação #1183": situação NA ENTREGA igual para item e lote, sem "Disponível" (2026-11-18)', async () => {
    const na = (codigo: string, rotulo: string, extra: Record<string, unknown> = {}) => ({ codigo, rotulo, entregue: 1, devolvido: codigo === 'ENTREGUE' ? 0 : 1, pendente: codigo === 'ENTREGUE' ? 1 : 0, ...extra })
    roteador({
      '/api/v1/me': () => json(200, perfil(['estoque.ver', 'estoque.entregar', 'movimentacoes.ver'])),
      '/api/v1/movements/1183': () => json(200, { id: 1183, tipo: 'ENTREGA', ocorrido_em: '2026-11-18T10:00:00Z', responsavel: { id: 1, nome: 'A' },
        local_origem: { id: 1, codigo: 'RES-POCO', nome: 'Reserva' }, local_destino: null, documento_referencia: null, itens_count: 3, estornada: false, estorno_id: null,
        militar: { id: 3, nome_guerra: 'CICERO LIMA', posto: 'RR' }, previsao_retorno: '2026-11-20', pendente_devolucao: null,
        itens: [
          { tipo: 'INDIVIDUAL', quantidade: 1, material: { id: 1, codigo: 'P', nome: 'PT 100 Cal. .40 Taurus' }, item: { id: 1, identificador: 'ARM-1', numero_serie: 'PST-1', situacao: 'ENTREGUE' },
            situacao_na_entrega: na('ENTREGUE', 'Entregue') },
          { tipo: 'INDIVIDUAL', quantidade: 1, material: { id: 2, codigo: 'C', nome: 'Colete balístico' }, item: { id: 2, identificador: 'COL-1', numero_serie: 'C-1', situacao: 'DISPONIVEL' },
            situacao_na_entrega: na('DEVOLVIDO', 'Devolvido', { hoje: 'hoje: disponível na RES-POCO' }) },
          { tipo: 'LOTE', quantidade: 10, material: { id: 3, codigo: 'M', nome: 'Munição .40' }, lote: { id: 5, codigo: 'INV2026', validade: null },
            situacao_lote: { situacao: 'DEVOLVIDO', entregue: 10, devolvido: 10, pendente: 0 }, situacao_na_entrega: { codigo: 'DEVOLVIDO', rotulo: 'Devolvido', entregue: 10, devolvido: 10, pendente: 0 } },
        ] }),
    })
    window.history.pushState({}, '', '/movimentacoes/1183')
    render(<App />)
    expect(await screen.findByRole('columnheader', { name: 'Situação na entrega' }, { timeout: 10_000 })).toBeInTheDocument()
    expect(screen.getByText('RR CICERO LIMA')).toBeInTheDocument()
    expect(screen.getByText('Entregue')).toBeInTheDocument()
    expect(screen.getAllByText('Devolvido')).toHaveLength(2) // colete e munição com o mesmo vocabulário
    expect(screen.getByText('hoje: disponível na RES-POCO')).toBeInTheDocument() // situação atual só como informação secundária
    expect(screen.queryByText('Disponível')).not.toBeInTheDocument()
  }, 20_000)
})

describe('Registrar entrega: lista paginada', () => {
  const local = { id: 1, codigo: 'RES', nome: 'Reserva', tipo: 'RESERVA', ativo: true, unidade: { id: 1, sigla: 'UN', nome: 'Unidade' } }
  const efetivo = { id: 3, nome: 'Cícero Lima', nome_guerra: 'CICERO LIMA', matricula: '***', matricula_completa: false, situacao: 'ATIVO', categoria_efetivo: 'RR', apto_arma_fogo: true,
    posto_graduacao: { id: 9, sigla: 'CIV' }, sigla_exibicao: 'RR', rotulo_curto: 'RR CICERO LIMA', unidade: { id: 1, sigla: 'UN' }, pode_editar: false }
  const item = (id: number) => ({ id, identificador: `EPI-${id}`, numero_serie: `COL-${id}`, situacao: 'DISPONIVEL', estado_conservacao: 'BOM', fabricado_em: null, validade: null,
    validade_estado: null, tamanho: null, nivel_protecao: null, material: { id: 2, codigo: 'C', nome: 'Colete balístico - tamanho P', tipo: null, natureza: 'EQUIPAMENTO' },
    local: { id: 1, codigo: 'RES', nome: 'Reserva' }, unidade: { id: 1, sigla: 'UN' }, pode_atualizar_conservacao: true, pode_editar: true, pode_baixar: true })
  const opcoes = { local_origem_padrao_id: 1, documentos: [{ codigo: 'SEM_DOCUMENTO', rotulo: 'Sem documento', descricao: 'Sem documento' }],
    motivos: [{ codigo: 'PATRULHA', rotulo: 'Patrulha', descricao: 'Serviço' }] }

  it('pagina no servidor, mantém o que foi marcado ao trocar de página, busca no conjunto todo e trata lote marcado como entregue', async () => {
    const { enviados, pedidos } = roteador({
      '/api/v1/me': () => json(200, perfil(['estoque.ver', 'estoque.entregar'])),
      '/api/v1/locations': () => json(200, { data: [local] }),
      '/api/v1/stock/delivery-options': () => json(200, opcoes),
      '/api/v1/stock/recipients': () => json(200, { data: [efetivo] }),
      '/api/v1/stock/individual-items': (u) => {
        const p = Number(u.searchParams.get('page') ?? 1)
        return json(200, { data: Array.from({ length: p === 1 ? 15 : 5 }, (_, n) => item((p - 1) * 15 + n + 1)), meta: { page: p, per_page: 15, total: 20, last_page: 2 } })
      },
      '/api/v1/stock/balances': () => json(200, { data: [{ local: { id: 1, codigo: 'RES', nome: 'Reserva' }, unidade: { id: 1, sigla: 'UN' },
        material: { id: 9, codigo: 'MUN', nome: 'Munição .40', modo_controle: 'LOTE', categoria: 'Munição', natureza: 'MUNICAO', unidade_medida: 'UN' },
        lote: { id: 5, codigo: 'INV2026', validade: null }, quantidade: 50, reservada: 0, disponivel: 50 }], meta: { page: 1, per_page: 15, total: 1, last_page: 1 } }),
      'POST /api/v1/movements': () => json(201, { id: 1183 }),
    })
    window.history.pushState({}, '', '/entregas/nova')
    render(<App />)
    const u = userEvent.setup()
    await u.type(await screen.findByRole('searchbox', { name: /Efetivo/ }, { timeout: 10_000 }), 'CIC')
    await u.click(await screen.findByRole('button', { name: /RR CICERO LIMA/ }, { timeout: 5_000 }))
    await screen.findByText('COL-1', {}, { timeout: 5_000 })
    // 15 por página, pedidos paginados ao servidor (itens DISPONIVEL e só lotes com saldo).
    const itens = pedidos.filter((p) => p.pathname === '/api/v1/stock/individual-items')
    expect(itens.at(-1)?.searchParams.get('per_page')).toBe('15')
    expect(pedidos.filter((p) => p.pathname === '/api/v1/stock/balances').at(-1)?.searchParams.get('lotes_disponiveis')).toBe('1')
    expect(screen.getByText('Nenhum material selecionado.')).toBeInTheDocument()
    await u.click(screen.getByRole('checkbox', { name: /COL-1\b/ }))
    const nav = screen.getAllByRole('navigation', { name: 'Paginação' })[0]
    await u.click(within(nav).getByRole('button', { name: /Próxima/ }))
    await screen.findByText('COL-16')
    await u.click(screen.getByRole('checkbox', { name: /COL-16/ }))
    // Lote: marcar = entregar (sugere 1); a quantidade pode ser ajustada.
    await u.click(screen.getByRole('checkbox', { name: /Entregar o lote INV2026/ }))
    expect(screen.getByRole('spinbutton', { name: /lote INV2026/ })).toHaveValue(1)
    const qtd = screen.getByRole('spinbutton', { name: /lote INV2026/ })
    await u.clear(qtd); await u.type(qtd, '10')
    expect(screen.getByText(/3 selecionado\(s\)/).closest('p')).toHaveTextContent('3 selecionado(s) · 2 item(ns) por série · 1 lote(s): 10 unidade(s)')
    await u.click(within(screen.getAllByRole('navigation', { name: 'Paginação' })[0]).getByRole('button', { name: /Anterior/ }))
    expect(await screen.findByRole('checkbox', { name: /COL-1\b/ })).toBeChecked() // marcado continua marcado
    // Busca no servidor (conjunto todo), voltando à página 1.
    await u.type(screen.getByRole('searchbox', { name: 'Buscar material' }), 'colete')
    await u.click(screen.getByRole('button', { name: 'Buscar' }))
    await waitFor(() => expect(pedidos.filter((p) => p.pathname === '/api/v1/stock/individual-items').at(-1)?.searchParams.get('q')).toBe('colete'))
    expect(pedidos.filter((p) => p.pathname === '/api/v1/stock/individual-items').at(-1)?.searchParams.get('page')).toBe('1')

    await u.selectOptions(screen.getByRole('combobox', { name: /Motivo/ }), 'PATRULHA')
    await u.click(screen.getByRole('button', { name: 'Revisar entrega' }))
    const dlg = await screen.findByRole('dialog')
    expect(within(dlg).getByText('RR CICERO LIMA')).toBeInTheDocument()
    await u.click(within(dlg).getByRole('button', { name: 'Confirmar entrega' }))
    await waitFor(() => expect((enviados.find((e) => e.url === '/api/v1/movements')?.corpo as { itens?: unknown[] } | undefined)?.itens)
      .toEqual([{ item_individual_id: 1 }, { item_individual_id: 16 }, { lote_id: 5, quantidade: 10 }]))
  }, 40_000)
})
