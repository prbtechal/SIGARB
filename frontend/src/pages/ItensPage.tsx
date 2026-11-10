import { CalendarClock } from 'lucide-react'
import { useState } from 'react'
import { useSearchParams } from 'react-router'
import { ErroApi, api, type Pagina as Pag } from '../api/client'
import type { CatalogoTipos, ItemIndividual, Unidade } from '../api/tipos'
import { Aviso, CONSERVACAO, Campo, Carregando, Dialogo, Erro, Pagina, Paginacao, Selo, Tabela, Vazio, data, rotulo, useNotificar } from '../components/ui'
import { useRecurso } from '../components/useRecurso'

const FILTROS = ['validade', 'perfil', 'tipo_id', 'unidade_id', 'situacao', 'q'] as const

/**
 * CP-12 — itens individuais com atributos físicos, validade (coletes) e estado de conservação.
 * A validade só informa: não muda a situação operacional (sem regra institucional aprovada).
 */
export default function ItensPage() {
  const notificar = useNotificar()
  const [params, setParams] = useSearchParams()
  const f = Object.fromEntries(FILTROS.map((k) => [k, params.get(k) ?? ''])) as Record<(typeof FILTROS)[number], string>
  const page = Number(params.get('page') ?? 1)
  const [busca, setBusca] = useState(f.q)
  const itens = useRecurso(() => api<Pag<ItemIndividual>>('/stock/individual-items', { query: { ...Object.fromEntries(Object.entries(f).filter(([, v]) => v)), page } }),
    [params.toString()])
  const cat = useRecurso(() => api<CatalogoTipos>('/catalog/tipos-material'), [])
  const unidades = useRecurso(() => api<{ data: Unidade[] }>('/units'), [])
  const definir = (k: string, v: string) => { const p = new URLSearchParams(params); if (v) p.set(k, v); else p.delete(k); p.delete('page'); setParams(p) }
  const [editar, setEditar] = useState<ItemIndividual | null>(null)
  const [cons, setCons] = useState({ estado_conservacao: '', justificativa: '' })
  const [erro, setErro] = useState<ErroApi | null>(null)

  const salvar = async () => {
    if (!editar) return
    setErro(null)
    try {
      await api(`/stock/individual-items/${editar.id}/conservation`, { method: 'PATCH', body: cons })
      notificar('ok', `Conservação de ${editar.identificador} atualizada.`); setEditar(null); itens.recarregar()
    } catch (x) { if (x instanceof ErroApi) setErro(x) }
  }

  return (
    <Pagina icone={CalendarClock} titulo="Itens e validades" trilha={[{ rotulo: 'Início', para: '/' }, { rotulo: 'Estoque', para: '/estoque' }, { rotulo: 'Itens e validades' }]}
      descricao="Itens individuais no seu escopo. Estado de conservação = condição física; situação = ciclo de vida (muda só por operações); validade = prazo do fabricante.">
      <form className="filtros" role="search" onSubmit={(e) => { e.preventDefault(); definir('q', busca) }}>
        <label>Buscar<input type="search" value={busca} onChange={(e) => setBusca(e.target.value)} placeholder="Identificador ou série" /></label>
        <label>Validade<select value={f.validade} onChange={(e) => definir('validade', e.target.value)}>
          <option value="">Todas</option><option value="VENCIDO">Vencidos</option><option value="VENCENDO">Vencendo</option><option value="VALIDO">Dentro da validade</option><option value="COM_VALIDADE">Com validade informada</option></select></label>
        <label>Perfil<select value={f.perfil} onChange={(e) => definir('perfil', e.target.value)}><option value="">Todos</option>
          {cat.dados && Object.entries(cat.dados.perfis).map(([k, r]) => <option key={k} value={k}>{r.rotulo}</option>)}</select></label>
        <label>Tipo<select value={f.tipo_id} onChange={(e) => definir('tipo_id', e.target.value)}><option value="">Todos</option>
          {cat.dados?.data.filter((t) => !f.perfil || t.perfil === f.perfil).map((t) => <option key={t.id} value={t.id}>{t.nome}</option>)}</select></label>
        <label>Unidade<select value={f.unidade_id} onChange={(e) => definir('unidade_id', e.target.value)}><option value="">Todas no escopo</option>
          {unidades.dados?.data.map((u) => <option key={u.id} value={u.id}>{'— '.repeat(u.nivel)}{u.sigla}</option>)}</select></label>
        <label>Situação<select value={f.situacao} onChange={(e) => definir('situacao', e.target.value)}><option value="">Todas</option>
          {['DISPONIVEL', 'RESERVADO', 'ACAUTELADO', 'ENTREGUE', 'MANUTENCAO', 'EM_TRANSITO', 'BAIXADO'].map((s) => <option key={s} value={s}>{rotulo(s)}</option>)}</select></label>
        <button className="btn btn-sec">Filtrar</button>
      </form>
      {itens.carregando && <Carregando />}
      {itens.erro && <Erro erro={itens.erro} tentar={itens.recarregar} />}
      {itens.dados && !itens.carregando && (itens.dados.data.length === 0 ? <Vazio titulo="Nenhum item encontrado com estes filtros." /> : <>
        <Tabela legenda="Itens individuais" linhas={itens.dados.data} chave={(i) => i.id} colunas={[
          { titulo: 'Identificador', celula: (i) => <><span className="mono">{i.identificador}</span>{i.numero_serie && <><br /><small>Série {i.numero_serie}</small></>}</> },
          { titulo: 'Material', celula: (i) => <>{i.material.nome}{i.material.tipo && <><br /><small>{i.material.tipo.nome}</small></>}</> },
          { titulo: 'Atributos', celula: (i) => [i.tamanho && `Tam. ${i.tamanho}`, i.nivel_protecao && `Nível ${i.nivel_protecao}`, i.fabricado_em && `Fab. ${data(i.fabricado_em)}`].filter(Boolean).join(' · ') || '—' },
          { titulo: 'Validade', celula: (i) => i.validade ? <><time dateTime={i.validade}>{data(i.validade)}</time><br /><Selo valor={i.validade_estado ?? ''} /></> : '—' },
          { titulo: 'Conservação', celula: (i) => rotulo(i.estado_conservacao) },
          { titulo: 'Situação', celula: (i) => <Selo valor={i.situacao} /> },
          { titulo: 'Local', celula: (i) => <>{i.local.codigo}{i.unidade && <><br /><small>{i.unidade.sigla}</small></>}</> },
          { titulo: 'Ações', celula: (i) => i.pode_atualizar_conservacao && !['BAIXADO', 'ESTORNADO'].includes(i.situacao)
            ? <button type="button" className="btn btn-sec btn-peq" onClick={() => { setErro(null); setCons({ estado_conservacao: i.estado_conservacao, justificativa: '' }); setEditar(i) }}>Conservação<span className="sr-only"> de {i.identificador}</span></button> : null },
        ]} />
        <Paginacao meta={itens.dados.meta} ir={(p) => { const q = new URLSearchParams(params); q.set('page', String(p)); setParams(q) }} />
      </>)}
      <Dialogo tour="conservacao" aberto={!!editar} titulo={`Atualizar conservação — ${editar?.identificador ?? ''}`} onFechar={() => setEditar(null)}
        rodape={<><button className="btn btn-sec" onClick={() => setEditar(null)}>Cancelar</button><button className="btn btn-pri" onClick={salvar}>Salvar</button></>}>
        {erro && !erro.erro.fields && <div className="aviso aviso-perigo" role="alert">{erro.erro.message}</div>}
        <Aviso>Altera só a condição física. A situação operacional ({editar && rotulo(editar.situacao)}) não muda.</Aviso>
        <Campo rotulo="Estado de conservação" obrigatorio erro={erro?.erro.fields?.estado_conservacao}>{(p) => <select {...p} value={cons.estado_conservacao} onChange={(e) => setCons({ ...cons, estado_conservacao: e.target.value })}>{CONSERVACAO.map((c) => <option key={c} value={c}>{rotulo(c)}</option>)}</select>}</Campo>
        <Campo rotulo="Justificativa" obrigatorio dica="Mínimo de 10 caracteres. Fica registrada na auditoria." erro={erro?.erro.fields?.justificativa}>{(p) => <textarea {...p} rows={3} maxLength={500} value={cons.justificativa} onChange={(e) => setCons({ ...cons, justificativa: e.target.value })} />}</Campo>
      </Dialogo>
    </Pagina>
  )
}
