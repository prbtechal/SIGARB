import { useState } from 'react'
import { ErroApi, api, novaChave } from '../../api/client'
import type { MovDetalhe, PendenteDevolucao } from '../../api/tipos'
import { RotuloItem, textoItem } from '../RotuloItem'
import { agoraMaceio, comFuso, ehRetroativa, TOLERANCIA_MS } from '../quando'
import { Aviso, CONSERVACAO, Campo, Carregando, Dialogo, Erro, Vazio, rotulo, useNotificar } from '../ui'
import { useRecurso } from '../useRecurso'
import { textoSituacaoLote } from './situacaoLote'
import { rotuloEfetivo } from '../efetivo'
import type { ItemBaixa } from './BaixaDialogo'

/** Estados que pedem baixa logo depois da devolução ("devolveu com problema → baixa"). */
export const ESTADOS_PROBLEMA = ['DANIFICADO', 'INSERVIVEL']

/**
 * Receber devolução (decisão do responsável, 2026-11-16): data/hora = agora (outra só para retroativa, com justificativa),
 * estado de cada item (padrão = o atual), total ou parcial e observação. Ao concluir, devolve os itens com problema para o chamador
 * oferecer a baixa. Regra 2026-11-18: só o próprio efetivo da entrega devolve — o diálogo só pede a conferência da identidade.
 */
export function DevolucaoDialogo({ entregaId, foco, onFechar, onConcluir }: {
  entregaId: number; foco?: { item_individual_id?: number | null; lote_id?: number | null }
  onFechar: () => void; onConcluir: (r: { problemas: ItemBaixa[]; localId: number | null; parcial: boolean }) => void
}) {
  const notificar = useNotificar()
  const det = useRecurso(() => api<MovDetalhe>(`/movements/${entregaId}`), [entregaId])
  const p: PendenteDevolucao | null | undefined = det.dados?.pendente_devolucao
  const [marcados, setMarcados] = useState<Record<number, boolean>>({})
  const [estado, setEstado] = useState<Record<number, string>>({})
  const [qtd, setQtd] = useState<Record<number, string>>({})
  const [outraHora, setOutraHora] = useState(false)
  const [quando, setQuando] = useState(agoraMaceio())
  const [obs, setObs] = useState('')
  const [erro, setErro] = useState<ErroApi | null>(null)
  const [enviando, setEnviando] = useState(false)
  const [chave, setChave] = useState(novaChave)
  const militar = det.dados?.militar

  const marcado = (id: number) => marcados[id] ?? true
  const estadoDe = (i: PendenteDevolucao['itens'][number]) => estado[i.item_individual_id] ?? i.estado_conservacao ?? 'BOM'
  const quantidade = (l: PendenteDevolucao['lotes'][number]) => qtd[l.lote_id] ?? String(l.quantidade)
  const itens = p ? [
    ...p.itens.filter((i) => marcado(i.item_individual_id)).map((i) => ({ item_individual_id: i.item_individual_id, estado_conservacao: estadoDe(i) })),
    ...p.lotes.filter((l) => Number(quantidade(l)) > 0).map((l) => ({ lote_id: l.lote_id, quantidade: Number(quantidade(l)) })),
  ] : []
  const total = p ? p.itens.length + p.lotes.length : 0
  const parcial = p ? p.itens.some((i) => !marcado(i.item_individual_id)) || p.lotes.some((l) => Number(quantidade(l)) < l.quantidade) : false
  const lotesInvalidos = p?.lotes.some((l) => { const n = Number(quantidade(l)); return !Number.isInteger(n) || n < 0 || n > l.quantidade }) ?? true
  const retroativa = outraHora && ehRetroativa(quando)
  const invalido = !itens.length || lotesInvalidos || (outraHora && !quando) || (retroativa && !obs.trim())
  const fe = (k: string) => erro?.erro.fields?.[k]

  const enviar = async () => {
    if (!p) return
    setEnviando(true); setErro(null)
    try {
      const corpo = {
        tipo: 'DEVOLUCAO', vinculo_id: entregaId, itens,
        ...(outraHora && quando ? { ocorrido_em: comFuso(quando) } : {}),
        ...(obs.trim() ? { justificativa: obs.trim() } : {}),
      }
      await api('/movements', { method: 'POST', body: corpo, headers: { 'Idempotency-Key': chave } })
      notificar('ok', parcial ? `Devolução parcial registrada; o restante da entrega #${entregaId} continua com o efetivo.` : `Entrega #${entregaId} devolvida por completo.`)
      const problemas = p.itens.filter((i) => marcado(i.item_individual_id) && ESTADOS_PROBLEMA.includes(estadoDe(i)))
        .map((i) => ({ id: i.item_individual_id, rotulo: `${textoItem(i.numero_serie, i.identificador)} — ${i.material.nome}`, estado: estadoDe(i) }))
      onConcluir({ problemas, localId: det.dados?.local_origem?.id ?? null, parcial })
    } catch (e) {
      if (e instanceof ErroApi) { setErro(e); if (e.status !== 409) setChave(novaChave()) }
    } finally { setEnviando(false) }
  }

  return (
    <Dialogo tour="devolucao" aberto titulo={`Receber devolução — entrega #${entregaId}${militar ? ' · ' + rotuloEfetivo(militar) : ''}`} onFechar={onFechar}
      rodape={<><button type="button" className="btn btn-sec" onClick={onFechar}>Cancelar</button>
        <button type="button" className="btn btn-pri" disabled={!p || invalido || enviando} onClick={enviar}>{enviando ? 'Registrando…' : parcial ? 'Registrar devolução parcial' : 'Registrar devolução'}</button></>}>
      {det.carregando && <Carregando />}{det.erro && <Erro erro={det.erro} tentar={det.recarregar} />}
      {det.dados && !p && <Vazio titulo="Esta entrega não tem itens pendentes de devolução." />}
      {p && <>
        {erro && !erro.erro.fields && <Aviso tipo="perigo">{erro.erro.code === 'ACESSO_NEGADO' ? 'Você não tem permissão no local desta entrega.' : erro.erro.message}</Aviso>}
        <div className="devolucao-quando">
          {!outraHora ? <p>Data e hora: <strong>agora</strong> ({quando.split('T')[0].split('-').reverse().join('/')} {quando.split('T')[1]}) ·{' '}
            <button type="button" className="link" onClick={() => setOutraHora(true)}>Registrar com outra data/hora</button></p>
            : <Campo rotulo="Data e hora da devolução" obrigatorio dica="Horário de Maceió. Anterior a agora = devolução retroativa (exige justificativa)." erro={fe('ocorrido_em')}>{(pp) =>
              <input {...pp} type="datetime-local" value={quando} max={agoraMaceio(new Date(Date.now() + TOLERANCIA_MS))} onChange={(e) => setQuando(e.target.value)} />}</Campo>}
        </div>

        <p className="devolucao-quem">Quem está devolvendo: <strong>{militar ? rotuloEfetivo(militar) : 'o efetivo da entrega'}</strong>
          <br /><small className="dica">Só o próprio efetivo que recebeu pode devolver. Confira a identidade antes de registrar.</small></p>

        <p className="dica">Desmarque o que ainda não voltou: o restante continua pendente com o efetivo. Informe o estado de cada item como chegou.</p>
        {p.itens.length > 0 && <fieldset className="campo"><legend>Itens individuais</legend>
          {p.itens.map((i) => (
            <div key={i.item_individual_id} className={`acoes-linha linha-devolucao${foco?.item_individual_id === i.item_individual_id ? ' linha-foco' : ''}`}>
              <label className="opcao"><input type="checkbox" checked={marcado(i.item_individual_id)} onChange={(e) => setMarcados({ ...marcados, [i.item_individual_id]: e.target.checked })} /> <RotuloItem serie={i.numero_serie} codigo={i.identificador} /> — {i.material.nome}</label>
              <label>Estado na devolução <select value={estadoDe(i)} disabled={!marcado(i.item_individual_id)} onChange={(e) => setEstado({ ...estado, [i.item_individual_id]: e.target.value })}>
                {CONSERVACAO.map((c) => <option key={c} value={c}>{rotulo(c)}{c === i.estado_conservacao ? ' (atual)' : ''}</option>)}</select></label>
              {marcado(i.item_individual_id) && ESTADOS_PROBLEMA.includes(estadoDe(i)) && <span className="selo selo-perigo">Com problema: você poderá registrar a baixa em seguida</span>}
            </div>))}
        </fieldset>}
        {p.lotes.length > 0 && <fieldset className="campo"><legend>Lotes</legend>
          {p.lotes.map((l) => (
            <label key={l.lote_id} className={`devolucao-qtd${foco?.lote_id === l.lote_id ? ' linha-foco' : ''}`}>{l.material.nome} (lote <span className="mono">{l.codigo}</span>)
              <input type="number" min={0} max={l.quantidade} step={1} value={quantidade(l)} aria-describedby={`max-${l.lote_id}`} onChange={(e) => setQtd({ ...qtd, [l.lote_id]: e.target.value })} />
              <span id={`max-${l.lote_id}`}>/ {l.quantidade} pendente(s){l.entregue !== undefined && l.entregue !== l.quantidade ? ` · ${l.situacao_na_entrega?.rotulo ?? textoSituacaoLote({ entregue: l.entregue, devolvido: l.devolvido ?? 0 })}` : ''}</span></label>))}
        </fieldset>}
        <Campo rotulo={retroativa ? 'Justificativa da devolução retroativa' : 'Observação'} obrigatorio={retroativa} erro={fe('justificativa')}>{(pp) =>
          <input {...pp} value={obs} maxLength={500} onChange={(e) => setObs(e.target.value)} />}</Campo>
        {!itens.length && <Aviso tipo="alerta">Marque ao menos um item ou informe uma quantidade.</Aviso>}
        {total > 1 && parcial && itens.length > 0 && <Aviso tipo="info">Devolução parcial: o que não foi marcado continua com o efetivo.</Aviso>}
      </>}
    </Dialogo>
  )
}
