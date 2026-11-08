import { render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, expect, it, vi } from 'vitest'
import App from '../App'

const json = (status: number, body: unknown) => new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } })
const perfil = {
  usuario: { id: 1, nome: 'Armeiro Fictício', login: 'a', militar: null }, papeis: [{ papel: 'ARMEIRO', papel_nome: 'Armeiro', unidade: { id: 1, sigla: 'UN' } }],
  permissoes: ['estoque.ver', 'estoque.entregar'], operacoes: [{ codigo: 'ENTREGA', estado: 'HABILITADA', pendencia: 'Decisão CP-13', rotulo: 'Entrega' }], ambiente: 'DEMONSTRACAO',
}
const entrega = { id: 7, tipo: 'ENTREGA', ocorrido_em: '2026-11-08T10:00:00Z', responsavel: { id: 1, nome: 'A' }, local_origem: { id: 1, codigo: 'RES', nome: 'Reserva' }, local_destino: null,
  documento_referencia: null, itens_count: 3, estornada: false, estorno_id: null, militar: { id: 3, nome_guerra: 'FICTO' }, previsao_retorno: '2026-11-10' }

afterEach(() => { vi.restoreAllMocks(); window.history.pushState({}, '', '/') })

it('devolução parcial envia só os itens marcados e a quantidade informada do lote', async () => {
  let enviado: unknown = null
  vi.spyOn(globalThis, 'fetch').mockImplementation(async (url, init) => {
    const u = String(url).replace(/\?.*$/, '')
    if (u === '/api/v1/me') return json(200, perfil)
    if (u === '/api/v1/movements' && init?.method === 'POST') { enviado = JSON.parse(String(init.body)); return json(201, { id: 8 }) }
    if (u === '/api/v1/movements') return json(200, { data: [entrega], meta: { page: 1, last_page: 1, total: 1 } })
    if (u === '/api/v1/movements/7') return json(200, { ...entrega, itens: [], pendente_devolucao: {
      itens: [{ item_individual_id: 11, identificador: 'PST-1', estado_conservacao: 'BOM', material: { codigo: 'P', nome: 'Pistola' } },
        { item_individual_id: 12, identificador: 'COL-1', estado_conservacao: 'BOM', material: { codigo: 'C', nome: 'Colete' } }],
      lotes: [{ lote_id: 5, codigo: 'L-1', quantidade: 30, material: { codigo: 'M', nome: 'Munição' } }] } })
    return new Response(null, { status: 204 })
  })
  window.history.pushState({}, '', '/operacoes')
  render(<App />)
  const u = userEvent.setup()
  await screen.findByText('10/11/2026', {}, { timeout: 10_000 }) // consulta por texto: barata no jsdom
  await u.click(screen.getByRole('button', { name: /devolução.*operação #7/ }))
  const dlg = await screen.findByRole('dialog')
  await u.click(await within(dlg).findByRole('checkbox', { name: /COL-1/ }))
  await u.selectOptions(within(dlg).getAllByRole('combobox')[0], 'REGULAR')
  const qtd = within(dlg).getByRole('spinbutton')
  await u.clear(qtd); await u.type(qtd, '10')
  await u.click(within(dlg).getByRole('button', { name: 'Registrar devolução parcial' }))
  await waitFor(() => expect(enviado).toEqual({ tipo: 'DEVOLUCAO', vinculo_id: 7, itens: [{ item_individual_id: 11, estado_conservacao: 'REGULAR' }, { lote_id: 5, quantidade: 10 }] }))
}, 20_000) // jsdom + disco lento: o fluxo completo passa de 5 s
