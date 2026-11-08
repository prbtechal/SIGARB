import { UserCheck } from 'lucide-react'
import { Link, useSearchParams } from 'react-router'
import { api, type Pagina as Pag } from '../api/client'
import type { CatalogoTipos, Unidade, Vinculado } from '../api/tipos'
import { Carregando, Erro, Pagina, Paginacao, Selo, Tabela, Vazio, data, dataHora, numero, rotulo } from '../components/ui'
import { useRecurso } from '../components/useRecurso'

const FILTROS = ['militar_id', 'custodia', 'perfil', 'tipo_id', 'modo_controle', 'unidade_id'] as const

/** CP-12 — RF-VIN-06: material sob responsabilidade de efetivos (acautelamentos ativos + entregas a devolver). Somente leitura. */
export default function VinculadosPage() {
  const [params, setParams] = useSearchParams()
  const f = Object.fromEntries(FILTROS.map((k) => [k, params.get(k) ?? ''])) as Record<(typeof FILTROS)[number], string>
  const page = Number(params.get('page') ?? 1)
  const lista = useRecurso(() => api<Pag<Vinculado>>('/stock/linked', { query: { ...Object.fromEntries(Object.entries(f).filter(([, v]) => v)), page } }), [params.toString()])
  const cat = useRecurso(() => api<CatalogoTipos>('/catalog/tipos-material'), [])
  const unidades = useRecurso(() => api<{ data: Unidade[] }>('/units'), [])
  const definir = (k: string, v: string) => { const p = new URLSearchParams(params); if (v) p.set(k, v); else p.delete(k); p.delete('page'); setParams(p) }

  return (
    <Pagina icone={UserCheck} titulo="Material com efetivos" trilha={[{ rotulo: 'Início', para: '/' }, { rotulo: 'Estoque', para: '/estoque' }, { rotulo: 'Material com efetivos' }]}
      descricao="Acautelamentos ativos e entregas com retorno ainda não devolvidas, no seu escopo.">
      <div className="filtros" role="search">
        {f.militar_id && <p>Filtrando por efetivo #{f.militar_id} · <button type="button" className="btn btn-sec btn-peq" onClick={() => definir('militar_id', '')}>Ver todos</button></p>}
        <label>Custódia<select value={f.custodia} onChange={(e) => definir('custodia', e.target.value)}><option value="">Todas</option><option value="ACAUTELAMENTO">Acautelamento</option><option value="ENTREGA">Entrega</option></select></label>
        <label>Perfil<select value={f.perfil} onChange={(e) => definir('perfil', e.target.value)}><option value="">Todos</option>
          {cat.dados && Object.entries(cat.dados.perfis).map(([k, r]) => <option key={k} value={k}>{r.rotulo}</option>)}</select></label>
        <label>Tipo<select value={f.tipo_id} onChange={(e) => definir('tipo_id', e.target.value)}><option value="">Todos</option>
          {cat.dados?.data.filter((t) => !f.perfil || t.perfil === f.perfil).map((t) => <option key={t.id} value={t.id}>{t.nome}</option>)}</select></label>
        <label>Controle<select value={f.modo_controle} onChange={(e) => definir('modo_controle', e.target.value)}><option value="">Todos</option><option value="INDIVIDUAL">Individual</option><option value="LOTE">Lote</option></select></label>
        <label>Unidade<select value={f.unidade_id} onChange={(e) => definir('unidade_id', e.target.value)}><option value="">Todas no escopo</option>
          {unidades.dados?.data.map((u) => <option key={u.id} value={u.id}>{'— '.repeat(u.nivel)}{u.sigla}</option>)}</select></label>
      </div>
      {lista.carregando && <Carregando />}
      {lista.erro && <Erro erro={lista.erro} tentar={lista.recarregar} />}
      {lista.dados && !lista.carregando && (lista.dados.data.length === 0 ? <Vazio titulo="Nenhum material sob responsabilidade com estes filtros." /> : <>
        <Tabela legenda="Material sob responsabilidade de efetivos" linhas={lista.dados.data} chave={(v) => `${v.custodia}-${v.origem}-${v.referencia}`} colunas={[
          { titulo: 'Efetivo', celula: (v) => <Link to={`/militares/${v.militar.id}`}>{v.militar.posto} {v.militar.nome_guerra}</Link> },
          { titulo: 'Material', celula: (v) => <>{v.material.nome}<br /><small>{[v.material.categoria, v.material.tipo].filter(Boolean).join(' · ')}</small></> },
          { titulo: 'Item / lote', celula: (v) => <><span className="mono">{v.referencia}</span>{v.material.modo_controle === 'LOTE' && <> · {numero(v.quantidade)}</>}</> },
          { titulo: 'Custódia', celula: (v) => v.custodia === 'ACAUTELAMENTO' ? <Link to={`/acautelamentos/${v.origem}`}><Selo valor="ACAUTELAMENTO" tom="info" /></Link> : <Link to={`/movimentacoes/${v.origem}`}><Selo valor="ENTREGA" /></Link> },
          { titulo: 'Conservação', celula: (v) => v.estado_conservacao ? rotulo(v.estado_conservacao) : '—' },
          { titulo: 'Validade', celula: (v) => data(v.validade) },
          { titulo: 'Desde', celula: (v) => <time dateTime={v.desde}>{dataHora(v.desde)}</time> },
          { titulo: 'Unidade', celula: (v) => v.unidade.sigla },
        ]} />
        <Paginacao meta={lista.dados.meta} ir={(p) => { const q = new URLSearchParams(params); q.set('page', String(p)); setParams(q) }} />
      </>)}
    </Pagina>
  )
}
