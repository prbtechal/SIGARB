import { useEffect, useState, type FormEvent } from 'react'
import { useNavigate, useParams } from 'react-router'
import { ErroApi, api } from '../api/client'
import type { Militar, Unidade } from '../api/tipos'
import { Campo, Carregando, Erro, Pagina, useNotificar } from '../components/ui'
import { useRecurso } from '../components/useRecurso'

export default function MilitarFormPage() {
  const { id } = useParams()
  const novo = !id
  const nav = useNavigate()
  const notificar = useNotificar()
  const unidades = useRecurso(() => api<{ data: Unidade[] }>('/units'), [])
  const postos = useRecurso(() => api<{ data: { id: number; sigla: string; nome: string }[] }>('/catalog/postos-graduacoes'), [])
  const atual = useRecurso(() => (novo ? Promise.resolve(null) : api<Militar>(`/militares/${id}`)), [id])
  const [f, setF] = useState({ nome: '', nome_guerra: '', matricula: '', unidade_id: '', posto_graduacao_id: '', situacao: 'ATIVO' })
  const [erro, setErro] = useState<ErroApi | null>(null)
  const [salvando, setSalvando] = useState(false)
  useEffect(() => {
    const m = atual.dados
    if (m) setF({ nome: m.nome, nome_guerra: m.nome_guerra, matricula: m.matricula_completa ? m.matricula : '', unidade_id: String(m.unidade.id), posto_graduacao_id: String(m.posto_graduacao.id), situacao: m.situacao })
  }, [atual.dados])
  const somenteLeitura = !novo && atual.dados && !atual.dados.pode_editar
  const fe = (k: string) => erro?.erro.fields?.[k]

  const salvar = async (e: FormEvent) => {
    e.preventDefault(); setErro(null); setSalvando(true)
    const corpo: Record<string, unknown> = { ...f, unidade_id: Number(f.unidade_id), posto_graduacao_id: Number(f.posto_graduacao_id) }
    if (!novo && !atual.dados?.matricula_completa) delete corpo.matricula
    try {
      await api(novo ? '/militares' : `/militares/${id}`, { method: novo ? 'POST' : 'PUT', body: corpo })
      notificar('ok', novo ? 'Efetivo cadastrado.' : 'Alterações salvas.')
      nav('/militares')
    } catch (x) { if (x instanceof ErroApi) setErro(x) } finally { setSalvando(false) }
  }

  if (unidades.carregando || postos.carregando || atual.carregando) return <Pagina titulo="Efetivo"><Carregando /></Pagina>
  if (atual.erro) return <Pagina titulo="Efetivo"><Erro erro={atual.erro} /></Pagina>
  return (
    <Pagina titulo={novo ? 'Cadastrar efetivo' : `Efetivo: ${atual.dados?.nome_guerra}`} trilha={[{ rotulo: 'Início', para: '/' }, { rotulo: 'Efetivos', para: '/militares' }, { rotulo: novo ? 'Novo' : 'Detalhe' }]}>
      {erro && <div className="aviso aviso-perigo" role="alert">{erro.erro.code === 'ACESSO_NEGADO' ? 'Sem permissão para esta unidade.' : erro.erro.message}</div>}
      {!novo && atual.dados?.usuario !== undefined && <p>Conta de acesso vinculada: <strong>{atual.dados?.usuario?.login ?? 'nenhuma'}</strong></p>}
      <form noValidate onSubmit={salvar} className="bloco">
        <fieldset disabled={!!somenteLeitura} className="grade">
          <Campo rotulo="Nome completo" obrigatorio erro={fe('nome')}>{(p) => <input {...p} value={f.nome} onChange={(e) => setF({ ...f, nome: e.target.value })} />}</Campo>
          <Campo rotulo="Nome de guerra" obrigatorio erro={fe('nome_guerra')}>{(p) => <input {...p} value={f.nome_guerra} onChange={(e) => setF({ ...f, nome_guerra: e.target.value })} />}</Campo>
          {(novo || atual.dados?.matricula_completa) && <Campo rotulo="Matrícula" obrigatorio dica="Letras maiúsculas, números e hífen (4–30)." erro={fe('matricula')}>{(p) => <input {...p} value={f.matricula} onChange={(e) => setF({ ...f, matricula: e.target.value.toUpperCase() })} />}</Campo>}
          <Campo rotulo="Posto/graduação" obrigatorio erro={fe('posto_graduacao_id')}>{(p) => <select {...p} value={f.posto_graduacao_id} onChange={(e) => setF({ ...f, posto_graduacao_id: e.target.value })}><option value="">Selecione…</option>{postos.dados?.data.map((x) => <option key={x.id} value={x.id}>{x.nome}</option>)}</select>}</Campo>
          <Campo rotulo="Unidade" obrigatorio erro={fe('unidade_id')}>{(p) => <select {...p} value={f.unidade_id} onChange={(e) => setF({ ...f, unidade_id: e.target.value })}><option value="">Selecione…</option>{unidades.dados?.data.map((u) => <option key={u.id} value={u.id}>{'— '.repeat(u.nivel)}{u.sigla} · {u.nome}</option>)}</select>}</Campo>
          <Campo rotulo="Situação" erro={fe('situacao')}>{(p) => <select {...p} value={f.situacao} onChange={(e) => setF({ ...f, situacao: e.target.value })}><option>ATIVO</option><option>AFASTADO</option><option>INATIVO</option></select>}</Campo>
        </fieldset>
        <div className="barra-acoes">
          <button type="button" className="btn btn-sec" onClick={() => nav('/militares')}>Voltar</button>
          {!somenteLeitura && <button className="btn btn-pri" disabled={salvando}>{salvando ? 'Salvando…' : 'Salvar'}</button>}
        </div>
      </form>
    </Pagina>
  )
}
