import { render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'
import App from '../App'
import { AbrirChamadoCentral, MeusChamadosCentral } from './CentralSuporte'
import { CENTRAL_VAZIA, linkSeguro, normalizarCentral, quandoCentral } from './centralSuporte'

const URL_CENTRAL = 'http://192.168.200.8/suporte'
const ABRIR = `${URL_CENTRAL}/novo?sistema=ARSENAL`
const COD = '01JDCENTRALARSENAL00000001'
const central = {
  disponivel: true, abertos: 2, nao_lidos: 1, url_abrir: ABRIR, url_chamados: `${URL_CENTRAL}/chamados`,
  chamados: [
    { protocolo: 'SUP-2026-000002', titulo: 'Erro ao imprimir', status_rotulo: 'Em análise', aberto: true, aguardando_voce: true, atualizado_em: '2026-11-18 09:00:00', link: `${URL_CENTRAL}/chamados/${COD}` },
    { protocolo: 'SUP-2026-000001', titulo: 'Dúvida de acesso', status_rotulo: 'Em análise', aberto: true, aguardando_voce: false, atualizado_em: null, link: 'javascript:alert(1)' },
    { protocolo: 'SUP-2026-000003', titulo: 'Encerrado', status_rotulo: 'Encerrado', aberto: false, aguardando_voce: false, atualizado_em: null, link: null },
  ],
  avisos: [{ id: 7, protocolo: 'SUP-2026-000002', texto: 'A equipe respondeu o chamado SUP-2026-000002', criada_em: '2026-11-19 09:00:00', link: `${URL_CENTRAL}/chamados/${COD}` }],
}

describe('Central de Suporte PRB — normalização', () => {
  it('só aceita links http(s) e devolve vazio para resposta inválida', () => {
    expect(linkSeguro('javascript:alert(1)')).toBeNull()
    expect(linkSeguro('data:text/html,x')).toBeNull()
    expect(linkSeguro(ABRIR)).toBe(ABRIR)
    expect(normalizarCentral(null)).toEqual(CENTRAL_VAZIA)
    expect(normalizarCentral('x')).toEqual(CENTRAL_VAZIA)
    const n = normalizarCentral(central)
    expect(n.chamados[1].link).toBeNull()
    expect(n.nao_lidos).toBe(1)
    expect(quandoCentral('2026-11-18 09:05:00')).toBe('18/11/2026 09:05')
  })
})

describe('Central de Suporte PRB — componentes', () => {
  it('"Abrir chamado" aponta para a Central com o Arsenal, em nova aba e sem opener', () => {
    render(<AbrirChamadoCentral url={ABRIR} />)
    const a = screen.getByRole('link', { name: /Abrir chamado na Central PRB/ })
    expect(a).toHaveAttribute('href', ABRIR)
    expect(a).toHaveAttribute('target', '_blank')
    expect(a).toHaveAttribute('rel', 'noopener noreferrer')
  })

  it('"Abrir chamado" some sem URL configurada', () => {
    const { container } = render(<AbrirChamadoCentral url={null} />)
    expect(container).toBeEmptyDOMElement()
  })

  it('"Meus chamados": só abertos, selo "Aguardando você", "Ver na Central" só com link seguro', () => {
    render(<MeusChamadosCentral central={normalizarCentral(central)} />)
    const bloco = screen.getByRole('region', { name: /Meus chamados na Central PRB/ })
    expect(within(bloco).getAllByRole('listitem')).toHaveLength(2)
    expect(within(bloco).getByText('Aguardando você')).toHaveClass('selo-alerta')
    const ver = within(bloco).getAllByRole('link', { name: /Ver na Central/ })
    expect(ver).toHaveLength(1)
    expect(ver[0]).toHaveAttribute('href', `${URL_CENTRAL}/chamados/${COD}`)
    expect(within(bloco).getByRole('link', { name: /Ver todos na Central/ })).toHaveAttribute('href', `${URL_CENTRAL}/chamados`)
    expect(within(bloco).queryByText('Encerrado')).not.toBeInTheDocument()
  })

  it('leitura desligada (disponivel=false) esconde o cartão', () => {
    const { container } = render(<MeusChamadosCentral central={{ ...CENTRAL_VAZIA, url_abrir: ABRIR }} />)
    expect(container).toBeEmptyDOMElement()
  })
})

const json = (status: number, body: unknown) => new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } })
const perfil = {
  usuario: { id: 1, nome: 'Operador Fictício', login: '52998224725', militar: null }, papeis: [{ papel: 'OPERADOR', papel_nome: 'Operador', unidade: { id: 1, sigla: 'UN' } }],
  permissoes: ['estoque.ver', 'suporte.abrir'], operacoes: [], ambiente: 'DEMONSTRACAO',
}
function roteador(centralResp: () => Response) {
  const pedidos: string[] = []
  vi.spyOn(globalThis, 'fetch').mockImplementation(async (url) => {
    const u = String(url)
    pedidos.push(u)
    const p = u.replace(/\?.*$/, '')
    if (p === '/api/v1/me') return json(200, perfil)
    if (p === '/api/v1/notifications') return json(200, { nao_lidas: 2, data: [] })
    if (p === '/api/v1/alerts') return json(200, { data: [], gerado_em: '2026-11-19T10:00:00-03:00' })
    if (p === '/api/v1/central-suporte') return centralResp()
    return new Response(null, { status: 204 })
  })
  return pedidos
}
afterEach(() => { vi.restoreAllMocks(); window.history.pushState({}, '', '/') })

describe('Central de Suporte PRB — no Arsenal', () => {
  it('sino soma os avisos da Central e Alertas mostra "Meus chamados"; o cliente nunca envia CPF', async () => {
    window.history.pushState({}, '', '/alertas')
    const pedidos = roteador(() => json(200, central))
    render(<App />)
    expect(await screen.findByRole('region', { name: /Meus chamados na Central PRB/ })).toBeInTheDocument()
    const sino = await screen.findByRole('button', { name: /Notificações \(3 não lidas\)/ })
    await userEvent.click(sino)
    const painel = screen.getByRole('region', { name: 'Notificações' })
    const aviso = within(painel).getByRole('link', { name: /A equipe respondeu/ })
    expect(aviso).toHaveAttribute('href', `${URL_CENTRAL}/chamados/${COD}`)
    expect(aviso).toHaveAttribute('target', '_blank')
    expect(within(painel).getByRole('link', { name: /Abrir chamado na Central PRB/ })).toHaveAttribute('href', ABRIR)
    const consultas = pedidos.filter((u) => u.includes('/central-suporte'))
    expect(consultas.length).toBeGreaterThan(0)
    consultas.forEach((u) => expect(u).not.toMatch(/\?|52998224725/))
  })

  it('Central fora do ar (erro 500): blocos escondidos e a tela segue funcionando', async () => {
    window.history.pushState({}, '', '/alertas')
    roteador(() => json(500, { code: 'ERRO', message: 'x' }))
    render(<App />)
    expect(await screen.findByText('Nenhum alerta no seu escopo.')).toBeInTheDocument()
    await waitFor(() => expect(screen.getByRole('button', { name: /Notificações \(2 não lidas\)/ })).toBeInTheDocument())
    expect(screen.queryByRole('region', { name: /Meus chamados na Central PRB/ })).not.toBeInTheDocument()
    expect(screen.queryByRole('link', { name: /Abrir chamado na Central PRB/ })).not.toBeInTheDocument()
  })
})
