import { HandHelping, UserCheck } from 'lucide-react'
import { useState } from 'react'
import { Link, useSearchParams } from 'react-router'
import { api, type Pagina as Pag } from '../api/client'
import type { CatalogoTipos, EntregaAgrupada, Material, Unidade } from '../api/tipos'
import { useAuth } from '../auth/AuthContext'
import { BaixaDialogo, type ItemBaixa } from '../components/estoque/BaixaDialogo'
import { DevolucaoDialogo } from '../components/estoque/DevolucaoDialogo'
import { ResumoMateriais, resumoConservacao } from '../components/estoque/EntregaResumo'
import { FiltroUnidade } from '../components/estoque/FiltroUnidade'
import { Aviso, Carregando, Erro, Pagina, Paginacao, Selo, Tabela, Vazio, data, dataHora } from '../components/ui'
import { useRecurso } from '../components/useRecurso'

const FILTROS = ['q', 'militar_id', 'material_id', 'atrasados', 'custodia', 'perfil', 'tipo_id', 'modo_controle', 'unidade_id'] as const

/** Nome do efetivo: link para o perfil só para quem tem a tela Efetivos (o armeiro não tem — decisão 2026-11-18). */
export function NomeEfetivo({ m }: { m: EntregaAgrupada['militar'] }) {
  const { pode } = useAuth()
  const nome = `${m.posto} ${m.nome_guerra}`
  return pode('militares.ver') ? <Link to={`/militares/${m.id}`}>{nome}</Link> : <strong>{nome}</strong>
}

/**
 * Entrega de material (decisões do responsável, 2026-11-16 e 2026-11-18): uma linha por entrega (ou acautelamento) com todos os
 * materiais ainda com o efetivo. Daqui o armeiro registra a entrega, recebe a devolução da entrega inteira (total ou parcial)
 * e, se algo voltou com problema, registra a baixa em seguida.
 */
export default function EntregasPage() {
  const { pode, perfil } = useAuth()
  const [params, setParams] = useSearchParams()
  const f = Object.fromEntries(FILTROS.map((k) => [k, params.get(k) ?? ''])) as Record<(typeof FILTROS)[number], string>
  const page = Number(params.get('page') ?? 1)
  const [busca, setBusca] = useState(f.q)
  const lista = useRecurso(() => api<Pag<EntregaAgrupada>>('/stock/linked', { query: { ...Object.fromEntries(Object.entries(f).filter(([, v]) => v)), page } }), [params.toString()])
  const cat = useRecurso(() => api<CatalogoTipos>('/catalog/tipos-material'), [])
  const mats = useRecurso(() => api<Pag<Material>>('/items', { query: { per_page: 100 } }).catch(() => null), [])
  const unidades = useRecurso(() => api<{ data: Unidade[] }>('/units'), [])
  const definir = (k: string, v: string) => { const p = new URLSearchParams(params); if (v) p.set(k, v); else p.delete(k); p.delete('page'); setParams(p) }
  const [devolver, setDevolver] = useState<EntregaAgrupada | null>(null)
  const [baixa, setBaixa] = useState<{ itens: ItemBaixa[]; localId: number } | null>(null)
  const podeEntregar = pode('estoque.entregar') && perfil?.operacoes.find((o) => o.codigo === 'ENTREGA')?.estado !== 'BLOQUEADA'
  const atrasados = lista.dados?.data.filter((v) => v.atrasado).length ?? 0

  return (
    <Pagina icone={UserCheck} titulo="Entrega de material" trilha={[{ rotulo: 'Início', para: '/' }, { rotulo: 'Entrega de material' }]}
      descricao="Uma linha por entrega: o que está com cada efetivo no seu escopo. Daqui você registra a entrega, recebe a devolução e dá baixa do que voltou com problema."
      acoes={podeEntregar && <Link className="btn btn-pri" to="/entregas/nova"><HandHelping size={18} aria-hidden="true" />Registrar entrega</Link>}>
      <form className="filtros" role="search" onSubmit={(e) => { e.preventDefault(); definir('q', busca.trim()) }}>
        <label>Buscar<input type="search" value={busca} onChange={(e) => setBusca(e.target.value)} placeholder="Efetivo, material, série, lote ou nº da entrega" /></label>
        <label>Material<select value={f.material_id} onChange={(e) => definir('material_id', e.target.value)}><option value="">Todos</option>
          {mats.dados?.data.map((m) => <option key={m.id} value={m.id}>{m.nome}</option>)}</select></label>
        <label className="check"><input type="checkbox" checked={f.atrasados === '1'} onChange={(e) => definir('atrasados', e.target.checked ? '1' : '')} />Só atrasados</label>
        <label>Custódia<select value={f.custodia} onChange={(e) => definir('custodia', e.target.value)}><option value="">Todas</option><option value="ENTREGA">Entrega</option><option value="ACAUTELAMENTO">Acautelamento</option></select></label>
        <label>Perfil<select value={f.perfil} onChange={(e) => definir('perfil', e.target.value)}><option value="">Todos</option>
          {cat.dados && Object.entries(cat.dados.perfis).map(([k, r]) => <option key={k} value={k}>{r.rotulo}</option>)}</select></label>
        <FiltroUnidade unidades={unidades.dados?.data} valor={f.unidade_id} onChange={(v) => definir('unidade_id', v)} />
        <button className="btn btn-sec">Filtrar</button>
      </form>
      {f.militar_id && <p className="filtro-ativo" role="status">Filtrando por um efetivo · <button type="button" className="link" onClick={() => definir('militar_id', '')}>Ver todos</button></p>}
      {atrasados > 0 && !f.atrasados && <Aviso tipo="alerta">{atrasados} entrega(s) desta página com devolução atrasada. <button type="button" className="link" onClick={() => definir('atrasados', '1')}>Ver só as atrasadas</button></Aviso>}
      {lista.carregando && <Carregando />}
      {lista.erro && <Erro erro={lista.erro} tentar={lista.recarregar} />}
      {lista.dados && !lista.carregando && (lista.dados.data.length === 0 ? <Vazio titulo="Nenhuma entrega pendente com estes filtros.">{podeEntregar && <Link to="/entregas/nova">Registrar uma entrega</Link>}</Vazio> : <>
        <Tabela legenda="Entregas com material ainda com o efetivo" linhas={lista.dados.data} chave={(e) => `${e.custodia}-${e.origem}`} colunas={[
          { titulo: 'Efetivo', celula: (e) => <NomeEfetivo m={e.militar} /> },
          { titulo: 'Materiais', celula: (e) => <ResumoMateriais e={e} /> },
          { titulo: 'Entregue em', celula: (e) => <time dateTime={e.desde}>{dataHora(e.desde)}</time> },
          { titulo: 'Previsão de retorno', celula: (e) => e.custodia === 'ACAUTELAMENTO' ? <Selo valor="ACAUTELAMENTO" tom="info" />
            : <>{data(e.previsao_retorno)}{e.atrasado && <><br /><span className="selo selo-perigo">Atrasado</span></>}</> },
          { titulo: 'Conservação', celula: (e) => resumoConservacao(e.conservacao) },
          { titulo: 'Ações', celula: (e) => e.custodia === 'ACAUTELAMENTO'
            ? <Link className="btn btn-sec btn-peq" to={`/acautelamentos/${e.origem}`}>Ver acautelamento</Link>
            : <div className="acoes-linha">
              {e.pode_receber && <button type="button" className="btn btn-pri btn-peq" onClick={() => setDevolver(e)}>Receber devolução<span className="sr-only"> da entrega #{e.entrega_id} de {e.militar.nome_guerra}</span></button>}
              <Link className="btn btn-sec btn-peq" to={`/movimentacoes/${e.entrega_id}`}>Entrega #{e.entrega_id}</Link>
            </div> },
        ]} />
        <Paginacao meta={lista.dados.meta} ir={(p) => { const q = new URLSearchParams(params); q.set('page', String(p)); setParams(q) }} />
      </>)}

      {devolver?.entrega_id && <DevolucaoDialogo entregaId={devolver.entrega_id}
        onFechar={() => setDevolver(null)}
        onConcluir={({ problemas, localId }) => { setDevolver(null); lista.recarregar(); if (problemas.length && localId && pode('estoque.baixar')) setBaixa({ itens: problemas, localId }) }} />}
      {baixa && <BaixaDialogo itens={baixa.itens} localId={baixa.localId} motivoInicial="Devolvido com problema pelo efetivo"
        onFechar={() => setBaixa(null)} onConcluir={() => setBaixa(null)} />}
    </Pagina>
  )
}
