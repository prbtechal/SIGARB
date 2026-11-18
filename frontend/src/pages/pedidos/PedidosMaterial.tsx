import { useRef, useState } from 'react'
import { Link, useParams, useSearchParams } from 'react-router'
import { Inbox, PackageCheck } from 'lucide-react'
import { ErroApi, api, novaChave, type Pagina as Pag } from '../../api/client'
import type { ItemIndividual, Local, PedidoDetalhe, PedidoItem, PedidoResumo, Saldo } from '../../api/tipos'
import { AvisoAptidao, SeloAptidao } from '../../components/AptidaoEfetivo'
import { RotuloItem } from '../../components/RotuloItem'
import { hojeMaceio } from '../../components/quando'
import { Aviso, Campo, Carregando, Dialogo, Erro, Pagina, Paginacao, Selo, Vazio, data, dataHora, numero, useNotificar } from '../../components/ui'
import { useRecurso } from '../../components/useRecurso'
import { FluxoPedido, LinhasPedido } from '../portal/PortalEfetivo'

/**
 * Pedidos de material (portal do efetivo, decisão do responsável 2026-11-18) — lado do armeiro (`pedidos.gerir`).
 * Aprovar antes (pode reduzir quantidades), recusar com motivo, e entregar na retirada conferindo a identidade.
 * Arma de fogo sai por acautelamento (com carteira); o resto, por ENTREGA. O servidor revalida tudo.
 */

const ULID = /^[0-9A-HJKMNP-TV-Z]{26}$/
const STATUS = ['PENDENTE', 'APROVADO', 'ENTREGUE', 'RECUSADO', 'CANCELADO'] as const

export function PedidosPage() {
  const [sp, setSp] = useSearchParams()
  const [pagina, setPagina] = useState(1)
  const [termo, setTermo] = useState(sp.get('q') ?? '')
  const status = sp.get('status') ?? ''
  const filtro = { status, q: sp.get('q') ?? '', de: sp.get('de') ?? '', ate: sp.get('ate') ?? '' }
  const r = useRecurso(() => api<Pag<PedidoResumo>>('/material-requests', { query: { page: pagina, ...Object.fromEntries(Object.entries(filtro).filter(([, v]) => v)) } }),
    [pagina, filtro.status, filtro.q, filtro.de, filtro.ate])
  const mudar = (k: string, v: string) => { const n = new URLSearchParams(sp); if (v) n.set(k, v); else n.delete(k); setSp(n); setPagina(1) }
  return (
    <Pagina icone={Inbox} titulo="Pedidos de material" trilha={[{ rotulo: 'Início', para: '/' }, { rotulo: 'Pedidos de material' }]}
      descricao="Pedidos feitos pelos efetivos no portal. Aprove antes; na retirada, confira a identidade e entregue.">
      <form className="filtros" role="search" onSubmit={(e) => { e.preventDefault(); mudar('q', termo.trim()) }}>
        <div className="grade">
          <Campo rotulo="Situação">{(p) => <select {...p} value={status} onChange={(e) => mudar('status', e.target.value)}><option value="">Todas</option>{STATUS.map((s) => <option key={s} value={s}>{SELO_TEXTO[s]}</option>)}</select>}</Campo>
          <Campo rotulo="Efetivo ou código" dica="Nome, nome de guerra, matrícula ou código do comprovante (PED-…).">{(p) => <input {...p} type="search" value={termo} onChange={(e) => setTermo(e.target.value)} />}</Campo>
          <Campo rotulo="De">{(p) => <input {...p} type="date" value={filtro.de} onChange={(e) => mudar('de', e.target.value)} />}</Campo>
          <Campo rotulo="Até">{(p) => <input {...p} type="date" value={filtro.ate} onChange={(e) => mudar('ate', e.target.value)} />}</Campo>
        </div>
        <div className="barra-acoes"><button className="btn btn-sec">Buscar</button></div>
      </form>
      {r.carregando ? <Carregando /> : r.erro ? <Erro erro={r.erro} tentar={r.recarregar} /> : !r.dados?.data.length ? <Vazio titulo="Nenhum pedido com estes filtros." /> : <>
        <div className="tabela-wrap"><table className="tabela">
          <caption className="sr-only">Pedidos de material</caption>
          <thead><tr><th scope="col">Pedido</th><th scope="col">Efetivo</th><th scope="col">Situação</th><th scope="col">Material</th><th scope="col">Retirada</th><th scope="col">Feito em</th></tr></thead>
          <tbody>{r.dados.data.map((p) => (
            <tr key={p.codigo}>
              <td data-rotulo="Pedido"><Link to={`/pedidos/${p.codigo}`}>{p.rotulo}</Link></td>
              <td data-rotulo="Efetivo">{p.efetivo.posto} {p.efetivo.nome_guerra} <small>· {p.unidade.sigla}</small><br /><SeloAptidao m={p.efetivo} /></td>
              <td data-rotulo="Situação"><Selo valor={p.status} /></td>
              <td data-rotulo="Material">{p.itens_resumo}{p.tem_letal && <> <span className="selo selo-alerta">Letal</span></>}</td>
              <td data-rotulo="Retirada">{data(p.retirada_prevista)}{p.local_retirada ? <><br /><small>{p.local_retirada.codigo}</small></> : null}</td>
              <td data-rotulo="Feito em">{dataHora(p.criado_em)}</td>
            </tr>))}</tbody>
        </table></div>
        <Paginacao meta={r.dados.meta} ir={setPagina} />
      </>}
    </Pagina>
  )
}

const SELO_TEXTO: Record<string, string> = { PENDENTE: 'Aguardando aprovação', APROVADO: 'Aprovado (a retirar)', ENTREGUE: 'Entregue', RECUSADO: 'Recusado', CANCELADO: 'Cancelado' }

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
      descricao={<>{p.efetivo.posto} {p.efetivo.nome_guerra} · {p.unidade.sigla} · retirada prevista {data(p.retirada_prevista)}{p.local_retirada ? ` em ${p.local_retirada.codigo}` : ''}</>}>
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
          <dt>Posto e nome de guerra</dt><dd>{ef.posto} {ef.nome_guerra} · {ef.unidade}</dd>
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
      <p>Confira a identidade de <strong>{p.efetivo.posto} {p.efetivo.nome_guerra}</strong> antes de confirmar.</p>
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
