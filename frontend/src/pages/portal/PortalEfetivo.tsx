import { useMemo, useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router'
import { ClipboardList, Home, PackageCheck, PackagePlus, Ticket } from 'lucide-react'
import { ErroApi, api, type Pagina as Pag } from '../../api/client'
import { CATEGORIAS_EFETIVO, type EfetivoPortal, type ItemCatalogoPortal, type MaterialComigo, type PedidoDetalhe, type PedidoResumo } from '../../api/tipos'
import { SeloAptidao } from '../../components/AptidaoEfetivo'
import { SituacaoLinhaLote } from '../../components/estoque/EntregaResumo'
import { hojeMaceio } from '../../components/quando'
import { Aviso, Campo, Carregando, Dialogo, Erro, Pagina, Paginacao, Selo, Vazio, data, dataHora, numero, rotuloNatureza, useNotificar } from '../../components/ui'
import { useRecurso } from '../../components/useRecurso'
import { rotuloEfetivo } from '../../components/efetivo'

/**
 * Portal do efetivo (decisão do responsável, 2026-11-18). O efetivo faz o pedido antes, o armeiro aprova e, na retirada,
 * confere a identidade e entrega. Tudo é revalidado no servidor (inclusive o bloqueio de arma de fogo e munição).
 */

const ULID = /^[0-9A-HJKMNP-TV-Z]{26}$/
type Resumo = { sem_vinculo: boolean; efetivo: EfetivoPortal | null; pedidos_abertos: PedidoResumo[]; limite_abertos: number; material: { total: number; atrasados: number } }
type Catalogo = { efetivo: EfetivoPortal; limite_abertos: number; abertos: number; locais: { id: number; codigo: string; nome: string; unidade: string | null }[]; data: ItemCatalogoPortal[] }

/** Aviso de cadastro sem tipo de efetivo: não pede arma de fogo nem munição; regularizar com a T.I. */
export function AvisoSemTipo({ efetivo }: { efetivo: EfetivoPortal | null | undefined }) {
  if (!efetivo || efetivo.categoria_efetivo) return null
  return <Aviso tipo="alerta" titulo="Seu tipo de efetivo não está cadastrado.">Você pode pedir os demais materiais, mas não arma de fogo nem munição. Procure a T.I. / suporte técnico para regularizar o cadastro.</Aviso>
}

function Efetivo({ e }: { e: EfetivoPortal }) {
  return <p className="descricao">{rotuloEfetivo(e)} · {e.unidade} · <SeloAptidao m={{ categoria_efetivo: e.categoria_efetivo, apto_arma_fogo: e.apto_arma_fogo }} /></p>
}

export function PortalInicioPage() {
  const { dados: d, erro, carregando, recarregar } = useRecurso(() => api<Resumo>('/portal/summary'), [])
  if (carregando) return <Pagina icone={Home} titulo="Início"><Carregando /></Pagina>
  if (erro || !d) return <Pagina icone={Home} titulo="Início">{erro && <Erro erro={erro} tentar={recarregar} />}</Pagina>
  if (d.sem_vinculo || !d.efetivo) return (
    <Pagina icone={Home} titulo="Início"><Aviso tipo="alerta" titulo="Sua conta não está ligada a um cadastro de efetivo.">Procure a T.I. para regularizar.</Aviso></Pagina>)
  const cheio = d.pedidos_abertos.length >= d.limite_abertos
  return (
    <Pagina icone={Home} titulo={`Olá, ${d.efetivo.nome_guerra}`} descricao={<>Peça o material antes e retire com o armeiro, sem fila. {d.efetivo.unidade}</>}
      acoes={cheio ? undefined : <Link className="btn btn-pri" to="/portal/novo">Fazer pedido</Link>}>
      <AvisoSemTipo efetivo={d.efetivo} />
      {d.material.atrasados > 0 && <Aviso tipo="perigo" titulo="Devolução atrasada:">{d.material.atrasados} material(is) com você passou(aram) da data de retorno. Procure o armeiro.</Aviso>}
      <div className="cartoes">
        <div className="cartao"><p className="cartao-rotulo">Pedidos em aberto</p><p className="cartao-valor">{d.pedidos_abertos.length}</p><p className="cartao-extra">Limite: {d.limite_abertos}</p></div>
        <div className="cartao"><p className="cartao-rotulo">Material com você</p><p className="cartao-valor">{numero(d.material.total)}</p><p className="cartao-extra"><Link to="/portal/material">Ver material</Link></p></div>
        <div className="cartao"><p className="cartao-rotulo">Tipo de efetivo</p><p className="cartao-valor portal-tipo">{d.efetivo.categoria_efetivo ? CATEGORIAS_EFETIVO[d.efetivo.categoria_efetivo] ?? d.efetivo.categoria_efetivo : 'Não cadastrado'}</p>
          <p className="cartao-extra">{d.efetivo.apto_arma_fogo ? 'Pode pedir arma de fogo e munição' : 'Sem arma de fogo e munição'}</p></div>
      </div>
      <section className="bloco" aria-labelledby="abertos-t">
        <div className="bloco-topo"><h2 id="abertos-t">Pedidos em aberto</h2><Link to="/portal/pedidos">Todos os pedidos</Link></div>
        {d.pedidos_abertos.length === 0 ? <Vazio titulo="Nenhum pedido em aberto." /> : (
          <ul className="lista-simples">
            {d.pedidos_abertos.map((p) => <li key={p.codigo}><Link to={`/portal/pedidos/${p.codigo}`}><strong>{p.rotulo}</strong></Link> <Selo valor={p.status} /> — {p.itens_resumo}
              {p.status === 'APROVADO' && <><br /><small>Aprovado: leve um documento e mostre o comprovante ao armeiro.</small></>}</li>)}
          </ul>)}
      </section>
    </Pagina>
  )
}

export function NovoPedidoPage() {
  const nav = useNavigate()
  const notificar = useNotificar()
  const cat = useRecurso(() => api<Catalogo>('/portal/catalog'), [])
  const [qtds, setQtds] = useState<Record<number, string>>({})
  const [filtro, setFiltro] = useState('')
  const [retirada, setRetirada] = useState('')
  const [local, setLocal] = useState('')
  const [obs, setObs] = useState('')
  const [erro, setErro] = useState<ErroApi | null>(null)
  const [enviando, setEnviando] = useState(false)
  const lista = useMemo(() => (cat.dados?.data ?? []).filter((m) => !filtro || `${m.nome} ${m.categoria} ${m.tipo ?? ''}`.toLowerCase().includes(filtro.toLowerCase())), [cat.dados, filtro])
  const categorias = useMemo(() => [...new Set(lista.map((m) => m.categoria ?? 'Outros'))], [lista])
  const escolhidos = Object.entries(qtds).filter(([, q]) => Number(q) > 0).map(([id, q]) => ({ material_id: Number(id), quantidade: Number(q) }))
  const trilha = [{ rotulo: 'Início', para: '/' }, { rotulo: 'Novo pedido' }]
  if (cat.carregando) return <Pagina icone={PackagePlus} titulo="Novo pedido" trilha={trilha}><Carregando /></Pagina>
  if (cat.erro || !cat.dados) return <Pagina icone={PackagePlus} titulo="Novo pedido" trilha={trilha}>{cat.erro && <Erro erro={cat.erro} tentar={cat.recarregar} />}</Pagina>
  const c = cat.dados
  const cheio = c.abertos >= c.limite_abertos
  const hoje = hojeMaceio()

  const enviar = async () => {
    setEnviando(true); setErro(null)
    try {
      const r = await api<PedidoDetalhe>('/portal/requests', { method: 'POST', body: { itens: escolhidos, ...(retirada ? { retirada_prevista: retirada } : {}),
        ...(local ? { local_retirada_id: Number(local) } : {}), ...(obs.trim() ? { observacao: obs.trim() } : {}) } })
      notificar('ok', `Pedido ${r.rotulo} enviado. Aguarde a aprovação do armeiro.`)
      nav(`/portal/pedidos/${r.codigo}`)
    } catch (e) { if (e instanceof ErroApi) setErro(e) } finally { setEnviando(false) }
  }

  return (
    <Pagina icone={PackagePlus} titulo="Novo pedido" trilha={trilha} descricao={<>Escolha o material e as quantidades. O armeiro aprova antes e você retira levando um documento.</>}>
      <Efetivo e={c.efetivo} />
      <AvisoSemTipo efetivo={c.efetivo} />
      {cheio && <Aviso tipo="alerta" titulo={`Você já tem ${c.abertos} pedidos em aberto (limite: ${c.limite_abertos}).`}>Aguarde a entrega ou cancele um deles em <Link to="/portal/pedidos">Meus pedidos</Link>.</Aviso>}
      {erro && <Aviso tipo="perigo" titulo="Não foi possível enviar.">{erro.erro.message}</Aviso>}
      <form noValidate onSubmit={(e) => { e.preventDefault(); if (escolhidos.length && !cheio) enviar() }}>
        <fieldset className="bloco">
          <legend>1. Material</legend>
          <div className="campo"><label htmlFor="filtro-cat">Filtrar</label><input id="filtro-cat" type="search" placeholder="Rádio, colete, algema…" value={filtro} onChange={(e) => setFiltro(e.target.value)} /></div>
          {lista.length === 0 ? <Vazio titulo="Nenhum material com este filtro." /> : categorias.map((cg) => (
            <div key={cg} className="tabela-wrap"><table className="tabela">
              <caption>{cg}</caption>
              <thead><tr><th scope="col">Material</th><th scope="col">Quantidade</th></tr></thead>
              <tbody>
                {lista.filter((m) => (m.categoria ?? 'Outros') === cg).map((m) => (
                  <tr key={m.id} className={m.bloqueio ? 'linha-bloqueada' : undefined}>
                    <td data-rotulo="Material">{m.nome}<br /><small>{rotuloNatureza(m.natureza)}{m.tipo ? ` · ${m.tipo}` : ''}{m.modo_controle === 'INDIVIDUAL' ? ' · 1 por pedido' : ''}</small></td>
                    <td data-rotulo="Quantidade">{m.bloqueio ? <span className="selo selo-alerta" title={m.bloqueio}>Bloqueado</span> : m.modo_controle === 'INDIVIDUAL'
                      ? <input type="checkbox" aria-label={`Pedir ${m.nome}`} checked={qtds[m.id] === '1'} onChange={(e) => setQtds({ ...qtds, [m.id]: e.target.checked ? '1' : '' })} />
                      : <input type="number" className="qtd" min={0} max={1000} step={1} inputMode="numeric" aria-label={`Quantidade de ${m.nome}`} value={qtds[m.id] ?? ''} onChange={(e) => setQtds({ ...qtds, [m.id]: e.target.value })} />}
                      {m.bloqueio && <><br /><small className="motivo-bloqueio">{m.bloqueio}</small></>}</td>
                  </tr>))}
              </tbody>
            </table></div>))}
        </fieldset>
        <fieldset className="bloco">
          <legend>2. Retirada</legend>
          <div className="grade">
            <Campo rotulo="Retirada prevista" dica="Opcional. Até 60 dias." erro={erro?.erro.fields?.retirada_prevista}>{(p) => <input {...p} type="date" min={hoje} value={retirada} onChange={(e) => setRetirada(e.target.value)} />}</Campo>
            <Campo rotulo="Local de retirada" dica="Opcional." erro={erro?.erro.fields?.local_retirada_id}>{(p) => (
              <select {...p} value={local} onChange={(e) => setLocal(e.target.value)}><option value="">Tanto faz</option>{c.locais.map((l) => <option key={l.id} value={l.id}>{l.codigo} — {l.nome}{l.unidade ? ` (${l.unidade})` : ''}</option>)}</select>)}</Campo>
            <Campo rotulo="Observação" dica="Ex.: serviço, evento, tamanho do colete." erro={erro?.erro.fields?.observacao}>{(p) => <input {...p} maxLength={500} value={obs} onChange={(e) => setObs(e.target.value)} />}</Campo>
          </div>
        </fieldset>
        {escolhidos.length > 0 && <p role="status">{escolhidos.length} material(is) no pedido.</p>}
        <div className="barra-acoes">
          <button type="button" className="btn btn-sec" onClick={() => nav(-1)}>Cancelar</button>
          <button className="btn btn-pri" disabled={!escolhidos.length || cheio || enviando}>{enviando ? 'Enviando…' : 'Enviar pedido'}</button>
        </div>
      </form>
    </Pagina>
  )
}

export function MeusPedidosPage() {
  const [pagina, setPagina] = useState(1)
  const r = useRecurso(() => api<Pag<PedidoResumo>>('/portal/requests', { query: { page: pagina } }), [pagina])
  return (
    <Pagina icone={ClipboardList} titulo="Meus pedidos" trilha={[{ rotulo: 'Início', para: '/' }, { rotulo: 'Meus pedidos' }]} acoes={<Link className="btn btn-pri" to="/portal/novo">Fazer pedido</Link>}>
      {r.carregando ? <Carregando /> : r.erro ? <Erro erro={r.erro} tentar={r.recarregar} /> : !r.dados?.data.length ? <Vazio titulo="Você ainda não fez pedidos." /> : <>
        <div className="tabela-wrap"><table className="tabela">
          <caption className="sr-only">Meus pedidos de material</caption>
          <thead><tr><th scope="col">Pedido</th><th scope="col">Situação</th><th scope="col">Material</th><th scope="col">Feito em</th><th scope="col">Retirada</th></tr></thead>
          <tbody>{r.dados.data.map((p) => (
            <tr key={p.codigo}>
              <td data-rotulo="Pedido"><Link to={`/portal/pedidos/${p.codigo}`}>{p.rotulo}</Link></td>
              <td data-rotulo="Situação"><Selo valor={p.status} /></td>
              <td data-rotulo="Material">{p.itens_resumo}</td>
              <td data-rotulo="Feito em">{dataHora(p.criado_em)}</td>
              <td data-rotulo="Retirada">{data(p.retirada_prevista)}</td>
            </tr>))}</tbody>
        </table></div>
        <Paginacao meta={r.dados.meta} ir={setPagina} />
      </>}
    </Pagina>
  )
}

/** Linha do tempo do pedido (efetivo e armeiro). */
export function FluxoPedido({ p }: { p: PedidoDetalhe }) {
  const passos = [
    { feito: true, titulo: 'Pedido feito', texto: `${dataHora(p.criado_em)}${p.observacao ? ` — “${p.observacao}”` : ''}` },
    { feito: !!p.decidido_em, titulo: p.status === 'RECUSADO' ? 'Recusado' : 'Aprovação', texto: p.decidido_em ? `${p.decidido_por ?? ''} em ${dataHora(p.decidido_em)}${p.motivo_recusa ? `. Motivo: ${p.motivo_recusa}` : ''}${p.observacao_armeiro ? `. ${p.observacao_armeiro}` : ''}` : p.status === 'CANCELADO' ? '—' : 'Aguardando o armeiro.' },
    { feito: !!p.entregue_em, titulo: p.status === 'CANCELADO' ? 'Cancelado' : 'Entrega', texto: p.entregue_em ? `${p.entregue_por ?? ''} em ${dataHora(p.entregue_em)}` : p.cancelado_em ? dataHora(p.cancelado_em) : 'Na retirada, com conferência de identidade.' },
  ]
  return <ol className="fluxo">{passos.map((s, n) => <li key={n} className={s.feito ? 'feito' : undefined}><strong>{s.titulo}</strong><span>{s.texto}</span></li>)}</ol>
}

export function LinhasPedido({ p }: { p: PedidoDetalhe }) {
  return (
    <div className="tabela-wrap"><table className="tabela">
      <caption className="sr-only">Material do pedido</caption>
      <thead><tr><th scope="col">Material</th><th scope="col">Pedido</th><th scope="col">Aprovado</th><th scope="col">Entregue</th></tr></thead>
      <tbody>{p.itens.map((i) => (
        <tr key={i.id}>
          <td data-rotulo="Material">{i.material.nome}<br /><small>{rotuloNatureza(i.material.natureza)}{i.material.acautelamento ? ' · por acautelamento' : ''}</small></td>
          <td data-rotulo="Pedido">{numero(i.quantidade)} {i.material.unidade_medida}</td>
          <td data-rotulo="Aprovado">{i.quantidade_aprovada ?? '—'}{i.quantidade_aprovada !== null && i.quantidade_aprovada < i.quantidade && <> <span className="selo selo-alerta">Reduzido</span></>}</td>
          <td data-rotulo="Entregue">{i.quantidade_entregue ?? '—'}</td>
        </tr>))}</tbody>
    </table></div>
  )
}

export function PedidoEfetivoPage() {
  const { codigo = '' } = useParams()
  const notificar = useNotificar()
  const { dados: p, erro, carregando, recarregar } = useRecurso(() => ULID.test(codigo) ? api<PedidoDetalhe>(`/portal/requests/${encodeURIComponent(codigo)}`) : Promise.reject({ code: 'NAO_ENCONTRADO', message: 'Pedido não encontrado.' }), [codigo])
  const [cancelar, setCancelar] = useState(false)
  const [motivo, setMotivo] = useState('')
  const [erroAcao, setErroAcao] = useState<ErroApi | null>(null)
  const [ocupado, setOcupado] = useState(false)
  const trilha = [{ rotulo: 'Início', para: '/' }, { rotulo: 'Meus pedidos', para: '/portal/pedidos' }, { rotulo: 'Pedido' }]
  if (carregando) return <Pagina titulo="Pedido" trilha={trilha}><Carregando /></Pagina>
  if (erro || !p) return <Pagina titulo="Pedido" trilha={trilha}>{erro && <Erro erro={erro} />}</Pagina>

  const confirmar = async () => {
    setOcupado(true); setErroAcao(null)
    try {
      await api(`/portal/requests/${encodeURIComponent(p.codigo)}/cancel`, { method: 'POST', body: { versao: p.versao, ...(motivo.trim() ? { motivo: motivo.trim() } : {}) } })
      notificar('ok', 'Pedido cancelado.'); setCancelar(false); recarregar()
    } catch (e) { if (e instanceof ErroApi) { setErroAcao(e); if (e.erro.code === 'VERSAO_DESATUALIZADA') recarregar() } } finally { setOcupado(false) }
  }

  return (
    <Pagina icone={Ticket} titulo={`Pedido ${p.rotulo}`} trilha={trilha} acoes={<Selo valor={p.status} />}
      descricao={<>Retirada prevista: {data(p.retirada_prevista)}{p.local_retirada ? ` · ${p.local_retirada.codigo} — ${p.local_retirada.nome}` : ''}</>}>
      {erroAcao && <Aviso tipo="perigo" titulo="Não foi possível cancelar.">{erroAcao.erro.message}</Aviso>}
      {p.status === 'APROVADO' && (
        <section className="bloco comprovante" aria-labelledby="comp-t">
          <h2 id="comp-t">Comprovante para a retirada</h2>
          <p>Mostre este código ao armeiro e leve um documento com foto.</p>
          <p className="comprovante-codigo" aria-label={`Código ${p.rotulo}`}>{p.rotulo}</p>
          <p className="dica">Código completo: <code>{p.codigo}</code></p>
        </section>)}
      {p.status === 'RECUSADO' && <Aviso tipo="perigo" titulo="Pedido recusado.">{p.motivo_recusa}</Aviso>}
      <section className="bloco" aria-labelledby="fluxo-t"><h2 id="fluxo-t">Andamento</h2><FluxoPedido p={p} /></section>
      <section className="bloco" aria-labelledby="linhas-t"><h2 id="linhas-t">Material</h2><LinhasPedido p={p} />
        {p.acautelamentos.length > 0 && <p>Arma(s) acautelada(s): {p.acautelamentos.map((a) => `${a.item ?? ''} (${a.codigo.slice(-6)})`).join(', ')}.</p>}</section>
      {p.pode_cancelar && <div className="barra-acoes"><button type="button" className="btn btn-sec-perigo" onClick={() => setCancelar(true)}>Cancelar pedido</button></div>}
      <Dialogo aberto={cancelar} titulo="Cancelar pedido" onFechar={() => setCancelar(false)}
        rodape={<><button type="button" className="btn btn-sec" onClick={() => setCancelar(false)}>Voltar</button><button type="button" className="btn btn-pri" disabled={ocupado} onClick={confirmar}>{ocupado ? 'Cancelando…' : 'Confirmar cancelamento'}</button></>}>
        <Campo rotulo="Motivo (opcional)">{(pp) => <input {...pp} maxLength={500} value={motivo} onChange={(e) => setMotivo(e.target.value)} />}</Campo>
      </Dialogo>
    </Pagina>
  )
}

export function MaterialComigoPage() {
  const r = useRecurso(() => api<{ data: MaterialComigo[] }>('/portal/material'), [])
  return (
    <Pagina icone={PackageCheck} titulo="Material comigo" trilha={[{ rotulo: 'Início', para: '/' }, { rotulo: 'Material comigo' }]}
      descricao="Material entregue a você que ainda não foi devolvido, e armas acauteladas.">
      {r.carregando ? <Carregando /> : r.erro ? <Erro erro={r.erro} tentar={r.recarregar} /> : !r.dados?.data.length ? <Vazio titulo="Nenhum material com você." /> : (
        <div className="tabela-wrap"><table className="tabela">
          <caption className="sr-only">Material com você</caption>
          <thead><tr><th scope="col">Material</th><th scope="col">Nº de série / lote</th><th scope="col">Quantidade</th><th scope="col">Situação</th><th scope="col">Desde</th><th scope="col">Devolver até</th></tr></thead>
          <tbody>{r.dados.data.map((m, n) => (
            <tr key={n}>
              <td data-rotulo="Material">{m.material.nome}<br /><small>{m.material.natureza_rotulo}{m.custodia === 'ACAUTELAMENTO' ? ' · acautelamento' : ''}</small></td>
              <td data-rotulo="Nº de série / lote">{m.numero_serie ?? (m.lote_codigo ? `Lote ${m.lote_codigo}` : m.referencia)}</td>
              <td data-rotulo="Quantidade">{numero(m.quantidade)} {m.material.unidade_medida}</td>
              <td data-rotulo="Situação">{m.lote_codigo || m.situacao_na_entrega ? <SituacaoLinhaLote i={m} /> : m.situacao ? <Selo valor={m.situacao} /> : '—'}</td>
              <td data-rotulo="Desde">{dataHora(m.desde)}</td>
              <td data-rotulo="Devolver até">{m.custodia === 'ACAUTELAMENTO' ? 'Cautela' : m.previsao_retorno ? data(m.previsao_retorno) : '—'}{m.atrasado && <> <span className="selo selo-perigo">Atrasado</span></>}</td>
            </tr>))}</tbody>
        </table></div>)}
    </Pagina>
  )
}
