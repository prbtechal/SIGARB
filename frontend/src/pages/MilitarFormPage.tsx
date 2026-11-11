import { CATEGORIAS_EFETIVO } from '../api/tipos'
import { useEffect, useState, type FormEvent } from 'react'
import { useNavigate, useParams } from 'react-router'
import { ErroApi, api } from '../api/client'
import type { Militar, Unidade } from '../api/tipos'
import { Campo, Carregando, Erro, Pagina, useNotificar } from '../components/ui'
import { useRecurso } from '../components/useRecurso'

const RESTRITOS = ['telefone_institucional', 'email_institucional', 'observacoes_administrativas']

export default function MilitarFormPage() {
  const { id } = useParams()
  const novo = !id
  const nav = useNavigate()
  const notificar = useNotificar()
  const unidades = useRecurso(() => api<{ data: Unidade[] }>('/units'), [])
  const postos = useRecurso(() => api<{ data: { id: number; sigla: string; nome: string }[] }>('/catalog/postos-graduacoes'), [])
  const atual = useRecurso(() => (novo ? Promise.resolve(null) : api<Militar>(`/militares/${id}`)), [id])
  const [f, setF] = useState({ nome: '', nome_guerra: '', matricula: '', unidade_id: '', posto_graduacao_id: '', situacao: 'ATIVO', categoria_efetivo: '',
    quadro: '', funcao: '', telefone_institucional: '', email_institucional: '', observacoes_administrativas: '' })
  const [erro, setErro] = useState<ErroApi | null>(null)
  const [salvando, setSalvando] = useState(false)
  useEffect(() => {
    const m = atual.dados
    if (m) setF({ nome: m.nome, nome_guerra: m.nome_guerra, matricula: m.matricula_completa ? m.matricula : '', unidade_id: String(m.unidade.id), posto_graduacao_id: String(m.posto_graduacao.id), situacao: m.situacao, categoria_efetivo: m.categoria_efetivo ?? '',
      quadro: m.quadro ?? '', funcao: m.funcao ?? '', telefone_institucional: m.telefone_institucional ?? '', email_institucional: m.email_institucional ?? '', observacoes_administrativas: m.observacoes_administrativas ?? '' })
  }, [atual.dados])
  const somenteLeitura = !novo && atual.dados && !atual.dados.pode_editar
  const fe = (k: string) => erro?.erro.fields?.[k]

  const salvar = async (e: FormEvent) => {
    e.preventDefault(); setErro(null); setSalvando(true)
    const vazio = (s: string) => s.trim() || null
    const corpo: Record<string, unknown> = { ...f, unidade_id: Number(f.unidade_id), posto_graduacao_id: Number(f.posto_graduacao_id),
      quadro: vazio(f.quadro), funcao: vazio(f.funcao), telefone_institucional: vazio(f.telefone_institucional), email_institucional: vazio(f.email_institucional), observacoes_administrativas: vazio(f.observacoes_administrativas) }
    // Sem acesso ao detalhe, matrícula, contatos e observações não são exibidos — e por isso não são enviados (não sobrescreve o que não se vê).
    if (!novo && !atual.dados?.matricula_completa) for (const c of ['matricula', ...RESTRITOS]) delete corpo[c]
    try {
      const r = await api<Militar>(novo ? '/militares' : `/militares/${id}`, { method: novo ? 'POST' : 'PUT', body: corpo })
      notificar('ok', novo ? 'Efetivo cadastrado.' : 'Alterações salvas.')
      nav(`/militares/${r.id}`)
    } catch (x) { if (x instanceof ErroApi) setErro(x) } finally { setSalvando(false) }
  }

  if (unidades.carregando || postos.carregando || atual.carregando) return <Pagina titulo="Efetivo"><Carregando /></Pagina>
  if (atual.erro) return <Pagina titulo="Efetivo"><Erro erro={atual.erro} /></Pagina>
  return (
    <Pagina titulo={novo ? 'Cadastrar efetivo' : `Editar: ${atual.dados?.nome_guerra}`} trilha={[{ rotulo: 'Início', para: '/' }, { rotulo: 'Efetivos', para: '/militares' }, ...(novo ? [] : [{ rotulo: 'Perfil', para: `/militares/${id}` }]), { rotulo: novo ? 'Novo' : 'Editar' }]}>
      {erro && <div className="aviso aviso-perigo" role="alert">{erro.erro.code === 'ACESSO_NEGADO' ? 'Sem permissão para esta unidade.' : erro.erro.message}</div>}
      <form noValidate onSubmit={salvar} className="bloco">
        <fieldset disabled={!!somenteLeitura} className="grade">
          <Campo rotulo="Nome completo" obrigatorio erro={fe('nome')}>{(p) => <input {...p} value={f.nome} onChange={(e) => setF({ ...f, nome: e.target.value })} />}</Campo>
          <Campo rotulo="Nome de guerra" obrigatorio erro={fe('nome_guerra')}>{(p) => <input {...p} value={f.nome_guerra} onChange={(e) => setF({ ...f, nome_guerra: e.target.value })} />}</Campo>
          {(novo || atual.dados?.matricula_completa) && <Campo rotulo="Matrícula" obrigatorio dica="Letras maiúsculas, números e hífen (4–30)." erro={fe('matricula')}>{(p) => <input {...p} value={f.matricula} onChange={(e) => setF({ ...f, matricula: e.target.value.toUpperCase() })} />}</Campo>}
          <Campo rotulo="Posto/graduação" obrigatorio erro={fe('posto_graduacao_id')}>{(p) => <select {...p} value={f.posto_graduacao_id} onChange={(e) => setF({ ...f, posto_graduacao_id: e.target.value })}><option value="">Selecione…</option>{postos.dados?.data.map((x) => <option key={x.id} value={x.id}>{x.nome}</option>)}</select>}</Campo>
          <Campo rotulo="Unidade" obrigatorio erro={fe('unidade_id')}>{(p) => <select {...p} value={f.unidade_id} onChange={(e) => setF({ ...f, unidade_id: e.target.value })}><option value="">Selecione…</option>{unidades.dados?.data.map((u) => <option key={u.id} value={u.id}>{'— '.repeat(u.nivel)}{u.sigla} · {u.nome}</option>)}</select>}</Campo>
          <Campo rotulo="Situação" erro={fe('situacao')}>{(p) => <select {...p} value={f.situacao} onChange={(e) => setF({ ...f, situacao: e.target.value })}><option>ATIVO</option><option>AFASTADO</option><option>INATIVO</option></select>}</Campo>
          <Campo rotulo="Tipo de efetivo" dica="Armamento e munição só podem ser entregues a RR (PM/BM) e PM/BM da ativa." erro={fe('categoria_efetivo')}>{(p) => <select {...p} value={f.categoria_efetivo} onChange={(e) => setF({ ...f, categoria_efetivo: e.target.value })}>
            <option value="">— Não informado —</option>{Object.entries(CATEGORIAS_EFETIVO).map(([v, r]) => <option key={v} value={v}>{r}</option>)}</select>}</Campo>
          <Campo rotulo="Quadro / categoria funcional" erro={fe('quadro')}>{(p) => <input {...p} maxLength={60} value={f.quadro} onChange={(e) => setF({ ...f, quadro: e.target.value })} />}</Campo>
          <Campo rotulo="Função" erro={fe('funcao')}>{(p) => <input {...p} maxLength={80} value={f.funcao} onChange={(e) => setF({ ...f, funcao: e.target.value })} />}</Campo>
        </fieldset>
        {(novo || atual.dados?.matricula_completa) && <fieldset disabled={!!somenteLeitura} className="grade">
          <legend>Contatos institucionais e observações (acesso restrito)</legend>
          <Campo rotulo="Telefone institucional" dica="Somente contato funcional." erro={fe('telefone_institucional')}>{(p) => <input {...p} type="tel" inputMode="tel" maxLength={20} autoComplete="off" value={f.telefone_institucional} onChange={(e) => setF({ ...f, telefone_institucional: e.target.value })} />}</Campo>
          <Campo rotulo="E-mail institucional" erro={fe('email_institucional')}>{(p) => <input {...p} type="email" maxLength={120} autoComplete="off" value={f.email_institucional} onChange={(e) => setF({ ...f, email_institucional: e.target.value })} />}</Campo>
          <Campo rotulo="Observações administrativas" dica="Não registre dados pessoais sensíveis (CPF, saúde, endereço)." erro={fe('observacoes_administrativas')}>{(p) => <textarea {...p} rows={3} maxLength={1000} value={f.observacoes_administrativas} onChange={(e) => setF({ ...f, observacoes_administrativas: e.target.value })} />}</Campo>
        </fieldset>}
        <div className="barra-acoes">
          <button type="button" className="btn btn-sec" onClick={() => nav(novo ? '/militares' : `/militares/${id}`)}>Voltar</button>
          {!somenteLeitura && <button className="btn btn-pri" disabled={salvando}>{salvando ? 'Salvando…' : 'Salvar'}</button>}
        </div>
      </form>
    </Pagina>
  )
}
