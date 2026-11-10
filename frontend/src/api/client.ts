// Cliente HTTP único (ADR-010). Sessão por cookie HttpOnly; nada sensível em storage.
export type ApiErro = { code: string; message: string; fields?: Record<string, string[]>; details?: Record<string, unknown>; request_id?: string }
export type Pagina<T> = { data: T[]; meta: { page: number; per_page: number; total: number; last_page: number } }

export class ErroApi extends Error {
  status: number
  erro: ApiErro
  constructor(status: number, erro: ApiErro) {
    super(erro.message)
    this.status = status
    this.erro = erro
  }
}

type Listener = (status: number) => void
const listeners = new Set<Listener>()
export const onErroGlobal = (l: Listener) => { listeners.add(l); return () => { listeners.delete(l) } }

function xsrf(): string | undefined {
  const m = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]+)/)
  return m ? decodeURIComponent(m[1]) : undefined
}

let csrfPronto: Promise<void> | null = null
export function garantirCsrf(forcar = false): Promise<void> {
  if (forcar || !csrfPronto || !xsrf()) csrfPronto = fetch(`${import.meta.env.BASE_URL}api/v1/csrf`, { credentials: 'same-origin' }).then(() => undefined)
  return csrfPronto
}

export async function api<T>(caminho: string, opts: { method?: string; body?: unknown; headers?: Record<string, string>; query?: Record<string, unknown> } = {}): Promise<T> {
  const method = opts.method ?? 'GET'
  const qs = opts.query
    ? '?' + new URLSearchParams(Object.entries(opts.query).filter(([, v]) => v !== undefined && v !== null && v !== '').map(([k, v]) => [k, String(v)])).toString()
    : ''
  const enviar = async () => {
    if (method !== 'GET') await garantirCsrf()
    const headers: Record<string, string> = { Accept: 'application/json', ...opts.headers }
    if (opts.body !== undefined) headers['Content-Type'] = 'application/json'
    const t = xsrf()
    if (t && method !== 'GET') headers['X-XSRF-TOKEN'] = t
    return fetch(`${import.meta.env.BASE_URL}api/v1${caminho}${qs === '?' ? '' : qs}`, { method, credentials: 'same-origin', headers, body: opts.body === undefined ? undefined : JSON.stringify(opts.body) })
  }
  let res = await enviar()
  if (res.status === 419) { await garantirCsrf(true); res = await enviar() } // token expirado: renova uma vez
  if (res.status === 204) return undefined as T
  if (!(res.headers.get('content-type') ?? '').includes('json')) {
    // Resposta inesperada (ex.: página de erro do servidor) nunca é tratada como dado válido.
    throw new ErroApi(res.ok ? 502 : res.status, { code: 'RESPOSTA_INVALIDA', message: 'Resposta inesperada do servidor.' })
  }
  const json = await res.json().catch(() => ({}))
  if (!res.ok) {
    const erro: ApiErro = json?.error ?? { code: 'ERRO_REDE', message: 'Falha de comunicação com o servidor.' }
    listeners.forEach((l) => l(res.status))
    throw new ErroApi(res.status, erro)
  }
  return json as T
}

export const novaChave = () => crypto.randomUUID()

/** Upload multipart com progresso (XHR: fetch não informa progresso de envio). Mesma política de CSRF/erros do api(). */
export async function enviarArquivo<T>(caminho: string, dados: FormData, onProgresso?: (pct: number) => void): Promise<T> {
  await garantirCsrf()
  return new Promise<T>((resolve, reject) => {
    const xhr = new XMLHttpRequest()
    xhr.open('POST', `${import.meta.env.BASE_URL}api/v1${caminho}`)
    xhr.withCredentials = true
    xhr.setRequestHeader('Accept', 'application/json')
    const t = xsrf()
    if (t) xhr.setRequestHeader('X-XSRF-TOKEN', t)
    xhr.upload.onprogress = (e) => { if (e.lengthComputable && onProgresso) onProgresso(Math.round((e.loaded / e.total) * 100)) }
    xhr.onerror = () => reject(new ErroApi(0, { code: 'ERRO_REDE', message: 'Falha de comunicação com o servidor.' }))
    xhr.onload = () => {
      let json: { error?: ApiErro } = {}
      try { json = JSON.parse(xhr.responseText) } catch { /* corpo não-JSON */ }
      if (xhr.status >= 200 && xhr.status < 300 && json && !json.error) return resolve(json as T)
      listeners.forEach((l) => l(xhr.status))
      reject(new ErroApi(xhr.status || 502, json.error ?? { code: 'RESPOSTA_INVALIDA', message: 'Resposta inesperada do servidor.' }))
    }
    xhr.send(dados)
  })
}
