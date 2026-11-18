import { CheckCircle2, ChevronDown, PackageOpen, Search } from 'lucide-react'
import { useCallback, useEffect, useRef, useState } from 'react'
import { Link, useSearchParams } from 'react-router'
import { api, type Pagina as Pag } from '../api/client'
import type { EfetivoPendente, EntregaAgrupada } from '../api/tipos'
import { useAuth } from '../auth/AuthContext'
import { BaixaDialogo, type ItemBaixa } from '../components/estoque/BaixaDialogo'
import { DevolucaoDialogo } from '../components/estoque/DevolucaoDialogo'
import { ResumoMateriais, resumoConservacao } from '../components/estoque/EntregaResumo'
import { Carregando, Erro, Pagina, Paginacao, Vazio, data, dataHora, numero } from '../components/ui'
import { rotuloEfetivo } from '../components/efetivo'
import { useRecurso } from '../components/useRecurso'

/** Agrupa as entregas por efetivo, na ordem em que vieram (mais recentes primeiro). */
export function porEfetivo(lista: EntregaAgrupada[]): { militar: EntregaAgrupada['militar']; entregas: EntregaAgrupada[] }[] {
  const m = new Map<number, { militar: EntregaAgrupada['militar']; entregas: EntregaAgrupada[] }>()
  for (const e of lista) {
    if (!m.has(e.militar.id)) m.set(e.militar.id, { militar: e.militar, entregas: [] })
    m.get(e.militar.id)!.entregas.push(e)
  }
  return [...m.values()]
}

const ATRASO_BUSCA_MS = 350

/** Resumo do que está com o efetivo: "2 entregas · 3 itens · 1 lote (30 unid.)". */
export function resumoPendente(e: EfetivoPendente): string {
  const partes = [`${e.entregas} ${e.entregas === 1 ? 'entrega' : 'entregas'}`]
  if (e.itens) partes.push(`${e.itens} ${e.itens === 1 ? 'item' : 'itens'}`)
  if (e.lotes) partes.push(`${e.lotes} ${e.lotes === 1 ? 'lote' : 'lotes'} (${numero(e.quantidade_lotes)} unid.)`)
  return partes.join(' · ')
}

/**
 * Devolução de material (decisões do responsável, 2026-11-18). A tela abre JÁ LISTANDO os efetivos com material pendente na base
 * (atrasados primeiro, depois a previsão de retorno mais próxima), sem precisar buscar. Estrutura das referências Mobbin (incident.io
 * "Post-incident flow", Plane "Work items", Meetup "Members"): lista de pessoas com prazo/selo na linha e ações ao expandir.
 * A busca do balcão (nome, matrícula, CPF, #entrega, PED-) filtra a mesma lista. Expandir mostra as entregas e "Receber devolução".
 */
export default function DevolucoesPage() {
  const { pode } = useAuth()
  const [params, setParams] = useSearchParams()
  const q = params.get('q') ?? ''
  const page = Number(params.get('page') ?? 1)
  const [termo, setTermo] = useState(q)
  const campo = useRef<HTMLInputElement>(null)
  const lista = useRecurso(() => api<Pag<EfetivoPendente>>('/stock/linked/recipients', { query: { ...(q ? { q } : {}), page } }), [q, page])
  const [aberto, setAberto] = useState<number | null>(null)
  const [devolver, setDevolver] = useState<EntregaAgrupada | null>(null)
  const [baixa, setBaixa] = useState<{ itens: ItemBaixa[]; localId: number } | null>(null)
  const [versao, setVersao] = useState(0)
  useEffect(() => { campo.current?.focus() }, [])

  const buscar = useCallback((t: string) => { setParams(t ? { q: t } : {}, { replace: true }); setAberto(null) }, [setParams])
  useEffect(() => {
    if (termo.trim() === q || (termo.trim().length === 1)) return
    const t = setTimeout(() => buscar(termo.trim()), ATRASO_BUSCA_MS)
    return () => clearTimeout(t)
  }, [termo, q, buscar])
  const concluir = ({ problemas, localId }: { problemas: ItemBaixa[]; localId: number | null }) => {
    setDevolver(null); lista.recarregar(); setVersao((v) => v + 1)
    if (problemas.length && localId && pode('estoque.baixar')) setBaixa({ itens: problemas, localId })
  }
  const total = lista.dados?.meta.total ?? 0
  const atrasados = lista.dados?.data.filter((e) => e.atrasado).length ?? 0

  return (
    <Pagina icone={PackageOpen} titulo="Devolução de material" trilha={[{ rotulo: 'Início', para: '/' }, { rotulo: 'Devolução de material' }]}
      descricao="Quem está com material da base, com os atrasados primeiro. Abra o efetivo para ver as entregas e receber a devolução.">
      <form className="balcao-busca" role="search" onSubmit={(e) => { e.preventDefault(); buscar(termo.trim()) }}>
        <label htmlFor="busca-devolucao">Efetivo, matrícula, CPF, nº da entrega ou comprovante do pedido</label>
        <div className="barra-busca barra-busca-larga">
          <Search size={18} aria-hidden="true" />
          <input id="busca-devolucao" ref={campo} type="search" autoComplete="off" value={termo} onChange={(e) => setTermo(e.target.value)}
            placeholder="Ex.: Silva · 123456 · 000.000.000-00 · #1183 · PED-7K2M9Q" aria-describedby="busca-devolucao-dica" />
        </div>
        <small id="busca-devolucao-dica" className="dica">A lista filtra enquanto você digita. Matrícula e CPF exatos. Só aparece o que está pendente na sua base.</small>
      </form>

      <p className="sr-only" role="status" aria-live="polite">{lista.dados && !lista.carregando ? `${total} efetivo(s) com material pendente${q ? ` para “${q}”` : ''}.` : ''}</p>
      {lista.carregando && !lista.dados && <Carregando rotulo="Carregando quem está com material…" />}
      {lista.erro && <Erro erro={lista.erro} tentar={lista.recarregar} />}
      {lista.dados && (lista.dados.data.length === 0
        ? (q
          ? <Vazio titulo={`Nada pendente encontrado para “${q}”.`} icone={Search}><button type="button" className="btn btn-sec" onClick={() => { setTermo(''); buscar('') }}>Ver todos os efetivos</button></Vazio>
          : <Vazio titulo="Nenhum material com efetivos na base." icone={CheckCircle2}><p>Tudo o que foi entregue já voltou. {pode('estoque.ver') && <Link to="/entregas">Ver entregas</Link>}</p></Vazio>)
        : <div aria-busy={lista.carregando} className={lista.carregando ? 'atualizando' : undefined}>
          <p className="lista-resumo">{total} {total === 1 ? 'efetivo' : 'efetivos'} com material pendente{atrasados > 0 && <> · <span className="selo selo-perigo">{atrasados} com atraso nesta página</span></>}</p>
          <ul className="lista-efetivos">
            {lista.dados.data.map((e) => {
              const exp = aberto === e.militar.id
              const painel = `entregas-ef-${e.militar.id}`
              return (
                <li key={e.militar.id} className={`efetivo-pendente${e.atrasado ? ' efetivo-atrasado' : ''}${exp ? ' aberto' : ''}`}>
                  <button type="button" className="efetivo-pendente-topo" aria-expanded={exp} aria-controls={painel} onClick={() => setAberto(exp ? null : e.militar.id)}>
                    <span className="efetivo-pendente-nome">
                      <strong>{rotuloEfetivo(e.militar)}</strong>
                      {e.militar.nome && <small>{e.militar.nome}</small>}
                    </span>
                    <span className="efetivo-pendente-info">
                      <span>{resumoPendente(e)}</span>
                      <small>Desde {dataHora(e.mais_antiga)}{e.proxima_previsao ? ` · retorno previsto ${data(e.proxima_previsao)}` : ' · cautela sem previsão'}</small>
                    </span>
                    <span className="efetivo-pendente-selos">
                      {e.atrasado ? <span className="selo selo-perigo">Atrasado{e.atrasadas > 1 ? ` (${e.atrasadas})` : ''}</span> : <span className="selo selo-ok">No prazo</span>}
                      <ChevronDown size={20} aria-hidden="true" className="chevron" />
                    </span>
                  </button>
                  {exp && <div id={painel} className="efetivo-pendente-corpo">
                    <EntregasDoEfetivo militarId={e.militar.id} versao={versao} aoDevolver={setDevolver} />
                  </div>}
                </li>)
            })}
          </ul>
          <Paginacao meta={lista.dados.meta} ir={(p) => { const n = new URLSearchParams(params); n.set('page', String(p)); setParams(n); setAberto(null) }} />
        </div>)}

      {devolver?.entrega_id && <DevolucaoDialogo entregaId={devolver.entrega_id} onFechar={() => setDevolver(null)} onConcluir={concluir} />}
      {baixa && <BaixaDialogo itens={baixa.itens} localId={baixa.localId} motivoInicial="Devolvido com problema pelo efetivo"
        onFechar={() => setBaixa(null)} onConcluir={() => setBaixa(null)} />}
    </Pagina>
  )
}

/** Entregas pendentes do efetivo (mesma fonte da tela "Entrega de material"), com "Receber devolução" ou "Desacautelar". */
function EntregasDoEfetivo({ militarId, versao, aoDevolver }: { militarId: number; versao: number; aoDevolver: (e: EntregaAgrupada) => void }) {
  const r = useRecurso(() => api<Pag<EntregaAgrupada>>('/stock/linked', { query: { militar_id: militarId, per_page: 50 } }), [militarId, versao])
  if (r.carregando && !r.dados) return <Carregando rotulo="Carregando entregas…" />
  if (r.erro) return <Erro erro={r.erro} tentar={r.recarregar} />
  const entregas = r.dados?.data ?? []
  if (!entregas.length) return <p className="dica">Nada mais pendente com este efetivo.</p>
  return (
    <ul className="balcao-entregas">
      {entregas.map((e) => (
        <li key={`${e.custodia}-${e.origem}`} className="balcao-entrega">
          <div className="balcao-entrega-info">
            <p><strong>{e.custodia === 'ACAUTELAMENTO' ? 'Acautelamento' : `Entrega #${e.entrega_id}`}</strong>
              {e.pedido && <> · pedido <span className="mono">{e.pedido.comprovante}</span></>}
              {' · '}<time dateTime={e.desde}>{dataHora(e.desde)}</time></p>
            <ResumoMateriais e={e} />
            <p className="dica">
              {e.custodia === 'ENTREGA' ? <>Retorno previsto: {data(e.previsao_retorno)}</> : 'Cautela ativa'}
              {e.atrasado && <> <span className="selo selo-perigo">Atrasado</span></>} · Conservação: {resumoConservacao(e.conservacao)}
            </p>
          </div>
          <div className="acoes-linha">
            {e.custodia === 'ACAUTELAMENTO'
              ? <Link className="btn btn-sec" to={`/acautelamentos/${e.origem}`}>Desacautelar<span className="sr-only"> (abre o acautelamento)</span></Link>
              : e.pode_receber && <button type="button" className="btn btn-pri" onClick={() => aoDevolver(e)} aria-label={`Receber devolução da entrega #${e.entrega_id}`}>Receber devolução</button>}
          </div>
        </li>))}
    </ul>
  )
}
