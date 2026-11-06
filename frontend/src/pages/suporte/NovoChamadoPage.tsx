import { useState, type FormEvent } from 'react'
import { useLocation, useNavigate } from 'react-router'
import { Send } from 'lucide-react'
import { ErroApi, api } from '../../api/client'
import type { ChamadoDetalhe } from '../../api/tipos'
import { Aviso, Campo, Pagina, useNotificar } from '../../components/ui'
import { CATEGORIAS, PRIORIDADES, avisarAlteracao } from './rotulos'

const MAX = 5000

export default function NovoChamadoPage() {
  const nav = useNavigate()
  const loc = useLocation()
  const notificar = useNotificar()
  const origem = (loc.state as { origem?: string } | null)?.origem
  const [f, setF] = useState({ categoria: 'ERRO', prioridade: 'MEDIA', assunto: '', descricao: '' })
  const [erro, setErro] = useState<ErroApi | null>(null)
  const [enviando, setEnviando] = useState(false)
  const fe = (k: string) => erro?.erro.fields?.[k]

  const enviar = async (e: FormEvent) => {
    e.preventDefault()
    setEnviando(true); setErro(null)
    try {
      // Diagnóstico: apenas o caminho da tela de origem, sem parâmetros (o servidor recusa query string).
      const rota = origem && /^\/[A-Za-z0-9/_-]*$/.test(origem) ? origem : undefined
      const c = await api<ChamadoDetalhe>('/support/tickets', { method: 'POST', body: { ...f, rota } })
      avisarAlteracao()
      notificar('ok', `Chamado ${c.protocolo} aberto. Guarde o protocolo.`)
      nav(`/suporte/chamados/${c.codigo}`)
    } catch (x) { if (x instanceof ErroApi) setErro(x) } finally { setEnviando(false) }
  }

  return (
    <Pagina titulo="Novo chamado" trilha={[{ rotulo: 'Central de suporte', para: '/suporte' }, { rotulo: 'Novo chamado' }]}
      descricao="Descreva o que aconteceu, o que você esperava e os passos para reproduzir.">
      <Aviso tipo="alerta" titulo="Não envie senhas, tokens ou dados pessoais.">Trechos que pareçam credenciais ou CPF são removidos automaticamente antes de gravar.</Aviso>
      {erro && !erro.erro.fields && <Aviso tipo="perigo">{erro.erro.message}</Aviso>}
      <form className="bloco" onSubmit={enviar} noValidate>
        <div className="grade">
          <Campo rotulo="Categoria" obrigatorio erro={fe('categoria')}>{(p) => (
            <select {...p} value={f.categoria} onChange={(e) => setF({ ...f, categoria: e.target.value })}>
              {Object.entries(CATEGORIAS).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
            </select>)}</Campo>
          <Campo rotulo="Prioridade" obrigatorio erro={fe('prioridade')} dica="Crítica: impede o trabalho de toda a unidade.">{(p) => (
            <select {...p} value={f.prioridade} onChange={(e) => setF({ ...f, prioridade: e.target.value })}>
              {Object.entries(PRIORIDADES).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
            </select>)}</Campo>
        </div>
        <Campo rotulo="Assunto" obrigatorio erro={fe('assunto')} dica="Resumo em uma frase (5 a 150 caracteres).">{(p) => (
          <input {...p} maxLength={150} value={f.assunto} onChange={(e) => setF({ ...f, assunto: e.target.value })} />)}</Campo>
        <Campo rotulo="Descrição" obrigatorio erro={fe('descricao')}>{(p) => (
          <textarea {...p} rows={8} maxLength={MAX} value={f.descricao} onChange={(e) => setF({ ...f, descricao: e.target.value })} />)}</Campo>
        <p className="contagem" aria-live="polite">{f.descricao.length}/{MAX} caracteres</p>
        {origem && <p className="dica">A tela de origem ({origem}) será anexada para ajudar o diagnóstico.</p>}
        <div className="barra-acoes">
          <button type="button" className="btn btn-sec" onClick={() => nav('/suporte')}>Cancelar</button>
          <button className="btn btn-pri" disabled={enviando}><Send size={18} aria-hidden="true" />{enviando ? 'Enviando…' : 'Abrir chamado'}</button>
        </div>
      </form>
    </Pagina>
  )
}
