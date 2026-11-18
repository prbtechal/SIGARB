import { fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'
import App from '../App'
import { AjusteQuantidadeDialogo, EditarItemDialogo } from '../components/estoque/EdicaoDialogos'
import { rotuloNatureza } from '../components/ui'
import type { ItemIndividual } from '../api/tipos'

const json = (status: number, body: unknown) => new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } })
const meta = (total: number) => ({ page: 1, per_page: 20, total, last_page: 1 })
const perfil = {
  usuario: { id: 1, nome: 'Armeiro Fictício', login: 'a', militar: null }, papeis: [{ papel: 'ARMEIRO', papel_nome: 'Armeiro', unidade: { id: 1, sigla: 'UN' } }],
  // Decisão 2026-11-18: o armeiro não tem militares.ver (tela Efetivos); acha o efetivo pela busca do balcão.
  permissoes: ['estoque.ver', 'estoque.entregar', 'estoque.baixar', 'estoque.editar', 'pedidos.gerir', 'relatorios.entregas'],
  operacoes: [{ codigo: 'ENTREGA', estado: 'HABILITADA', pendencia: 'Decisão', rotulo: 'Entrega' }, { codigo: 'BAIXA', estado: 'HABILITADA', pendencia: 'Decisão', rotulo: 'Baixa' }], ambiente: 'DEMONSTRACAO',
}
type Rota = (init?: RequestInit) => Response
function roteador(rotas: Record<string, Rota>, enviados: { url: string; corpo: unknown }[] = []) {
  vi.spyOn(globalThis, 'fetch').mockImplementation(async (url, init) => {
    const u = String(url).replace(/\?.*$/, '')
    if (init?.method && init.method !== 'GET' && init.body) enviados.push({ url: u, corpo: JSON.parse(String(init.body)) })
    const h = rotas[`${init?.method ?? 'GET'} ${u}`] ?? rotas[u]
    return h ? h(init) : new Response(null, { status: 204 })
  })
  return enviados
}
afterEach(() => { vi.restoreAllMocks(); window.history.pushState({}, '', '/') })

const efetivoR1 = { id: 3, nome: 'Pessoa Fictícia', nome_guerra: 'FICTO', matricula: '***', matricula_completa: false, situacao: 'ATIVO', categoria_efetivo: 'R1', apto_arma_fogo: false,
  posto_graduacao: { id: 1, sigla: 'SD' }, unidade: { id: 1, sigla: 'UN' }, pode_editar: false }
const opcoes = { local_origem_padrao_id: 1,
  documentos: [{ codigo: 'CAUTELA', rotulo: 'Cautela', descricao: 'Termo de cautela assinado pelo efetivo' }, { codigo: 'SEM_DOCUMENTO', rotulo: 'Sem documento', descricao: 'Entrega sem documento de referência' }],
  motivos: [{ codigo: 'PATRULHA', rotulo: 'Serviço de patrulha (escala)', descricao: 'Material para o serviço ordinário do turno' }, { codigo: 'OUTRO', rotulo: 'Outro', descricao: 'Descreva o motivo' }] }
const item = (id: number, serie: string, natureza: string, nome: string) => ({ id, identificador: `X-${id}`, numero_serie: serie, situacao: 'DISPONIVEL', estado_conservacao: 'BOM',
  fabricado_em: null, validade: null, validade_estado: null, tamanho: null, nivel_protecao: null, material: { id: id * 10, codigo: 'M', nome, tipo: null, natureza },
  local: { id: 1, codigo: 'RES', nome: 'Reserva' }, unidade: { id: 1, sigla: 'UN' }, pode_atualizar_conservacao: true, pode_editar: true, pode_baixar: true })

export const entregaAgrupada = { custodia: 'ENTREGA', origem: '7', desde: '2026-11-15T10:00:00Z', entrega_id: 7, pedido: null, militar: { id: 3, posto: 'SD', nome_guerra: 'FICTO' },
  unidade: { id: 1, sigla: 'UN' }, local: { id: 1, codigo: 'RES', nome: 'Reserva' }, previsao_retorno: '2026-11-10', atrasado: true, total_itens: 1, quantidade_total: 1,
  conservacao: { BOM: 1 }, pode_receber: true, itens: [{ material: { id: 1, codigo: 'C', nome: 'Colete', modo_controle: 'INDIVIDUAL', categoria: 'EPI', tipo: null, perfil: null, natureza_rotulo: 'EPI' },
    item_individual_id: 11, lote_id: null, referencia: 'EPI-000011', numero_serie: 'CL-11', quantidade: 1, situacao: 'ENTREGUE', estado_conservacao: 'BOM', validade: null }] }

describe('fluxo do armeiro', () => {
  it('entrega: bloqueia arma para R1 e envia o payload completo com item e lote', async () => {
    const enviados = roteador({
      '/api/v1/me': () => json(200, perfil),
      '/api/v1/locations': () => json(200, { data: [{ id: 1, codigo: 'RES', nome: 'Reserva', tipo: 'RESERVA', ativo: true, unidade: { id: 1, sigla: 'UN', nome: 'Unidade' } }] }),
      '/api/v1/stock/recipients': () => json(200, { data: [efetivoR1] }),
      '/api/v1/stock/delivery-options': () => json(200, opcoes),
      '/api/v1/stock/individual-items': () => json(200, { data: [item(1, 'PST-01', 'ARMAMENTO', 'Pistola'), item(2, 'CL-01', 'EQUIPAMENTO', 'Colete')], meta: meta(2) }),
      '/api/v1/stock/balances': () => json(200, { data: [{ local: { id: 1, codigo: 'RES', nome: 'Reserva' }, unidade: { id: 1, sigla: 'UN' },
        material: { id: 9, codigo: 'AG', nome: 'Algema', modo_controle: 'LOTE', categoria: 'Contenção', natureza: 'EQUIPAMENTO', unidade_medida: 'UN' },
        lote: { id: 5, codigo: 'L-1', validade: null }, quantidade: 10, reservada: 0, disponivel: 10 }], meta: meta(1) }),
      'POST /api/v1/movements': () => json(201, { id: 77 }),
    })
    window.history.pushState({}, '', '/estoque/vinculados/nova-entrega') // endereço antigo redireciona
    render(<App />)
    const u = userEvent.setup()
    await u.type(await screen.findByRole('searchbox', { name: /Efetivo/ }, { timeout: 10_000 }), 'FIC')
    await u.click(await screen.findByRole('button', { name: /FICTO/ }, { timeout: 5_000 }))
    expect(screen.getByText(/pode receber os demais materiais/)).toBeInTheDocument()
    // Local de origem já vem com a reserva padrão da base.
    await waitFor(() => expect(screen.getByRole('combobox', { name: /Local de origem/ })).toHaveValue('1'))
    await screen.findByText('Pistola')
    expect(screen.queryByRole('checkbox', { name: /PST-01/ })).not.toBeInTheDocument() // arma: sem checkbox para R1
    expect(screen.getAllByText('Não pode para R1').length).toBeGreaterThan(0)
    await u.click(screen.getByRole('checkbox', { name: /CL-01/ }))
    await u.type(screen.getByRole('spinbutton', { name: /lote L-1/ }), '3')
    const revisar = screen.getByRole('button', { name: 'Revisar entrega' })
    await u.selectOptions(screen.getByRole('combobox', { name: /Motivo/ }), 'OUTRO')
    expect(revisar).toBeDisabled() // "Outro" exige descrever
    await u.type(screen.getByRole('textbox', { name: /Descreva o motivo/ }), 'Apoio à Defesa Civil')
    await u.selectOptions(screen.getByRole('combobox', { name: /Documento de referência/ }), 'CAUTELA')
    expect(screen.getByText('Termo de cautela assinado pelo efetivo')).toBeInTheDocument() // descrição da opção como dica
    await u.type(screen.getByRole('textbox', { name: /Número ou identificação/ }), '1')
    await u.click(revisar)
    await u.click(within(await screen.findByRole('dialog')).getByRole('button', { name: 'Confirmar entrega' }))
    await waitFor(() => expect(enviados.find((e) => e.url === '/api/v1/movements')?.corpo).toEqual({
      tipo: 'ENTREGA', local_origem_id: 1, militar_id: 3, motivo_codigo: 'OUTRO', justificativa: 'Apoio à Defesa Civil', previsao_retorno: expect.stringMatching(/^\d{4}-\d{2}-\d{2}$/),
      documento_codigo: 'CAUTELA', documento_numero: '1', itens: [{ item_individual_id: 2 }, { lote_id: 5, quantidade: 3 }] }))
  }, 30_000)

  it('devolução só pelo próprio efetivo, com item inservível, oferece a baixa e envia os dois registros', async () => {
    const vinc = entregaAgrupada
    const enviados = roteador({
      '/api/v1/me': () => json(200, perfil),
      '/api/v1/stock/linked': () => json(200, { data: [vinc], meta: meta(1) }),
      '/api/v1/movements/7': () => json(200, { id: 7, tipo: 'ENTREGA', ocorrido_em: '2026-11-15T10:00:00Z', responsavel: { id: 1, nome: 'A' }, local_origem: { id: 1, codigo: 'RES', nome: 'Reserva' },
        local_destino: null, documento_referencia: null, itens_count: 1, estornada: false, estorno_id: null, militar: { id: 3, nome_guerra: 'FICTO' }, previsao_retorno: '2026-11-10', itens: [],
        pendente_devolucao: { itens: [{ item_individual_id: 11, identificador: 'EPI-000011', numero_serie: 'CL-11', estado_conservacao: 'BOM', material: { codigo: 'C', nome: 'Colete' } }], lotes: [] } }),
      'POST /api/v1/movements': () => json(201, { id: 8 }),
    })
    window.history.pushState({}, '', '/entregas')
    render(<App />)
    const u = userEvent.setup()
    expect(await screen.findByText('Atrasado', {}, { timeout: 10_000 })).toBeInTheDocument()
    await u.click(screen.getByRole('button', { name: /Receber devolução/ }))
    const dlg = await screen.findByRole('dialog')
    await within(dlg).findByText(/agora/)
    // Regra 2026-11-18: sem "outro efetivo" nem "terceiro" — só a confirmação de quem recebeu.
    expect(within(dlg).queryByRole('radio')).not.toBeInTheDocument()
    expect(within(dlg).queryByRole('textbox', { name: /Nome de quem está devolvendo/ })).not.toBeInTheDocument()
    expect(within(dlg).getByText(/Quem está devolvendo/)).toHaveTextContent('Quem está devolvendo: FICTO')
    expect(within(dlg).getByText(/Só o próprio efetivo que recebeu pode devolver/)).toBeInTheDocument()
    await u.selectOptions(within(dlg).getByRole('combobox', { name: /Estado na devolução/ }), 'INSERVIVEL')
    await u.type(within(dlg).getByRole('textbox', { name: /Observação/ }), 'Placa rachada')
    await u.click(within(dlg).getByRole('button', { name: 'Registrar devolução' }))
    await waitFor(() => expect(enviados[0]?.corpo).toEqual({ tipo: 'DEVOLUCAO', vinculo_id: 7, itens: [{ item_individual_id: 11, estado_conservacao: 'INSERVIVEL' }],
      justificativa: 'Placa rachada' }))

    const bx = await screen.findByRole('dialog', { name: 'Registrar baixa' })
    await u.type(within(bx).getByRole('textbox', { name: /Documento/ }), 'TERMO-1')
    await u.click(within(bx).getByRole('button', { name: 'Confirmar baixa' }))
    await waitFor(() => expect(enviados[1]?.corpo).toEqual({ tipo: 'BAIXA', local_origem_id: 1, documento_referencia: 'TERMO-1',
      justificativa: 'Devolvido com problema pelo efetivo', itens: [{ item_individual_id: 11 }] }))
  }, 30_000)

  it('ajuste de quantidade: recusa abaixo do reservado e envia quantidade correta, saldo visto e motivo', async () => {
    const enviados = roteador({ 'POST /api/v1/stock/adjustments': () => json(201, { id: 30 }) })
    const salvo = vi.fn()
    render(<AjusteQuantidadeDialogo saldo={{ local: { id: 1, codigo: 'RES', nome: 'Reserva' }, lote: { id: 5, codigo: 'L-1' }, material: 'Munição', quantidade: 50, reservada: 10 }}
      onFechar={() => {}} onSalvo={salvo} />)
    const u = userEvent.setup()
    const qtd = screen.getByRole('spinbutton', { name: /Quantidade correta/ })
    await u.clear(qtd); await u.type(qtd, '8')
    expect(screen.getByRole('alert')).toHaveTextContent('abaixo da quantidade reservada')
    await u.clear(qtd); await u.type(qtd, '45')
    expect(screen.getByText(/Saída de 5/)).toBeInTheDocument()
    await u.type(screen.getByRole('textbox', { name: /Motivo/ }), 'Contagem física do dia')
    await u.click(screen.getByRole('button', { name: 'Registrar ajuste' }))
    await waitFor(() => expect(salvo).toHaveBeenCalledWith(30))
    expect(enviados[0].corpo).toEqual({ local_id: 1, lote_id: 5, quantidade: 45, quantidade_atual: 50, motivo: 'Contagem física do dia' })
  })

  it('editar item envia só o que mudou, com motivo', async () => {
    const enviados = roteador({
      '/api/v1/catalog/tipos-material': () => json(200, { data: [], niveis_protecao: ['IIIA'], perfis: { GERAL: { rotulo: 'Geral', modo: null, material_obrig: [], material_perm: [], item_obrig: [], item_perm: [] },
        COLETE: { rotulo: 'Colete', modo: 'INDIVIDUAL', material_obrig: [], material_perm: [], item_obrig: ['tamanho', 'nivel_protecao', 'fabricado_em'], item_perm: ['validade'] } } }),
      'PATCH /api/v1/stock/individual-items/2': () => json(200, { id: 2 }),
    })
    const colete = { ...item(2, 'CL-01', 'EQUIPAMENTO', 'Colete'), tamanho: 'M', nivel_protecao: 'IIIA', fabricado_em: '2024-01-10', validade: '2029-01-10',
      material: { id: 20, codigo: 'C', nome: 'Colete', tipo: { id: 1, nome: 'Colete', perfil: 'COLETE' }, natureza: 'EQUIPAMENTO' } } as ItemIndividual
    const salvo = vi.fn()
    render(<EditarItemDialogo item={colete} onFechar={() => {}} onSalvo={salvo} />)
    const u = userEvent.setup()
    fireEvent.change(await screen.findByLabelText(/^Validade/), { target: { value: '2030-06-30' } })
    const serie = screen.getByRole('textbox', { name: /Número de série/ })
    await u.clear(serie); await u.type(serie, 'CL-01A')
    expect(screen.getByRole('button', { name: 'Salvar alterações' })).toBeDisabled() // falta o motivo
    await u.type(screen.getByRole('textbox', { name: /Motivo/ }), 'Etiqueta conferida')
    await u.click(screen.getByRole('button', { name: 'Salvar alterações' }))
    await waitFor(() => expect(salvo).toHaveBeenCalled())
    expect(enviados[0].corpo).toEqual({ motivo: 'Etiqueta conferida', numero_serie: 'CL-01A', validade: '2030-06-30' })
  })

  it('natureza EQUIPAMENTO aparece como EPI (código inalterado)', () => {
    expect(rotuloNatureza('EQUIPAMENTO')).toBe('EPI')
    expect(rotuloNatureza('MUNICAO')).toBe('Munição')
  })
})
