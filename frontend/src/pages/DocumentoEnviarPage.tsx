import { useRef, useState, type FormEvent } from 'react'
import { useNavigate, useSearchParams } from 'react-router'
import { ErroApi, api, enviarArquivo } from '../api/client'
import type { DocDetalhe, Unidade } from '../api/tipos'
import { ACCEPT, tamanho, validarArquivo } from '../components/arquivos'
import { Campo, Carregando, Pagina, useNotificar } from '../components/ui'
import { useRecurso } from '../components/useRecurso'

/** RF-014 — envio com validação local (espelho) + validação real no servidor + progresso. */
export default function DocumentoEnviarPage() {
  const nav = useNavigate()
  const [params] = useSearchParams()
  const movimentacao = params.get('movimentacao')
  const notificar = useNotificar()
  const tipos = useRecurso(() => api<{ data: { id: number; nome: string }[] }>('/document-types'), [])
  const unidades = useRecurso(() => api<{ data: Unidade[] }>('/units'), [])
  const [f, setF] = useState({ titulo: '', tipo_documento_id: '', unidade_id: '', numero_referencia: '', descricao: '' })
  const [arquivo, setArquivo] = useState<File | null>(null)
  const [erroArq, setErroArq] = useState<string | null>(null)
  const [erro, setErro] = useState<ErroApi | null>(null)
  const [progresso, setProgresso] = useState<number | null>(null)
  const inputArq = useRef<HTMLInputElement>(null)
  const fe = (k: string) => erro?.erro.fields?.[k]

  const escolher = (fl: File | null) => { setArquivo(fl); setErroArq(fl ? validarArquivo(fl) : null) }

  const enviar = async (e: FormEvent) => {
    e.preventDefault(); setErro(null)
    const v = validarArquivo(arquivo)
    if (v) { setErroArq(v); inputArq.current?.focus(); return }
    const fd = new FormData()
    Object.entries(f).forEach(([k, val]) => { if (val) fd.append(k, val) })
    if (movimentacao) fd.append('movimentacao_id', movimentacao)
    fd.append('arquivo', arquivo!)
    setProgresso(0)
    try {
      const d = await enviarArquivo<DocDetalhe>('/documents', fd, setProgresso)
      notificar('ok', 'Documento enviado e registrado.')
      nav(`/documentos/${d.codigo}`)
    } catch (x) {
      if (x instanceof ErroApi) {
        setErro(x)
        if (['TIPO_NAO_PERMITIDO', 'CONTEUDO_INCOMPATIVEL', 'CONTEUDO_MALFORMADO', 'CONTEUDO_ATIVO', 'ARQUIVO_VAZIO', 'ARQUIVO_GRANDE_DEMAIS'].includes(x.erro.code)) setErroArq(x.erro.message)
      }
      setProgresso(null)
    }
  }

  if (tipos.carregando || unidades.carregando) return <Pagina titulo="Enviar documento"><Carregando /></Pagina>
  const enviando = progresso !== null
  return (
    <Pagina titulo="Enviar documento" trilha={[{ rotulo: 'Início', para: '/' }, { rotulo: 'Documentos', para: '/documentos' }, { rotulo: 'Enviar' }]}
      descricao={movimentacao ? `O documento será vinculado à movimentação #${movimentacao}.` : 'Os dados descritivos podem ser corrigidos depois; o arquivo não pode ser substituído.'}>
      {erro && !erroArq && <div className="aviso aviso-perigo" role="alert"><strong>Não foi possível enviar. </strong>{erro.erro.code === 'ACESSO_NEGADO' ? 'Você não tem permissão de envio nesta unidade.' : erro.erro.message}</div>}
      <form noValidate onSubmit={enviar} className="bloco" aria-busy={enviando}>
        <fieldset disabled={enviando} className="grade">
          <Campo rotulo="Tipo de documento" obrigatorio erro={fe('tipo_documento_id')}>{(p) => (
            <select {...p} value={f.tipo_documento_id} onChange={(e) => setF({ ...f, tipo_documento_id: e.target.value })}>
              <option value="">Selecione…</option>{tipos.dados?.data.map((t) => <option key={t.id} value={t.id}>{t.nome}</option>)}
            </select>)}</Campo>
          <Campo rotulo="Unidade responsável" obrigatorio dica="Define quem poderá consultar o documento." erro={fe('unidade_id')}>{(p) => (
            <select {...p} value={f.unidade_id} onChange={(e) => setF({ ...f, unidade_id: e.target.value })}>
              <option value="">Selecione…</option>{unidades.dados?.data.map((u) => <option key={u.id} value={u.id}>{'— '.repeat(u.nivel)}{u.sigla} · {u.nome}</option>)}
            </select>)}</Campo>
          <Campo rotulo="Título" obrigatorio erro={fe('titulo')}>{(p) => <input {...p} value={f.titulo} maxLength={160} onChange={(e) => setF({ ...f, titulo: e.target.value })} />}</Campo>
          <Campo rotulo="Número de referência" erro={fe('numero_referencia')}>{(p) => <input {...p} value={f.numero_referencia} maxLength={120} onChange={(e) => setF({ ...f, numero_referencia: e.target.value })} />}</Campo>
          <Campo rotulo="Descrição" erro={fe('descricao')}>{(p) => <textarea {...p} rows={2} value={f.descricao} maxLength={500} onChange={(e) => setF({ ...f, descricao: e.target.value })} />}</Campo>
          <Campo rotulo="Arquivo" obrigatorio dica="PDF, PNG ou JPG, até 10 MB. PDFs com scripts ou anexos são recusados." erro={erroArq ? [erroArq] : fe('arquivo')}>{(p) => (
            <input {...p} ref={inputArq} type="file" accept={ACCEPT} onChange={(e) => escolher(e.target.files?.[0] ?? null)} />)}</Campo>
        </fieldset>
        {arquivo && !erroArq && <p className="dica">Selecionado: <strong>{arquivo.name}</strong> ({tamanho(arquivo.size)})</p>}
        {enviando && (
          <div className="progresso">
            <label htmlFor="prog-envio">Enviando… {progresso}%</label>
            <progress id="prog-envio" max={100} value={progresso ?? 0}>{progresso}%</progress>
          </div>
        )}
        <div className="barra-acoes">
          <button type="button" className="btn btn-sec" onClick={() => nav(-1)} disabled={enviando}>Cancelar</button>
          <button className="btn btn-pri" disabled={enviando}>{enviando ? 'Enviando…' : 'Enviar documento'}</button>
        </div>
      </form>
    </Pagina>
  )
}
