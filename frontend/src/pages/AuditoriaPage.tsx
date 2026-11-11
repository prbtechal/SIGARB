import { useState } from 'react'
import { ChevronRight, Filter, ShieldAlert, ShieldCheck, UserSearch, X } from 'lucide-react'
import { Link } from 'react-router'
import { api, type Pagina as Pag } from '../api/client'
import type { Evento } from '../api/tipos'
import { Aviso, Carregando, Dialogo, Erro, Pagina, Paginacao, Vazio } from '../components/ui'
import { useRecurso } from '../components/useRecurso'

// Categorias = prefixo da ação (o servidor filtra por "acao LIKE prefixo%").
const CATEGORIAS: Record<string, string> = {
  'auth.': 'Autenticação', 'acesso.': 'Acesso e permissões', 'estoque.': 'Estoque', 'documento': 'Documentos',
  'organizacao.': 'Organização', 'militar': 'Efetivos', 'material.': 'Catálogo', 'referencia': 'Referências',
  'usuario': 'Usuários', 'suporte.': 'Central de suporte', 'auditoria.': 'Consultas à auditoria',
}
const VAZIO = { acao: '', resultado: '', de: '', ate: '', recurso_tipo: '', request_id: '', usuario_id: '' }
const FUSO = 'America/Maceio'

const RESULTADOS = {
  SUCESSO: { rotulo: 'Sucesso', tom: 'ok', legenda: 'a ação foi concluída' },
  NEGADO: { rotulo: 'Negado', tom: 'perigo', legenda: 'bloqueado por permissão ou regra' },
  FALHA: { rotulo: 'Falha', tom: 'alerta', legenda: 'não concluída por erro ou dado inválido' },
} as const

const CAMPOS: Record<string, string> = {
  justificativa: 'Justificativa', motivo: 'Motivo', observacao: 'Observação', papel: 'Papel', papel_nome: 'Papel', unidade_id: 'Unidade (nº)',
  expira_em: 'Válido até', quantidade: 'Quantidade', login: 'CPF/login', nome: 'Nome', email: 'E-mail', ativo: 'Ativo', codigo: 'Código',
  tipo: 'Tipo', estado_conservacao: 'Conservação', situacao: 'Situação', local_destino_id: 'Local de destino (nº)', local_origem_id: 'Local de origem (nº)',
  documento_referencia: 'Documento de referência', categoria_id: 'Categoria (nº)', modelo: 'Modelo', ref: 'Sessão', senha_redefinida: 'Senha redefinida',
  linhas: 'Linhas', divergencias: 'Divergências', provisoria: 'Regra provisória', chave: 'Chave', valor: 'Valor', operacao: 'Operação', acao: 'Ação',
  resultado: 'Resultado', de: 'De', ate: 'Até', usuario_id: 'Pessoa (nº)', recurso_tipo: 'Tipo de recurso', request_id: 'Requisição',
  permissao: 'Permissão', concedida: 'Concedida', perfil: 'Perfil', titulo: 'Título', protocolo: 'Protocolo', status: 'Status',
}
const rotuloCampo = (k: string) => CAMPOS[k] ?? (k.charAt(0).toUpperCase() + k.slice(1)).replace(/[_-]+/g, ' ')
const valor = (v: unknown): string => v === null || v === undefined || v === '' ? '—' : typeof v === 'boolean' ? (v ? 'Sim' : 'Não') : typeof v === 'object' ? JSON.stringify(v) : String(v)
const mascararCpf = (login: string) => (/^\d{11}$/.test(login) ? `***.***.***-${login.slice(-2)}` : login)
const absoluta = (iso: string) => new Date(iso).toLocaleString('pt-BR', { dateStyle: 'short', timeStyle: 'medium', timeZone: FUSO })
function relativa(iso: string, agora = Date.now()) {
  const s = Math.round((new Date(iso).getTime() - agora) / 1000)
  const rtf = new Intl.RelativeTimeFormat('pt-BR', { numeric: 'auto' })
  for (const [u, n] of [['day', 86400], ['hour', 3600], ['minute', 60]] as const) if (Math.abs(s) >= n) return rtf.format(Math.round(s / n), u)
  return 'agora há pouco'
}

function SeloResultado({ r }: { r: Evento['resultado'] }) {
  const d = RESULTADOS[r]
  return <span className={`selo selo-${d?.tom ?? 'neutro'}`}>{d?.rotulo ?? r}</span>
}

/** "Dados": campos com rótulo em português; quando há antes/depois, mostra a mudança campo a campo. */
function Dados({ dados }: { dados: Record<string, unknown> | null }) {
  if (!dados || Object.keys(dados).length === 0) return <p className="dica">Nenhum dado adicional registrado.</p>
  const { antes, depois, ...resto } = dados as { antes?: unknown; depois?: unknown } & Record<string, unknown>
  const obj = (v: unknown) => (v && typeof v === 'object' && !Array.isArray(v) ? v as Record<string, unknown> : null)
  const a = obj(antes), d = obj(depois)
  const mudanca = a || d ? [...new Set([...Object.keys(a ?? {}), ...Object.keys(d ?? {})])] : []
  if (!a && !d) { if (antes !== undefined) resto.antes = antes; if (depois !== undefined) resto.depois = depois }
  return <>
    {mudanca.length > 0 && <table className="tabela aud-mudanca"><caption className="sr-only">Alterações</caption>
      <thead><tr><th scope="col">Campo</th><th scope="col">Antes</th><th scope="col">Depois</th></tr></thead>
      <tbody>{mudanca.map((k) => <tr key={k}><th scope="row">{rotuloCampo(k)}</th><td>{valor(a?.[k])}</td><td><strong>{valor(d?.[k])}</strong></td></tr>)}</tbody>
    </table>}
    {Object.keys(resto).length > 0 && <dl className="detalhes">{Object.entries(resto).map(([k, v]) => <div key={k}><dt>{rotuloCampo(k)}</dt><dd className="quebra">{valor(v)}</dd></div>)}</dl>}
  </>
}

export default function AuditoriaPage() {
  const [form, setForm] = useState(VAZIO)
  const [f, setF] = useState({ ...VAZIO, page: 1 })
  const [ator, setAtor] = useState<string | null>(null)
  const [aberto, setAberto] = useState<Evento | null>(null)
  const { dados, erro, carregando, recarregar } = useRecurso(() => api<Pag<Evento>>('/audit-events', { query: f }), [JSON.stringify(f)])
  const acoes = useRecurso(() => api<{ data: { codigo: string; rotulo: string }[] }>('/audit-events/actions').catch(() => null), [])
  // Verificação completa da cadeia: disponível só para escopo global (o servidor responde 403 aos demais).
  const integ = useRecurso(() => api<{ integra: boolean; total: number; primeiro_evento_invalido: number | null }>('/audit-events/integrity').catch(() => null), [])
  const aplicar = (novo: typeof form) => { setForm(novo); setF({ ...novo, page: 1 }) }
  const filtrosAtivos = Object.entries(f).filter(([k, v]) => k !== 'page' && v !== '').length
  const porPessoa = (e: Evento) => { setAtor(e.usuario!.nome); aplicar({ ...form, usuario_id: String(e.usuario!.id) }); setAberto(null) }

  return (
    <Pagina titulo="Auditoria" icone={ShieldCheck} trilha={[{ rotulo: 'Início', para: '/' }, { rotulo: 'Auditoria' }]}
      descricao="Quem fez o quê, quando e com qual resultado. Registro permanente, somente leitura; esta consulta também é registrada.">
      {integ.dados && (integ.dados.integra
        ? <Aviso tipo="ok" titulo="Integridade verificada:">{integ.dados.total} eventos, cadeia de hash íntegra.</Aviso>
        : <Aviso tipo="perigo" titulo="Cadeia de auditoria inconsistente">a partir do evento #{integ.dados.primeiro_evento_invalido}. Acione a equipe de segurança.</Aviso>)}
      {!integ.carregando && !integ.dados && <Aviso tipo="info">A verificação completa da cadeia é restrita a quem tem escopo global de auditoria.</Aviso>}

      <form className="filtros" role="search" aria-label="Filtrar eventos" onSubmit={(e) => { e.preventDefault(); setF({ ...form, page: 1 }) }}>
        <label>Área
          <select value={Object.keys(CATEGORIAS).includes(form.acao) ? form.acao : ''} onChange={(e) => setForm({ ...form, acao: e.target.value })}>
            <option value="">Todas</option>{Object.entries(CATEGORIAS).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
          </select>
        </label>
        <label>Ação
          <select value={acoes.dados?.data.some((a) => a.codigo === form.acao) ? form.acao : ''} onChange={(e) => setForm({ ...form, acao: e.target.value })}>
            <option value="">Todas</option>{acoes.dados?.data.map((a) => <option key={a.codigo} value={a.codigo}>{a.rotulo}</option>)}
          </select>
        </label>
        <label>Resultado<select value={form.resultado} onChange={(e) => setForm({ ...form, resultado: e.target.value })}><option value="">Todos</option>
          {Object.entries(RESULTADOS).map(([k, v]) => <option key={k} value={k}>{v.rotulo}</option>)}</select></label>
        <label>De<input type="date" value={form.de} onChange={(e) => setForm({ ...form, de: e.target.value })} /></label>
        <label>Até<input type="date" value={form.ate} onChange={(e) => setForm({ ...form, ate: e.target.value })} /></label>
        <details className="aud-mais">
          <summary>Filtros técnicos</summary>
          <label>Código da ação (prefixo)<input value={form.acao} onChange={(e) => setForm({ ...form, acao: e.target.value })} placeholder="ex.: estoque.entrada" className="mono" /></label>
          <label>Tipo de recurso<input value={form.recurso_tipo} onChange={(e) => setForm({ ...form, recurso_tipo: e.target.value })} placeholder="ex.: chamado" /></label>
          <label>Requisição<input value={form.request_id} onChange={(e) => setForm({ ...form, request_id: e.target.value.trim() })} placeholder="identificador da requisição" className="mono" /></label>
        </details>
        <button className="btn btn-pri"><Filter size={18} aria-hidden="true" />Filtrar</button>
        {filtrosAtivos > 0 && <button type="button" className="btn btn-sec" onClick={() => { setAtor(null); aplicar(VAZIO) }}><X size={18} aria-hidden="true" />Limpar ({filtrosAtivos})</button>}
      </form>
      {ator && <Aviso tipo="info" titulo="Pessoa:">{ator}. <button type="button" className="btn btn-sec" onClick={() => { setAtor(null); aplicar({ ...form, usuario_id: '' }) }}>Ver todas as pessoas</button></Aviso>}

      <p className="aud-legenda" aria-label="Legenda dos resultados">
        {Object.entries(RESULTADOS).map(([k, v]) => <span key={k}><SeloResultado r={k as Evento['resultado']} /> {v.legenda}</span>)}
      </p>
      <p className="dica">Você vê eventos das unidades do seu escopo; eventos sem unidade (ex.: entrada no sistema) aparecem apenas para escopo global.</p>

      {carregando && <Carregando />}{erro && <Erro erro={erro} tentar={recarregar} />}
      {dados && !carregando && (dados.data.length === 0 ? <Vazio titulo="Nenhum evento encontrado." icone={ShieldAlert}><p>Ajuste os filtros ou o período.</p></Vazio> : <>
        <ul className="aud-lista" aria-label="Eventos de auditoria">
          {dados.data.map((e) => (
            <li key={e.id}>
              <button type="button" className="aud-linha" onClick={() => setAberto(e)} aria-haspopup="dialog">
                <span className="aud-frase">{e.frase ?? `${e.usuario?.nome ?? 'Sistema'} · ${e.acao}`}</span>
                <span className="aud-meta">
                  <time dateTime={e.ocorrido_em} title={absoluta(e.ocorrido_em)}>{relativa(e.ocorrido_em)} · {absoluta(e.ocorrido_em)}</time>
                  <SeloResultado r={e.resultado} />
                </span>
                <ChevronRight size={18} aria-hidden="true" className="aud-seta" />
                <span className="sr-only">Ver detalhes do evento #{e.id}</span>
              </button>
            </li>
          ))}
        </ul>
        <Paginacao meta={dados.meta} ir={(p) => setF({ ...f, page: p })} />
      </>)}

      <Dialogo aberto={!!aberto} titulo={aberto ? (aberto.acao_rotulo ?? aberto.acao) : ''} onFechar={() => setAberto(null)}
        rodape={<>
          {aberto?.request_id && <button className="btn btn-sec" onClick={() => { aplicar({ ...VAZIO, request_id: aberto.request_id! }); setAberto(null) }}>Ver eventos da mesma requisição</button>}
          <button className="btn btn-pri" onClick={() => setAberto(null)}>Fechar</button>
        </>}>
        {aberto && (
          <div className="aud-painel">
            <p className="aud-frase">{aberto.frase} <SeloResultado r={aberto.resultado} /></p>
            <h3>Quando</h3>
            <dl className="detalhes">
              <div><dt>Data e hora</dt><dd><time dateTime={aberto.ocorrido_em}>{absoluta(aberto.ocorrido_em)}</time> <small>(horário de Maceió, {relativa(aberto.ocorrido_em)})</small></dd></div>
            </dl>
            <h3>Quem</h3>
            <dl className="detalhes">
              <div><dt>Nome</dt><dd>{aberto.usuario?.nome ?? 'Sistema'}</dd></div>
              <div><dt>CPF/login</dt><dd className="mono">{aberto.usuario ? mascararCpf(aberto.usuario.login) : '—'}</dd></div>
              <div><dt>Endereço IP</dt><dd className="mono">{aberto.ip ?? '—'}</dd></div>
              {aberto.usuario && <div><dt>Filtro</dt><dd><button type="button" className="btn btn-sec" onClick={() => porPessoa(aberto)}><UserSearch size={16} aria-hidden="true" />Ver ações desta pessoa</button></dd></div>}
            </dl>
            <h3>O quê</h3>
            <dl className="detalhes">
              <div><dt>Ação</dt><dd>{aberto.acao_rotulo ?? aberto.acao}<br /><small className="mono">{aberto.acao}</small></dd></div>
              <div><dt>Resultado</dt><dd><SeloResultado r={aberto.resultado} /></dd></div>
              <div><dt>Alvo</dt><dd>{aberto.recurso_link ? <Link to={aberto.recurso_link}>{aberto.recurso_rotulo}</Link> : (aberto.recurso_rotulo ?? '—')}</dd></div>
              <div><dt>Unidade</dt><dd>{aberto.unidade_rotulo ?? '—'}</dd></div>
            </dl>
            <h3>Dados</h3>
            <Dados dados={aberto.dados} />
            <details className="aud-tecnico">
              <summary>Dados técnicos</summary>
              <dl className="detalhes">
                <div><dt>Evento</dt><dd className="mono">#{aberto.id}</dd></div>
                <div><dt>Requisição</dt><dd className="mono quebra">{aberto.request_id ?? '—'}</dd></div>
                <div><dt>Hash (prefixo)</dt><dd className="mono">{aberto.hash}</dd></div>
                <div><dt>Recurso</dt><dd className="mono">{aberto.recurso_tipo ? `${aberto.recurso_tipo} #${aberto.recurso_id}` : '—'}</dd></div>
              </dl>
              {aberto.dados ? <pre className="pre quebra aud-json">{JSON.stringify(aberto.dados, null, 2)}</pre> : <p className="dica">Sem metadados.</p>}
            </details>
          </div>
        )}
      </Dialogo>
    </Pagina>
  )
}
