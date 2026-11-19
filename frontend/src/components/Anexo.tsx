import { useId, useRef, useState, type DragEvent } from 'react'
import { FileUp, Paperclip, X } from 'lucide-react'
import { ErroApi, api, enviarArquivo } from '../api/client'
import type { DocDetalhe } from '../api/tipos'
import { ACCEPT, tamanho, validarArquivo } from './arquivos'
import { Campo, Dialogo } from './ui'
import { useRecurso } from './useRecurso'

/** Códigos de erro do servidor que dizem respeito ao arquivo (mostrados no próprio campo). */
export const ERROS_ARQUIVO = ['TIPO_NAO_PERMITIDO', 'CONTEUDO_INCOMPATIVEL', 'CONTEUDO_MALFORMADO', 'CONTEUDO_ATIVO', 'CONTEUDO_NAO_VERIFICAVEL', 'ARQUIVO_VAZIO', 'ARQUIVO_GRANDE_DEMAIS', 'ARQUIVO_INVALIDO']

/**
 * Anexo opcional (2026-11-19): área de soltar + botão de escolher; mostra o arquivo escolhido com tamanho e "Remover".
 * Validação local espelha o servidor (que revalida por conteúdo). `rotulo` vira o nome acessível do input.
 */
export function CampoAnexo({ rotulo, arquivo, onChange, erro, opcional = true, desabilitado }: {
  rotulo: string; arquivo: File | null; onChange: (f: File | null, erro: string | null) => void; erro?: string | null; opcional?: boolean; desabilitado?: boolean
}) {
  const id = useId()
  const ref = useRef<HTMLInputElement>(null)
  const [arrastando, setArrastando] = useState(false)
  const escolher = (f: File | null) => onChange(f, f ? validarArquivo(f) : null)
  const soltar = (e: DragEvent) => { e.preventDefault(); setArrastando(false); if (!desabilitado) escolher(e.dataTransfer.files?.[0] ?? null) }
  return (
    <div className={`campo campo-anexo${erro ? ' campo-erro' : ''}`}>
      <label htmlFor={id}>{rotulo}{opcional ? <span className="dica"> (opcional)</span> : <span aria-hidden="true" className="obrig"> *</span>}</label>
      {arquivo && !erro ? (
        <div className="anexo-escolhido">
          <Paperclip size={18} aria-hidden="true" />
          <span className="anexo-nome">{arquivo.name}</span><small>{tamanho(arquivo.size)}</small>
          <button type="button" className="btn btn-sec btn-peq" disabled={desabilitado} onClick={() => { escolher(null); if (ref.current) ref.current.value = '' }}>
            <X size={16} aria-hidden="true" />Remover<span className="sr-only"> {arquivo.name}</span></button>
        </div>
      ) : (
        <div className={`anexo-zona${arrastando ? ' arrastando' : ''}`} onDragOver={(e) => { e.preventDefault(); setArrastando(true) }} onDragLeave={() => setArrastando(false)} onDrop={soltar}>
          <FileUp size={22} aria-hidden="true" />
          <span>Arraste o arquivo aqui ou</span>
          <input id={id} ref={ref} type="file" accept={ACCEPT} disabled={desabilitado} aria-invalid={erro ? true : undefined}
            aria-describedby={`${id}-dica${erro ? ` ${id}-erro` : ''}`} onChange={(e) => escolher(e.target.files?.[0] ?? null)} />
        </div>
      )}
      <small id={`${id}-dica`} className="dica">PDF, PNG ou JPG, até 10 MB. Fica em área privada; o download é registrado.</small>
      {erro && <small id={`${id}-erro`} className="msg-erro" role="alert">{erro}</small>}
    </div>
  )
}

type TipoDoc = { id: number; codigo: string; nome: string }
const PADRAO_TIPO: Record<string, string> = { ENTRADA: 'NOTA_RECEBIMENTO', SAIDA: 'TERMO', BAIXA: 'TERMO' }
export const ROTULO_REGISTRO: Record<string, string> = { ENTRADA: 'Entrada', SAIDA: 'Saída', BAIXA: 'Baixa' }

/** Envia o anexo para `POST /movements/{id}/documents` (unidade e vínculo definidos pelo servidor). */
export function enviarAnexo(movId: number, arquivo: File, extra: Record<string, string> = {}, progresso?: (p: number) => void) {
  const fd = new FormData()
  Object.entries(extra).forEach(([k, v]) => { if (v) fd.append(k, v) })
  fd.append('arquivo', arquivo)
  return enviarArquivo<DocDetalhe>(`/movements/${movId}/documents`, fd, progresso)
}

/** Anexar documento a um registro já salvo (tela Documentos › Registros sem documento e detalhe da movimentação). */
export function AnexarDialogo({ registro, onFechar, onSalvo }: {
  registro: { id: number; tipo: string; documento_referencia?: string | null } | null; onFechar: () => void; onSalvo: (d: DocDetalhe) => void
}) {
  const tipos = useRecurso(() => (registro ? api<{ data: TipoDoc[] }>('/document-types') : Promise.resolve(null)), [registro?.id])
  const [tipo, setTipo] = useState('')
  const [titulo, setTitulo] = useState('')
  const [arquivo, setArquivo] = useState<File | null>(null)
  const [erroArq, setErroArq] = useState<string | null>(null)
  const [erro, setErro] = useState<ErroApi | null>(null)
  const [progresso, setProgresso] = useState<number | null>(null)
  const padrao = tipos.dados?.data.find((t) => t.codigo === PADRAO_TIPO[registro?.tipo ?? ''])?.id
  const tipoEfetivo = tipo || (padrao ? String(padrao) : '')
  const fechar = () => { setTipo(''); setTitulo(''); setArquivo(null); setErroArq(null); setErro(null); setProgresso(null); onFechar() }
  const enviar = async () => {
    const v = validarArquivo(arquivo)
    if (v) { setErroArq(v); return }
    if (!registro) return
    setErro(null); setProgresso(0)
    try {
      const d = await enviarAnexo(registro.id, arquivo!, { tipo_documento_id: tipoEfetivo, titulo: titulo.trim() }, setProgresso)
      fechar(); onSalvo(d)
    } catch (x) {
      if (x instanceof ErroApi) { setErro(x); if (ERROS_ARQUIVO.includes(x.erro.code)) setErroArq(x.erro.message) }
      setProgresso(null)
    }
  }
  const enviando = progresso !== null
  const rot = registro ? `${ROTULO_REGISTRO[registro.tipo] ?? registro.tipo} #${registro.id}` : ''
  return (
    <Dialogo aberto={!!registro} titulo={`Anexar documento à ${rot}`} onFechar={fechar}
      rodape={<><button className="btn btn-sec" disabled={enviando} onClick={fechar}>Cancelar</button>
        <button className="btn btn-pri" disabled={enviando || !arquivo || !!erroArq} onClick={enviar}>{enviando ? `Enviando… ${progresso}%` : 'Anexar'}</button></>}>
      {erro && !erroArq && <div className="aviso aviso-perigo" role="alert">{erro.erro.message}</div>}
      <p className="dica">O documento fica na unidade do registro{registro?.documento_referencia ? <> e recebe o número <strong>{registro.documento_referencia}</strong></> : null}. O arquivo não pode ser trocado depois — só ganhar uma nova versão.</p>
      <CampoAnexo rotulo="Arquivo" opcional={false} arquivo={arquivo} erro={erroArq} desabilitado={enviando} onChange={(f, e) => { setArquivo(f); setErroArq(e) }} />
      <Campo rotulo="Tipo de documento" erro={erro?.erro.fields?.tipo_documento_id}>{(p) => (
        <select {...p} value={tipoEfetivo} disabled={enviando} onChange={(e) => setTipo(e.target.value)}>
          {tipos.dados?.data.map((t) => <option key={t.id} value={t.id}>{t.nome}</option>)}
        </select>)}</Campo>
      <Campo rotulo="Título" dica={`Em branco: "${rot}${registro?.documento_referencia ? ` — ${registro.documento_referencia}` : ''}".`} erro={erro?.erro.fields?.titulo}>{(p) => (
        <input {...p} value={titulo} maxLength={160} disabled={enviando} onChange={(e) => setTitulo(e.target.value)} />)}</Campo>
    </Dialogo>
  )
}
