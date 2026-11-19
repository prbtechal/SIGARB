import { render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'
import App from '../App'

const json = (status: number, body: unknown) => new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } })
const perfil = (permissoes: string[]) => ({
  usuario: { id: 1, nome: 'Gestora Fictícia', login: 'g', militar: null }, papeis: [{ papel: 'GESTOR', papel_nome: 'Gestor', unidade: { id: 1, sigla: 'UN' } }],
  permissoes, operacoes: [{ codigo: 'ENTRADA', estado: 'HABILITADA' }, { codigo: 'SAIDA', estado: 'HABILITADA', regra_provisoria: 'x' }, { codigo: 'BAIXA', estado: 'HABILITADA', regra_provisoria: 'y' }], ambiente: 'DEMONSTRACAO',
})
const meta = (total: number) => ({ page: 1, per_page: 50, total, last_page: 1 })
const locais = { data: [{ id: 5, codigo: 'RES-1', nome: 'Reserva', tipo: 'RESERVA', ativo: true, unidade: { id: 1, sigla: 'UN', nome: 'U' } }] }

function roteador(rotas: Record<string, (init?: RequestInit, url?: string) => Response>) {
  return vi.spyOn(globalThis, 'fetch').mockImplementation(async (url, init) => {
    const u = String(url).replace(/\?.*$/, '')
    const h = rotas[`${init?.method ?? 'GET'} ${u}`] ?? rotas[u]
    return h ? h(init, String(url)) : new Response(null, { status: 204 })
  })
}

/** XHR simulado (upload com progresso); guarda o destino e o FormData enviados. */
function xhrFalso(resposta: { status: number; body: unknown }) {
  const enviados: { url: string; fd: FormData }[] = []
  class X {
    upload: { onprogress?: (e: { lengthComputable: boolean; loaded: number; total: number }) => void } = {}
    status = 0; responseText = ''; withCredentials = false; url = ''
    onload?: () => void; onerror?: () => void
    open(_m: string, u: string) { this.url = u }
    setRequestHeader() {}
    send(fd: FormData) {
      enviados.push({ url: this.url, fd })
      setTimeout(() => { this.status = resposta.status; this.responseText = JSON.stringify(resposta.body); this.onload?.() }, 0)
    }
  }
  vi.stubGlobal('XMLHttpRequest', X)
  return enviados
}

afterEach(() => { vi.restoreAllMocks(); vi.unstubAllGlobals(); window.history.pushState({}, '', '/') })

describe('origem com lista e "Outro…" na entrada', () => {
  it('teclado: filtra, Enter escolhe da lista; "Outro…" libera o campo e grava o texto digitado; anexo vai depois do registro', async () => {
    const corpos: Record<string, unknown>[] = []
    roteador({
      '/api/v1/me': () => json(200, perfil(['estoque.ver', 'estoque.entrada', 'documentos.enviar'])),
      '/api/v1/locations': () => json(200, locais),
      '/api/v1/items': () => json(200, { data: [{ id: 8, codigo: 'LOT', nome: 'Lote', modo_controle: 'LOTE', categoria: { id: 1, nome: 'c', natureza: 'MUNICAO' } }], meta: meta(1) }),
      '/api/v1/stock/origins': () => json(200, { data: [{ valor: 'Doação da SENASP', usos: 3 }, { valor: 'Compra direta', usos: 1 }] }),
      'POST /api/v1/movements': (init) => { corpos.push(JSON.parse(String(init?.body))); return json(201, { id: 41, tipo: 'ENTRADA' }) },
    })
    const enviados = xhrFalso({ status: 201, body: { codigo: '01JABCDEFGHJKMNPQRSTVWXYZ0', titulo: 'Entrada #41' } })
    window.history.pushState({}, '', '/movimentacoes/nova-entrada')
    render(<App />)
    const u = userEvent.setup()
    await u.selectOptions(await screen.findByLabelText(/Local de destino/), '5')
    await u.selectOptions(screen.getByLabelText(/Material \(item 1\)/), '8')
    await u.type(screen.getByLabelText(/Código do lote/), 'L1')
    await u.type(screen.getByLabelText(/Documento de referência/), 'NF-1')

    const origem = screen.getByRole('combobox', { name: /^Origem/ })
    await u.type(origem, 'senasp')
    const lista = screen.getByRole('listbox')
    expect(within(lista).getAllByRole('option').map((o) => o.textContent)).toEqual(['Doação da SENASP3×', 'Outro…usar “senasp”'])
    await u.keyboard('{Enter}')
    expect(origem).toHaveValue('Doação da SENASP')

    // Origem que não existe: ↓ até "Outro…", Enter, o campo livre já vem com o texto e recebe o foco.
    await u.clear(origem)
    await u.type(origem, 'Apreensão')
    await u.keyboard('{ArrowDown}{Enter}')
    const livre = screen.getByLabelText(/^Origem/)
    expect(livre).not.toHaveAttribute('role', 'combobox')
    expect(livre).toHaveFocus()
    await u.type(livre, ' judicial 12/2026')
    expect(screen.getByRole('button', { name: /Escolher da lista/ })).toBeInTheDocument()

    await u.upload(screen.getByLabelText(/Anexar o documento de referência/), new File(['%PDF-1.4'], 'nota.pdf', { type: 'application/pdf' }))
    expect(screen.getByText('nota.pdf', { selector: '.anexo-nome' })).toBeInTheDocument()
    await u.click(screen.getByRole('button', { name: 'Revisar entrada' }))
    await u.click(await screen.findByRole('button', { name: 'Confirmar e registrar' }))
    await waitFor(() => expect(enviados).toHaveLength(1))
    expect(corpos[0]).toMatchObject({ origem_descricao: 'Apreensão judicial 12/2026', documento_referencia: 'NF-1' })
    expect(enviados[0].url).toMatch(/\/api\/v1\/movements\/41\/documents$/)
    expect((enviados[0].fd.get('arquivo') as File).name).toBe('nota.pdf')
  })

  it('anexo inválido é recusado no navegador e bloqueia o envio', async () => {
    roteador({
      '/api/v1/me': () => json(200, perfil(['estoque.ver', 'estoque.entrada', 'documentos.enviar'])),
      '/api/v1/locations': () => json(200, locais),
      '/api/v1/items': () => json(200, { data: [], meta: meta(0) }),
    })
    window.history.pushState({}, '', '/movimentacoes/nova-entrada')
    render(<App />)
    const u = userEvent.setup({ applyAccept: false })
    await u.upload(await screen.findByLabelText(/Anexar o documento de referência/), new File(['MZ'], 'virus.exe'))
    expect(await screen.findByText('Formato não permitido. Aceitos: PDF, PNG, JPG.')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Revisar entrada' })).toBeDisabled()
  })
})

describe('registrar saída', () => {
  it('Saldos leva à saída com o lote escolhido; quantidade acima do disponível bloqueia; envia SAIDA idempotente com destinação', async () => {
    let corpo: Record<string, unknown> | undefined; let chave = ''
    const saldo = { local: { id: 5, codigo: 'RES-1', nome: 'Reserva' }, unidade: { id: 1, sigla: 'UN' }, material: { id: 8, codigo: 'MUN', nome: 'Cartucho fictício', modo_controle: 'LOTE', categoria: 'Munição', natureza: 'MUNICAO', unidade_medida: 'un' },
      lote: { id: 70, codigo: 'L-70', validade: null }, quantidade: 50, reservada: 0, disponivel: 50, pode_editar: false }
    roteador({
      '/api/v1/me': () => json(200, perfil(['estoque.ver', 'estoque.saida', 'estoque.baixar'])),
      '/api/v1/locations': () => json(200, locais),
      '/api/v1/stock/balances': () => json(200, { data: [saldo], meta: meta(1) }),
      '/api/v1/stock/individual-items': () => json(200, { data: [], meta: meta(0) }),
      '/api/v1/stock/origins': () => json(200, { data: [{ valor: 'Recolhimento ao DEP', usos: 2 }] }),
      'POST /api/v1/movements': (init) => { corpo = JSON.parse(String(init?.body)); chave = (init?.headers as Record<string, string>)['Idempotency-Key']; return json(201, { id: 90, tipo: 'SAIDA' }) },
    })
    window.history.pushState({}, '', '/estoque')
    render(<App />)
    const u = userEvent.setup()
    expect(await screen.findByRole('link', { name: 'Registrar saída' })).toHaveAttribute('href', '/estoque/saida')
    await u.click(await screen.findByRole('link', { name: /Saída\s*de Cartucho fictício lote L-70/ }))

    expect(await screen.findByRole('heading', { name: 'Registrar saída' })).toBeInTheDocument()
    expect(screen.getByRole('radio', { name: /Saída definitiva/ })).toBeChecked()
    const qtd = await screen.findByLabelText('Quantidade que sai do lote L-70')
    expect(await screen.findByText(/Vai sair/)).toBeInTheDocument() // lote da linha já selecionado
    await u.type(qtd, '60')
    expect(screen.getByText(/Quantidade acima do disponível em Lote L-70/)).toBeInTheDocument()
    await u.clear(qtd); await u.type(qtd, '20')

    const destino = screen.getByRole('combobox', { name: /Destinação/ })
    await u.click(destino)
    await u.click(screen.getByRole('option', { name: /Recolhimento ao DEP/ }))
    await u.type(screen.getByLabelText(/Documento de referência/), 'TERMO-9')
    const revisar = screen.getByRole('button', { name: 'Revisar saída' })
    expect(revisar).toBeDisabled() // motivo obrigatório
    await u.type(screen.getByLabelText(/^Motivo/), 'Recolhimento por vencimento')
    await u.click(revisar)
    await u.click(await screen.findByRole('button', { name: 'Confirmar e registrar' }))
    await waitFor(() => expect(corpo).toBeDefined())
    expect(corpo).toEqual({ tipo: 'SAIDA', local_origem_id: 5, destinacao: 'Recolhimento ao DEP', documento_referencia: 'TERMO-9', justificativa: 'Recolhimento por vencimento', itens: [{ lote_id: 70, quantidade: 20 }] })
    expect(chave).toMatch(/^[0-9a-f-]{36}$/)
  })

  it('sem permissão de saída nem de baixa a tela é negada', async () => {
    roteador({ '/api/v1/me': () => json(200, perfil(['estoque.ver'])), '/api/v1/locations': () => json(200, locais) })
    window.history.pushState({}, '', '/estoque/saida')
    render(<App />)
    expect((await screen.findAllByText(/Acesso negado|não tem permissão|Você não pode registrar saídas/i)).length).toBeGreaterThan(0)
    expect(screen.queryByRole('button', { name: 'Revisar saída' })).not.toBeInTheDocument()
  })
})

describe('documentos: raiz de busca e registros sem documento', () => {
  it('lista mostra o registro ligado; aba de pendentes anexa ao registro', async () => {
    const doc = { codigo: '01JABCDEFGHJKMNPQRSTVWXYZ0', titulo: 'Entrada #41 — NF-1', numero_referencia: 'NF-1', tipo: { id: 1, codigo: 'NOTA_RECEBIMENTO', nome: 'Nota de recebimento' }, unidade: { id: 1, sigla: 'UN' },
      nome_original: 'nota.pdf', mime: 'application/pdf', tamanho_bytes: 2048, situacao: 'ATIVO', enviado_por: 'Gestora', enviado_em: '2026-11-19T10:00:00Z', versao: 2, substituido_por: null,
      registros: [{ movimentacao_id: 41, tipo: 'ENTRADA', origem: 'Doação da SENASP', documento_referencia: 'NF-1' }] }
    const pend = { id: 42, tipo: 'SAIDA', ocorrido_em: '2026-11-19T11:00:00Z', origem: 'Recolhimento', documento_referencia: 'TERMO-2', local: { id: 5, codigo: 'RES-1' }, itens_count: 1, responsavel: 'Gestora', pode_anexar: true }
    let consulta = ''
    roteador({
      '/api/v1/me': () => json(200, perfil(['documentos.ver', 'documentos.enviar'])),
      '/api/v1/document-types': () => json(200, { data: [{ id: 1, codigo: 'NOTA_RECEBIMENTO', nome: 'Nota de recebimento' }, { id: 2, codigo: 'TERMO', nome: 'Termo' }] }),
      '/api/v1/documents': (_i, url) => { consulta = url ?? ''; return json(200, { data: [doc], meta: meta(1) }) },
      '/api/v1/documents/pending-records': () => json(200, { data: [pend], meta: { ...meta(1), per_page: 20 } }),
    })
    const enviados = xhrFalso({ status: 201, body: { ...doc, codigo: '01JABCDEFGHJKMNPQRSTVWXYZ1', titulo: 'Saída #42 — TERMO-2' } })
    window.history.pushState({}, '', '/documentos')
    render(<App />)
    const u = userEvent.setup()
    expect(await screen.findByRole('link', { name: 'Entrada #41' })).toHaveAttribute('href', '/movimentacoes/41')
    expect(screen.getByText('v2')).toBeInTheDocument()
    await u.type(screen.getByLabelText('Buscar'), 'senasp')
    await u.click(screen.getByRole('button', { name: 'Buscar' }))
    await waitFor(() => expect(consulta).toContain('q=senasp'))

    await u.click(screen.getByRole('button', { name: /Registros sem documento/ }))
    await screen.findByRole('link', { name: 'Saída #42' })
    await u.click(screen.getByRole('button', { name: /Anexar\s*documento à Saída #42/ }))
    const dlg = await screen.findByRole('dialog', { name: 'Anexar documento à Saída #42' })
    await waitFor(() => expect(within(dlg).getByLabelText('Tipo de documento')).toHaveValue('2')) // termo é o padrão da saída
    await u.upload(within(dlg).getByLabelText(/^Arquivo/), new File(['%PDF-1.4'], 'termo.pdf', { type: 'application/pdf' }))
    await u.click(within(dlg).getByRole('button', { name: 'Anexar' }))
    await waitFor(() => expect(enviados).toHaveLength(1))
    expect(enviados[0].url).toMatch(/\/api\/v1\/movements\/42\/documents$/)
    expect(enviados[0].fd.get('tipo_documento_id')).toBe('2')
  })
})
