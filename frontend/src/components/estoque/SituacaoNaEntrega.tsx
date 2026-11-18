import type { SituacaoNaEntrega } from '../../api/tipos'
import { Selo } from '../ui'

/** Tom do selo pela situação relativa à entrega (mesmo código de cores das demais situações). */
export function tomSituacaoNaEntrega(codigo: SituacaoNaEntrega['codigo']): 'info' | 'alerta' | 'ok' | 'neutro' | 'perigo' {
  return { ENTREGUE: 'info', DEVOLVIDO: 'ok', DEVOLVIDO_PARCIAL: 'alerta', CONSUMIDO: 'neutro', BAIXADO_APOS_DEVOLUCAO: 'perigo' }[codigo] as 'info'
}

/**
 * Situação do material RELATIVA À ENTREGA (decisão 2026-11-18), igual para item e lote: "Entregue", "Devolvido",
 * "Devolvido parcialmente: X de Y", "Consumido", "Baixado após devolução". Nunca "Disponível" — a situação atual do item
 * no estoque só aparece como informação secundária discreta ("hoje: disponível na RES-POCO").
 */
export function SeloSituacaoNaEntrega({ s }: { s: SituacaoNaEntrega }) {
  return <>
    <Selo valor={s.rotulo} tom={tomSituacaoNaEntrega(s.codigo)} />
    {s.hoje && <><br /><small className="dica">{s.hoje}</small></>}
  </>
}
