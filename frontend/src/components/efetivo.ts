/**
 * Sigla e rótulo do efetivo — função única do front-end (espelho de Militar::siglaExibicao no servidor; decisão 2026-11-18).
 * Quase todo o efetivo vindo do cadastro de funcionários tem posto CIV ("Civil / sem posto"): nesse caso a sigla exibida é a da
 * categoria (RR, PM, BM, VIG, R1); sem categoria, só o nome. Posto real (SD, 3SGT…) aparece como sempre.
 */
export const SIGLAS_CATEGORIA: Record<string, string> = { RR: 'RR', PM_ATIVA: 'PM', BM_ATIVA: 'BM', VIGILANTE: 'VIG', R1: 'R1' }

export function siglaEfetivo(posto?: string | null, categoria?: string | null): string | null {
  if (posto && posto !== 'CIV') return posto
  return (categoria && SIGLAS_CATEGORIA[categoria]) || null
}

type EfetivoRotulavel = { nome_guerra: string; posto?: string | null; posto_graduacao?: { sigla: string } | null; categoria_efetivo?: string | null; sigla_exibicao?: string | null }

/** "RR CICERO LIMA", "SD SILVA" ou só "JOAO". Aceita o DTO compacto (posto) ou o completo (posto_graduacao + categoria). */
export function rotuloEfetivo(m: EfetivoRotulavel | null | undefined): string {
  if (!m) return ''
  const sigla = m.sigla_exibicao !== undefined ? m.sigla_exibicao : siglaEfetivo(m.posto ?? m.posto_graduacao?.sigla, m.categoria_efetivo)
  return [sigla, m.nome_guerra].filter(Boolean).join(' ')
}
