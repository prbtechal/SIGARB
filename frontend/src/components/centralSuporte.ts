// Central de Suporte PRB (GET /central-suporte). O servidor já filtra pelo CPF da sessão e monta os links; aqui só
// garantimos a forma e que nenhum link fora de http(s) chegue a um href.

export type ChamadoCentral = { protocolo: string; titulo: string; status_rotulo: string; aberto: boolean; aguardando_voce: boolean; atualizado_em: string | null; link: string | null }
export type AvisoCentral = { id: number; protocolo: string; texto: string; criada_em: string | null; link: string | null }
export type Central = { disponivel: boolean; abertos: number; nao_lidos: number; chamados: ChamadoCentral[]; avisos: AvisoCentral[]; url_abrir: string | null; url_chamados: string | null }

export const CENTRAL_VAZIA: Central = Object.freeze({ disponivel: false, abertos: 0, nao_lidos: 0, chamados: [], avisos: [], url_abrir: null, url_chamados: null }) as Central

export function linkSeguro(v: unknown): string | null {
  return typeof v === 'string' && /^https?:\/\/[^\s"'<>`]+$/i.test(v) ? v : null
}

const inteiro = (v: unknown) => (Number.isFinite(Number(v)) && Number(v) > 0 ? Math.trunc(Number(v)) : 0)
const texto = (v: unknown) => (v == null ? '' : String(v))
const lista = (v: unknown): Record<string, unknown>[] => (Array.isArray(v) ? v.filter((x) => x && typeof x === 'object') : [])

export function normalizarCentral(d: unknown): Central {
  if (!d || typeof d !== 'object') return CENTRAL_VAZIA
  const o = d as Record<string, unknown>
  return {
    disponivel: o.disponivel === true,
    abertos: inteiro(o.abertos),
    nao_lidos: inteiro(o.nao_lidos),
    url_abrir: linkSeguro(o.url_abrir),
    url_chamados: linkSeguro(o.url_chamados),
    chamados: lista(o.chamados).map((c) => ({
      protocolo: texto(c.protocolo), titulo: texto(c.titulo), status_rotulo: texto(c.status_rotulo),
      aberto: c.aberto === true, aguardando_voce: c.aguardando_voce === true,
      atualizado_em: typeof c.atualizado_em === 'string' ? c.atualizado_em : null, link: linkSeguro(c.link),
    })),
    avisos: lista(o.avisos).map((a) => ({
      id: inteiro(a.id), protocolo: texto(a.protocolo), texto: texto(a.texto),
      criada_em: typeof a.criada_em === 'string' ? a.criada_em : null, link: linkSeguro(a.link),
    })),
  }
}

/** Tom do selo (classes .selo-*) pelo estado do chamado. */
export const tomStatusCentral = (c: ChamadoCentral) => (c.aguardando_voce ? 'alerta' : c.aberto ? 'info' : 'neutro')

/** Data/hora da view (`AAAA-MM-DD HH:MM:SS`, horário da Central) exibida como veio, sem conversão de fuso. */
export const quandoCentral = (v: string | null) => {
  const m = v?.match(/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})/)
  return m ? `${m[3]}/${m[2]}/${m[1]} ${m[4]}:${m[5]}` : ''
}
