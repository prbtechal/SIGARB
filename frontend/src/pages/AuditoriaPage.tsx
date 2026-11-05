import { useState } from 'react'
import { api, type Pagina as Pag } from '../api/client'
import type { Evento } from '../api/tipos'
import { Aviso, Carregando, Erro, Pagina, Paginacao, Selo, Tabela, Vazio, dataHora } from '../components/ui'
import { useRecurso } from '../components/useRecurso'

export default function AuditoriaPage() {
  const [f, setF] = useState({ acao: '', resultado: '', de: '', ate: '', page: 1 })
  const [acao, setAcao] = useState('')
  const { dados, erro, carregando, recarregar } = useRecurso(() => api<Pag<Evento>>('/audit-events', { query: f }), [f.acao, f.resultado, f.de, f.ate, f.page])
  const integ = useRecurso(() => api<{ integra: boolean; total: number; primeiro_evento_invalido: number | null }>('/audit-events/integrity').catch(() => null), [])
  return (
    <Pagina titulo="Auditoria" trilha={[{ rotulo: 'Início', para: '/' }, { rotulo: 'Auditoria' }]}
      descricao="Trilha append-only encadeada por hash. Somente leitura. Esta consulta também é auditada.">
      {integ.dados && (integ.dados.integra
        ? <Aviso tipo="ok" titulo="Integridade verificada:">{integ.dados.total} eventos, cadeia de hash íntegra.</Aviso>
        : <Aviso tipo="perigo" titulo="Cadeia de auditoria inconsistente">a partir do evento #{integ.dados.primeiro_evento_invalido}. Acione a equipe de segurança.</Aviso>)}
      <Aviso tipo="info">Você vê eventos das unidades do seu escopo; eventos sem unidade (ex.: autenticação) aparecem apenas para escopo global.</Aviso>
      <form className="filtros" role="search" onSubmit={(e) => { e.preventDefault(); setF({ ...f, acao, page: 1 }) }}>
        <label>Ação (prefixo)<input value={acao} onChange={(e) => setAcao(e.target.value)} placeholder="ex.: estoque." /></label>
        <label>Resultado<select value={f.resultado} onChange={(e) => setF({ ...f, resultado: e.target.value, page: 1 })}><option value="">Todos</option><option>SUCESSO</option><option>NEGADO</option><option>FALHA</option></select></label>
        <label>De<input type="date" value={f.de} onChange={(e) => setF({ ...f, de: e.target.value, page: 1 })} /></label>
        <label>Até<input type="date" value={f.ate} onChange={(e) => setF({ ...f, ate: e.target.value, page: 1 })} /></label>
        <button className="btn btn-sec">Filtrar</button>
      </form>
      {carregando && <Carregando />}{erro && <Erro erro={erro} tentar={recarregar} />}
      {dados && !carregando && (dados.data.length === 0 ? <Vazio titulo="Nenhum evento encontrado." /> : <>
        <Tabela legenda="Eventos de auditoria" linhas={dados.data} chave={(e) => e.id} colunas={[
          { titulo: 'Data/hora', celula: (e) => <time dateTime={e.ocorrido_em}>{dataHora(e.ocorrido_em)}</time> },
          { titulo: 'Ação', celula: (e) => <span className="mono">{e.acao}</span> },
          { titulo: 'Resultado', celula: (e) => <Selo valor={e.resultado} /> },
          { titulo: 'Ator', celula: (e) => e.usuario?.login ?? '—' },
          { titulo: 'Recurso', celula: (e) => (e.recurso_tipo ? `${e.recurso_tipo} #${e.recurso_id}` : '—') },
          { titulo: 'Detalhes', celula: (e) => e.dados ? <details><summary>Ver</summary><pre className="pre">{JSON.stringify(e.dados, null, 2)}</pre></details> : '—' },
          { titulo: 'Hash', celula: (e) => <span className="mono" title="Prefixo do hash do evento">{e.hash}</span> },
        ]} />
        <Paginacao meta={dados.meta} ir={(p) => setF({ ...f, page: p })} />
      </>)}
    </Pagina>
  )
}
