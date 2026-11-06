import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'
import App from '../App'

const json = (status: number, body: unknown) => new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } })
const perfil = (permissoes: string[]) => ({ usuario: { id: 1, nome: 'Teste', login: 't', militar: null }, papeis: [], permissoes, operacoes: [], ambiente: 'DEMONSTRACAO' })
const doc = { codigo: '01JABCDEFGHJKMNPQRSTVWXYZ0', titulo: 'Termo fictício', numero_referencia: null, tipo: { id: 1, codigo: 'TERMO', nome: 'Termo' }, unidade: { id: 1, sigla: 'UN' }, nome_original: 'termo.pdf', mime: 'application/pdf', tamanho_bytes: 2048, situacao: 'ATIVO', enviado_por: 'Gestora', enviado_em: '2026-11-05T10:00:00Z', descricao: null, sha256: 'a'.repeat(64), extensao: 'pdf', arquivado_em: null, vinculos: [], vinculos_ocultos: 1, pode_gerir: false }

function rotas(m: Record<string, () => Response>) {
  return vi.spyOn(globalThis, 'fetch').mockImplementation(async (url, init) => {
    const u = String(url).replace(/\?.*$/, '')
    return (m[`${init?.method ?? 'GET'} ${u}`] ?? m[u])?.() ?? new Response(null, { status: 204 })
  })
}

/** XHR simulado: responde conforme `resposta`, emitindo progresso. */
function xhrFalso(resposta: { status: number; body: unknown }) {
  const enviados: FormData[] = []
  class X {
    upload: { onprogress?: (e: { lengthComputable: boolean; loaded: number; total: number }) => void } = {}
    status = 0; responseText = ''; withCredentials = false
    onload?: () => void; onerror?: () => void
    headers: Record<string, string> = {}
    open() {}
    setRequestHeader(k: string, v: string) { this.headers[k] = v }
    send(fd: FormData) {
      enviados.push(fd)
      setTimeout(() => { this.upload.onprogress?.({ lengthComputable: true, loaded: 50, total: 100 }); this.status = resposta.status; this.responseText = JSON.stringify(resposta.body); this.onload?.() }, 0)
    }
  }
  vi.stubGlobal('XMLHttpRequest', X)
  return enviados
}

afterEach(() => { vi.restoreAllMocks(); vi.unstubAllGlobals(); window.history.pushState({}, '', '/') })

describe('documentos', () => {
  it('envio: valida arquivo localmente, depois mostra erro do servidor no campo', async () => {
    rotas({
      '/api/v1/me': () => json(200, perfil(['documentos.ver', 'documentos.enviar'])),
      '/api/v1/document-types': () => json(200, { data: [{ id: 1, nome: 'Termo' }] }),
      '/api/v1/units': () => json(200, { data: [{ id: 1, sigla: 'UN', nome: 'Unidade', nivel: 0 }] }),
    })
    const enviados = xhrFalso({ status: 422, body: { error: { code: 'CONTEUDO_ATIVO', message: 'PDF com conteúdo ativo não é aceito.' } } })
    window.history.pushState({}, '', '/documentos/enviar')
    render(<App />)
    const u = userEvent.setup({ applyAccept: false })
    const campo = await screen.findByLabelText(/^Arquivo/)
    await u.upload(campo, new File(['MZ'], 'virus.exe'))
    expect(await screen.findByText('Formato não permitido. Aceitos: PDF, PNG, JPG.')).toBeInTheDocument()
    expect(campo).toHaveAttribute('aria-invalid', 'true')
    await u.click(screen.getByRole('button', { name: 'Enviar documento' }))
    expect(enviados).toHaveLength(0) // nada sai do navegador com arquivo inválido

    await u.selectOptions(screen.getByLabelText(/Tipo de documento/), '1')
    await u.selectOptions(screen.getByLabelText(/Unidade responsável/), '1')
    await u.type(screen.getByLabelText(/^Título/), 'Termo fictício')
    await u.upload(campo, new File(['%PDF-1.4'], 'termo.pdf', { type: 'application/pdf' }))
    await u.click(screen.getByRole('button', { name: 'Enviar documento' }))
    await waitFor(() => expect(enviados).toHaveLength(1))
    expect(enviados[0].get('titulo')).toBe('Termo fictício')
    expect((enviados[0].get('arquivo') as File).name).toBe('termo.pdf')
    expect(await screen.findByText('PDF com conteúdo ativo não é aceito.')).toBeInTheDocument()
  })

  it('detalhe: baixar é link de download; sem gerir não há editar/arquivar; vínculos fora do escopo são só contados', async () => {
    rotas({ '/api/v1/me': () => json(200, perfil(['documentos.ver'])), [`/api/v1/documents/${doc.codigo}`]: () => json(200, doc) })
    window.history.pushState({}, '', `/documentos/${doc.codigo}`)
    render(<App />)
    const baixar = await screen.findByRole('link', { name: /Baixar/ })
    expect(baixar).toHaveAttribute('href', `/api/v1/documents/${doc.codigo}/download`)
    expect(screen.queryByRole('button', { name: /Editar dados/ })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /Arquivar/ })).not.toBeInTheDocument()
    expect(screen.getByText('1 vínculo(s) fora do seu escopo.')).toBeInTheDocument()
  })

  it('documento fora do escopo mostra mensagem neutra', async () => {
    rotas({ '/api/v1/me': () => json(200, perfil(['documentos.ver'])), [`/api/v1/documents/${doc.codigo}`]: () => json(404, { error: { code: 'NAO_ENCONTRADO', message: 'Documento não encontrado.' } }) })
    window.history.pushState({}, '', `/documentos/${doc.codigo}`)
    render(<App />)
    expect(await screen.findByRole('alert')).toHaveTextContent('Documento não encontrado ou fora do seu escopo.')
  })

  it('menu mostra Documentos só com permissão', async () => {
    rotas({ '/api/v1/me': () => json(200, perfil(['estoque.ver'])), '/api/v1/dashboard': () => json(200, { pendencias_institucionais: 12, operacoes_bloqueadas: 11 }) })
    render(<App />)
    const nav = await screen.findByRole('navigation', { name: 'Navegação principal' })
    expect(nav).not.toHaveTextContent('Documentos')
  })
})
