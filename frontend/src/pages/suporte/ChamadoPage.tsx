import { useEffect, useState, type FormEvent } from 'react'
import { useParams } from 'react-router'
import { EyeOff, History, Info, Lock, RotateCcw, Save, Send } from 'lucide-react'
import { ErroApi, api } from '../../api/client'
import type { ChamadoDetalhe, MensagemChamado } from '../../api/tipos'
import { Aviso, Campo, Carregando, Dialogo, Erro, Pagina, Selo, dataHora, useNotificar } from '../../components/ui'
import { useRecurso } from '../../components/useRecurso'
import { CATEGORIAS, PRIORIDADES, STATUS, avisarAlteracao } from './rotulos'

type Evento = { id: number; ocorrido_em: string; acao: string; usuario: { nome: string } | null; request_id: string | null }
const ACOES: Record<string, string> = {
  'suporte.chamado.aberto': 'Chamado aberto', 'suporte.resposta': 'Resposta pública', 'suporte.nota_interna': 'Nota interna',
  'suporte.atendimento.alterado': 'Atendimento alterado', 'suporte.chamado.reaberto': 'Chamado reaberto',
}

function Mensagem({ m }: { m: MensagemChamado }) {
  if (m.tipo === 'SISTEMA') return <li className="msg msg-sistema"><Info size={16} aria-hidden="true" /><span>{m.autor?.nome}: {m.corpo} · <time dateTime={m.criada_em}>{dataHora(m.criada_em)}</time></span></li>
  const classe = m.tipo === 'INTERNA' ? 'msg-interna' : m.do_solicitante ? 'msg-solicitante' : 'msg-atendimento'
  return (
    <li className={`msg ${classe}`}>
      <div className="msg-meta">
        <strong>{m.autor?.nome ?? '—'}</strong>
        <span>{m.tipo === 'INTERNA' ? 'Nota interna' : m.do_solicitante ? 'Solicitante' : 'Atendimento'}</span>
        <time dateTime={m.criada_em}>{dataHora(m.criada_em)}</time>
        {m.tipo === 'INTERNA' && <span className="selo selo-alerta"><EyeOff size={14} aria-hidden="true" />Não visível ao solicitante</span>}
        {m.redigido && <span className="selo selo-neutro"><Lock size={14} aria-hidden="true" />Dados sensíveis removidos</span>}
      </div>
      <p className="msg-corpo">{m.corpo}</p>
    </li>
  )
}

/** `ocupado`: mensagem em envio ou chamado recarregando — salvar agora usaria uma versão desatualizada (409). */
function PainelAtendimento({ c, aoAlterar, ocupado }: { c: ChamadoDetalhe; aoAlterar: () => void; ocupado: boolean }) {
  const notificar = useNotificar()
  const [f, setF] = useState({ status: c.status, prioridade: c.prioridade, atribuido_a_id: c.atribuido_a?.id ?? '' as number | '' })
  const [agentes, setAgentes] = useState<{ id: number; nome: string }[]>([])
  const [erro, setErro] = useState<ErroApi | null>(null)
  const [salvando, setSalvando] = useState(false)
  useEffect(() => { setF({ status: c.status, prioridade: c.prioridade, atribuido_a_id: c.atribuido_a?.id ?? '' }) }, [c])
  useEffect(() => { api<{ data: { id: number; nome: string }[] }>(`/support/tickets/${c.codigo}/agents`).then((r) => setAgentes(r.data)).catch(() => {}) }, [c.codigo])
  const encerrado = c.status === 'RESOLVIDO' || c.status === 'CANCELADO'

  const salvar = async (e: FormEvent) => {
    e.preventDefault()
    setSalvando(true); setErro(null)
    try {
      await api(`/support/tickets/${c.codigo}`, { method: 'PATCH', body: {
        versao: c.versao, status: f.status !== c.status ? f.status : undefined, prioridade: f.prioridade,
        atribuido_a_id: f.atribuido_a_id === '' ? null : f.atribuido_a_id,
      } })
      notificar('ok', 'Atendimento atualizado.'); avisarAlteracao(); aoAlterar()
    } catch (x) {
      if (x instanceof ErroApi) { setErro(x); if (x.erro.code === 'CONFLITO_VERSAO') aoAlterar() }
    } finally { setSalvando(false) }
  }

  return (
    <form className="bloco" onSubmit={salvar} aria-labelledby="titulo-atendimento">
      <h2 id="titulo-atendimento">Atendimento</h2>
      {erro && <Aviso tipo={erro.erro.code === 'CONFLITO_VERSAO' ? 'alerta' : 'perigo'}>{erro.erro.message}</Aviso>}
      <Campo rotulo="Situação">{(p) => (
        <select {...p} value={f.status} disabled={encerrado} onChange={(e) => setF({ ...f, status: e.target.value as typeof f.status })}>
          <option value={c.status}>{STATUS[c.status]} (atual)</option>
          {c.transicoes.map((s) => <option key={s} value={s}>{STATUS[s]}</option>)}
        </select>)}</Campo>
      <Campo rotulo="Prioridade">{(p) => (
        <select {...p} value={f.prioridade} disabled={encerrado} onChange={(e) => setF({ ...f, prioridade: e.target.value })}>
          {Object.entries(PRIORIDADES).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
        </select>)}</Campo>
      <Campo rotulo="Responsável">{(p) => (
        <select {...p} value={f.atribuido_a_id} disabled={encerrado} onChange={(e) => setF({ ...f, atribuido_a_id: e.target.value === '' ? '' : Number(e.target.value) })}>
          <option value="">Ninguém</option>
          {agentes.map((a) => <option key={a.id} value={a.id}>{a.nome}</option>)}
        </select>)}</Campo>
      {!encerrado && <button className="btn btn-pri btn-bloco" disabled={salvando || ocupado}><Save size={18} aria-hidden="true" />{salvando ? 'Salvando…' : 'Salvar alterações'}</button>}
    </form>
  )
}

function Historico({ codigo, versao }: { codigo: string; versao: number }) {
  const h = useRecurso(() => api<{ data: Evento[] }>(`/support/tickets/${codigo}/history`), [codigo, versao])
  return (
    <section className="bloco" aria-labelledby="titulo-historico">
      <h2 id="titulo-historico"><History size={18} aria-hidden="true" /> Trilha de auditoria</h2>
      {h.carregando ? <Carregando /> : h.erro ? <Erro erro={h.erro} /> : (
        <ol className="linha-tempo">
          {h.dados?.data.map((e) => (
            <li key={e.id}><strong>{ACOES[e.acao] ?? e.acao}</strong> — {e.usuario?.nome ?? 'sistema'}<br />
              <time dateTime={e.ocorrido_em}>{dataHora(e.ocorrido_em)}</time>{e.request_id && <> · <span className="mono">{e.request_id.slice(0, 13)}</span></>}</li>
          ))}
        </ol>
      )}
    </section>
  )
}

export default function ChamadoPage() {
  const { codigo = '' } = useParams()
  const notificar = useNotificar()
  const r = useRecurso(() => api<ChamadoDetalhe>(`/support/tickets/${codigo}`), [codigo])
  const [texto, setTexto] = useState('')
  const [tipo, setTipo] = useState<'PUBLICA' | 'INTERNA'>('PUBLICA')
  const [erro, setErro] = useState<ErroApi | null>(null)
  const [enviando, setEnviando] = useState(false)
  const [reabrir, setReabrir] = useState(false)
  const [motivo, setMotivo] = useState('')

  if (r.carregando && !r.dados) return <Carregando />
  if (r.erro) return <Pagina titulo="Chamado" trilha={[{ rotulo: 'Central de suporte', para: '/suporte' }, { rotulo: 'Chamado' }]}><Erro erro={r.erro} tentar={r.recarregar} /></Pagina>
  const c = r.dados!
  const encerrado = c.status === 'RESOLVIDO' || c.status === 'CANCELADO'

  const responder = async (e: FormEvent) => {
    e.preventDefault()
    setEnviando(true); setErro(null)
    try {
      await api(`/support/tickets/${c.codigo}/messages`, { method: 'POST', body: { corpo: texto, tipo } })
      setTexto(''); notificar('ok', tipo === 'INTERNA' ? 'Nota interna registrada.' : 'Mensagem enviada.'); avisarAlteracao(); r.recarregar()
    } catch (x) { if (x instanceof ErroApi) setErro(x) } finally { setEnviando(false) }
  }
  const confirmarReabertura = async () => {
    setErro(null)
    try {
      await api(`/support/tickets/${c.codigo}/reopen`, { method: 'POST', body: { versao: c.versao, motivo } })
      setReabrir(false); setMotivo(''); notificar('ok', 'Chamado reaberto.'); avisarAlteracao(); r.recarregar()
    } catch (x) { if (x instanceof ErroApi) { setErro(x); setReabrir(false); r.recarregar() } }
  }

  return (
    <Pagina titulo={c.assunto} trilha={[{ rotulo: c.atendimento ? 'Atendimento' : 'Central de suporte', para: c.atendimento ? '/suporte/atendimento' : '/suporte' }, { rotulo: c.protocolo }]}
      descricao={<><span className="mono">{c.protocolo}</span> · {CATEGORIAS[c.categoria] ?? c.categoria} · aberto em {dataHora(c.criado_em)}</>}
      acoes={<><Selo valor={c.status} /><Selo valor={c.prioridade} />{c.pode_reabrir && <button className="btn btn-sec" onClick={() => setReabrir(true)}><RotateCcw size={18} aria-hidden="true" />Reabrir</button>}</>}>
      {c.status === 'AGUARDANDO_SOLICITANTE' && !c.atendimento && <Aviso tipo="alerta" titulo="O atendimento aguarda sua resposta.">Responda abaixo para dar continuidade.</Aviso>}
      {erro && <Aviso tipo={erro.erro.code === 'CONFLITO_VERSAO' ? 'alerta' : 'perigo'}>{erro.erro.message}</Aviso>}
      <div className="chamado-grade">
        <div>
          <h2 className="sr-only">Conversa</h2>
          <ol className="conversa" aria-label="Conversa do chamado">{c.mensagens.map((m) => <Mensagem key={m.id} m={m} />)}</ol>
          {encerrado ? (
            <Aviso tipo="info">Chamado {c.status === 'RESOLVIDO' ? 'resolvido' : 'cancelado'}{c.resolvido_em ? ` em ${dataHora(c.resolvido_em)}` : ''}. {c.pode_reabrir ? 'Se o problema voltou, use “Reabrir”.' : 'Para um novo problema, abra outro chamado.'}</Aviso>
          ) : (
            <form className="compositor" onSubmit={responder} aria-label="Responder ao chamado">
              {c.atendimento && (
                <div className="abas" role="radiogroup" aria-label="Tipo de mensagem">
                  <button type="button" role="radio" aria-checked={tipo === 'PUBLICA'} className={`aba${tipo === 'PUBLICA' ? ' ativa' : ''}`} onClick={() => setTipo('PUBLICA')}>Responder ao solicitante</button>
                  <button type="button" role="radio" aria-checked={tipo === 'INTERNA'} className={`aba${tipo === 'INTERNA' ? ' ativa' : ''}`} onClick={() => setTipo('INTERNA')}>Nota interna</button>
                </div>
              )}
              <Campo rotulo={tipo === 'INTERNA' ? 'Nota interna (visível só para o atendimento)' : 'Mensagem'} erro={erro?.erro.fields?.corpo} obrigatorio>{(p) => (
                <textarea {...p} rows={5} maxLength={5000} value={texto} onChange={(e) => setTexto(e.target.value)} />)}</Campo>
              <div className="barra-acoes">
                <button className="btn btn-pri" disabled={enviando || texto.trim().length < 2}><Send size={18} aria-hidden="true" />{enviando ? 'Enviando…' : tipo === 'INTERNA' ? 'Registrar nota' : 'Enviar mensagem'}</button>
              </div>
            </form>
          )}
        </div>
        <aside className="painel-lateral" aria-label="Detalhes do chamado">
          <dl className="detalhes">
            <div><dt>Solicitante</dt><dd>{c.solicitante?.nome ?? '—'}</dd></div>
            <div><dt>Responsável</dt><dd>{c.atribuido_a?.nome ?? 'Ninguém ainda'}</dd></div>
            <div><dt>Unidade</dt><dd>{c.unidade?.sigla ?? 'Global'}</dd></div>
            <div><dt>Reaberturas</dt><dd>{c.reaberturas}</dd></div>
          </dl>
          {c.atendimento && <PainelAtendimento c={c} aoAlterar={r.recarregar} ocupado={enviando || r.carregando} />}
          {c.atendimento && c.diagnostico && (
            <section className="bloco" aria-labelledby="titulo-diag">
              <h2 id="titulo-diag">Diagnóstico</h2>
              <dl className="lista-simples">
                {c.diagnostico.rota && <div><dt className="dica">Tela</dt><dd className="mono">{c.diagnostico.rota}</dd></div>}
                {c.diagnostico.request_id && <div><dt className="dica">Request ID</dt><dd className="mono quebra">{c.diagnostico.request_id}</dd></div>}
                {c.diagnostico.navegador && <div><dt className="dica">Navegador</dt><dd className="quebra">{c.diagnostico.navegador}</dd></div>}
              </dl>
            </section>
          )}
          {c.atendimento && <Historico codigo={c.codigo} versao={c.versao} />}
        </aside>
      </div>
      <Dialogo aberto={reabrir} titulo="Reabrir chamado" onFechar={() => setReabrir(false)}
        rodape={<><button className="btn btn-sec" onClick={() => setReabrir(false)}>Cancelar</button><button className="btn btn-pri" disabled={motivo.trim().length < 5} onClick={confirmarReabertura}>Reabrir chamado</button></>}>
        <Campo rotulo="Por que reabrir?" obrigatorio dica="Ex.: o erro voltou a acontecer.">{(p) => <textarea {...p} rows={4} maxLength={1000} value={motivo} onChange={(e) => setMotivo(e.target.value)} />}</Campo>
      </Dialogo>
    </Pagina>
  )
}
