import { useState } from 'react'
import { ErroApi, api, novaChave } from '../../api/client'
import type { CatalogoTipos, ItemIndividual, MovDetalhe } from '../../api/tipos'
import { ROTULO_CAMPO } from '../perfilMaterial'
import { Aviso, CONSERVACAO, Campo, Dialogo, numero, rotulo, useNotificar } from '../ui'
import { useRecurso } from '../useRecurso'

const ATRIBUTOS = ['fabricado_em', 'validade', 'tamanho', 'nivel_protecao'] as const
type CamposItem = { numero_serie: string; estado_conservacao: string; fabricado_em: string; validade: string; tamanho: string; nivel_protecao: string }

const MensagemErro = ({ erro }: { erro: ErroApi | null }) => erro && !erro.erro.fields
  ? <Aviso tipo="perigo">{erro.erro.code === 'ACESSO_NEGADO' ? 'Você não tem permissão para editar neste local.' : erro.erro.message}</Aviso> : null

/**
 * Corrigir dados do item individual (PATCH /stock/individual-items/{id}): série, conservação e os atributos que o perfil do tipo
 * admite (ex.: colete — fabricação, validade, tamanho, nível). Envia só o que mudou, com motivo; o identificador nunca muda.
 */
export function EditarItemDialogo({ item, onFechar, onSalvo }: { item: ItemIndividual; onFechar: () => void; onSalvo: () => void }) {
  const notificar = useNotificar()
  const cat = useRecurso(() => api<CatalogoTipos>('/catalog/tipos-material'), [])
  const inicial: CamposItem = { numero_serie: item.numero_serie ?? '', estado_conservacao: item.estado_conservacao, fabricado_em: item.fabricado_em ?? '',
    validade: item.validade ?? '', tamanho: item.tamanho ?? '', nivel_protecao: item.nivel_protecao ?? '' }
  const [f, setF] = useState<CamposItem>(inicial)
  const [motivo, setMotivo] = useState('')
  const [erro, setErro] = useState<ErroApi | null>(null)
  const [enviando, setEnviando] = useState(false)
  const regra = cat.dados?.perfis[item.material.tipo?.perfil ?? 'GERAL']
  // Atributo aparece se o perfil admite ou se o item já tem valor (dado antigo pode ser limpo).
  const atributos = ATRIBUTOS.filter((a) => regra?.item_obrig.includes(a) || regra?.item_perm.includes(a) || inicial[a] !== '')
  const obrig = (c: string) => !!regra?.item_obrig.includes(c)
  const mudou = (Object.keys(f) as (keyof CamposItem)[]).filter((k) => f[k] !== inicial[k])
  const fe = (k: string) => erro?.erro.fields?.[k]

  const salvar = async () => {
    setEnviando(true); setErro(null)
    try {
      const corpo: Record<string, string | null> = { motivo: motivo.trim() }
      for (const k of mudou) corpo[k] = f[k].trim() === '' ? null : f[k].trim()
      await api(`/stock/individual-items/${item.id}`, { method: 'PATCH', body: corpo })
      notificar('ok', `Item ${item.numero_serie || item.identificador} atualizado.`)
      onSalvo()
    } catch (e) { if (e instanceof ErroApi) setErro(e) } finally { setEnviando(false) }
  }

  return (
    <Dialogo tour="editar-item" aberto titulo={`Editar item — ${item.numero_serie || item.identificador}`} onFechar={onFechar}
      rodape={<><button type="button" className="btn btn-sec" onClick={onFechar}>Cancelar</button>
        <button type="button" className="btn btn-pri" disabled={!mudou.length || motivo.trim().length < 10 || enviando} onClick={salvar}>{enviando ? 'Salvando…' : 'Salvar alterações'}</button></>}>
      <MensagemErro erro={erro} />
      <p className="dica">{item.material.nome} · código do sistema <span className="mono">{item.identificador}</span> (não muda) · situação: {rotulo(item.situacao)} (só muda por operação).</p>
      <div className="grade">
        <Campo rotulo="Número de série" obrigatorio={obrig('numero_serie')} dica="Único por material." erro={fe('numero_serie')}>{(p) => <input {...p} value={f.numero_serie} maxLength={80} onChange={(e) => setF({ ...f, numero_serie: e.target.value })} />}</Campo>
        <Campo rotulo="Estado de conservação" obrigatorio erro={fe('estado_conservacao')}>{(p) => <select {...p} value={f.estado_conservacao} onChange={(e) => setF({ ...f, estado_conservacao: e.target.value })}>{CONSERVACAO.map((c) => <option key={c} value={c}>{rotulo(c)}</option>)}</select>}</Campo>
        {atributos.map((a) => (
          <Campo key={a} rotulo={ROTULO_CAMPO[a]} obrigatorio={obrig(a)} erro={fe(a)}>{(p) => a === 'nivel_protecao'
            ? <select {...p} value={f.nivel_protecao} onChange={(e) => setF({ ...f, nivel_protecao: e.target.value })}><option value="">—</option>
              {[...new Set([...(cat.dados?.niveis_protecao ?? []), ...(inicial.nivel_protecao ? [inicial.nivel_protecao] : [])])].map((n) => <option key={n}>{n}</option>)}</select>
            : <input {...p} type={a === 'tamanho' ? 'text' : 'date'} maxLength={a === 'tamanho' ? 20 : undefined} value={f[a]} onChange={(e) => setF({ ...f, [a]: e.target.value })} />}</Campo>))}
      </div>
      <Campo rotulo="Motivo da correção" obrigatorio dica="Mínimo de 10 caracteres. Fica na auditoria com o antes e o depois." erro={fe('motivo')}>{(p) => <textarea {...p} rows={2} maxLength={500} value={motivo} onChange={(e) => setMotivo(e.target.value)} />}</Campo>
    </Dialogo>
  )
}

export type LoteEdicao = { id: number; codigo: string; validade: string | null; material: string }

/** Corrigir código e validade do lote (PATCH /stock/lots/{id}); vale para todos os locais do lote. */
export function EditarLoteDialogo({ lote, onFechar, onSalvo }: { lote: LoteEdicao; onFechar: () => void; onSalvo: () => void }) {
  const notificar = useNotificar()
  const [codigo, setCodigo] = useState(lote.codigo)
  const [validade, setValidade] = useState(lote.validade ?? '')
  const [motivo, setMotivo] = useState('')
  const [erro, setErro] = useState<ErroApi | null>(null)
  const [enviando, setEnviando] = useState(false)
  const corpo = { ...(codigo.trim() !== lote.codigo ? { codigo: codigo.trim() } : {}), ...(validade !== (lote.validade ?? '') ? { validade: validade || null } : {}) }
  const fe = (k: string) => erro?.erro.fields?.[k]

  const salvar = async () => {
    setEnviando(true); setErro(null)
    try {
      await api(`/stock/lots/${lote.id}`, { method: 'PATCH', body: { ...corpo, motivo: motivo.trim() } })
      notificar('ok', `Lote ${codigo.trim()} atualizado.`)
      onSalvo()
    } catch (e) { if (e instanceof ErroApi) setErro(e) } finally { setEnviando(false) }
  }

  return (
    <Dialogo tour="editar-lote" aberto titulo={`Editar lote ${lote.codigo}`} onFechar={onFechar}
      rodape={<><button type="button" className="btn btn-sec" onClick={onFechar}>Cancelar</button>
        <button type="button" className="btn btn-pri" disabled={!Object.keys(corpo).length || motivo.trim().length < 10 || enviando} onClick={salvar}>{enviando ? 'Salvando…' : 'Salvar alterações'}</button></>}>
      <MensagemErro erro={erro} />
      <p className="dica">{lote.material}. A alteração vale para o lote em todos os locais; saldo e histórico não mudam.</p>
      <div className="grade">
        <Campo rotulo="Código do lote" obrigatorio dica="Não pode repetir outro lote do mesmo material." erro={fe('codigo')}>{(p) => <input {...p} value={codigo} maxLength={60} onChange={(e) => setCodigo(e.target.value)} />}</Campo>
        <Campo rotulo="Validade" erro={fe('validade')}>{(p) => <input {...p} type="date" value={validade} onChange={(e) => setValidade(e.target.value)} />}</Campo>
      </div>
      <Campo rotulo="Motivo da correção" obrigatorio dica="Mínimo de 10 caracteres." erro={fe('motivo')}>{(p) => <textarea {...p} rows={2} maxLength={500} value={motivo} onChange={(e) => setMotivo(e.target.value)} />}</Campo>
    </Dialogo>
  )
}

export type SaldoAjuste = { local: { id: number; codigo: string; nome: string }; lote: { id: number; codigo: string }; material: string; unidade_medida?: string; quantidade: number; reservada: number }

/**
 * Ajustar quantidade de um lote num local (POST /stock/adjustments): a quantidade correta + motivo → movimentação AJUSTE,
 * sem aprovação. Nunca abaixo do reservado; o que está com efetivos já está fora deste saldo.
 */
export function AjusteQuantidadeDialogo({ saldo, onFechar, onSalvo }: { saldo: SaldoAjuste; onFechar: () => void; onSalvo: (movId: number) => void }) {
  const notificar = useNotificar()
  const [qtd, setQtd] = useState(String(saldo.quantidade))
  const [motivo, setMotivo] = useState('')
  const [doc, setDoc] = useState('')
  const [erro, setErro] = useState<ErroApi | null>(null)
  const [enviando, setEnviando] = useState(false)
  const [chave, setChave] = useState(novaChave)
  const n = Number(qtd)
  const valida = qtd !== '' && Number.isInteger(n) && n >= 0
  const dif = valida ? n - saldo.quantidade : 0
  const abaixo = valida && n < saldo.reservada
  const fe = (k: string) => erro?.erro.fields?.[k]

  const salvar = async () => {
    setEnviando(true); setErro(null)
    try {
      const r = await api<MovDetalhe>('/stock/adjustments', { method: 'POST', headers: { 'Idempotency-Key': chave }, body: {
        local_id: saldo.local.id, lote_id: saldo.lote.id, quantidade: n, quantidade_atual: saldo.quantidade, motivo: motivo.trim(),
        ...(doc.trim() ? { documento_referencia: doc.trim() } : {}) } })
      notificar('ok', `Ajuste #${r.id} registrado: lote ${saldo.lote.codigo} em ${saldo.local.codigo} agora tem ${numero(n)}.`)
      onSalvo(r.id)
    } catch (e) { if (e instanceof ErroApi) { setErro(e); if (e.status !== 409) setChave(novaChave()) } } finally { setEnviando(false) }
  }

  return (
    <Dialogo tour="ajustar-quantidade" aberto titulo={`Ajustar quantidade — lote ${saldo.lote.codigo}`} onFechar={onFechar}
      rodape={<><button type="button" className="btn btn-sec" onClick={onFechar}>Cancelar</button>
        <button type="button" className="btn btn-pri" disabled={!valida || dif === 0 || abaixo || motivo.trim().length < 10 || enviando} onClick={salvar}>{enviando ? 'Registrando…' : 'Registrar ajuste'}</button></>}>
      <MensagemErro erro={erro} />
      <p>{saldo.material} · {saldo.local.codigo} — {saldo.local.nome}</p>
      <p>Saldo atual: <strong>{numero(saldo.quantidade)}</strong>{saldo.reservada > 0 && <> · reservada: {numero(saldo.reservada)}</>}</p>
      <p className="dica">O que está com efetivos já saiu deste saldo e não é afetado. O ajuste vira uma movimentação imutável com o motivo.</p>
      <Campo rotulo="Quantidade correta" obrigatorio erro={fe('quantidade')}>{(p) => <input {...p} type="number" min={saldo.reservada} step={1} inputMode="numeric" value={qtd} onChange={(e) => setQtd(e.target.value)} />}</Campo>
      {valida && dif !== 0 && !abaixo && <p role="status" className={dif < 0 ? 'texto-alerta' : ''}>{dif > 0 ? `Entrada de ${numero(dif)} (sobra)` : `Saída de ${numero(-dif)} (falta)`} {saldo.unidade_medida ?? ''}</p>}
      {abaixo && <p className="msg-erro" role="alert">Não pode ficar abaixo da quantidade reservada ({numero(saldo.reservada)}). Libere a reserva antes.</p>}
      <Campo rotulo="Motivo" obrigatorio dica="Mínimo de 10 caracteres (ex.: contagem física, erro de digitação na entrada)." erro={fe('motivo')}>{(p) => <textarea {...p} rows={2} maxLength={500} value={motivo} onChange={(e) => setMotivo(e.target.value)} />}</Campo>
      <Campo rotulo="Documento de referência" erro={fe('documento_referencia')}>{(p) => <input {...p} value={doc} maxLength={120} onChange={(e) => setDoc(e.target.value)} />}</Campo>
    </Dialogo>
  )
}
