import { useEffect, useState } from 'react'
import { Link, useParams } from 'react-router'
import { ClipboardList } from 'lucide-react'
import { ErroApi, api } from '../../api/client'
import { Aviso, Campo, Carregando, Dialogo, Erro, Pagina, Selo, dataHora, useNotificar } from '../../components/ui'
import { useRecurso } from '../../components/useRecurso'
import { ESTADOS_INV, type InvResumo } from './InventariosPage'

type Inv = InvResumo & { aberto_por: string; contado_por: string | null; decidido_por: string | null; decidido_em: string | null; observacao: string | null
  ajuste_falta_id: number | null; ajuste_sobra_id: number | null
  itens: { id: number; tipo: 'LOTE' | 'INDIVIDUAL'; esperado: number; contado: number | null; material: { nome: string }; identificacao: string }[]
  acoes: { contar: boolean; cancelar: boolean; decidir: boolean } }

export default function InventarioPage() {
  const { codigo = '' } = useParams()
  const notificar = useNotificar()
  const valido = /^[0-9A-HJKMNP-TV-Z]{26}$/.test(codigo)
  const { dados: inv, erro, carregando, recarregar } = useRecurso(() => valido ? api<Inv>(`/inventories/${encodeURIComponent(codigo)}`) : Promise.reject({ code: 'NAO_ENCONTRADO', message: 'Inventário não encontrado.' }), [codigo])
  const [cont, setCont] = useState<Record<number, string>>({})
  const [ocupado, setOcupado] = useState(false)
  const [erroAcao, setErroAcao] = useState<ErroApi | null>(null)
  const [dlg, setDlg] = useState<'rejeitar' | 'cancelar' | null>(null)
  const [texto, setTexto] = useState('')
  useEffect(() => { if (inv) setCont(Object.fromEntries(inv.itens.map((l) => [l.id, l.contado === null ? '' : String(l.contado)]))) }, [inv])

  const chamar = async (metodo: 'POST' | 'PUT', rota: string, corpo: Record<string, unknown>, ok: string) => {
    if (!inv) return
    setOcupado(true); setErroAcao(null)
    try { await api(`/inventories/${encodeURIComponent(inv.codigo)}/${rota}`, { method: metodo, body: { ...corpo, versao: inv.versao } }); notificar('ok', ok); setDlg(null); setTexto(''); recarregar() }
    catch (e) { if (e instanceof ErroApi) { setErroAcao(e); if (e.erro.code === 'VERSAO_DESATUALIZADA') recarregar() } }
    finally { setOcupado(false) }
  }
  const salvar = (ok = 'Contagem salva.') => chamar('PUT', 'counts', { contagens: inv!.itens.filter((l) => cont[l.id] !== '').map((l) => ({ id: l.id, contado: Number(cont[l.id]) })) }, ok)

  const trilha = [{ rotulo: 'Início', para: '/' }, { rotulo: 'Inventários', para: '/inventarios' }, { rotulo: 'Detalhe' }]
  if (carregando) return <Pagina titulo="Inventário" trilha={trilha}><Carregando /></Pagina>
  if (erro || !inv) return <Pagina titulo="Inventário" trilha={trilha}>{erro && <Erro erro={erro} />}</Pagina>
  const pendentes = inv.itens.filter((l) => cont[l.id] === '' || cont[l.id] === undefined).length
  const alterado = inv.itens.some((l) => (l.contado === null ? '' : String(l.contado)) !== (cont[l.id] ?? ''))

  return (
    <Pagina icone={ClipboardList} titulo={`Inventário — ${inv.local.codigo}`} trilha={trilha}
      descricao={`Aberto por ${inv.aberto_por} em ${dataHora(inv.criado_em)}${inv.contado_por ? ` · contado por ${inv.contado_por}` : ''}${inv.decidido_por ? ` · decidido por ${inv.decidido_por}` : ''}`}
      acoes={<Selo valor={ESTADOS_INV[inv.estado]} tom={inv.estado === 'AGUARDANDO_APROVACAO' ? 'alerta' : inv.estado === 'AJUSTADO' ? 'ok' : 'neutro'} />}>
      <Aviso tipo="info">Lotes divergentes são ajustados na aprovação. Itens individuais divergentes ficam registrados para apuração e baixa, sem ajuste automático.</Aviso>
      {inv.observacao && <Aviso tipo="info" titulo="Observação:">{inv.observacao}</Aviso>}
      {(inv.ajuste_falta_id || inv.ajuste_sobra_id) && <Aviso tipo="ok" titulo="Ajuste aplicado:">{inv.ajuste_falta_id && <Link to={`/movimentacoes/${inv.ajuste_falta_id}`}>falta #{inv.ajuste_falta_id}</Link>} {inv.ajuste_sobra_id && <Link to={`/movimentacoes/${inv.ajuste_sobra_id}`}>sobra #{inv.ajuste_sobra_id}</Link>}</Aviso>}
      {erroAcao && <div className="aviso aviso-perigo" role="alert"><strong>Não foi possível concluir. </strong>{erroAcao.erro.message}</div>}

      <div className="tabela-wrap"><table className="tabela">
        <caption className="sr-only">Linhas do inventário</caption>
        <thead><tr><th scope="col">Material</th><th scope="col">Identificação</th><th scope="col" className="num">Esperado</th><th scope="col">Contado</th><th scope="col" className="num">Diferença</th></tr></thead>
        <tbody>{inv.itens.map((l) => {
          const c = cont[l.id] === '' || cont[l.id] === undefined ? null : Number(cont[l.id])
          const dif = c === null ? null : c - l.esperado
          return (
            <tr key={l.id}>
              <td data-rotulo="Material">{l.material.nome}</td>
              <td data-rotulo="Identificação" className="mono">{l.identificacao}</td>
              <td data-rotulo="Esperado" className="num">{l.esperado}</td>
              <td data-rotulo="Contado">{inv.acoes.contar
                ? <input type="number" min={0} max={l.tipo === 'INDIVIDUAL' ? 1 : undefined} inputMode="numeric" className="qtd" aria-label={`Contado de ${l.identificacao}`} value={cont[l.id] ?? ''} onChange={(e) => setCont({ ...cont, [l.id]: e.target.value })} />
                : l.contado ?? '—'}</td>
              <td data-rotulo="Diferença" className="num">{dif === null ? '—' : dif === 0 ? '0' : <strong className={dif < 0 ? 'txt-perigo' : 'txt-alerta'}>{dif > 0 ? '+' : ''}{dif}</strong>}</td>
            </tr>)
        })}</tbody>
      </table></div>

      <div className="barra-acoes">
        {inv.acoes.cancelar && <button className="btn btn-sec" onClick={() => setDlg('cancelar')}>Cancelar inventário</button>}
        {inv.acoes.contar && <button className="btn btn-sec" disabled={ocupado || !alterado} onClick={() => salvar()}>Salvar contagem</button>}
        {inv.acoes.contar && <button className="btn btn-pri" disabled={ocupado || pendentes > 0 || alterado} title={alterado ? 'Salve a contagem antes de enviar' : undefined}
          onClick={() => chamar('POST', 'submit', {}, 'Contagem enviada para aprovação.')}>Enviar para aprovação{pendentes > 0 ? ` (${pendentes} sem contagem)` : ''}</button>}
        {inv.acoes.decidir && <button className="btn btn-sec" onClick={() => setDlg('rejeitar')}>Rejeitar ajuste</button>}
        {inv.acoes.decidir && <button className="btn btn-pri" disabled={ocupado} onClick={() => chamar('POST', 'decision', { decisao: 'APROVAR' }, 'Ajuste aprovado e aplicado.')}>Aprovar ajuste</button>}
      </div>

      <Dialogo aberto={!!dlg} titulo={dlg === 'rejeitar' ? 'Rejeitar ajuste' : 'Cancelar inventário'} onFechar={() => setDlg(null)}
        rodape={<><button className="btn btn-sec" onClick={() => setDlg(null)}>Voltar</button><button className="btn btn-pri" disabled={ocupado || texto.trim().length < 5}
          onClick={() => dlg === 'rejeitar' ? chamar('POST', 'decision', { decisao: 'REJEITAR', observacao: texto }, 'Ajuste rejeitado.') : chamar('POST', 'cancel', { motivo: texto }, 'Inventário cancelado.')}>Confirmar</button></>}>
        <Campo rotulo="Justificativa" obrigatorio dica="Mínimo de 5 caracteres.">{(p) => <textarea {...p} rows={3} maxLength={500} value={texto} onChange={(e) => setTexto(e.target.value)} />}</Campo>
      </Dialogo>
    </Pagina>
  )
}
