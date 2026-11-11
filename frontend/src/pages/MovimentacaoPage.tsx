import { useRef, useState } from 'react'
import { useAuth } from '../auth/AuthContext'
import type { DocResumo } from '../api/tipos'
import type { Pagina as Pag } from '../api/client'
import { Link, useParams } from 'react-router'
import { ErroApi, api, novaChave } from '../api/client'
import type { MovDetalhe } from '../api/tipos'
import { Aviso, Campo, Carregando, Dialogo, Erro, Pagina, Selo, Tabela, data, dataHora, numero, useNotificar } from '../components/ui'
import { useRecurso } from '../components/useRecurso'

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
    <Pagina titulo={`Movimentação #${id}`} trilha={[{ rotulo: 'Início', para: '/' }, { rotulo: 'Movimentações', para: '/movimentacoes' }, { rotulo: `#${id}` }]}
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
          <div><dt>Justificativa</dt><dd>{m.justificativa ?? '—'}</dd></div>
        </dl>
        <h2>Itens</h2>
        <Tabela legenda="Itens da movimentação" linhas={m.itens} chave={(i) => `${i.item?.id ?? 'l' + i.lote?.id}`} colunas={[
          { titulo: 'Material', celula: (i) => <Link to={`/estoque/materiais/${i.material.id}`}>{i.material.codigo} — {i.material.nome}</Link> },
          { titulo: 'Identificação', celula: (i) => i.item ? <>{i.item.identificador}{i.item.numero_serie && <small> · Série {i.item.numero_serie}</small>}</> : <>Lote {i.lote?.codigo} <small>· Val. {data(i.lote?.validade ?? null)}</small></> },
          { titulo: 'Situação atual', celula: (i) => i.item ? <Selo valor={i.item.situacao} /> : '—' },
          { titulo: 'Quantidade', num: true, celula: (i) => numero(i.quantidade) },
        ]} />
        {pode('documentos.ver') && <section aria-labelledby="docs-mov">
          <h2 id="docs-mov">Documentos vinculados</h2>
          {docs.dados && (docs.dados.data.length === 0 ? <p>Nenhum documento vinculado.</p> : (
            <ul className="lista-simples">{docs.dados.data.map((dc) => <li key={dc.codigo}><Link to={`/documentos/${dc.codigo}`}>{dc.titulo}</Link> · {dc.tipo.nome}</li>)}</ul>
          ))}
          {pode('documentos.enviar') && <Link className="btn btn-sec" to={`/documentos/enviar?movimentacao=${id}`}>Anexar documento</Link>}
        </section>}
      </>}
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
