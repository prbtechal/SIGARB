import { useState } from 'react'
import { Link } from 'react-router'
import { BellRing, Download } from 'lucide-react'
import { api } from '../api/client'
import { useAuth } from '../auth/AuthContext'
import { Aviso, Carregando, Erro, Pagina, Selo, Vazio, data, dataHora, numero } from '../components/ui'
import { GraficoSeries } from '../components/GraficoSeries'
import type { RelatorioEntregas as RelatorioEntregasT } from '../api/tipos'
import { useRecurso } from '../components/useRecurso'
import { AbrirChamadoCentral, MeusChamadosCentral, useCentralSuporte } from '../components/CentralSuporte'

export type Alerta = { tipo: string; severidade: 'perigo' | 'alerta' | 'info'; titulo: string; quantidade: number; link: string }

/** Lista de alertas (RF-022). Reaproveitada como bloco no Início. */
export function ListaAlertas({ alertas }: { alertas: Alerta[] }) {
  if (!alertas.length) return <Vazio titulo="Nenhum alerta no seu escopo." icone={BellRing} />
  return <ul className="lista-alertas">{alertas.map((a) => (
    <li key={a.tipo} className={`alerta-item alerta-${a.severidade}`}><strong>{a.quantidade}</strong> <Link to={a.link}>{a.titulo}</Link>
      <Selo valor={{ perigo: 'Urgente', alerta: 'Atenção', info: 'Informativo' }[a.severidade]} tom={a.severidade} /></li>
  ))}</ul>
}

export function AlertasPage() {
  const { dados, erro, carregando, recarregar } = useRecurso(() => api<{ data: Alerta[]; gerado_em: string }>('/alerts'), [])
  const central = useCentralSuporte()
  return (
    <Pagina icone={BellRing} titulo="Alertas" trilha={[{ rotulo: 'Início', para: '/' }, { rotulo: 'Alertas' }]}
      descricao="Situações que pedem atenção dentro do seu escopo, calculadas no momento da consulta."
      acoes={central.disponivel ? undefined : <AbrirChamadoCentral url={central.url_abrir} />}>
      <MeusChamadosCentral central={central} />
      {carregando && <Carregando />}{erro && <Erro erro={erro} tentar={recarregar} />}
      {dados && <><ListaAlertas alertas={dados.data} /><p className="dica">Gerado em {dataHora(dados.gerado_em)}.</p></>}
    </Pagina>
  )
}

const CHAVES: Record<string, { nome: string; periodo: boolean }> = { saldos: { nome: 'Saldos de estoque', periodo: false }, movimentacoes: { nome: 'Movimentações', periodo: true }, acautelamentos: { nome: 'Acautelamentos', periodo: false } }

type Classe = 'armas' | 'municao' | 'demais'
const AGRUPAR = { dia: 'Por dia', semana: 'Por semana', mes: 'Por mês' } as const

/**
 * Relatório de entregas e devoluções (decisão do responsável, 2026-11-18): o que o armeiro precisa — quantas ARMAS (perfil
 * ARMA_FOGO), munições e demais materiais saíram e voltaram na base, por dia, semana ou mês. Gráfico + tabela + CSV/XLSX.
 */
export function RelatorioEntregas() {
  const [f, setF] = useState<{ de: string; ate: string; agrupar: keyof typeof AGRUPAR }>({ de: '', ate: '', agrupar: 'dia' })
  const [classe, setClasse] = useState<Classe>('armas')
  const query = Object.fromEntries(Object.entries(f).filter(([, v]) => v)) as Record<string, string>
  const r = useRecurso(() => api<RelatorioEntregasT>('/reports/deliveries-returns', { query }), [f.de, f.ate, f.agrupar])
  const exportar = (formato: 'csv' | 'xlsx') => `${import.meta.env.BASE_URL}api/v1/reports/deliveries-returns/export?${new URLSearchParams({ ...query, formato }).toString()}`
  const d = r.dados
  return (
    <section className="bloco" aria-labelledby="rel-entregas">
      <h2 id="rel-entregas">Entregas e devoluções na base</h2>
      <form className="relatorio-controles" aria-label="Período e agrupamento" onSubmit={(e) => e.preventDefault()}>
        <label>Agrupar<select value={f.agrupar} onChange={(e) => setF({ ...f, agrupar: e.target.value as keyof typeof AGRUPAR })}>
          {Object.entries(AGRUPAR).map(([k, v]) => <option key={k} value={k}>{v}</option>)}</select></label>
        <label>De<input type="date" value={f.de} onChange={(e) => setF({ ...f, de: e.target.value })} /></label>
        <label>Até<input type="date" value={f.ate} onChange={(e) => setF({ ...f, ate: e.target.value })} /></label>
        <label>Material<select value={classe} onChange={(e) => setClasse(e.target.value as Classe)}>
          <option value="armas">Armas de fogo</option><option value="municao">Munição</option><option value="demais">Demais materiais</option></select></label>
      </form>
      {r.carregando && <Carregando />}
      {r.erro && <Erro erro={r.erro} tentar={r.recarregar} />}
      {d && !r.carregando && <>
        <p className="dica">Período: {data(d.de)} a {data(d.ate)}. Item individual conta 1; lote conta a quantidade. Inclui acautelamento e desacautelamento.</p>
        <ul className="relatorio-totais" aria-label="Totais do período">
          {(Object.keys(d.classes) as Classe[]).map((k) => (
            <li key={k}><span>{d.classes[k]}</span><strong>{numero(d.totais[k].entregues)} <small>entregues</small></strong><span>{numero(d.totais[k].devolvidas)} devolvidas</span></li>))}
        </ul>
        <GraficoSeries titulo={`${d.classes[classe]} — entregues e devolvidas (${AGRUPAR[d.agrupar].toLowerCase()})`} periodos={d.series.map((s) => s.rotulo)}
          series={[{ nome: 'Entregues', valores: d.series.map((s) => s[classe].entregues) }, { nome: 'Devolvidas', valores: d.series.map((s) => s[classe].devolvidas) }]} />
        <div className="acoes-linha">
          <a className="btn btn-sec" href={exportar('xlsx')} download><Download size={18} aria-hidden="true" />Baixar Excel (XLSX)</a>
          <a className="btn btn-sec" href={exportar('csv')} download><Download size={18} aria-hidden="true" />Baixar CSV</a>
        </div>
      </>}
    </section>
  )
}

export function RelatoriosPage() {
  const { pode } = useAuth()
  const [p, setP] = useState({ de: '', ate: '' })
  const url = (k: string) => `${import.meta.env.BASE_URL}api/v1/reports/${k}${CHAVES[k].periodo ? '?' + new URLSearchParams(Object.entries(p).filter(([, v]) => v)).toString() : ''}`
  return (
    <Pagina icone={Download} titulo="Relatórios" trilha={[{ rotulo: 'Início', para: '/' }, { rotulo: 'Relatórios' }]}
      descricao="Gráficos e exportações (CSV e Excel) das consultas que você já pode ver. Para PDF, use a impressão da própria tela.">
      <Aviso tipo="info">Cada exportação é registrada na trilha de auditoria.</Aviso>
      {pode('relatorios.entregas') && <RelatorioEntregas />}
      {!pode('relatorios.exportar') ? (!pode('relatorios.entregas') && <Vazio titulo="Você não tem permissão para exportar." />) : <>
        <form className="filtros" aria-label="Período das movimentações" onSubmit={(e) => e.preventDefault()}>
          <label>De<input type="date" value={p.de} onChange={(e) => setP({ ...p, de: e.target.value })} /></label>
          <label>Até<input type="date" value={p.ate} onChange={(e) => setP({ ...p, ate: e.target.value })} /></label>
        </form>
        <ul className="grade-ops">{Object.entries(CHAVES).map(([k, c]) => (
          <li key={k} className="op"><h2>{c.nome}</h2><p>{c.periodo ? 'Usa o período acima, se informado.' : 'Situação atual.'}</p>
            <a className="btn btn-sec" href={url(k)} download><Download size={18} aria-hidden="true" />Baixar CSV<span className="sr-only"> de {c.nome}</span></a></li>
        ))}</ul>
      </>}
    </Pagina>
  )
}
