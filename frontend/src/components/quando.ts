/** Fuso do Arsenal (ADR-015): telas sempre na hora de Maceió, qualquer que seja o fuso do navegador. */
export const FUSO = 'America/Maceio'
/** Agora em America/Maceio no formato do <input type="datetime-local"> (AAAA-MM-DDTHH:mm). */
export const agoraMaceio = (d = new Date()) =>
  new Intl.DateTimeFormat('sv-SE', { timeZone: FUSO, year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit' }).format(d).replace(' ', 'T')
// shortcut: Maceió é UTC-3 fixo (sem horário de verão desde 2019); se voltar a ter, calcular o offset pelo Intl.
export const comFuso = (v: string) => `${v}:00-03:00`
export const TOLERANCIA_MS = 5 * 60_000 // igual a EstoqueService::TOLERANCIA_OCORRIDO_MIN
/** Data/hora informada antes da tolerância = registro retroativo (exige justificativa no servidor). */
export const ehRetroativa = (v: string) => !!v && new Date(comFuso(v)).getTime() < Date.now() - TOLERANCIA_MS
/** Hoje (Maceió) em AAAA-MM-DD, para comparar previsões de retorno. */
export const hojeMaceio = () => agoraMaceio().slice(0, 10)
/** Tempo decorrido em texto curto: "agora", "há 40 min", "há 5 h", "há 3 dias". */
export function haQuanto(iso: string, agora = Date.now()): string {
  const min = Math.max(0, Math.floor((agora - new Date(iso).getTime()) / 60_000))
  if (min < 1) return 'agora'
  if (min < 60) return `há ${min} min`
  const h = Math.floor(min / 60)
  if (h < 24) return `há ${h} h`
  const d = Math.floor(h / 24)
  return `há ${d} ${d === 1 ? 'dia' : 'dias'}`
}
/** Dias inteiros desde a data/hora (para destacar pedido parado). */
export const diasDesde = (iso: string, agora = Date.now()) => Math.floor((agora - new Date(iso).getTime()) / 86_400_000)
