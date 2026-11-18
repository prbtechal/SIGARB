import { HandHelping, UserCheck } from 'lucide-react'
import { useState } from 'react'
import { Link, useSearchParams } from 'react-router'
import { api, type Pagina as Pag } from '../api/client'
import type { CatalogoTipos, Material, Unidade, Vinculado } from '../api/tipos'
import { useAuth } from '../auth/AuthContext'
import { BaixaDialogo, type ItemBaixa } from '../components/estoque/BaixaDialogo'
import { DevolucaoDialogo } from '../components/estoque/DevolucaoDialogo'
import { RotuloItem } from '../components/RotuloItem'
import { Aviso, Carregando, Erro, Pagina, Paginacao, Selo, Tabela, Vazio, data, dataHora, numero, rotulo } from '../components/ui'
import { useRecurso } from '../components/useRecurso'

const FILTROS = ['q', 'militar_id', 'material_id', 'atrasados', 'custodia', 'perfil', 'tipo_id', 'modo_controle', 'unidade_id'] as const

/**
 * Material com efetivo (decisão do responsável, 2026-11-16): o armeiro registra a entrega daqui, recebe devoluções (total ou parcial,
 * com estado de cada item e quem devolveu) e, se algo voltou com problema, registra a baixa em seguida.
 * Lista = acautelamentos ativos + entregas com retorno ainda pendentes (descontadas as devoluções parciais).
 */
export default function VinculadosPage() {
  const { pode, perfil } = useAuth()
  const [params, setParams] = useSearchParams()
  const f = Object.fromEntries(FILTROS.map((k) => [k, params.get(k) ?? ''])) as Record<(typeof FILTROS)[number], string>
  const page = Number(params.get('page') ?? 1)
  const [busca, setBusca] = useState(f.q)
  const lista = useRecurso(() => api<Pag<Vinculado>>('/stock/linked', { query: { ...Object.fromEntries(Object.entries(f).filter(([, v]) => v)), page } }), [params.toString()])
  const cat = useRecurso(() => api<CatalogoTipos>('/catalog/tipos-material'), [])
  const mats = useRecurso(() => api<Pag<Material>>('/items', { query: { per_page: 100 } }).catch(() => null), [])
  const unidades = useRecurso(() => api<{ data: Unidade[] }>('/units'), [])
  const definir = (k: string, v: string) => { const p = new URLSearchParams(params); if (v) p.set(k, v); else p.delete(k); p.delete('page'); setParams(p) }
  const [devolver, setDevolver] = useState<Vinculado | null>(null)
  const [baixa, setBaixa] = useState<{ itens: ItemBaixa[]; localId: number } | null>(null)
  const podeEntregar = pode('estoque.entregar') && perfil?.operacoes.find((o) => o.codigo === 'ENTREGA')?.estado !== 'BLOQUEADA'
  const atrasados = lista.dados?.data.filter((v) => v.atrasado).length ?? 0

  return (
    <Pagina icone={UserCheck} titulo="Material com efetivos" trilha={[{ rotulo: 'Início', para: '/' }, { rotulo: 'Estoque', para: '/estoque' }, { rotulo: 'Material com efetivos' }]}
      descricao="Entregas com retorno ainda pendentes e acautelamentos ativos, no seu escopo. Daqui você entrega, recebe a devolução e dá baixa do que voltou com problema."
      acoes={podeEntregar && <Link className="btn btn-pri" to="/estoque/vinculados/nova-entrega"><HandHelping size={18} aria-hidden="true" />Registrar entrega a efetivo</Link>}>
      <form className="filtros" role="search" onSubmit={(e) => { e.preventDefault(); definir('q', busca.trim()) }}>
        <label>Buscar<input type="search" value={busca} onChange={(e) => setBusca(e.target.value)} placeholder="Efetivo, material, nº de série ou lote" /></label>
        <label>Material<select value={f.material_id} onChange={(e) => definir('material_id', e.target.value)}><option value="">Todos</option>
          {mats.dados?.data.map((m) => <option key={m.id} value={m.id}>{m.nome}</option>)}</select></label>
        <label className="check"><input type="checkbox" checked={f.atrasados === '1'} onChange={(e) => definir('atrasados', e.target.checked ? '1' : '')} />Só atrasados</label>
        <label>Custódia<select value={f.custodia} onChange={(e) => definir('custodia', e.target.value)}><option value="">Todas</option><option value="ENTREGA">Entrega</option><option value="ACAUTELAMENTO">Acautelamento</option></select></label>
        <label>Perfil<select value={f.perfil} onChange={(e) => definir('perfil', e.target.value)}><option value="">Todos</option>
          {cat.dados && Object.entries(cat.dados.perfis).map(([k, r]) => <option key={k} value={k}>{r.rotulo}</option>)}</select></label>
        <label>Unidade<select value={f.unidade_id} onChange={(e) => definir('unidade_id', e.target.value)}><option value="">Todas no escopo</option>
          {unidades.dados?.data.map((u) => <option key={u.id} value={u.id}>{'— '.repeat(u.nivel)}{u.sigla}</option>)}</select></label>
        <button className="btn btn-sec">Filtrar</button>
      </form>
      {f.militar_id && <p className="filtro-ativo" role="status">Filtrando por um efetivo · <button type="button" className="link" onClick={() => definir('militar_id', '')}>Ver todos</button></p>}
      {atrasados > 0 && !f.atrasados && <Aviso tipo="alerta">{atrasados} linha(s) desta página com devolução atrasada. <button type="button" className="link" onClick={() => definir('atrasados', '1')}>Ver só os atrasados</button></Aviso>}
      {lista.carregando && <Carregando />}
      {lista.erro && <Erro erro={lista.erro} tentar={lista.recarregar} />}
      {lista.dados && !lista.carregando && (lista.dados.data.length === 0 ? <Vazio titulo="Nenhum material com efetivos com estes filtros.">{podeEntregar && <Link to="/estoque/vinculados/nova-entrega">Registrar uma entrega</Link>}</Vazio> : <>
        <Tabela legenda="Material sob responsabilidade de efetivos" linhas={lista.dados.data} chave={(v) => `${v.custodia}-${v.origem}-${v.item_individual_id ?? 'l' + v.lote_id}`} colunas={[
          { titulo: 'Efetivo', celula: (v) => <Link to={`/militares/${v.militar.id}`}>{v.militar.posto} {v.militar.nome_guerra}</Link> },
          { titulo: 'Material', celula: (v) => <>{v.material.nome}<br /><small>{[v.material.natureza_rotulo, v.material.tipo].filter(Boolean).join(' · ')}</small></> },
          { titulo: 'Nº de série / quantidade', celula: (v) => v.lote_id ? <><RotuloItem lote={v.lote_codigo ?? v.referencia} /> · <strong>{numero(v.quantidade)}</strong> {v.material.unidade_medida ?? ''}</>
            : <RotuloItem serie={v.numero_serie} codigo={v.referencia} /> },
          { titulo: 'Entregue em', celula: (v) => <time dateTime={v.desde}>{dataHora(v.desde)}</time> },
          { titulo: 'Previsão de retorno', celula: (v) => v.custodia === 'ACAUTELAMENTO' ? <Selo valor="ACAUTELAMENTO" tom="info" />
            : <>{data(v.previsao_retorno ?? null)}{v.atrasado && <><br /><span className="selo selo-perigo">Atrasado</span></>}</> },
          { titulo: 'Conservação', celula: (v) => v.estado_conservacao ? rotulo(v.estado_conservacao) : '—' },
          { titulo: 'Ações', celula: (v) => v.custodia === 'ACAUTELAMENTO'
            ? <Link className="btn btn-sec btn-peq" to={`/acautelamentos/${v.origem}`}>Ver acautelamento</Link>
            : <div className="acoes-linha">
              {v.pode_receber && <button type="button" className="btn btn-pri btn-peq" onClick={() => setDevolver(v)}>Receber devolução<span className="sr-only"> de {v.militar.nome_guerra}: {v.numero_serie ?? v.referencia}</span></button>}
              <Link className="btn btn-sec btn-peq" to={`/movimentacoes/${v.entrega_id ?? v.origem}`}>Entrega #{v.entrega_id ?? v.origem}</Link>
            </div> },
        ]} />
        <Paginacao meta={lista.dados.meta} ir={(p) => { const q = new URLSearchParams(params); q.set('page', String(p)); setParams(q) }} />
      </>)}

      {devolver?.entrega_id && <DevolucaoDialogo entregaId={devolver.entrega_id} foco={{ item_individual_id: devolver.item_individual_id, lote_id: devolver.lote_id }}
        onFechar={() => setDevolver(null)}
        onConcluir={({ problemas, localId }) => { setDevolver(null); lista.recarregar(); if (problemas.length && localId && pode('estoque.baixar')) setBaixa({ itens: problemas, localId }) }} />}
      {baixa && <BaixaDialogo itens={baixa.itens} localId={baixa.localId} motivoInicial="Devolvido com problema pelo efetivo"
        onFechar={() => setBaixa(null)} onConcluir={() => setBaixa(null)} />}
    </Pagina>
  )
}
