import { PackageOpen, Search } from 'lucide-react'
import { useEffect, useRef, useState } from 'react'
import { Link, useSearchParams } from 'react-router'
import { api, type Pagina as Pag } from '../api/client'
import type { EntregaAgrupada } from '../api/tipos'
import { useAuth } from '../auth/AuthContext'
import { BaixaDialogo, type ItemBaixa } from '../components/estoque/BaixaDialogo'
import { DevolucaoDialogo } from '../components/estoque/DevolucaoDialogo'
import { ResumoMateriais, resumoConservacao } from '../components/estoque/EntregaResumo'
import { Carregando, Erro, Pagina, Paginacao, Vazio, data, dataHora } from '../components/ui'
import { useRecurso } from '../components/useRecurso'
import { NomeEfetivo } from './EntregasPage'

/** Agrupa as entregas por efetivo, na ordem em que vieram (mais recentes primeiro). */
export function porEfetivo(lista: EntregaAgrupada[]): { militar: EntregaAgrupada['militar']; entregas: EntregaAgrupada[] }[] {
  const m = new Map<number, { militar: EntregaAgrupada['militar']; entregas: EntregaAgrupada[] }>()
  for (const e of lista) {
    if (!m.has(e.militar.id)) m.set(e.militar.id, { militar: e.militar, entregas: [] })
    m.get(e.militar.id)!.entregas.push(e)
  }
  return [...m.values()]
}

/**
 * Devolução de material (decisão do responsável, 2026-11-18): tela de balcão. Uma busca só — nome, nome de guerra, matrícula,
 * CPF, nº da entrega (#1183) ou comprovante do pedido (PED-XXXXXX) — mostra o que está com o efetivo, agrupado por entrega.
 * "Receber devolução" abre o diálogo de sempre (data automática, estado por item, quem devolve, total/parcial, baixa em seguida).
 */
export default function DevolucoesPage() {
  const { pode } = useAuth()
  const [params, setParams] = useSearchParams()
  const q = params.get('q') ?? ''
  const militar = params.get('militar_id') ?? ''
  const page = Number(params.get('page') ?? 1)
  const [termo, setTermo] = useState(q)
  const campo = useRef<HTMLInputElement>(null)
  const ativo = q.length >= 2 || !!militar
  const lista = useRecurso(() => ativo
    ? api<Pag<EntregaAgrupada>>('/stock/linked', { query: { ...(q ? { q } : {}), ...(militar ? { militar_id: militar } : {}), per_page: 30, page } })
    : Promise.resolve(null), [params.toString()])
  const [devolver, setDevolver] = useState<EntregaAgrupada | null>(null)
  const [baixa, setBaixa] = useState<{ itens: ItemBaixa[]; localId: number } | null>(null)
  useEffect(() => { campo.current?.focus() }, [])

  const buscar = (t: string) => { const p = new URLSearchParams(); if (t) p.set('q', t); setParams(p) }
  const verEfetivo = (id: number) => setParams(new URLSearchParams({ militar_id: String(id) }))
  const grupos = porEfetivo(lista.dados?.data ?? [])

  return (
    <Pagina icone={PackageOpen} titulo="Devolução de material" trilha={[{ rotulo: 'Início', para: '/' }, { rotulo: 'Devolução de material' }]}
      descricao="Busque o efetivo ou a entrega, confira o que está com ele e receba a devolução. Data e hora vêm automáticas.">
      <form className="balcao-busca" role="search" onSubmit={(e) => { e.preventDefault(); buscar(termo.trim()) }}>
        <label htmlFor="busca-devolucao">Efetivo, matrícula, CPF, nº da entrega ou comprovante do pedido</label>
        <div className="acoes-linha">
          <input id="busca-devolucao" ref={campo} type="search" className="estica" autoComplete="off" value={termo} onChange={(e) => setTermo(e.target.value)}
            placeholder="Ex.: Silva · 123456 · 000.000.000-00 · #1183 · PED-7K2M9Q" aria-describedby="busca-devolucao-dica" />
          <button className="btn btn-pri" disabled={termo.trim().length < 2}><Search size={18} aria-hidden="true" />Buscar</button>
        </div>
        <small id="busca-devolucao-dica" className="dica">Matrícula e CPF exatos. Só aparece o que está pendente na sua base.</small>
      </form>

      {militar && <p className="filtro-ativo" role="status">Tudo o que está com o efetivo · <button type="button" className="link" onClick={() => buscar(q)}>Voltar à busca</button></p>}
      {!ativo && <Vazio titulo="Digite ao menos 2 caracteres para buscar." icone={Search} />}
      {lista.carregando && <Carregando rotulo="Buscando…" />}
      {lista.erro && <Erro erro={lista.erro} tentar={lista.recarregar} />}
      {lista.dados && !lista.carregando && (grupos.length === 0
        ? <Vazio titulo="Nada pendente encontrado.">{pode('estoque.ver') && <Link to="/entregas">Ver todas as entregas</Link>}</Vazio>
        : <>
          <p role="status" className="sr-only">{lista.dados.meta.total} entrega(s) encontrada(s).</p>
          {grupos.map((g) => (
            <section key={g.militar.id} className="cartao balcao-efetivo" aria-labelledby={`ef-${g.militar.id}`}>
              <header className="balcao-efetivo-topo">
                <h2 id={`ef-${g.militar.id}`}><NomeEfetivo m={g.militar} />{g.militar.nome && <small> · {g.militar.nome}</small>}</h2>
                {!militar && <button type="button" className="btn btn-sec btn-peq" onClick={() => verEfetivo(g.militar.id)}>Ver tudo com este efetivo</button>}
              </header>
              <ul className="balcao-entregas">
                {g.entregas.map((e) => (
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
                        : e.pode_receber && <button type="button" className="btn btn-pri" onClick={() => setDevolver(e)}>Receber devolução<span className="sr-only"> da entrega #{e.entrega_id}</span></button>}
                    </div>
                  </li>))}
              </ul>
            </section>))}
          <Paginacao meta={lista.dados.meta} ir={(p) => { const n = new URLSearchParams(params); n.set('page', String(p)); setParams(n) }} />
        </>)}

      {devolver?.entrega_id && <DevolucaoDialogo entregaId={devolver.entrega_id}
        onFechar={() => setDevolver(null)}
        onConcluir={({ problemas, localId }) => { setDevolver(null); lista.recarregar(); if (problemas.length && localId && pode('estoque.baixar')) setBaixa({ itens: problemas, localId }) }} />}
      {baixa && <BaixaDialogo itens={baixa.itens} localId={baixa.localId} motivoInicial="Devolvido com problema pelo efetivo"
        onFechar={() => setBaixa(null)} onConcluir={() => setBaixa(null)} />}
    </Pagina>
  )
}
