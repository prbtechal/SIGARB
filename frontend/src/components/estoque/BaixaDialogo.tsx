import { useState } from 'react'
import { ErroApi, api, novaChave } from '../../api/client'
import type { MovDetalhe } from '../../api/tipos'
import { Aviso, Campo, Dialogo, rotulo, useNotificar } from '../ui'

export type ItemBaixa = { id: number; rotulo: string; estado?: string | null }

/**
 * Baixa de material com problema (regra BAIXA: item disponível ou em manutenção no local, motivo e documento obrigatórios).
 * Usada depois de uma devolução com item danificado/inservível e nas listas de itens.
 */
export function BaixaDialogo({ itens, localId, motivoInicial = '', onFechar, onConcluir }: {
  itens: ItemBaixa[]; localId: number; motivoInicial?: string; onFechar: () => void; onConcluir: (movId: number) => void
}) {
  const notificar = useNotificar()
  const [marcados, setMarcados] = useState<Set<number>>(new Set(itens.map((i) => i.id)))
  const [doc, setDoc] = useState('')
  const [motivo, setMotivo] = useState(motivoInicial)
  const [erro, setErro] = useState<ErroApi | null>(null)
  const [enviando, setEnviando] = useState(false)
  const [chave, setChave] = useState(novaChave)
  const fe = (k: string) => erro?.erro.fields?.[k]
  const valido = marcados.size > 0 && doc.trim().length >= 3 && motivo.trim().length >= 5

  const enviar = async () => {
    setEnviando(true); setErro(null)
    try {
      const r = await api<MovDetalhe>('/movements', { method: 'POST', headers: { 'Idempotency-Key': chave }, body: {
        tipo: 'BAIXA', local_origem_id: localId, documento_referencia: doc.trim(), justificativa: motivo.trim(),
        itens: itens.filter((i) => marcados.has(i.id)).map((i) => ({ item_individual_id: i.id })) } })
      notificar('ok', `Baixa #${r.id} registrada (${marcados.size} item(ns)).`)
      onConcluir(r.id)
    } catch (e) {
      if (e instanceof ErroApi) { setErro(e); if (e.status !== 409) setChave(novaChave()) }
    } finally { setEnviando(false) }
  }

  return (
    <Dialogo tour="registrar-baixa" aberto titulo="Registrar baixa" onFechar={onFechar}
      rodape={<><button type="button" className="btn btn-sec" onClick={onFechar}>Agora não</button>
        <button type="button" className="btn btn-perigo" disabled={!valido || enviando} onClick={enviar}>{enviando ? 'Registrando…' : 'Confirmar baixa'}</button></>}>
      <Aviso tipo="alerta">A baixa tira o item do estoque em definitivo e não pode ser desfeita. Exige motivo e documento.</Aviso>
      {erro && !erro.erro.fields && <Aviso tipo="perigo">{erro.erro.code === 'ACESSO_NEGADO' ? 'Você não tem permissão de baixa neste local.' : erro.erro.message}</Aviso>}
      <fieldset className="campo"><legend>Itens</legend>
        {itens.map((i) => (
          <label key={i.id} className="opcao">
            <input type="checkbox" checked={marcados.has(i.id)} onChange={(e) => setMarcados((s) => { const n = new Set(s); if (e.target.checked) n.add(i.id); else n.delete(i.id); return n })} />
            {i.rotulo}{i.estado && <> — {rotulo(i.estado)}</>}
          </label>))}
      </fieldset>
      <Campo rotulo="Documento de referência" obrigatorio dica="Ex.: termo de baixa, laudo, ofício." erro={fe('documento_referencia')}>{(p) => <input {...p} value={doc} maxLength={120} onChange={(e) => setDoc(e.target.value)} />}</Campo>
      <Campo rotulo="Motivo" obrigatorio dica="Mínimo de 5 caracteres." erro={fe('justificativa')}>{(p) => <textarea {...p} rows={3} maxLength={500} value={motivo} onChange={(e) => setMotivo(e.target.value)} />}</Campo>
    </Dialogo>
  )
}
