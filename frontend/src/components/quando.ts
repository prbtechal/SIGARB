/** Agora em America/Maceio no formato do <input type="datetime-local"> (AAAA-MM-DDTHH:mm). */
export const agoraMaceio = (d = new Date()) =>
  new Intl.DateTimeFormat('sv-SE', { timeZone: 'America/Maceio', year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit' }).format(d).replace(' ', 'T')
// shortcut: Maceió é UTC-3 fixo (sem horário de verão desde 2019); se voltar a ter, calcular o offset pelo Intl.
export const comFuso = (v: string) => `${v}:00-03:00`
export const TOLERANCIA_MS = 5 * 60_000 // igual a EstoqueService::TOLERANCIA_OCORRIDO_MIN
/** Data/hora informada antes da tolerância = registro retroativo (exige justificativa no servidor). */
export const ehRetroativa = (v: string) => !!v && new Date(comFuso(v)).getTime() < Date.now() - TOLERANCIA_MS
/** Hoje (Maceió) em AAAA-MM-DD, para comparar previsões de retorno. */
export const hojeMaceio = () => agoraMaceio().slice(0, 10)
