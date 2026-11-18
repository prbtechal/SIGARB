import { render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'
import App from '../App'
import { soPortal } from '../api/tipos'

const json = (status: number, body: unknown) => new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } })
const op = (codigo: string) => ({ codigo, estado: 'HABILITADA', pendencia: 'Decisão', rotulo: codigo })
const perfilEfetivo = {
  usuario: { id: 9, nome: 'Pessoa Fictícia', login: '52998224725', militar: { id: 3, nome_guerra: 'FICTO', posto: 'SD' } },
  papeis: [{ papel: 'EFETIVO', papel_nome: 'Efetivo', unidade: { id: 1, sigla: 'UN' } }], permissoes: ['portal.efetivo'], operacoes: [op('ENTREGA')], ambiente: 'DEMONSTRACAO',
}
const perfilArmeiro = {
  usuario: { id: 1, nome: 'Armeiro Fictício', login: 'a', militar: null }, papeis: [{ papel: 'ARMEIRO', papel_nome: 'Armeiro', unidade: { id: 1, sigla: 'UN' } }],
  permissoes: ['estoque.ver', 'estoque.entregar', 'militares.ver', 'pedidos.gerir'], operacoes: [op('ENTREGA')], ambiente: 'DEMONSTRACAO',
}
const efetivoR1 = { nome: 'Pessoa Fictícia', nome_guerra: 'FICTO', posto: 'SD', unidade: 'UN', categoria_efetivo: 'R1', apto_arma_fogo: false, situacao: 'ATIVO' }
const COD = '01JF0000000000000000ABCDEF'
const pedido = (extra: Record<string, unknown> = {}) => ({
  codigo: COD, rotulo: 'PED-ABCDEF', status: 'PENDENTE', versao: 1, efetivo: { id: 3, nome_guerra: 'FICTO', posto: 'SD', categoria_efetivo: 'PM_ATIVA', apto_arma_fogo: true },
  unidade: { id: 1, sigla: 'UN' }, retirada_prevista: null, local_retirada: { id: 1, codigo: 'RES', nome: 'Reserva' }, itens_resumo: 'Algema × 10', tem_letal: false,
  criado_em: '2026-11-18T10:00:00-03:00', decidido_em: null, entregue_em: null, observacao: 'Serviço', motivo_recusa: null, observacao_armeiro: null, decidido_por: null,
  entregue_por: null, cancelado_em: null, entrega_movimentacao_id: null, acautelamentos: [],
  itens: [{ id: 11, quantidade: 10, quantidade_aprovada: null, quantidade_entregue: null, material: { id: 9, codigo: 'AG', nome: 'Algema', modo_controle: 'LOTE', unidade_medida: 'UN', categoria: 'Contenção', natureza: 'EQUIPAMENTO', natureza_rotulo: 'EPI', perfil: null, acautelamento: false } }],
  ...extra,
})

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

describe('portal do efetivo', () => {
  it('soPortal: só quem tem apenas permissões do portal', () => {
    expect(soPortal(['portal.efetivo'])).toBe(true)
    expect(soPortal(['portal.efetivo', 'suporte.abrir'])).toBe(true)
    expect(soPortal(['portal.efetivo', 'estoque.ver'])).toBe(false)
    expect(soPortal(['estoque.ver'])).toBe(false)
  })

  it('menu enxuto e início do efetivo com aviso de pedidos e devolução atrasada', async () => {
    roteador({
      '/api/v1/me': () => json(200, perfilEfetivo),
      '/api/v1/notifications': () => json(200, { nao_lidas: 0, data: [] }),
      '/api/v1/portal/summary': () => json(200, { sem_vinculo: false, efetivo: { ...efetivoR1, categoria_efetivo: null }, pedidos_abertos: [pedido({ status: 'APROVADO' })], limite_abertos: 3, material: { total: 2, atrasados: 1 } }),
    })
    render(<App />)
    expect(await screen.findByRole('heading', { name: 'Olá, FICTO' }, { timeout: 10_000 })).toBeInTheDocument()
    const nav = screen.getByRole('navigation', { name: 'Navegação principal' })
    expect(within(nav).getByRole('link', { name: 'Novo pedido' })).toBeInTheDocument()
    expect(within(nav).getByRole('link', { name: 'Material comigo' })).toBeInTheDocument()
    expect(within(nav).queryByRole('link', { name: /Saldos de estoque|Alertas|Operações/ })).not.toBeInTheDocument()
    expect(screen.getByText(/Seu tipo de efetivo não está cadastrado/)).toBeInTheDocument()
    expect(screen.getByText(/Procure a T.I./)).toBeInTheDocument()
    expect(screen.getByText(/Devolução atrasada/)).toBeInTheDocument()
    expect(screen.getByText(/mostre o comprovante ao armeiro/)).toBeInTheDocument()
  })

  it('novo pedido: arma bloqueada para R1 com o motivo e payload enviado', async () => {
    const enviados = roteador({
      '/api/v1/me': () => json(200, perfilEfetivo),
      '/api/v1/notifications': () => json(200, { nao_lidas: 0, data: [] }),
      '/api/v1/portal/catalog': () => json(200, { efetivo: efetivoR1, limite_abertos: 3, abertos: 0, locais: [{ id: 1, codigo: 'RES', nome: 'Reserva', unidade: 'UN' }], data: [
        { id: 1, codigo: 'PST', nome: 'Pistola', modo_controle: 'INDIVIDUAL', unidade_medida: 'UN', categoria: 'Armas', natureza: 'ARMAMENTO', natureza_rotulo: 'Armamento', tipo: 'Pistola', perfil: 'ARMA_FOGO', bloqueio: 'Arma de fogo e munição são só para RR (PM/BM) e PM/BM da ativa. Seu tipo de efetivo: R1.' },
        { id: 2, codigo: 'RAD', nome: 'Rádio', modo_controle: 'INDIVIDUAL', unidade_medida: 'UN', categoria: 'Comunicação', natureza: 'EQUIPAMENTO', natureza_rotulo: 'EPI', tipo: null, perfil: 'GERAL', bloqueio: null },
        { id: 3, codigo: 'AG', nome: 'Algema', modo_controle: 'LOTE', unidade_medida: 'UN', categoria: 'Contenção', natureza: 'EQUIPAMENTO', natureza_rotulo: 'EPI', tipo: null, perfil: 'GERAL', bloqueio: null },
      ] }),
      'POST /api/v1/portal/requests': () => json(201, pedido()),
      [`/api/v1/portal/requests/${COD}`]: () => json(200, { ...pedido(), pode_cancelar: true }),
    })
    window.history.pushState({}, '', '/portal/novo')
    render(<App />)
    const u = userEvent.setup()
    await screen.findByText('Pistola', {}, { timeout: 10_000 })
    expect(screen.queryByRole('checkbox', { name: /Pedir Pistola/ })).not.toBeInTheDocument()
    expect(screen.getByText(/Seu tipo de efetivo: R1/)).toBeInTheDocument()
    await u.click(screen.getByRole('checkbox', { name: 'Pedir Rádio' }))
    await u.type(screen.getByRole('spinbutton', { name: 'Quantidade de Algema' }), '4')
    await u.selectOptions(screen.getByRole('combobox', { name: /Local de retirada/ }), '1')
    await u.click(screen.getByRole('button', { name: 'Enviar pedido' }))
    await waitFor(() => expect(enviados.find((e) => e.url.endsWith('/portal/requests'))).toBeTruthy(), { timeout: 10_000 })
    expect(enviados.find((e) => e.url.endsWith('/portal/requests'))!.corpo).toEqual({ itens: [{ material_id: 2, quantidade: 1 }, { material_id: 3, quantidade: 4 }], local_retirada_id: 1 })
    expect(await screen.findByRole('heading', { name: 'Pedido PED-ABCDEF' }, { timeout: 10_000 })).toBeInTheDocument()
  })

  it('pedido aprovado mostra o comprovante; cancelar envia a versão', async () => {
    const enviados = roteador({
      '/api/v1/me': () => json(200, perfilEfetivo),
      '/api/v1/notifications': () => json(200, { nao_lidas: 0, data: [] }),
      [`/api/v1/portal/requests/${COD}`]: () => json(200, { ...pedido({ status: 'APROVADO', versao: 2, decidido_em: '2026-11-18T11:00:00-03:00', decidido_por: 'Armeiro' }), pode_cancelar: true }),
      [`POST /api/v1/portal/requests/${COD}/cancel`]: () => json(200, { ...pedido({ status: 'CANCELADO' }), pode_cancelar: false }),
    })
    window.history.pushState({}, '', `/portal/pedidos/${COD}`)
    render(<App />)
    const u = userEvent.setup()
    expect(await screen.findByText('Comprovante para a retirada', {}, { timeout: 10_000 })).toBeInTheDocument()
    expect(screen.getByLabelText('Código PED-ABCDEF')).toBeInTheDocument()
    await u.click(screen.getByRole('button', { name: 'Cancelar pedido' }))
    await u.click(screen.getByRole('button', { name: 'Confirmar cancelamento' }))
    await waitFor(() => expect(enviados.find((e) => e.url.endsWith('/cancel'))?.corpo).toEqual({ versao: 2 }), { timeout: 10_000 })
  })
})

describe('pedidos de material (armeiro)', () => {
  it('aprova reduzindo a quantidade e recusa exige motivo', async () => {
    const enviados = roteador({
      '/api/v1/me': () => json(200, perfilArmeiro),
      '/api/v1/notifications': () => json(200, { nao_lidas: 0, data: [] }),
      '/api/v1/material-requests/summary': () => json(200, { pendentes: 1, aprovados: 0 }),
      [`/api/v1/material-requests/${COD}`]: () => json(200, { ...pedido(), acoes: { decidir: true, entregar: false },
        efetivo_conferencia: { id: 3, nome: 'Pessoa Fictícia', nome_guerra: 'FICTO', posto: 'SD', unidade: 'UN', matricula: '•••123', cpf_mascarado: '•••.982.247-••', situacao: 'ATIVO', categoria_efetivo: 'PM_ATIVA', apto_arma_fogo: true, foto_versao: null } }),
      [`POST /api/v1/material-requests/${COD}/decision`]: () => json(200, pedido({ status: 'APROVADO' })),
    })
    window.history.pushState({}, '', `/pedidos/${COD}`)
    render(<App />)
    const u = userEvent.setup()
    expect(await screen.findByText('Conferência de identidade', {}, { timeout: 10_000 })).toBeInTheDocument()
    expect(screen.getByText('•••.982.247-••')).toBeInTheDocument()
    await u.click(screen.getByRole('button', { name: 'Recusar' }))
    const dialogo = screen.getByRole('dialog')
    expect(within(dialogo).getByRole('button', { name: 'Confirmar' })).toBeDisabled()
    await u.click(within(dialogo).getByRole('button', { name: 'Voltar' }))
    await u.click(screen.getByRole('button', { name: 'Aprovar' }))
    const qtd = screen.getByRole('spinbutton', { name: /Algema/ })
    await u.clear(qtd)
    await u.type(qtd, '6')
    await u.click(within(screen.getByRole('dialog')).getByRole('button', { name: 'Confirmar' }))
    await waitFor(() => expect(enviados.find((e) => e.url.endsWith('/decision'))?.corpo).toEqual({ decisao: 'APROVAR', versao: 1, itens: [{ id: 11, quantidade_aprovada: 6 }] }), { timeout: 10_000 })
  })
})
