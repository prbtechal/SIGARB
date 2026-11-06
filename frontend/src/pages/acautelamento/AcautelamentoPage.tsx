import { useState } from 'react'
import { Link, useParams } from 'react-router'
import { ShieldHalf } from 'lucide-react'
import { ErroApi, api } from '../../api/client'
import type { AcautDetalhe } from '../../api/tipos'
import { Aviso, Campo, Carregando, Dialogo, Erro, Pagina, Selo, data, dataHora, useNotificar } from '../../components/ui'
import { useRecurso } from '../../components/useRecurso'

const ULID = /^[0-9A-HJKMNP-TV-Z]{26}$/
type Acao = 'rejeitar' | 'cancelar' | 'inspecionar' | 'desacautelar' | null

export default function AcautelamentoPage() {
  const { codigo = '' } = useParams()
  const notificar = useNotificar()
  const valido = ULID.test(codigo)
  const { dados: a, erro, carregando, recarregar } = useRecurso(() => valido ? api<AcautDetalhe>(`/acautelamentos/${encodeURIComponent(codigo)}`) : Promise.reject({ code: 'NAO_ENCONTRADO', message: 'Acautelamento não encontrado.' }), [codigo])
  const [acao, setAcao] = useState<Acao>(null)
  const [form, setForm] = useState({ texto: '', resultado: 'CONFORME', conservacao: 'BOM' })
  const [ocupado, setOcupado] = useState(false)
  const [erroAcao, setErroAcao] = useState<ErroApi | null>(null)

  const enviar = async (rota: string, corpo: Record<string, unknown>, ok: string) => {
    if (!a) return
    setOcupado(true); setErroAcao(null)
    try {
      await api(`/acautelamentos/${encodeURIComponent(a.codigo)}/${rota}`, { method: 'POST', body: { ...corpo, versao: a.versao } })
      notificar('ok', ok); setAcao(null); setForm({ texto: '', resultado: 'CONFORME', conservacao: 'BOM' }); recarregar()
    } catch (e) {
      if (e instanceof ErroApi) { setErroAcao(e); if (e.erro.code === 'VERSAO_DESATUALIZADA') recarregar() }
    } finally { setOcupado(false) }
  }

  const trilha = [{ rotulo: 'Início', para: '/' }, { rotulo: 'Acautelamentos', para: '/acautelamentos' }, { rotulo: 'Detalhe' }]
  if (carregando) return <Pagina titulo="Acautelamento" trilha={trilha}><Carregando /></Pagina>
  if (erro || !a) return <Pagina titulo="Acautelamento" trilha={trilha}>{erro && <Erro erro={erro} />}</Pagina>

  const passos = [
    { feito: true, titulo: 'Solicitado', texto: `${a.solicitante.nome} em ${dataHora(a.criado_em)}. ${a.justificativa}` },
    { feito: !!a.decidido_em, titulo: a.estado === 'REJEITADO' ? 'Rejeitado' : a.estado === 'CANCELADO' && !a.decidido_em ? 'Cancelado' : 'Decisão',
      texto: a.decidido_em ? `${a.aprovador?.nome} em ${dataHora(a.decidido_em)}${a.decisao_justificativa ? '. ' + a.decisao_justificativa : ''}` : a.estado === 'CANCELADO' ? a.decisao_justificativa ?? '' : 'Aguardando aprovador (não pode ser quem pediu).' },
    { feito: !!a.entrega_id, titulo: 'Entrega', texto: a.entrega_id ? <Link to={`/movimentacoes/${a.entrega_id}`}>Movimentação #{a.entrega_id}</Link> : 'Após a aprovação.' },
    ...a.inspecoes.map((i) => ({ feito: true, titulo: `Inspeção ${i.resultado === 'CONFORME' ? 'conforme' : 'não conforme'}`, texto: `${i.inspetor} em ${dataHora(i.realizada_em)}${i.observacao ? '. ' + i.observacao : ''}` })),
    { feito: !!a.devolucao_id, titulo: 'Desacautelamento', texto: a.devolucao_id ? <Link to={`/movimentacoes/${a.devolucao_id}`}>Movimentação #{a.devolucao_id}</Link> : a.estado === 'ATIVO' ? `Próxima inspeção: ${data(a.proxima_inspecao)}` : '—' },
  ]

  const dialogo = {
    rejeitar: { titulo: 'Rejeitar solicitação', campo: 'Justificativa da rejeição', go: () => enviar('decision', { decisao: 'REJEITAR', justificativa: form.texto }, 'Solicitação rejeitada.') },
    cancelar: { titulo: 'Cancelar acautelamento', campo: 'Motivo do cancelamento', go: () => enviar('cancel', { justificativa: form.texto }, 'Acautelamento cancelado.') },
    inspecionar: { titulo: 'Registrar inspeção', campo: 'Observação', go: () => enviar('inspections', { resultado: form.resultado, observacao: form.texto || null }, 'Inspeção registrada.') },
    desacautelar: { titulo: 'Desacautelar', campo: 'Divergências encontradas (se houver)', go: () => enviar('return', { estado_conservacao: form.conservacao, divergencias: form.texto || null }, 'Item desacautelado e devolvido ao estoque.') },
  }
  const textoObrigatorio = acao === 'rejeitar' || acao === 'cancelar' || (acao === 'inspecionar' && form.resultado === 'NAO_CONFORME')

  return (
    <Pagina icone={ShieldHalf} titulo={`Acautelamento — ${a.efetivo.posto ?? ''} ${a.efetivo.nome_guerra}`} trilha={trilha}
      descricao={<>Item <span className="mono">{a.item.identificador}</span> · {a.item.material} · {a.unidade.sigla}</>}
      acoes={<Selo valor={a.estado} tom={a.estado === 'ATIVO' ? 'ok' : a.estado === 'SOLICITADO' ? 'alerta' : undefined} />}>
      {a.inspecao_atrasada && <Aviso tipo="perigo" titulo="Inspeção atrasada:">venceu em {data(a.proxima_inspecao)}.</Aviso>}
      {erroAcao && <div className="aviso aviso-perigo" role="alert"><strong>Não foi possível concluir. </strong>{erroAcao.erro.message}</div>}

      <section className="bloco" aria-labelledby="fluxo-t">
        <h2 id="fluxo-t">Fluxo</h2>
        <ol className="fluxo">
          {passos.map((p, n) => <li key={n} className={p.feito ? 'feito' : undefined}><strong>{p.titulo}</strong><span>{p.texto}</span></li>)}
        </ol>
      </section>

      <div className="barra-acoes">
        {a.acoes.cancelar && <button className="btn btn-sec" onClick={() => setAcao('cancelar')}>Cancelar</button>}
        {a.acoes.decidir && <button className="btn btn-sec" onClick={() => setAcao('rejeitar')}>Rejeitar</button>}
        {a.acoes.decidir && <button className="btn btn-pri" disabled={ocupado} onClick={() => enviar('decision', { decisao: 'APROVAR' }, 'Acautelamento aprovado.')}>Aprovar</button>}
        {a.acoes.entregar && <button className="btn btn-pri" disabled={ocupado} onClick={() => enviar('delivery', {}, 'Item entregue. Carteira disponível.')}>Registrar entrega</button>}
        {a.acoes.carteira && <Link className="btn btn-sec" to={`/acautelamentos/${a.codigo}/carteira`}>Carteira</Link>}
        {a.acoes.inspecionar && <button className="btn btn-sec" onClick={() => setAcao('inspecionar')}>Registrar inspeção</button>}
        {a.acoes.desacautelar && <button className="btn btn-pri" onClick={() => setAcao('desacautelar')}>Desacautelar</button>}
      </div>

      <Dialogo aberto={!!acao} titulo={acao ? dialogo[acao].titulo : ''} onFechar={() => setAcao(null)}
        rodape={<><button className="btn btn-sec" onClick={() => setAcao(null)}>Voltar</button>
          <button className="btn btn-pri" disabled={ocupado || (textoObrigatorio && form.texto.trim().length < 5)} onClick={() => acao && dialogo[acao].go()}>{ocupado ? 'Enviando…' : 'Confirmar'}</button></>}>
        {acao === 'inspecionar' && <Campo rotulo="Resultado" obrigatorio>{(p) => <select {...p} value={form.resultado} onChange={(e) => setForm({ ...form, resultado: e.target.value })}><option value="CONFORME">Conforme</option><option value="NAO_CONFORME">Não conforme</option></select>}</Campo>}
        {acao === 'desacautelar' && <Campo rotulo="Condição do item" obrigatorio>{(p) => <select {...p} value={form.conservacao} onChange={(e) => setForm({ ...form, conservacao: e.target.value })}>{['NOVO', 'BOM', 'REGULAR', 'RUIM'].map((c) => <option key={c} value={c}>{c[0] + c.slice(1).toLowerCase()}</option>)}</select>}</Campo>}
        {acao && <Campo rotulo={dialogo[acao].campo} obrigatorio={textoObrigatorio} dica={textoObrigatorio ? 'Mínimo de 5 caracteres.' : undefined}>{(p) => <textarea {...p} rows={3} maxLength={acao === 'desacautelar' ? 400 : 500} value={form.texto} onChange={(e) => setForm({ ...form, texto: e.target.value })} />}</Campo>}
      </Dialogo>
    </Pagina>
  )
}
