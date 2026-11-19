import { AnexarDialogo } from '../components/Anexo'
import { useRef, useState } from 'react'
import { useAuth } from '../auth/AuthContext'
import type { DocResumo } from '../api/tipos'
import type { Pagina as Pag } from '../api/client'
import { Link, useParams } from 'react-router'
import { ErroApi, api, novaChave } from '../api/client'
import type { MovDetalhe } from '../api/tipos'
import { Aviso, Campo, Carregando, Dialogo, Erro, Pagina, Selo, Tabela, data, dataHora, numero, rotulo, useNotificar } from '../components/ui'
import { BaixaDialogo } from '../components/estoque/BaixaDialogo'
import { ESTADOS_PROBLEMA } from '../components/estoque/DevolucaoDialogo'
import { useRecurso } from '../components/useRecurso'
import { RotuloItem, textoItem } from '../components/RotuloItem'
import { rotuloEfetivo } from '../components/efetivo'
import { textoSituacaoLote, tomSituacaoLote } from '../components/estoque/situacaoLote'
import { SeloSituacaoNaEntrega } from '../components/estoque/SituacaoNaEntrega'

export default function MovimentacaoPage() {
  const { id } = useParams()
  const notificar = useNotificar()
  const { dados: m, erro, carregando, recarregar } = useRecurso(() => api<MovDetalhe>(`/movements/${id}`), [id])
  const [aberto, setAberto] = useState(false)
  const [just, setJust] = useState('')
  const [erroEst, setErroEst] = useState<ErroApi | null>(null)
  const [enviando, setEnviando] = useState(false)
  const chave = useRef(novaChave())
  const { pode } = useAuth()
  const [baixa, setBaixa] = useState(false)
  const [anexar, setAnexar] = useState(false)
  // Devolução com item danificado/inservível que continua disponível: oferece a baixa (fluxo "devolveu com problema → baixa").
  const naEntrega = m?.itens.some((i) => i.situacao_na_entrega) ?? false
  const problemas = m?.tipo === 'DEVOLUCAO' ? m.itens.filter((i) => i.item && ESTADOS_PROBLEMA.includes(i.estado_conservacao ?? '') && i.item.situacao === 'DISPONIVEL') : []
  const docs = useRecurso(() => (pode('documentos.ver') ? api<Pag<DocResumo>>('/documents', { query: { movimentacao_id: id, situacao: '' } }) : Promise.resolve(null)), [id]) // mesma chave em reenvios do mesmo diálogo → idempotente

  const estornar = async () => {
    setEnviando(true); setErroEst(null)
    try {
      const r = await api<MovDetalhe>(`/movements/${id}/reversal`, { method: 'POST', body: { justificativa: just }, headers: { 'Idempotency-Key': chave.current } })
      setAberto(false); setJust(''); chave.current = novaChave()
      notificar('ok', `Estorno #${r.id} registrado.`)
      recarregar()
    } catch (e) { if (e instanceof ErroApi) setErroEst(e) } finally { setEnviando(false) }
  }

  return (
    <Pagina titulo={`Movimentação #${id}`} trilha={[{ rotulo: 'Início', para: '/' }, pode('movimentacoes.ver') ? { rotulo: 'Movimentações', para: '/movimentacoes' } : { rotulo: 'Entrega de material', para: '/entregas' }, { rotulo: `#${id}` }]}
      acoes={m?.pode_estornar && <button className="btn btn-perigo" onClick={() => setAberto(true)}>Estornar…</button>}>
      {carregando && <Carregando />}
      {erro && <Erro erro={erro} tentar={recarregar} />}
      {m && <>
        {m.estornada && <Aviso tipo="alerta">Esta movimentação foi estornada pela <Link to={`/movimentacoes/${m.estorno_id}`}>#{m.estorno_id}</Link>. O registro original é preservado.</Aviso>}
        {m.estorno_de_id && <Aviso tipo="info">Estorno da movimentação <Link to={`/movimentacoes/${m.estorno_de_id}`}>#{m.estorno_de_id}</Link>.</Aviso>}
        <dl className="detalhes">
          <div><dt>Tipo</dt><dd><Selo valor={m.tipo} /></dd></div>
          <div><dt>Data/hora</dt><dd>{dataHora(m.ocorrido_em)}</dd></div>
          <div><dt>Responsável</dt><dd>{m.responsavel.nome}</dd></div>
          <div><dt>Local</dt><dd>{(m.local_destino ?? m.local_origem)?.codigo} — {(m.local_destino ?? m.local_origem)?.nome}</dd></div>
          <div><dt>Origem</dt><dd>{m.origem_descricao ?? '—'}</dd></div>
          <div><dt>Documento de referência</dt><dd>{m.documento_referencia ?? '—'}</dd></div>
          <div><dt>{m.tipo === 'DEVOLUCAO' || m.tipo === 'AJUSTE' ? 'Observação / motivo' : 'Justificativa'}</dt><dd>{m.justificativa ?? '—'}</dd></div>
          {m.militar && <div><dt>Efetivo</dt><dd>{pode('militares.ver') ? <Link to={`/militares/${m.militar.id}`}>{rotuloEfetivo(m.militar)}</Link> : rotuloEfetivo(m.militar)}</dd></div>}
          {m.previsao_retorno && <div><dt>Previsão de retorno</dt><dd>{data(m.previsao_retorno)}</dd></div>}
          {m.devolvido_por && <div><dt>Devolvido por</dt><dd>{m.devolvido_por.militar ? rotuloEfetivo(m.devolvido_por.militar) : m.devolvido_por.nome ?? '—'}{!m.devolvido_por.militar && m.devolvido_por.nome && ' (sem cadastro)'}</dd></div>}
          {m.entrega_id && <div><dt>Entrega de origem</dt><dd><Link to={`/movimentacoes/${m.entrega_id}`}>#{m.entrega_id}</Link></dd></div>}
        </dl>
        {problemas.length > 0 && pode('estoque.baixar') && <Aviso tipo="alerta" titulo="Devolvido com problema:">{problemas.map((i) => textoItem(i.item?.numero_serie, i.item?.identificador)).join(', ')} ainda no estoque.{' '}
          <button type="button" className="btn btn-sec-perigo btn-peq" onClick={() => setBaixa(true)}>Registrar baixa</button></Aviso>}
        <h2>Itens</h2>
        <Tabela legenda="Itens da movimentação" linhas={m.itens} chave={(i) => `${i.item?.id ?? 'l' + i.lote?.id}`} colunas={[
          { titulo: 'Material', celula: (i) => <Link to={`/estoque/materiais/${i.material.id}`}>{i.material.nome}</Link> },
          { titulo: 'Identificação', celula: (i) => i.item ? <RotuloItem serie={i.item.numero_serie} codigo={i.item.identificador} /> : <>Lote {i.lote?.codigo} <small>· Val. {data(i.lote?.validade ?? null)}</small></> },
          // Entrega/devolução: situação RELATIVA À ENTREGA, igual para item e lote, calculada no servidor (decisão 2026-11-18).
          { titulo: naEntrega ? 'Situação na entrega' : 'Situação atual', celula: (i) => i.situacao_na_entrega ? <SeloSituacaoNaEntrega s={i.situacao_na_entrega} />
            : i.item ? <Selo valor={i.item.situacao} />
            : i.situacao_lote ? <Selo valor={textoSituacaoLote(i.situacao_lote)} tom={tomSituacaoLote(i.situacao_lote)} /> : '—' },
          ...(m.tipo === 'DEVOLUCAO' ? [{ titulo: 'Estado na devolução', celula: (i: MovDetalhe['itens'][number]) => i.estado_conservacao
            ? <>{rotulo(i.estado_conservacao)}{i.estado_anterior && i.estado_anterior !== i.estado_conservacao && <><br /><small>antes: {rotulo(i.estado_anterior)}</small></>}</> : '—' }] : []),
          { titulo: 'Quantidade', num: true, celula: (i) => numero(i.quantidade) },
        ]} />
        {pode('documentos.ver') && <section aria-labelledby="docs-mov">
          <h2 id="docs-mov">Documentos vinculados</h2>
          {docs.dados && (docs.dados.data.length === 0 ? <p>Nenhum documento vinculado.</p> : (
            <ul className="lista-simples">{docs.dados.data.map((dc) => <li key={dc.codigo}><Link to={`/documentos/${dc.codigo}`}>{dc.titulo}</Link> · {dc.tipo.nome}</li>)}</ul>
          ))}
          {pode('documentos.enviar') && m && <button type="button" className="btn btn-sec" onClick={() => setAnexar(true)}>Anexar documento</button>}
        </section>}
      </>}
      <AnexarDialogo registro={anexar && m ? { id: m.id, tipo: m.tipo, documento_referencia: m.documento_referencia } : null} onFechar={() => setAnexar(false)}
        onSalvo={(d) => { notificar('ok', `Documento “${d.titulo}” anexado.`); docs.recarregar() }} />
      {baixa && m?.local_origem && <BaixaDialogo itens={problemas.map((i) => ({ id: i.item!.id, rotulo: `${textoItem(i.item!.numero_serie, i.item!.identificador)} — ${i.material.nome}`, estado: i.estado_conservacao }))}
        localId={m.local_origem.id} motivoInicial="Devolvido com problema pelo efetivo" onFechar={() => setBaixa(false)} onConcluir={(id) => { setBaixa(false); notificar('ok', `Baixa #${id} registrada.`); recarregar() }} />}
      <Dialogo aberto={aberto} titulo="Estornar movimentação" onFechar={() => { setAberto(false); setErroEst(null) }}
        rodape={<><button className="btn btn-sec" onClick={() => setAberto(false)}>Cancelar</button><button className="btn btn-perigo" disabled={enviando || just.trim().length < 10} onClick={estornar}>{enviando ? 'Estornando…' : 'Confirmar estorno'}</button></>}>
        <p>O estorno cria uma nova movimentação que reverte esta entrada. O registro original permanece no histórico. Esta ação é auditada e não pode ser desfeita.</p>
        {erroEst && <div className="aviso aviso-perigo" role="alert">{erroEst.erro.message}</div>}
        <Campo rotulo="Justificativa" obrigatorio dica="Mínimo de 10 caracteres." erro={erroEst?.erro.fields?.justificativa}>
          {(p) => <textarea {...p} rows={3} value={just} onChange={(e) => setJust(e.target.value)} />}
        </Campo>
      </Dialogo>
    </Pagina>
  )
}
