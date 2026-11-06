import { useState } from 'react'
import { useNavigate } from 'react-router'
import { ShieldHalf } from 'lucide-react'
import { ErroApi, api, type Pagina as Pag } from '../../api/client'
import type { AcautDetalhe, Militar } from '../../api/tipos'
import { Aviso, Campo, Carregando, Pagina, useNotificar } from '../../components/ui'
import { useRecurso } from '../../components/useRecurso'

type Item = { id: number; identificador: string; numero_serie: string | null; material: { nome: string }; local: { codigo: string } }

export default function NovoAcautelamentoPage() {
  const nav = useNavigate()
  const notificar = useNotificar()
  const efetivos = useRecurso(() => api<Pag<Militar>>('/militares', { query: { per_page: 100, situacao: 'ATIVO' } }), [])
  const itens = useRecurso(() => api<Pag<Item>>('/stock/individual-items', { query: { situacao: 'DISPONIVEL', per_page: 100 } }), [])
  const [f, setF] = useState({ militar_id: '', item_individual_id: '', justificativa: '' })
  const [erro, setErro] = useState<ErroApi | null>(null)
  const [enviando, setEnviando] = useState(false)
  const fe = (k: string) => erro?.erro.fields?.[k]

  const enviar = async () => {
    setEnviando(true); setErro(null)
    try {
      const a = await api<AcautDetalhe>('/acautelamentos/requests', { method: 'POST', body: { militar_id: Number(f.militar_id), item_individual_id: Number(f.item_individual_id), justificativa: f.justificativa } })
      notificar('ok', 'Solicitação registrada. Aguarda decisão de um aprovador.')
      nav(`/acautelamentos/${a.codigo}`)
    } catch (e) { if (e instanceof ErroApi) setErro(e) } finally { setEnviando(false) }
  }

  const trilha = [{ rotulo: 'Início', para: '/' }, { rotulo: 'Acautelamentos', para: '/acautelamentos' }, { rotulo: 'Nova solicitação' }]
  if (efetivos.carregando || itens.carregando) return <Pagina titulo="Nova solicitação" trilha={trilha}><Carregando /></Pagina>
  return (
    <Pagina titulo="Nova solicitação de acautelamento" icone={ShieldHalf} trilha={trilha}>
      <Aviso tipo="alerta" titulo="Regra provisória:">quem solicita não pode decidir. A entrega só acontece depois da aprovação.</Aviso>
      {erro && <div className="aviso aviso-perigo" role="alert"><strong>Não foi possível solicitar. </strong>{erro.erro.message}</div>}
      <form className="bloco" noValidate onSubmit={(e) => { e.preventDefault(); enviar() }}>
        <div className="grade">
          <Campo rotulo="Efetivo" obrigatorio erro={fe('militar_id')}>{(p) => (
            <select {...p} value={f.militar_id} onChange={(e) => setF({ ...f, militar_id: e.target.value })}>
              <option value="">Selecione…</option>{efetivos.dados?.data.map((m) => <option key={m.id} value={m.id}>{m.posto_graduacao.sigla} {m.nome_guerra} ({m.unidade.sigla})</option>)}
            </select>)}</Campo>
          <Campo rotulo="Item" obrigatorio dica="Somente itens individuais disponíveis no seu escopo." erro={fe('item_individual_id')}>{(p) => (
            <select {...p} value={f.item_individual_id} onChange={(e) => setF({ ...f, item_individual_id: e.target.value })}>
              <option value="">Selecione…</option>{itens.dados?.data.map((i) => <option key={i.id} value={i.id}>{i.identificador} — {i.material.nome} ({i.local.codigo})</option>)}
            </select>)}</Campo>
          <Campo rotulo="Justificativa" obrigatorio dica="Mínimo de 5 caracteres." erro={fe('justificativa')}>{(p) => <textarea {...p} rows={3} maxLength={500} value={f.justificativa} onChange={(e) => setF({ ...f, justificativa: e.target.value })} />}</Campo>
        </div>
        <div className="barra-acoes">
          <button type="button" className="btn btn-sec" onClick={() => nav(-1)}>Cancelar</button>
          <button className="btn btn-pri" disabled={enviando || !f.militar_id || !f.item_individual_id || f.justificativa.trim().length < 5}>{enviando ? 'Enviando…' : 'Solicitar'}</button>
        </div>
      </form>
    </Pagina>
  )
}
