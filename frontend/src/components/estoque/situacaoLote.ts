import type { SituacaoLote } from '../../api/tipos'

const n = (v: number) => v.toLocaleString('pt-BR')

/**
 * Situação equivalente de um lote entregue (decisão 2026-11-18): lote não tem situação própria, então é calculada por
 * entregue − devoluções ligadas à entrega. "Entregue · 10 pendentes", "Parcial: 4 de 10 devolvidos", "Devolvido", "Consumo".
 */
export function textoSituacaoLote(s: Pick<SituacaoLote, 'entregue' | 'devolvido'> & { situacao?: SituacaoLote['situacao'] | string | null }): string {
  if (s.situacao === 'CONSUMIDO') return 'Consumo (sem retorno)'
  const pendente = Math.max(0, s.entregue - s.devolvido)
  if (pendente === 0) return 'Devolvido'
  if (s.devolvido > 0) return `Parcial: ${n(s.devolvido)} de ${n(s.entregue)} devolvidos`
  return `Entregue · ${n(pendente)} ${pendente === 1 ? 'pendente' : 'pendentes'}`
}

/** Tom do selo, no mesmo código de cores das situações de item. */
export function tomSituacaoLote(s: { entregue: number; devolvido: number; situacao?: string | null }): 'info' | 'alerta' | 'ok' | 'neutro' {
  if (s.situacao === 'CONSUMIDO') return 'neutro'
  const pendente = s.entregue - s.devolvido
  return pendente <= 0 ? 'ok' : s.devolvido > 0 ? 'alerta' : 'info'
}
