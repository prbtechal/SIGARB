import { render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'
import App from '../App'
import { FiltroUnidade, raizesEscopo } from '../components/estoque/FiltroUnidade'
import { nomesResumidos, resumoConservacao } from '../components/estoque/EntregaResumo'
import { GraficoSeries } from '../components/GraficoSeries'
import type { EntregaAgrupada, Unidade } from '../api/tipos'
import { porEfetivo } from './DevolucoesPage'

const json = (status: number, body: unknown) => new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } })
const meta = (total: number) => ({ page: 1, per_page: 20, total, last_page: 1 })
const ARMEIRO = ['estoque.ver', 'estoque.entregar', 'estoque.baixar', 'estoque.editar', 'pedidos.gerir', 'relatorios.entregas', 'militares.ver_detalhe', 'suporte.abrir']
const perfil = (permissoes = ARMEIRO) => ({
  usuario: { id: 7, nome: 'Armeiro Fictício', login: 'a', militar: null }, papeis: [{ papel: 'ARMEIRO', papel_nome: 'Armeiro', unidade: { id: 2, sigla: 'POCO' } }],
  permissoes, operacoes: [{ codigo: 'ENTREGA', estado: 'HABILITADA', pendencia: 'Decisão', rotulo: 'Entrega' }], ambiente: 'DEMONSTRACAO',
})
function roteador(rotas: Record<string, (url: string) => Response>) {
  const pedidos: string[] = []
  vi.spyOn(globalThis, 'fetch').mockImplementation(async (url) => {
    pedidos.push(String(url))
    const h = rotas[String(url).replace(/\?.*$/, '')]
    return h ? h(String(url)) : new Response(null, { status: 204 })
  })
  return pedidos
}
afterEach(() => { vi.restoreAllMocks(); window.history.pushState({}, '', '/') })

const un = (id: number, sigla: string, sup: number | null, nivel: number): Unidade =>
  ({ id, sigla, nome: sigla, tipo: 'UNIDADE', ativo: true, organizacao: { id: 1, sigla: 'PRB' }, unidade_superior_id: sup, nivel })
const mat = (id: number, nome: string) => ({ id, codigo: `M${id}`, nome, modo_controle: 'INDIVIDUAL', categoria: 'C', tipo: null, perfil: null, natureza_rotulo: 'EPI' })
const entrega1183: EntregaAgrupada = {
  custodia: 'ENTREGA', origem: '1183', desde: '2026-11-18T10:00:00Z', entrega_id: 1183, pedido: { codigo: '01JABCDEFGHJKMNPQRSTV7K2M9', comprovante: 'PED-7K2M9Q' },
  militar: { id: 3, posto: 'SD', nome_guerra: 'FICTO', nome: 'Pessoa Fictícia' }, unidade: { id: 2, sigla: 'POCO' }, local: { id: 12, codigo: 'RES-POCO', nome: 'Reserva Poço' },
  previsao_retorno: '2026-11-10', atrasado: true, total_itens: 3, quantidade_total: 32, conservacao: { BOM: 2, REGULAR: 1 }, pode_receber: true,
  itens: [
    { material: mat(1, 'Pistola .40'), item_individual_id: 1, lote_id: null, referencia: 'ARM-1', numero_serie: 'PST-1', quantidade: 1, situacao: 'ENTREGUE', estado_conservacao: 'BOM', validade: null },
    { material: mat(2, 'Colete'), item_individual_id: 2, lote_id: null, referencia: 'EPI-2', numero_serie: 'COL-1', quantidade: 1, situacao: 'ENTREGUE', estado_conservacao: 'REGULAR', validade: null },
    { material: { ...mat(3, 'Munição .40'), modo_controle: 'LOTE' }, item_individual_id: null, lote_id: 5, referencia: 'L-1', lote_codigo: 'L-1', quantidade: 30, situacao: null, estado_conservacao: 'BOM', validade: null },
  ],
}

describe('armeiro na própria base (decisões 2026-11-18)', () => {
  it('menu do armeiro: operação do balcão, sem telas de gestão', async () => {
    roteador({ '/api/v1/me': () => json(200, perfil()), '/api/v1/dashboard': () => json(200, {}) })
    render(<App />)
    const nav = (await screen.findAllByRole('navigation', { name: 'Navegação principal' }, { timeout: 10_000 }))[0]
    for (const r of ['Início', 'Pedidos de material', 'Entrega de material', 'Devolução de material', 'Saldos de estoque', 'Itens e validades', 'Relatórios', 'Alertas']) {
      expect(within(nav).getByRole('link', { name: new RegExp(r) })).toBeInTheDocument()
    }
    for (const r of ['Movimentações', 'Efetivos', 'Documentos', 'Inventários', 'Operações e pendências', 'Catálogo de materiais', 'Importar e exportar', 'Unidades e locais']) {
      expect(within(nav).queryByRole('link', { name: r })).not.toBeInTheDocument()
    }
  })

  it('lista "Entrega de material": uma linha por entrega com resumo, conservação e devolução da entrega inteira', async () => {
    roteador({
      '/api/v1/me': () => json(200, perfil()), '/api/v1/stock/linked': () => json(200, { data: [entrega1183], meta: meta(1) }),
      '/api/v1/units': () => json(200, { data: [un(2, 'POCO', 1, 2), un(20, 'SMB', 2, 3)] }),
      '/api/v1/movements/1183': () => json(200, { id: 1183, tipo: 'ENTREGA', militar: { id: 3, nome_guerra: 'FICTO' }, itens: [], local_origem: { id: 12, codigo: 'RES-POCO', nome: 'Reserva Poço' },
        pendente_devolucao: { itens: [{ item_individual_id: 1, identificador: 'ARM-1', numero_serie: 'PST-1', estado_conservacao: 'BOM', material: { codigo: 'M1', nome: 'Pistola .40' } },
          { item_individual_id: 2, identificador: 'EPI-2', numero_serie: 'COL-1', estado_conservacao: 'REGULAR', material: { codigo: 'M2', nome: 'Colete' } }],
        lotes: [{ lote_id: 5, codigo: 'L-1', quantidade: 30, material: { codigo: 'M3', nome: 'Munição .40' } }] } }),
    })
    window.history.pushState({}, '', '/estoque/vinculados?atrasados=1') // endereço antigo redireciona com os filtros
    render(<App />)
    expect(await screen.findByRole('heading', { level: 1, name: 'Entrega de material' }, { timeout: 10_000 })).toBeInTheDocument()
    expect(window.location.pathname).toBe('/entregas')
    expect(window.location.search).toBe('?atrasados=1')
    const linhas = await screen.findAllByRole('row')
    expect(linhas).toHaveLength(2) // cabeçalho + 1 entrega (3 materiais)
    expect(screen.getByText('3 materiais')).toBeInTheDocument()
    expect(screen.getByText('Pistola .40, Colete +1')).toBeInTheDocument()
    expect(screen.getByText('2 Bom · 1 Regular')).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Entrega #1183' })).toHaveAttribute('href', '/movimentacoes/1183')
    expect(screen.getByText('SD FICTO').tagName).toBe('STRONG') // sem militares.ver: sem link para a tela Efetivos
    // Filtro de unidade: só o escopo, sem "Todas".
    const filtro = screen.getByRole('combobox', { name: 'Unidade' })
    expect(within(filtro).getAllByRole('option').map((o) => o.textContent)).toEqual(['Toda a base POCO', 'POCO', '— SMB'])
    await userEvent.setup().click(screen.getByRole('button', { name: /Receber devolução/ }))
    const dlg = await screen.findByRole('dialog')
    expect(await within(dlg).findByText(/PST-1/)).toBeInTheDocument()
    expect(within(dlg).getByText(/COL-1/)).toBeInTheDocument()
    expect(within(dlg).getByRole('spinbutton')).toHaveValue(30)
  }, 30_000)

  it('Devolução de material: uma busca, resultado agrupado por efetivo e por entrega', async () => {
    const pedidos = roteador({ '/api/v1/me': () => json(200, perfil()), '/api/v1/stock/linked': () => json(200, { data: [entrega1183], meta: meta(1) }) })
    window.history.pushState({}, '', '/devolucoes')
    render(<App />)
    const busca = await screen.findByRole('searchbox', { name: /Efetivo, matrícula, CPF/ }, { timeout: 10_000 })
    expect(busca).toHaveFocus()
    expect(screen.getByText('Digite ao menos 2 caracteres para buscar.')).toBeInTheDocument()
    const u = userEvent.setup()
    await u.type(busca, 'PED-7K2M9Q')
    await u.click(screen.getByRole('button', { name: 'Buscar' }))
    expect(await screen.findByRole('heading', { level: 2, name: /SD FICTO/ })).toBeInTheDocument()
    expect(screen.getByText('Entrega #1183')).toBeInTheDocument()
    expect(screen.getByText('PED-7K2M9Q')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: /Receber devolução/ })).toBeInTheDocument()
    expect(pedidos.some((p) => p.includes('/api/v1/stock/linked?') && p.includes('q=PED-7K2M9Q'))).toBe(true)
    await u.click(screen.getByRole('button', { name: 'Ver tudo com este efetivo' }))
    await waitFor(() => expect(pedidos.some((p) => p.includes('militar_id=3'))).toBe(true))
  }, 30_000)

  it('Relatórios do armeiro: gráfico de entregas e devoluções com tabela, sem as exportações de gestão', async () => {
    const serie = (rotulo: string, a: number, b: number) => ({ rotulo, inicio: '2026-11-18', fim: '2026-11-18', armas: { entregues: a, devolvidas: b }, municao: { entregues: 30, devolvidas: 12 }, demais: { entregues: 1, devolvidas: 0 } })
    const pedidos = roteador({
      '/api/v1/me': () => json(200, perfil()),
      '/api/v1/reports/deliveries-returns': () => json(200, { de: '2026-11-17', ate: '2026-11-18', agrupar: 'dia', classes: { armas: 'Armas', municao: 'Munição', demais: 'Demais materiais' },
        series: [serie('17/11', 0, 0), serie('18/11', 2, 1)], totais: { armas: { entregues: 2, devolvidas: 1 }, municao: { entregues: 30, devolvidas: 12 }, demais: { entregues: 1, devolvidas: 0 } } }),
    })
    window.history.pushState({}, '', '/relatorios')
    render(<App />)
    expect(await screen.findByRole('heading', { level: 2, name: 'Entregas e devoluções na base' }, { timeout: 10_000 })).toBeInTheDocument()
    const totais = await screen.findByRole('list', { name: 'Totais do período' })
    expect(within(totais).getByText('Armas').parentElement).toHaveTextContent('2 entregues1 devolvidas')
    const tabela = screen.getByRole('table', { name: /Armas — entregues e devolvidas/ })
    expect(within(tabela).getByRole('row', { name: '18/11 2 1' })).toBeInTheDocument()
    expect(screen.getByRole('link', { name: /Excel/ })).toHaveAttribute('href', expect.stringContaining('/reports/deliveries-returns/export?agrupar=dia&formato=xlsx'))
    expect(screen.queryByText('Baixar CSV de Saldos de estoque')).not.toBeInTheDocument()
    await userEvent.setup().selectOptions(screen.getByRole('combobox', { name: 'Agrupar' }), 'mes')
    await waitFor(() => expect(pedidos.some((p) => p.includes('agrupar=mes'))).toBe(true))
  }, 30_000)

  it('auxiliares: conservação, nomes, raízes do escopo e agrupamento por efetivo', () => {
    expect(resumoConservacao({ BOM: 3 })).toBe('Bom')
    expect(resumoConservacao({ BOM: 2, REGULAR: 1 })).toBe('2 Bom · 1 Regular')
    expect(resumoConservacao({})).toBe('—')
    expect(nomesResumidos(entrega1183)).toBe('Pistola .40, Colete +1')
    expect(raizesEscopo([un(2, 'POCO', 1, 2), un(20, 'SMB', 2, 3)]).map((u) => u.sigla)).toEqual(['POCO'])
    expect(raizesEscopo([un(2, 'POCO', 1, 2), un(9, 'ARA', 12, 1)])).toHaveLength(2)
    const outra = { ...entrega1183, origem: '1200', entrega_id: 1200 }
    const g = porEfetivo([entrega1183, outra, { ...entrega1183, origem: '9', militar: { ...entrega1183.militar, id: 4 } }])
    expect(g.map((x) => [x.militar.id, x.entregas.length])).toEqual([[3, 2], [4, 1]])
    render(<FiltroUnidade unidades={[un(2, 'POCO', 1, 2), un(9, 'ARA', 12, 1)]} valor="" onChange={() => {}} />)
    expect(screen.getByRole('option', { name: 'Todas do meu escopo' })).toBeInTheDocument()
  })

  it('gráfico de séries sem dados mostra o vazio e mantém a tabela acessível', () => {
    render(<GraficoSeries titulo="Armas" periodos={['17/11']} series={[{ nome: 'Entregues', valores: [0] }, { nome: 'Devolvidas', valores: [0] }]} />)
    expect(screen.getByText('Nenhuma entrega ou devolução neste período.')).toBeInTheDocument()
    expect(screen.getByRole('columnheader', { name: 'Devolvidas' })).toBeInTheDocument()
  })
})
