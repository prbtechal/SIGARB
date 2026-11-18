import { useEffect, useMemo, useRef, useState } from 'react'
import { useNavigate } from 'react-router'
import { HandHelping } from 'lucide-react'
import { ErroApi, api, novaChave, type Pagina as Pag } from '../api/client'
import { CATEGORIAS_EFETIVO, type ItemIndividual, type Local, type Militar, type MovDetalhe, type OpcoesEntrega, type Saldo } from '../api/tipos'
import { useAuth } from '../auth/AuthContext'
import { BuscaEfetivo } from '../components/estoque/BuscaEfetivo'
import { RotuloItem, textoItem } from '../components/RotuloItem'
import { hojeMaceio } from '../components/quando'
import { Aviso, Campo, Carregando, Dialogo, NATUREZAS_LETAIS, Pagina, Vazio, data, numero, rotuloNatureza, useNotificar } from '../components/ui'
import { useRecurso } from '../components/useRecurso'

const letal = (natureza?: string | null) => !!natureza && NATUREZAS_LETAIS.includes(natureza)

/**
 * Registrar entrega a efetivo (decisão do responsável, 2026-11-16): efetivo com selo de aptidão, local de origem, itens por série e
 * lotes com saldo disponível, previsão de retorno (com data = pendente de devolução; sem data = consumo), documento e motivo.
 * Usa POST /movements (ENTREGA); o servidor revalida tudo, inclusive o bloqueio de arma/munição para quem não pode.
 */
export default function NovaEntregaPage() {
  const nav = useNavigate()
  const notificar = useNotificar()
  const { perfil } = useAuth()
  const bloqueada = perfil?.operacoes.find((o) => o.codigo === 'ENTREGA')?.estado === 'BLOQUEADA'
  const [efetivo, setEfetivo] = useState<Militar | null>(null)
  const [origem, setOrigem] = useState('')
  const [busca, setBusca] = useState('')
  const [termo, setTermo] = useState('')
  const [itensSel, setItensSel] = useState<Map<number, ItemIndividual>>(new Map())
  const [qtds, setQtds] = useState<Record<number, string>>({})
  const [consumo, setConsumo] = useState(false)
  const [retorno, setRetorno] = useState(hojeMaceio())
  const [doc, setDoc] = useState('')
  const [docNumero, setDocNumero] = useState('')
  const [motivo, setMotivo] = useState('')
  const [motivoTexto, setMotivoTexto] = useState('')
  const [revisar, setRevisar] = useState(false)
  const [erro, setErro] = useState<ErroApi | null>(null)
  const [enviando, setEnviando] = useState(false)
  const chave = useRef(novaChave())
  const locais = useRecurso(() => api<{ data: Local[] }>('/locations', { query: { somente_ativos: 1 } }), [])
  // Decisão 2026-11-18: listas de documento/motivo vêm do servidor, com o local de origem padrão da base (Reserva).
  const opcoes = useRecurso(() => api<OpcoesEntrega>('/stock/delivery-options'), [])
  const padrao = opcoes.dados?.local_origem_padrao_id
  useEffect(() => {
    if (padrao && !origem && locais.dados?.data.some((l) => l.id === padrao)) setOrigem(String(padrao))
  }, [padrao, locais.dados]) // eslint-disable-line react-hooks/exhaustive-deps
  const estoque = useRecurso(async () => {
    if (!origem) return null
    const [i, s] = await Promise.all([
      api<Pag<ItemIndividual>>('/stock/individual-items', { query: { local_id: origem, situacao: 'DISPONIVEL', per_page: 100, ...(busca ? { q: busca } : {}) } }),
      api<Pag<Saldo>>('/stock/balances', { query: { local_id: origem, per_page: 100, ...(busca ? { q: busca } : {}) } }),
    ])
    return { itens: i.data, lotes: s.data.filter((x) => x.lote && x.disponivel > 0) }
  }, [origem, busca])
  const lotes = useMemo(() => estoque.dados?.lotes ?? [], [estoque.dados])
  // Lotes escolhidos ficam guardados mesmo que a busca mude (precisamos do saldo e da natureza para revisar).
  const [lotesVistos, setLotesVistos] = useState<Record<number, Saldo>>({})
  const hoje = hojeMaceio()
  const apto = efetivo?.apto_arma_fogo === true
  const tipoEfetivo = efetivo?.categoria_efetivo ? CATEGORIAS_EFETIVO[efetivo.categoria_efetivo] ?? efetivo.categoria_efetivo : 'sem tipo cadastrado'
  const bloqueadoLetal = (natureza?: string | null) => !!efetivo && letal(natureza) && !apto

  const linhasLote = Object.entries(qtds).filter(([, q]) => Number(q) > 0).map(([id, q]) => ({ lote: lotesVistos[Number(id)], q: Number(q) })).filter((l) => l.lote)
  const excedidos = linhasLote.filter((l) => l.q > l.lote.disponivel || !Number.isInteger(l.q))
  const letaisEscolhidos = [...[...itensSel.values()].filter((i) => letal(i.material.natureza)).map((i) => textoItem(i.numero_serie, i.identificador)),
    ...linhasLote.filter((l) => letal(l.lote.material.natureza)).map((l) => `lote ${l.lote.lote!.codigo}`)]
  const conflitoLetal = !!efetivo && !apto && letaisEscolhidos.length > 0
  const total = itensSel.size + linhasLote.length
  const outro = motivo === 'OUTRO'
  const motivoOk = !!motivo && (!outro || motivoTexto.trim().length >= 5)
  const docSel = opcoes.dados?.documentos.find((d) => d.codigo === doc)
  const motivoSel = opcoes.dados?.motivos.find((m) => m.codigo === motivo)
  const comNumero = !!doc && doc !== 'SEM_DOCUMENTO'
  const pronto = !!efetivo && !!origem && total > 0 && !excedidos.length && !conflitoLetal && motivoOk && (consumo || !!retorno) && !bloqueada

  const escolherOrigem = (v: string) => { setOrigem(v); setItensSel(new Map()); setQtds({}); setLotesVistos({}) }
  const marcarItem = (i: ItemIndividual, sim: boolean) => setItensSel((m) => { const n = new Map(m); if (sim) n.set(i.id, i); else n.delete(i.id); return n })
  const qtdLote = (s: Saldo, v: string) => { setQtds({ ...qtds, [s.lote!.id]: v }); setLotesVistos({ ...lotesVistos, [s.lote!.id]: s }) }

  const enviar = async () => {
    if (!efetivo) return
    setEnviando(true); setErro(null)
    try {
      const corpo = {
        tipo: 'ENTREGA', local_origem_id: Number(origem), militar_id: efetivo.id, motivo_codigo: motivo,
        ...(motivoTexto.trim() ? { justificativa: motivoTexto.trim() } : {}),
        ...(consumo ? {} : { previsao_retorno: retorno }),
        ...(doc ? { documento_codigo: doc } : {}),
        ...(comNumero && docNumero.trim() ? { documento_numero: docNumero.trim() } : {}),
        itens: [...[...itensSel.keys()].map((id) => ({ item_individual_id: id })), ...linhasLote.map((l) => ({ lote_id: l.lote.lote!.id, quantidade: l.q }))],
      }
      const r = await api<MovDetalhe>('/movements', { method: 'POST', body: corpo, headers: { 'Idempotency-Key': chave.current } })
      notificar('ok', `Entrega #${r.id} registrada para ${efetivo.nome_guerra}.`)
      nav(consumo ? `/movimentacoes/${r.id}` : `/entregas?militar_id=${efetivo.id}`)
    } catch (e) {
      if (e instanceof ErroApi) { setErro(e); setRevisar(false); if (e.status !== 409) chave.current = novaChave() }
    } finally { setEnviando(false) }
  }

  const trilha = [{ rotulo: 'Início', para: '/' }, { rotulo: 'Entrega de material', para: '/entregas' }, { rotulo: 'Registrar entrega' }]
  if (locais.carregando || opcoes.carregando) return <Pagina titulo="Registrar entrega" trilha={trilha}><Carregando /></Pagina>

  return (
    <Pagina icone={HandHelping} titulo="Registrar entrega" trilha={trilha}
      descricao="Material entregue a um efetivo. Com previsão de retorno fica pendente até a devolução; sem data é consumo e sai do estoque.">
      {bloqueada && <Aviso tipo="alerta">A operação de entrega está desligada no momento.</Aviso>}
      {erro && <Aviso tipo="perigo" titulo="Não foi possível registrar.">{erro.erro.code === 'ACESSO_NEGADO' ? 'Você não tem permissão de entrega neste local ou para este efetivo.' : erro.erro.message}</Aviso>}
      <form noValidate onSubmit={(e) => { e.preventDefault(); if (pronto) setRevisar(true) }}>
        <fieldset className="bloco">
          <legend>1. Quem recebe</legend>
          <BuscaEfetivo obrigatorio valor={efetivo} onEscolher={setEfetivo} />
          {erro?.erro.fields?.militar_id && <p className="msg-erro">{erro.erro.fields.militar_id.join(' ')}</p>}
        </fieldset>

        <fieldset className="bloco">
          <legend>2. De onde sai e o que vai</legend>
          <div className="grade">
            <Campo rotulo="Local de origem" obrigatorio erro={erro?.erro.fields?.local_origem_id}>{(p) => (
              <select {...p} value={origem} onChange={(e) => escolherOrigem(e.target.value)}>
                <option value="">Selecione…</option>{locais.dados?.data.map((l) => <option key={l.id} value={l.id}>{l.codigo} — {l.nome} ({l.unidade.sigla}){l.id === padrao ? ' · padrão da base' : ''}</option>)}
              </select>)}</Campo>
            {origem && <div role="search" className="campo">
              <label htmlFor="busca-material">Buscar material</label>
              <div className="acoes-linha"><input id="busca-material" type="search" placeholder="Material, nº de série ou lote" value={termo} className="estica"
                onChange={(e) => setTermo(e.target.value)} onKeyDown={(e) => { if (e.key === 'Enter') { e.preventDefault(); setBusca(termo.trim()) } }} />
                <button type="button" className="btn btn-sec" onClick={() => setBusca(termo.trim())}>Buscar</button></div>
            </div>}
          </div>
          {efetivo && !apto && <Aviso tipo="alerta">{efetivo.nome_guerra} ({tipoEfetivo}) não pode receber arma de fogo nem munição: esses itens aparecem bloqueados.</Aviso>}
          {!origem ? <p className="dica">Escolha o local de origem para ver o que está disponível.</p>
            : estoque.carregando ? <Carregando />
            : !estoque.dados?.itens.length && !lotes.length ? <Vazio titulo="Nada disponível neste local com esta busca." />
            : <div className="tabela-wrap"><table className="tabela">
              <caption className="sr-only">Material disponível no local de origem</caption>
              <thead><tr><th scope="col">Material</th><th scope="col">Nº de série / lote</th><th scope="col">Disponível</th><th scope="col">Entregar</th></tr></thead>
              <tbody>
                {estoque.dados?.itens.map((i) => {
                  const vencido = i.validade_estado === 'VENCIDO'
                  const inservivel = i.estado_conservacao === 'INSERVIVEL'
                  const bloq = bloqueadoLetal(i.material.natureza) || vencido || inservivel
                  return (
                    <tr key={`i${i.id}`} className={bloq ? 'linha-bloqueada' : undefined}>
                      <td data-rotulo="Material">{i.material.nome}<br /><small>{rotuloNatureza(i.material.natureza)}{letal(i.material.natureza) && ' · letal'}</small></td>
                      <td data-rotulo="Nº de série / lote"><RotuloItem serie={i.numero_serie} codigo={i.identificador} />
                        {vencido && <><br /><span className="selo selo-perigo">Vencido</span></>}{inservivel && <><br /><span className="selo selo-perigo">Inservível</span></>}</td>
                      <td data-rotulo="Disponível">1</td>
                      <td data-rotulo="Entregar">{bloqueadoLetal(i.material.natureza) ? <span className="selo selo-alerta">Não pode para {tipoEfetivo}</span>
                        : <input type="checkbox" disabled={vencido || inservivel} aria-label={`Entregar ${textoItem(i.numero_serie, i.identificador)}`} checked={itensSel.has(i.id)} onChange={(e) => marcarItem(i, e.target.checked)} />}</td>
                    </tr>)
                })}
                {lotes.map((s) => {
                  const vencido = !!s.lote!.validade && s.lote!.validade < hoje
                  const q = qtds[s.lote!.id] ?? ''
                  return (
                    <tr key={`l${s.lote!.id}`} className={bloqueadoLetal(s.material.natureza) || vencido ? 'linha-bloqueada' : undefined}>
                      <td data-rotulo="Material">{s.material.nome}<br /><small>{rotuloNatureza(s.material.natureza)}{letal(s.material.natureza) && ' · letal'}</small></td>
                      <td data-rotulo="Nº de série / lote"><RotuloItem lote={s.lote!.codigo} />{s.lote!.validade && <><br /><small>Val. {data(s.lote!.validade)}</small></>}{vencido && <> <span className="selo selo-perigo">Vencido</span></>}</td>
                      <td data-rotulo="Disponível">{numero(s.disponivel)} {s.material.unidade_medida}</td>
                      <td data-rotulo="Entregar">{bloqueadoLetal(s.material.natureza) ? <span className="selo selo-alerta">Não pode para {tipoEfetivo}</span>
                        : <input type="number" min={0} max={s.disponivel} step={1} inputMode="numeric" className="qtd" disabled={vencido} aria-label={`Quantidade do lote ${s.lote!.codigo}`}
                          aria-invalid={Number(q) > s.disponivel || undefined} value={q} onChange={(e) => qtdLote(s, e.target.value)} />}</td>
                    </tr>)
                })}
              </tbody>
            </table></div>}
          {excedidos.length > 0 && <p className="msg-erro" role="alert">Quantidade acima do disponível em {excedidos.map((l) => l.lote.lote!.codigo).join(', ')}.</p>}
          {conflitoLetal && <Aviso tipo="perigo" titulo="Arma de fogo/munição para quem não pode:">{letaisEscolhidos.join(', ')}. Retire esses itens ou escolha outro efetivo.</Aviso>}
          {total > 0 && <p role="status">{total} linha(s) selecionada(s){itensSel.size ? ` · ${itensSel.size} item(ns) individual(is)` : ''}{linhasLote.length ? ` · ${linhasLote.length} lote(s)` : ''}.</p>}
        </fieldset>

        <fieldset className="bloco">
          <legend>3. Retorno e registro</legend>
          <div className="grade grade-3">
            <div className="campo-grupo">
              <Campo rotulo="Previsão de retorno" obrigatorio={!consumo} dica={consumo ? 'Sem retorno: o material sai do estoque (consumo).' : 'Até esta data fica pendente com o efetivo; depois aparece como atrasado.'} erro={erro?.erro.fields?.previsao_retorno}>{(p) =>
                <input {...p} type="date" min={hoje} value={consumo ? '' : retorno} disabled={consumo} onChange={(e) => setRetorno(e.target.value)} />}</Campo>
              <label className="opcao opcao-abaixo"><input type="checkbox" checked={consumo} onChange={(e) => setConsumo(e.target.checked)} /> Sem retorno (consumo)</label>
            </div>
            <div className="campo-grupo">
              <Campo rotulo="Documento de referência" dica={docSel?.descricao ?? 'Escolha o documento que ampara a entrega.'} erro={erro?.erro.fields?.documento_codigo ?? erro?.erro.fields?.documento_referencia}>{(p) => (
                <select {...p} value={doc} onChange={(e) => setDoc(e.target.value)}>
                  <option value="">Selecione…</option>
                  {opcoes.dados?.documentos.map((d) => <option key={d.codigo} value={d.codigo}>{d.rotulo} — {d.descricao}</option>)}
                </select>)}</Campo>
              {comNumero && <Campo rotulo="Número ou identificação (opcional)" erro={erro?.erro.fields?.documento_numero}>{(p) =>
                <input {...p} value={docNumero} maxLength={60} placeholder="Ex.: 45/2026" onChange={(e) => setDocNumero(e.target.value)} />}</Campo>}
            </div>
            <div className="campo-grupo">
              <Campo rotulo="Motivo" obrigatorio dica={motivoSel?.descricao ?? 'Por que o material está sendo entregue.'} erro={erro?.erro.fields?.motivo_codigo}>{(p) => (
                <select {...p} value={motivo} onChange={(e) => setMotivo(e.target.value)}>
                  <option value="">Selecione…</option>
                  {opcoes.dados?.motivos.map((m) => <option key={m.codigo} value={m.codigo}>{m.rotulo}{m.codigo === 'OUTRO' ? ' — descreva' : ` — ${m.descricao}`}</option>)}
                </select>)}</Campo>
              {motivo && <Campo rotulo={outro ? 'Descreva o motivo' : 'Observação (opcional)'} obrigatorio={outro} dica={outro ? 'Mínimo de 5 caracteres.' : undefined} erro={erro?.erro.fields?.justificativa}>{(p) =>
                <input {...p} value={motivoTexto} maxLength={450} onChange={(e) => setMotivoTexto(e.target.value)} />}</Campo>}
            </div>
          </div>
        </fieldset>
        <div className="barra-acoes">
          <button type="button" className="btn btn-sec" onClick={() => nav(-1)}>Cancelar</button>
          <button className="btn btn-pri" disabled={!pronto}>Revisar entrega</button>
        </div>
      </form>

      <Dialogo tour="confirmar-entrega" aberto={revisar} titulo="Confirmar entrega" onFechar={() => setRevisar(false)}
        rodape={<><button type="button" className="btn btn-sec" onClick={() => setRevisar(false)}>Voltar e corrigir</button><button type="button" className="btn btn-pri" disabled={enviando} onClick={enviar}>{enviando ? 'Registrando…' : 'Confirmar entrega'}</button></>}>
        <p>Para: <strong>{efetivo?.posto_graduacao.sigla} {efetivo?.nome_guerra}</strong> · Origem: <strong>{locais.dados?.data.find((l) => String(l.id) === origem)?.codigo}</strong></p>
        <ul className="lista-simples">
          {[...itensSel.values()].map((i) => <li key={`i${i.id}`}>{i.material.nome} — {textoItem(i.numero_serie, i.identificador)}</li>)}
          {linhasLote.map((l) => <li key={`l${l.lote.lote!.id}`}>{l.lote.material.nome} — lote {l.lote.lote!.codigo}: {numero(l.q)}</li>)}
        </ul>
        <p>{consumo ? 'Sem retorno: consumo, sai do estoque.' : `Retorno previsto: ${data(retorno)}.`}</p>
        <p>Documento: <strong>{docSel && doc !== 'SEM_DOCUMENTO' ? `${docSel.rotulo}${docNumero.trim() ? ' nº ' + docNumero.trim() : ''}` : 'sem documento'}</strong> · Motivo: <strong>{outro ? motivoTexto.trim() : motivoSel?.rotulo}</strong></p>
        <Aviso tipo="alerta">O registro não pode ser editado depois; correções são feitas por devolução.</Aviso>
      </Dialogo>
    </Pagina>
  )
}
