import { BuscaEfetivo } from '../../components/estoque/BuscaEfetivo'
import { textoItem } from '../../components/RotuloItem'
import { useEffect, useState } from 'react'
import { useNavigate, useSearchParams } from 'react-router'
import { ShieldHalf } from 'lucide-react'
import { ErroApi, api, type Pagina as Pag } from '../../api/client'
import type { AcautDetalhe, Militar } from '../../api/tipos'
import { AtalhoCadastro, Aviso, Campo, Carregando, Pagina, useNotificar } from '../../components/ui'
import { useAuth } from '../../auth/AuthContext'
import { useRecurso } from '../../components/useRecurso'

type Item = { id: number; identificador: string; numero_serie: string | null; material: { nome: string }; local: { codigo: string } }

export default function NovoAcautelamentoPage() {
  const nav = useNavigate()
  const notificar = useNotificar()
  const { pode } = useAuth()
  const itens = useRecurso(() => api<Pag<Item>>('/stock/individual-items', { query: { situacao: 'DISPONIVEL', per_page: 100 } }), [])
  const [params] = useSearchParams()
  const [efetivo, setEfetivo] = useState<Militar | null>(null)
  // Vindo do perfil do efetivo (tela de gestão): já abre com ele escolhido.
  const pre = params.get('militar_id')
  useEffect(() => {
    if (pre && pode('militares.ver')) api<Militar>(`/militares/${pre}`).then(setEfetivo).catch(() => {})
  }, [pre]) // eslint-disable-line react-hooks/exhaustive-deps
  const [f, setF] = useState({ item_individual_id: '', justificativa: '' })
  const [erro, setErro] = useState<ErroApi | null>(null)
  const [enviando, setEnviando] = useState(false)
  const fe = (k: string) => erro?.erro.fields?.[k]

  const enviar = async () => {
    setEnviando(true); setErro(null)
    try {
      const a = await api<AcautDetalhe>('/acautelamentos/requests', { method: 'POST', body: { militar_id: efetivo?.id, item_individual_id: Number(f.item_individual_id), justificativa: f.justificativa } })
      notificar('ok', 'Solicitação registrada. Aguarda decisão de um aprovador.')
      nav(`/acautelamentos/${a.codigo}`)
    } catch (e) { if (e instanceof ErroApi) setErro(e) } finally { setEnviando(false) }
  }

  const trilha = [{ rotulo: 'Início', para: '/' }, { rotulo: 'Acautelamentos', para: '/acautelamentos' }, { rotulo: 'Nova solicitação' }]
  if (itens.carregando) return <Pagina titulo="Nova solicitação" trilha={trilha}><Carregando /></Pagina>
  return (
    <Pagina titulo="Nova solicitação de acautelamento" icone={ShieldHalf} trilha={trilha}>
      <Aviso tipo="info" titulo="Como funciona:">quem solicita não pode decidir. A entrega só acontece depois da aprovação.</Aviso>
      {erro && <div className="aviso aviso-perigo" role="alert"><strong>Não foi possível solicitar. </strong>{erro.erro.message}</div>}
      <form className="bloco" noValidate onSubmit={(e) => { e.preventDefault(); enviar() }}>
        <div className="grade">
          {/* Decisão 2026-11-18: busca do balcão (escopo da operação), sem exigir a tela Efetivos. */}
          <div>
            <BuscaEfetivo obrigatorio valor={efetivo} onEscolher={setEfetivo} />
            {fe('militar_id') && <p className="msg-erro">{fe('militar_id')!.join(' ')}</p>}
            {pode('militares.gerir') && !efetivo && <AtalhoCadastro para="/militares/novo" rotulo="Efetivo não está na lista? Cadastrar efetivo" aoVoltar={() => {}} />}
          </div>
          <Campo rotulo="Item" obrigatorio dica="Somente itens individuais disponíveis no seu escopo." erro={fe('item_individual_id')}>{(p) => (
            <select {...p} value={f.item_individual_id} onChange={(e) => setF({ ...f, item_individual_id: e.target.value })}>
              <option value="">Selecione…</option>{itens.dados?.data.map((i) => <option key={i.id} value={i.id}>{textoItem(i.numero_serie, i.identificador)} — {i.material.nome} ({i.local.codigo})</option>)}
            </select>)}</Campo>
          <Campo rotulo="Justificativa" obrigatorio dica="Mínimo de 5 caracteres." erro={fe('justificativa')}>{(p) => <textarea {...p} rows={3} maxLength={500} value={f.justificativa} onChange={(e) => setF({ ...f, justificativa: e.target.value })} />}</Campo>
        </div>
        <div className="barra-acoes">
          <button type="button" className="btn btn-sec" onClick={() => nav(-1)}>Cancelar</button>
          <button className="btn btn-pri" disabled={enviando || !efetivo || !f.item_individual_id || f.justificativa.trim().length < 5}>{enviando ? 'Enviando…' : 'Solicitar'}</button>
        </div>
      </form>
    </Pagina>
  )
}
