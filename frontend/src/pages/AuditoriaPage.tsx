import { useState } from 'react'
import { Eye, Filter, ShieldAlert, ShieldCheck, UserSearch, X } from 'lucide-react'
import { api, type Pagina as Pag } from '../api/client'
import type { Evento } from '../api/tipos'
import { Aviso, Carregando, Dialogo, Erro, Pagina, Paginacao, Selo, Tabela, Vazio, dataHora } from '../components/ui'
import { useRecurso } from '../components/useRecurso'

// Categorias = prefixo da ação (o servidor filtra por "acao LIKE prefixo%").
const CATEGORIAS: Record<string, string> = {
  'autenticacao.': 'Autenticação', 'acesso.': 'Acesso e permissões', 'estoque.': 'Estoque', 'documento': 'Documentos',
  'organizacao.': 'Organização', 'militar': 'Efetivos', 'catalogo.': 'Catálogo', 'referencia': 'Referências',
  'usuario': 'Usuários', 'suporte.': 'Central de suporte', 'auditoria.': 'Consultas à auditoria',
}
const VAZIO = { acao: '', resultado: '', de: '', ate: '', recurso_tipo: '', request_id: '', usuario_id: '' }

export default function AuditoriaPage() {
  const [form, setForm] = useState(VAZIO)
  const [f, setF] = useState({ ...VAZIO, page: 1 })
  const [ator, setAtor] = useState<string | null>(null)
  const [aberto, setAberto] = useState<Evento | null>(null)
  const { dados, erro, carregando, recarregar } = useRecurso(() => api<Pag<Evento>>('/audit-events', { query: f }), [JSON.stringify(f)])
  // Verificação completa da cadeia: disponível só para escopo global (o servidor responde 403 aos demais).
  const integ = useRecurso(() => api<{ integra: boolean; total: number; primeiro_evento_invalido: number | null }>('/audit-events/integrity').catch(() => null), [])
  const aplicar = (novo: typeof form) => { setForm(novo); setF({ ...novo, page: 1 }) }
  const filtrosAtivos = Object.entries(f).filter(([k, v]) => k !== 'page' && v !== '').length

  return (
    <Pagina titulo="Auditoria" icone={ShieldCheck} trilha={[{ rotulo: 'Início', para: '/' }, { rotulo: 'Auditoria' }]}
      descricao="Trilha append-only encadeada por hash. Somente leitura. Esta consulta também é auditada.">
      {integ.dados && (integ.dados.integra
        ? <Aviso tipo="ok" titulo="Integridade verificada:">{integ.dados.total} eventos, cadeia de hash íntegra.</Aviso>
        : <Aviso tipo="perigo" titulo="Cadeia de auditoria inconsistente">a partir do evento #{integ.dados.primeiro_evento_invalido}. Acione a equipe de segurança.</Aviso>)}
      {!integ.carregando && !integ.dados && <Aviso tipo="info">A verificação completa da cadeia é restrita a quem tem escopo global de auditoria.</Aviso>}
      <Aviso tipo="info">Você vê eventos das unidades do seu escopo; eventos sem unidade (ex.: autenticação) aparecem apenas para escopo global.</Aviso>

      <form className="filtros" role="search" aria-label="Filtrar eventos" onSubmit={(e) => { e.preventDefault(); setF({ ...form, page: 1 }) }}>
        <label>Categoria
          <select value={Object.keys(CATEGORIAS).includes(form.acao) ? form.acao : ''} onChange={(e) => setForm({ ...form, acao: e.target.value })}>
            <option value="">Todas</option>{Object.entries(CATEGORIAS).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
          </select>
        </label>
        <label>Ação (prefixo)<input value={form.acao} onChange={(e) => setForm({ ...form, acao: e.target.value })} placeholder="ex.: estoque.entrada" /></label>
        <label>Resultado<select value={form.resultado} onChange={(e) => setForm({ ...form, resultado: e.target.value })}><option value="">Todos</option><option value="SUCESSO">Sucesso</option><option value="NEGADO">Negado</option><option value="FALHA">Falha</option></select></label>
        <label>Tipo de recurso<input value={form.recurso_tipo} onChange={(e) => setForm({ ...form, recurso_tipo: e.target.value })} placeholder="ex.: chamado" /></label>
        <label>Request ID<input value={form.request_id} onChange={(e) => setForm({ ...form, request_id: e.target.value.trim() })} placeholder="correlação de requisição" className="mono" /></label>
        <label>De<input type="date" value={form.de} onChange={(e) => setForm({ ...form, de: e.target.value })} /></label>
        <label>Até<input type="date" value={form.ate} onChange={(e) => setForm({ ...form, ate: e.target.value })} /></label>
        <button className="btn btn-pri"><Filter size={18} aria-hidden="true" />Filtrar</button>
        {filtrosAtivos > 0 && <button type="button" className="btn btn-sec" onClick={() => { setAtor(null); aplicar(VAZIO) }}><X size={18} aria-hidden="true" />Limpar ({filtrosAtivos})</button>}
      </form>
      {ator && <Aviso tipo="info" titulo="Filtrando por ator:">{ator}. <button type="button" className="btn btn-sec" onClick={() => { setAtor(null); aplicar({ ...form, usuario_id: '' }) }}>Remover filtro de ator</button></Aviso>}

      {carregando && <Carregando />}{erro && <Erro erro={erro} tentar={recarregar} />}
      {dados && !carregando && (dados.data.length === 0 ? <Vazio titulo="Nenhum evento encontrado." icone={ShieldAlert}><p>Ajuste os filtros ou o período.</p></Vazio> : <>
        <Tabela legenda="Eventos de auditoria" linhas={dados.data} chave={(e) => e.id} colunas={[
          { titulo: 'Data/hora', celula: (e) => <time dateTime={e.ocorrido_em}>{dataHora(e.ocorrido_em)}</time> },
          { titulo: 'Ação', celula: (e) => <span className="mono">{e.acao}</span> },
          { titulo: 'Resultado', celula: (e) => <Selo valor={e.resultado} /> },
          { titulo: 'Ator', celula: (e) => e.usuario ? (
            <button type="button" className="btn btn-sec" title={`Filtrar eventos de ${e.usuario.login}`}
              onClick={() => { setAtor(`${e.usuario!.nome} (${e.usuario!.login})`); aplicar({ ...form, usuario_id: String(e.usuario!.id) }) }}>
              <UserSearch size={16} aria-hidden="true" />{e.usuario.login}
            </button>) : '—' },
          { titulo: 'Recurso', celula: (e) => (e.recurso_tipo ? `${e.recurso_tipo} #${e.recurso_id}` : '—') },
          { titulo: 'Detalhes', celula: (e) => <button type="button" className="btn btn-sec" onClick={() => setAberto(e)}><Eye size={16} aria-hidden="true" />Ver<span className="sr-only"> evento #{e.id}</span></button> },
        ]} />
        <Paginacao meta={dados.meta} ir={(p) => setF({ ...f, page: p })} />
      </>)}

      <Dialogo aberto={!!aberto} titulo={aberto ? `Evento #${aberto.id}` : ''} onFechar={() => setAberto(null)}
        rodape={<>
          {aberto?.request_id && <button className="btn btn-sec" onClick={() => { aplicar({ ...VAZIO, request_id: aberto.request_id! }); setAberto(null) }}>Ver eventos da mesma requisição</button>}
          <button className="btn btn-pri" onClick={() => setAberto(null)}>Fechar</button>
        </>}>
        {aberto && (
          <>
            <dl className="detalhes">
              <div><dt>Data/hora</dt><dd>{dataHora(aberto.ocorrido_em)}</dd></div>
              <div><dt>Ação</dt><dd className="mono">{aberto.acao}</dd></div>
              <div><dt>Resultado</dt><dd><Selo valor={aberto.resultado} /></dd></div>
              <div><dt>Ator</dt><dd>{aberto.usuario ? `${aberto.usuario.nome} (${aberto.usuario.login})` : '—'}</dd></div>
              <div><dt>Recurso</dt><dd>{aberto.recurso_tipo ? `${aberto.recurso_tipo} #${aberto.recurso_id}` : '—'}</dd></div>
              <div><dt>IP</dt><dd className="mono">{aberto.ip ?? '—'}</dd></div>
              <div><dt>Request ID</dt><dd className="mono quebra">{aberto.request_id ?? '—'}</dd></div>
              <div><dt>Hash (prefixo)</dt><dd className="mono">{aberto.hash}</dd></div>
            </dl>
            <h3>Metadados registrados</h3>
            {aberto.dados ? <pre className="pre quebra">{JSON.stringify(aberto.dados, null, 2)}</pre> : <p className="dica">Sem metadados.</p>}
          </>
        )}
      </Dialogo>
    </Pagina>
  )
}
