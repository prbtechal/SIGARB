import { useState } from 'react'
import { Link } from 'react-router'
import { BellRing, Download } from 'lucide-react'
import { api } from '../api/client'
import { useAuth } from '../auth/AuthContext'
import { Aviso, Carregando, Erro, Pagina, Selo, Vazio, dataHora } from '../components/ui'
import { useRecurso } from '../components/useRecurso'

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
  return (
    <Pagina icone={BellRing} titulo="Alertas" trilha={[{ rotulo: 'Início', para: '/' }, { rotulo: 'Alertas' }]}
      descricao="Situações que pedem atenção dentro do seu escopo, calculadas no momento da consulta.">
      {carregando && <Carregando />}{erro && <Erro erro={erro} tentar={recarregar} />}
      {dados && <><ListaAlertas alertas={dados.data} /><p className="dica">Gerado em {dataHora(dados.gerado_em)}.</p></>}
    </Pagina>
  )
}

const CHAVES: Record<string, { nome: string; periodo: boolean }> = { saldos: { nome: 'Saldos de estoque', periodo: false }, movimentacoes: { nome: 'Movimentações', periodo: true }, acautelamentos: { nome: 'Acautelamentos', periodo: false } }

export function RelatoriosPage() {
  const { pode } = useAuth()
  const [p, setP] = useState({ de: '', ate: '' })
  const url = (k: string) => `${import.meta.env.BASE_URL}api/v1/reports/${k}${CHAVES[k].periodo ? '?' + new URLSearchParams(Object.entries(p).filter(([, v]) => v)).toString() : ''}`
  return (
    <Pagina icone={Download} titulo="Relatórios" trilha={[{ rotulo: 'Início', para: '/' }, { rotulo: 'Relatórios' }]}
      descricao="Exportação em CSV (abre no Excel) das consultas que você já pode ver. Para PDF, use a impressão da própria tela.">
      <Aviso tipo="info">Cada exportação é registrada na trilha de auditoria.</Aviso>
      {!pode('relatorios.exportar') ? <Vazio titulo="Você não tem permissão para exportar." /> : <>
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
