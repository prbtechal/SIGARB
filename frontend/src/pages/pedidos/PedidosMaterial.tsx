import { useCallback, useEffect, useRef, useState } from 'react'
import { Link, useParams, useSearchParams } from 'react-router'
import { Check, CheckCircle2, Clock, Inbox, PackageCheck, Search, X } from 'lucide-react'
import { ErroApi, api, novaChave, type Pagina as Pag } from '../../api/client'
import type { ItemIndividual, Local, PedidoDetalhe, PedidoItem, PedidoResumo, ResumoPedidos, Saldo, StatusPedido } from '../../api/tipos'
import { AvisoAptidao, SeloAptidao } from '../../components/AptidaoEfetivo'
import { RotuloItem } from '../../components/RotuloItem'
import { diasDesde, haQuanto, hojeMaceio } from '../../components/quando'
import { Aviso, Campo, Carregando, Dialogo, Erro, Pagina, Paginacao, Selo, Vazio, data, dataHora, numero, useNotificar } from '../../components/ui'
import { useRecurso } from '../../components/useRecurso'
import { FluxoPedido, LinhasPedido } from '../portal/PortalEfetivo'
import { rotuloEfetivo } from '../../components/efetivo'

/**
 * Pedidos de material (portal do efetivo, decisão do responsável 2026-11-18) — lado do armeiro (`pedidos.gerir`).
 * Aprovar antes (pode reduzir quantidades), recusar com motivo, e entregar na retirada conferindo a identidade.
 * Arma de fogo sai por acautelamento (com carteira); o resto, por ENTREGA. O servidor revalida tudo.
 */

const ULID = /^[0-9A-HJKMNP-TV-Z]{26}$/
/** Abas de situação (Pendentes é o padrão). "TODOS" não vai para a API. */
const ABAS: { status: StatusPedido | 'TODOS'; rotulo: string }[] = [
  { status: 'PENDENTE', rotulo: 'Pendentes' }, { status: 'APROVADO', rotulo: 'Aguardando retirada' }, { status: 'ENTREGUE', rotulo: 'Entregues' },
  { status: 'RECUSADO', rotulo: 'Recusados' }, { status: 'CANCELADO', rotulo: 'Cancelados' }, { status: 'TODOS', rotulo: 'Todos' },
]
/** Pedido pendente parado há este número de dias ou mais ganha destaque. */
export const DIAS_PARADO = 2
const ATRASO_BUSCA_MS = 350

/** Texto do estado vazio por aba (a fila zerada de pendentes é uma boa notícia). */
export function textoVazio(status: string, filtrando: boolean): string {
  if (filtrando) return 'Nenhum pedido com estes filtros.'
  return ({ PENDENTE: 'Nenhum pedido aguardando aprovação. Fila em dia.', APROVADO: 'Nenhum pedido aprovado aguardando retirada.',
    ENTREGUE: 'Nenhum pedido entregue ainda.', RECUSADO: 'Nenhum pedido recusado.', CANCELADO: 'Nenhum pedido cancelado.' } as Record<string, string>)[status]
    ?? 'Nenhum pedido feito pelos efetivos ainda.'
}

/**
 * Pedidos de material — painel do armeiro (redesenho 2026-11-18). Estrutura das referências Mobbin: abas de situação com contadores
 * (Mercury, Searchable), barra de filtros compacta em linha (fal "Request History", Canva "Designs to review"), tabela densa com selo
 * de situação e ações na linha; no celular a tabela vira cartões (padrão do sistema). Filtra sem botão (debounce) e mantém tudo na URL.
 */
export function PedidosPage() {
  const [sp, setSp] = useSearchParams()
  const notificar = useNotificar()
  const status = (sp.get('status') ?? 'PENDENTE') as StatusPedido | 'TODOS'
  const filtro = { q: sp.get('q') ?? '', de: sp.get('de') ?? '', ate: sp.get('ate') ?? '' }
  const pagina = Number(sp.get('page') ?? 1)
  const [termo, setTermo] = useState(filtro.q)
  const filtrando = !!(filtro.q || filtro.de || filtro.ate)
  const consulta = Object.fromEntries(Object.entries(filtro).filter(([, v]) => v))
  const r = useRecurso(() => api<Pag<PedidoResumo>>('/material-requests', { query: { page: pagina, per_page: 15, ...consulta,
    ...(status !== 'TODOS' ? { status } : {}), sort: status === 'PENDENTE' || status === 'APROVADO' ? 'id' : '-id' } }), [sp.toString()])
  const resumo = useRecurso(() => api<ResumoPedidos>('/material-requests/summary', { query: consulta }), [filtro.q, filtro.de, filtro.ate])
  const [acao, setAcao] = useState<{ tipo: 'aprovar' | 'recusar'; p: PedidoResumo } | null>(null)

  const mudar = useCallback((mud: Record<string, string>) => {
    setSp((atual) => {
      const n = new URLSearchParams(atual)
      for (const [k, v] of Object.entries(mud)) { if (v) n.set(k, v); else n.delete(k) }
      n.delete('page')
      return n
    }, { replace: true })
  }, [setSp])
  // Busca sem botão: aplica 350 ms depois da última tecla (Enter aplica na hora).
  useEffect(() => {
    if (termo.trim() === filtro.q) return
    const t = setTimeout(() => mudar({ q: termo.trim() }), ATRASO_BUSCA_MS)
    return () => clearTimeout(t)
  }, [termo, filtro.q, mudar])
  const total = (s: StatusPedido | 'TODOS') => !resumo.dados ? null : s === 'TODOS'
    ? Object.values(resumo.dados.por_status).reduce((a, b) => a + b, 0) : resumo.dados.por_status[s] ?? 0
  const recarregar = () => { r.recarregar(); resumo.recarregar() }

  return (
    <Pagina icone={Inbox} titulo="Pedidos de material" trilha={[{ rotulo: 'Início', para: '/' }, { rotulo: 'Pedidos de material' }]}
      descricao="Pedidos feitos pelos efetivos no portal. Aprove antes; na retirada, confira a identidade e entregue.">
      <div className="abas abas-contador" role="group" aria-label="Situação do pedido">
        {ABAS.map((a) => {
          const n = total(a.status)
          return (
            <button key={a.status} type="button" className={`aba${status === a.status ? ' ativa' : ''}`} aria-pressed={status === a.status}
              onClick={() => mudar({ status: a.status === 'PENDENTE' ? '' : a.status })}>
              {a.rotulo}{n !== null && <span className="aba-contador">{n}<span className="sr-only"> pedido(s)</span></span>}
            </button>)
        })}
      </div>

      <form className="barra-filtros" role="search" onSubmit={(e) => { e.preventDefault(); mudar({ q: termo.trim() }) }}>
        <label className="barra-busca">
          <span className="sr-only">Buscar por efetivo ou código do pedido</span>
          <Search size={18} aria-hidden="true" />
          <input type="search" value={termo} onChange={(e) => setTermo(e.target.value)} placeholder="Efetivo, matrícula ou PED-…" autoComplete="off" />
        </label>
        <label className="barra-campo">De<input type="date" value={filtro.de} max={filtro.ate || undefined} onChange={(e) => mudar({ de: e.target.value })} /></label>
        <label className="barra-campo">Até<input type="date" value={filtro.ate} min={filtro.de || undefined} onChange={(e) => mudar({ ate: e.target.value })} /></label>
        {filtrando && <button type="button" className="btn btn-sec btn-peq" onClick={() => { setTermo(''); mudar({ q: '', de: '', ate: '' }) }}><X size={16} aria-hidden="true" />Limpar filtros</button>}
      </form>

      <p className="sr-only" role="status" aria-live="polite">{r.dados && !r.carregando ? `${r.dados.meta.total} pedido(s) encontrado(s).` : ''}</p>
      {r.carregando && !r.dados ? <Carregando rotulo="Carregando pedidos…" /> : r.erro ? <Erro erro={r.erro} tentar={recarregar} /> : r.dados && (r.dados.data.length === 0
        ? <VazioPedidos status={status} filtrando={filtrando} limpar={() => { setTermo(''); mudar({ q: '', de: '', ate: '' }) }} />
        : <div className={r.carregando ? 'atualizando' : undefined} aria-busy={r.carregando}>
          {(status === 'PENDENTE' || status === 'APROVADO') && r.dados.meta.total > 1 && <p className="dica lista-ordem">Mais antigos primeiro.</p>}
          <div className="tabela-wrap"><table className="tabela tabela-pedidos">
            <caption className="sr-only">Pedidos de material — {ABAS.find((a) => a.status === status)?.rotulo}</caption>
            <thead><tr><th scope="col">Pedido</th><th scope="col">Efetivo</th><th scope="col">Material</th><th scope="col">Feito em</th><th scope="col">Retirada prevista</th><th scope="col">Situação</th><th scope="col"><span className="sr-only">Ações</span></th></tr></thead>
            <tbody>{r.dados.data.map((p) => {
              const parado = p.status === 'PENDENTE' && diasDesde(p.criado_em) >= DIAS_PARADO
              return (
                <tr key={p.codigo} className={parado ? 'linha-parada' : undefined}>
                  <td data-rotulo="Pedido"><Link className="mono codigo-pedido" to={`/pedidos/${p.codigo}`}>{p.rotulo}</Link></td>
                  <td data-rotulo="Efetivo"><div><strong>{rotuloEfetivo(p.efetivo)}</strong>{p.efetivo.nome && <small className="sub">{p.efetivo.nome}</small>}<span className="sub"><SeloAptidao m={p.efetivo} /></span></div></td>
                  <td data-rotulo="Material"><div><span className="resumo-itens">{p.itens_resumo}</span>{p.tem_letal && <span className="sub"><span className="selo selo-alerta">Letal</span></span>}</div></td>
                  <td data-rotulo="Feito em"><div><time dateTime={p.criado_em}>{dataHora(p.criado_em)}</time>
                    {parado ? <span className="sub"><span className="selo selo-alerta"><Clock size={14} aria-hidden="true" />Parado {haQuanto(p.criado_em)}</span></span> : <small className="sub">{haQuanto(p.criado_em)}</small>}</div></td>
                  <td data-rotulo="Retirada prevista"><div>{p.retirada_prevista ? data(p.retirada_prevista) : <span className="dica">Sem data</span>}{p.local_retirada && <small className="sub">{p.local_retirada.codigo}</small>}</div></td>
                  <td data-rotulo="Situação"><Selo valor={p.status} /></td>
                  <td data-rotulo="Ações"><div className="acoes-linha">
                    {p.acoes?.decidir && <>
                      <button type="button" className="btn btn-pri btn-peq" onClick={() => setAcao({ tipo: 'aprovar', p })} aria-label={`Aprovar o pedido ${p.rotulo}`}><Check size={16} aria-hidden="true" />Aprovar</button>
                      <button type="button" className="btn btn-sec-perigo btn-peq" onClick={() => setAcao({ tipo: 'recusar', p })} aria-label={`Recusar o pedido ${p.rotulo}`}>Recusar</button>
                    </>}
                    {p.acoes?.entregar && <Link className="btn btn-pri btn-peq" to={`/pedidos/${p.codigo}?entregar=1`} aria-label={`Entregar o pedido ${p.rotulo}`}><PackageCheck size={16} aria-hidden="true" />Entregar</Link>}
                    {!p.acoes?.decidir && !p.acoes?.entregar && <Link className="btn btn-sec btn-peq" to={`/pedidos/${p.codigo}`} aria-label={`Ver o pedido ${p.rotulo}`}>Ver</Link>}
                  </div></td>
                </tr>)
            })}</tbody>
          </table></div>
          <Paginacao meta={r.dados.meta} ir={(n) => setSp((a) => { const x = new URLSearchParams(a); x.set('page', String(n)); return x })} />
        </div>)}

      {acao && <DecisaoRapida tipo={acao.tipo} p={acao.p} onFechar={() => setAcao(null)}
        onConcluir={(msg) => { setAcao(null); notificar('ok', msg); recarregar() }} />}
    </Pagina>
  )
}

function VazioPedidos({ status, filtrando, limpar }: { status: string; filtrando: boolean; limpar: () => void }) {
  const positivo = status === 'PENDENTE' && !filtrando
  return (
    <Vazio titulo={textoVazio(status, filtrando)} icone={positivo ? CheckCircle2 : Inbox}>
      {filtrando ? <button type="button" className="btn btn-sec" onClick={limpar}>Limpar filtros</button> : <>
        <p className="dica">Como funciona o pedido prévio:</p>
        <ol className="passos-fluxo">
          <li><strong>O efetivo pede</strong> no portal do Arsenal (entra com o CPF e escolhe o material e a data de retirada).</li>
          <li><strong>Você aprova ou recusa</strong> aqui; pode reduzir a quantidade. O efetivo é avisado.</li>
          <li><strong>Na retirada</strong>, confira a identidade (foto e documento) e registre a entrega. Arma de fogo sai por acautelamento.</li>
        </ol>
      </>}
    </Vazio>
  )
}

/** Aprovar (quantidades pedidas) ou recusar (com motivo) direto da lista. Para reduzir quantidades, o detalhe do pedido. */
function DecisaoRapida({ tipo, p, onFechar, onConcluir }: { tipo: 'aprovar' | 'recusar'; p: PedidoResumo; onFechar: () => void; onConcluir: (msg: string) => void }) {
  const [motivo, setMotivo] = useState('')
  const [erro, setErro] = useState<ErroApi | null>(null)
  const [ocupado, setOcupado] = useState(false)
  const recusar = tipo === 'recusar'
  const enviar = async () => {
    setOcupado(true); setErro(null)
    try {
      await api(`/material-requests/${encodeURIComponent(p.codigo)}/decision`, { method: 'POST',
        body: recusar ? { decisao: 'RECUSAR', versao: p.versao, motivo: motivo.trim() } : { decisao: 'APROVAR', versao: p.versao } })
      onConcluir(recusar ? `Pedido ${p.rotulo} recusado. O efetivo foi avisado.` : `Pedido ${p.rotulo} aprovado. O efetivo foi avisado.`)
    } catch (e) { if (e instanceof ErroApi) setErro(e) } finally { setOcupado(false) }
  }
  return (
    <Dialogo aberto titulo={`${recusar ? 'Recusar' : 'Aprovar'} pedido ${p.rotulo}`} onFechar={onFechar}
      rodape={<><button type="button" className="btn btn-sec" onClick={onFechar}>Voltar</button>
        <button type="button" className={`btn ${recusar ? 'btn-sec-perigo' : 'btn-pri'}`} disabled={ocupado || (recusar && motivo.trim().length < 5)} onClick={enviar}>
          {ocupado ? 'Enviando…' : recusar ? 'Recusar pedido' : 'Aprovar como pedido'}</button></>}>
      {erro && <Aviso tipo="perigo" titulo="Não foi possível concluir.">{erro.erro.code === 'VERSAO_DESATUALIZADA' ? 'O pedido mudou desde que a lista foi aberta. Feche e confira de novo.' : erro.erro.message}</Aviso>}
      <p><strong>{rotuloEfetivo(p.efetivo)}</strong>{p.efetivo.nome ? ` · ${p.efetivo.nome}` : ''} · <SeloAptidao m={p.efetivo} /></p>
      <p>{p.itens_resumo}{p.tem_letal && <> <span className="selo selo-alerta">Letal</span></>}</p>
      {recusar
        ? <Campo rotulo="Motivo da recusa" obrigatorio dica="Mínimo de 5 caracteres. O efetivo verá este motivo.">{(pp) =>
          <textarea {...pp} rows={3} maxLength={500} value={motivo} onChange={(e) => setMotivo(e.target.value)} />}</Campo>
        : <p className="dica">Aprova as quantidades pedidas. Para reduzir alguma linha ou deixar observação, <Link to={`/pedidos/${p.codigo}`}>abra o pedido</Link>.</p>}
    </Dialogo>
  )
}

type Escolha = { itens: Set<number>; lotes: Record<number, string> }

export function PedidoPage() {
  const { codigo = '' } = useParams()
  const notificar = useNotificar()
  const { dados: p, erro, carregando, recarregar } = useRecurso(() => ULID.test(codigo) ? api<PedidoDetalhe>(`/material-requests/${encodeURIComponent(codigo)}`) : Promise.reject({ code: 'NAO_ENCONTRADO', message: 'Pedido não encontrado.' }), [codigo])
  const [acao, setAcao] = useState<'aprovar' | 'recusar' | 'entregar' | null>(null)
  const [aprovadas, setAprovadas] = useState<Record<number, string>>({})
  const [texto, setTexto] = useState('')
  const [erroAcao, setErroAcao] = useState<ErroApi | null>(null)
  const [ocupado, setOcupado] = useState(false)
  // "Entregar" na lista abre o detalhe já com o diálogo de entrega (conferência de identidade continua na tela).
  const [sp, setSp] = useSearchParams()
  useEffect(() => {
    if (sp.get('entregar') === '1' && p?.acoes?.entregar) { setAcao('entregar'); setSp({}, { replace: true }) }
  }, [p, sp, setSp])
  const trilha = [{ rotulo: 'Início', para: '/' }, { rotulo: 'Pedidos de material', para: '/pedidos' }, { rotulo: 'Pedido' }]
  if (carregando) return <Pagina titulo="Pedido" trilha={trilha}><Carregando /></Pagina>
  if (erro || !p) return <Pagina titulo="Pedido" trilha={trilha}>{erro && <Erro erro={erro} />}</Pagina>
  const ef = p.efetivo_conferencia

  const decidir = async (decisao: 'APROVAR' | 'RECUSAR') => {
    setOcupado(true); setErroAcao(null)
    try {
      const corpo = decisao === 'RECUSAR' ? { decisao, versao: p.versao, motivo: texto.trim() }
        : { decisao, versao: p.versao, ...(texto.trim() ? { observacao: texto.trim() } : {}), itens: p.itens.map((i) => ({ id: i.id, quantidade_aprovada: Number(aprovadas[i.id] ?? i.quantidade) })) }
      await api(`/material-requests/${encodeURIComponent(p.codigo)}/decision`, { method: 'POST', body: corpo })
      notificar('ok', decisao === 'APROVAR' ? 'Pedido aprovado. O efetivo foi avisado.' : 'Pedido recusado. O efetivo foi avisado.')
      setAcao(null); setTexto(''); recarregar()
    } catch (e) { if (e instanceof ErroApi) { setErroAcao(e); if (e.erro.code === 'VERSAO_DESATUALIZADA') recarregar() } } finally { setOcupado(false) }
  }

  return (
    <Pagina icone={PackageCheck} titulo={`Pedido ${p.rotulo}`} trilha={trilha} acoes={<Selo valor={p.status} />}
      descricao={<>{rotuloEfetivo(p.efetivo)}{p.efetivo.nome ? ` (${p.efetivo.nome})` : ''} · {p.unidade.sigla} · feito {haQuanto(p.criado_em)} · retirada prevista {p.retirada_prevista ? data(p.retirada_prevista) : 'sem data'}{p.local_retirada ? ` em ${p.local_retirada.codigo}` : ''}</>}>
      {p.status === 'PENDENTE' && diasDesde(p.criado_em) >= DIAS_PARADO && <Aviso tipo="alerta" titulo="Pedido parado.">Aguardando aprovação {haQuanto(p.criado_em)}.</Aviso>}
      {erroAcao && <Aviso tipo="perigo" titulo="Não foi possível concluir.">{erroAcao.erro.message}</Aviso>}
      {ef && <Conferencia ef={ef} />}
      <section className="bloco" aria-labelledby="linhas-t"><h2 id="linhas-t">Material</h2><LinhasPedido p={p} />
        {p.observacao && <p><strong>Observação do efetivo:</strong> {p.observacao}</p>}
        {p.acautelamentos.length > 0 && <p>Acautelamentos: {p.acautelamentos.map((a) => <Link key={a.codigo} to={`/acautelamentos/${a.codigo}`}>{a.item ?? a.codigo}</Link>)}</p>}
        {p.entrega_movimentacao_id && <p><Link to={`/movimentacoes/${p.entrega_movimentacao_id}`}>Movimentação de entrega #{p.entrega_movimentacao_id}</Link></p>}</section>
      <section className="bloco" aria-labelledby="fluxo-t"><h2 id="fluxo-t">Andamento</h2><FluxoPedido p={p} /></section>
      <div className="barra-acoes">
        {p.acoes?.decidir && <><button type="button" className="btn btn-sec-perigo" onClick={() => { setTexto(''); setAcao('recusar') }}>Recusar</button>
          <button type="button" className="btn btn-pri" onClick={() => { setTexto(''); setAprovadas({}); setAcao('aprovar') }}>Aprovar</button></>}
        {p.acoes?.entregar && <button type="button" className="btn btn-pri" onClick={() => setAcao('entregar')}>Entregar pedido</button>}
      </div>

      <Dialogo aberto={acao === 'aprovar' || acao === 'recusar'} titulo={acao === 'recusar' ? 'Recusar pedido' : 'Aprovar pedido'} onFechar={() => setAcao(null)}
        rodape={<><button type="button" className="btn btn-sec" onClick={() => setAcao(null)}>Voltar</button>
          <button type="button" className="btn btn-pri" disabled={ocupado || (acao === 'recusar' && texto.trim().length < 5)} onClick={() => decidir(acao === 'recusar' ? 'RECUSAR' : 'APROVAR')}>{ocupado ? 'Enviando…' : 'Confirmar'}</button></>}>
        {acao === 'aprovar' && <>
          <p>Ajuste para baixo o que não puder atender (0 = não atende a linha).</p>
          {p.itens.map((i) => <Campo key={i.id} rotulo={`${i.material.nome} (pedido: ${i.quantidade})`}>{(pp) =>
            <input {...pp} type="number" className="qtd" min={0} max={i.quantidade} step={1} value={aprovadas[i.id] ?? String(i.quantidade)} onChange={(e) => setAprovadas({ ...aprovadas, [i.id]: e.target.value })} />}</Campo>)}
        </>}
        <Campo rotulo={acao === 'recusar' ? 'Motivo da recusa' : 'Observação para o efetivo'} obrigatorio={acao === 'recusar'} dica={acao === 'recusar' ? 'Mínimo de 5 caracteres. O efetivo verá este motivo.' : 'Opcional.'}>{(pp) =>
          <textarea {...pp} rows={3} maxLength={500} value={texto} onChange={(e) => setTexto(e.target.value)} />}</Campo>
      </Dialogo>

      {acao === 'entregar' && <EntregaPedido p={p} aoFechar={() => setAcao(null)} aoConcluir={() => { setAcao(null); recarregar() }} />}
    </Pagina>
  )
}

function Conferencia({ ef }: { ef: NonNullable<PedidoDetalhe['efetivo_conferencia']> }) {
  return (
    <section className="bloco conferencia" aria-labelledby="conf-t">
      <h2 id="conf-t">Conferência de identidade</h2>
      <div className="conferencia-corpo">
        <div className="moldura">{ef.foto_versao
          ? <img src={`${import.meta.env.BASE_URL}api/v1/militares/${ef.id}/foto?v=${ef.foto_versao}`} alt={`Foto de ${ef.nome}`} />
          : <span className="moldura-vazia" role="img" aria-label="Sem foto cadastrada">{ef.nome_guerra.slice(0, 2)}</span>}</div>
        <dl className="lista-simples">
          <dt>Nome</dt><dd>{ef.nome}</dd>
          <dt>Posto e nome de guerra</dt><dd>{rotuloEfetivo(ef)} · {ef.unidade}</dd>
          <dt>Matrícula</dt><dd>{ef.matricula}</dd>
          <dt>CPF</dt><dd>{ef.cpf_mascarado ?? '—'}</dd>
          <dt>Tipo de efetivo</dt><dd><SeloAptidao m={ef} /></dd>
        </dl>
      </div>
      <AvisoAptidao m={ef} />
    </section>
  )
}

/** Entrega do pedido: local, itens (série) e lotes que atendem cada linha, previsão de retorno. */
export function EntregaPedido({ p, aoFechar, aoConcluir }: { p: PedidoDetalhe; aoFechar: () => void; aoConcluir: () => void }) {
  const notificar = useNotificar()
  const [local, setLocal] = useState(p.local_retirada ? String(p.local_retirada.id) : '')
  const [escolha, setEscolha] = useState<Record<number, Escolha>>({})
  const [consumo, setConsumo] = useState(false)
  const [retorno, setRetorno] = useState(hojeMaceio())
  const [erro, setErro] = useState<ErroApi | null>(null)
  const [enviando, setEnviando] = useState(false)
  const chave = useRef(novaChave())
  const linhas = p.itens.filter((i) => (i.quantidade_aprovada ?? 0) > 0)
  const locais = useRecurso(() => api<{ data: Local[] }>('/locations', { query: { somente_ativos: 1 } }), [])
  const estoque = useRecurso(async () => {
    if (!local) return null
    const porLinha: Record<number, { itens: ItemIndividual[]; lotes: Saldo[] }> = {}
    await Promise.all(linhas.map(async (l) => {
      porLinha[l.id] = l.material.modo_controle === 'INDIVIDUAL'
        ? { itens: (await api<Pag<ItemIndividual>>('/stock/individual-items', { query: { local_id: local, material_id: l.material.id, situacao: 'DISPONIVEL', per_page: 100 } })).data, lotes: [] }
        : { itens: [], lotes: (await api<Pag<Saldo>>('/stock/balances', { query: { local_id: local, material_id: l.material.id, per_page: 100 } })).data.filter((s) => s.lote && s.disponivel > 0) }
    }))
    return porLinha
  }, [local, p.codigo])
  const qtdLinha = (l: PedidoItem) => { const e = escolha[l.id]; return e ? e.itens.size + Object.values(e.lotes).reduce((s, q) => s + (Number(q) || 0), 0) : 0 }
  const total = linhas.reduce((s, l) => s + qtdLinha(l), 0)
  const acima = linhas.filter((l) => qtdLinha(l) > (l.quantidade_aprovada ?? 0))
  const soArma = linhas.filter((l) => qtdLinha(l) > 0).every((l) => l.material.acautelamento)
  const pronto = !!local && total > 0 && !acima.length && (consumo || soArma || !!retorno)
  const mudar = (id: number, f: (e: Escolha) => Escolha) => setEscolha((x) => ({ ...x, [id]: f(x[id] ?? { itens: new Set(), lotes: {} }) }))

  const enviar = async () => {
    setEnviando(true); setErro(null)
    try {
      const corpo = { versao: p.versao, local_id: Number(local), ...(consumo || soArma ? {} : { previsao_retorno: retorno }),
        linhas: linhas.map((l) => ({ id: l.id, itens: [...(escolha[l.id]?.itens ?? [])], lotes: Object.entries(escolha[l.id]?.lotes ?? {}).filter(([, q]) => Number(q) > 0).map(([lote, q]) => ({ lote_id: Number(lote), quantidade: Number(q) })) })) }
      await api(`/material-requests/${encodeURIComponent(p.codigo)}/delivery`, { method: 'POST', body: corpo, headers: { 'Idempotency-Key': chave.current } })
      notificar('ok', `Pedido ${p.rotulo} entregue a ${p.efetivo.nome_guerra}.`)
      aoConcluir()
    } catch (e) { if (e instanceof ErroApi) { setErro(e); if (e.status !== 409) chave.current = novaChave() } } finally { setEnviando(false) }
  }

  return (
    <Dialogo aberto titulo={`Entregar pedido ${p.rotulo}`} onFechar={aoFechar}
      rodape={<><button type="button" className="btn btn-sec" onClick={aoFechar}>Voltar</button>
        <button type="button" className="btn btn-pri" disabled={!pronto || enviando} onClick={enviar}>{enviando ? 'Registrando…' : 'Confirmar entrega'}</button></>}>
      {erro && <Aviso tipo="perigo" titulo="Não foi possível entregar.">{erro.erro.code === 'ACESSO_NEGADO' ? 'Você não tem permissão de entrega neste local.' : erro.erro.message}</Aviso>}
      <p>Confira a identidade de <strong>{rotuloEfetivo(p.efetivo)}</strong> antes de confirmar.</p>
      <Campo rotulo="Local de origem" obrigatorio>{(pp) => (
        <select {...pp} value={local} onChange={(e) => { setLocal(e.target.value); setEscolha({}) }}>
          <option value="">Selecione…</option>{locais.dados?.data.map((l) => <option key={l.id} value={l.id}>{l.codigo} — {l.nome} ({l.unidade.sigla})</option>)}
        </select>)}</Campo>
      {local && (estoque.carregando ? <Carregando /> : estoque.dados && linhas.map((l) => {
        const disp = estoque.dados![l.id] ?? { itens: [], lotes: [] }
        return (
          <fieldset key={l.id} className="bloco">
            <legend>{l.material.nome} — aprovado {l.quantidade_aprovada}{l.material.acautelamento ? ' · sai por acautelamento (carteira)' : ''}</legend>
            {!disp.itens.length && !disp.lotes.length && <p className="dica">Nada disponível deste material neste local.</p>}
            {disp.itens.map((i) => {
              const bloq = i.validade_estado === 'VENCIDO' || i.estado_conservacao === 'INSERVIVEL'
              return <label key={i.id} className="opcao"><input type="checkbox" disabled={bloq} checked={!!escolha[l.id]?.itens.has(i.id)}
                onChange={(e) => mudar(l.id, (x) => { const s = new Set(x.itens); if (e.target.checked) s.add(i.id); else s.delete(i.id); return { ...x, itens: s } })} />
                <RotuloItem serie={i.numero_serie} codigo={i.identificador} />{bloq && <> <span className="selo selo-perigo">Indisponível</span></>}</label>
            })}
            {disp.lotes.map((s) => <Campo key={s.lote!.id} rotulo={`Lote ${s.lote!.codigo} (disponível ${numero(s.disponivel)})`}>{(pp) =>
              <input {...pp} type="number" className="qtd" min={0} max={Math.min(s.disponivel, l.quantidade_aprovada ?? 0)} step={1} value={escolha[l.id]?.lotes[s.lote!.id] ?? ''}
                onChange={(e) => mudar(l.id, (x) => ({ ...x, lotes: { ...x.lotes, [s.lote!.id]: e.target.value } }))} />}</Campo>)}
            {qtdLinha(l) > (l.quantidade_aprovada ?? 0) && <p className="msg-erro" role="alert">Acima do aprovado ({l.quantidade_aprovada}).</p>}
          </fieldset>)
      }))}
      {!soArma && <div className="campo">
        <label className="opcao"><input type="checkbox" checked={consumo} onChange={(e) => setConsumo(e.target.checked)} /> Sem retorno (consumo — sai do estoque)</label>
        {!consumo && <Campo rotulo="Previsão de retorno" obrigatorio>{(pp) => <input {...pp} type="date" min={hojeMaceio()} value={retorno} onChange={(e) => setRetorno(e.target.value)} />}</Campo>}
      </div>}
      {total > 0 && <p role="status">{total} unidade(s) selecionada(s). O que não for entregue fica registrado no pedido.</p>}
    </Dialogo>
  )
}
