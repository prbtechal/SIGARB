// Teste de carga LOCAL (k6). Somente contra a API E2E isolada; dados sintéticos.
import http from 'k6/http'
import { check, sleep } from 'k6'
import { Trend, Counter } from 'k6/metrics'
import exec from 'k6/execution'

const BASE = __ENV.BASE || 'http://127.0.0.1:18482'
const SENHA = __ENV.SENHA
const LOTE = __ENV.LOTE || 'LOTE-CARGA'
const loginMs = new Trend('sigarb_login_ms', true)
const entradas = new Counter('sigarb_entradas_ok')
const replays = new Counter('sigarb_replays_ok')

export const options = {
  noCookiesReset: true, // mantém a sessão do VU entre iterações (padrão do k6 é zerar)
  scenarios: {
    leitura: { executor: 'constant-vus', vus: 15, duration: '60s', exec: 'leitura' },
    autenticacao: { executor: 'constant-vus', vus: 3, duration: '60s', exec: 'autenticacao', startTime: '0s' },
    estoque: { executor: 'constant-vus', vus: 5, duration: '40s', exec: 'estoque', startTime: '10s' },
  },
  thresholds: { 'http_req_failed{cenario:leitura}': ['rate<0.01'], 'http_req_failed{cenario:estoque}': ['rate<0.01'] },
}

function xsrf(jar) {
  const c = jar.cookiesForURL(BASE + '/')['XSRF-TOKEN']
  return c ? decodeURIComponent(c[0]) : ''
}
function entrar(login, tag) {
  const jar = http.cookieJar()
  http.get(`${BASE}/api/v1/csrf`, { tags: { cenario: tag } })
  const r = http.post(`${BASE}/api/v1/auth/login`, JSON.stringify({ login, password: SENHA }),
    { headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': xsrf(jar) }, tags: { cenario: tag, rota: 'login' } })
  loginMs.add(r.timings.duration)
  check(r, { 'login 200': (x) => x.status === 200 })
  http.get(`${BASE}/api/v1/csrf`, { tags: { cenario: tag } })
  return jar
}
// Uma conta sintética por VU (idInTest é único entre cenários; 23 contas criadas).
const usuario = () => `carga.${String(exec.vu.idInTest).padStart(2, '0')}`
const H = { Accept: 'application/json' }

let sessao = null
export function leitura() {
  if (!sessao) sessao = entrar(usuario(), 'leitura')
  for (const rota of ['me', 'stock/balances', 'movements', 'documents', 'dashboard', 'militares']) {
    const r = http.get(`${BASE}/api/v1/${rota}`, { headers: H, tags: { cenario: 'leitura', rota } })
    check(r, { [`${rota} 200`]: (x) => x.status === 200 })
  }
  sleep(0.5)
}

export function autenticacao() {
  // login repetido na própria conta (custo do bcrypt + sessão + auditoria)
  entrar(usuario(), 'autenticacao')
  sleep(1)
}

export function estoque() {
  if (!sessao) sessao = entrar(usuario(), 'estoque')
  const local = Number(__ENV.LOCAL), material = Number(__ENV.MATERIAL)
  const chave = `carga-${__VU}-${__ITER}-${Date.now()}`
  const corpo = JSON.stringify({ tipo: 'ENTRADA', local_destino_id: local, origem_descricao: 'Carga sintética', documento_referencia: 'DOC-CARGA',
    itens: [{ material_id: material, lote_codigo: LOTE, quantidade: 1 }] })
  const h = { ...H, 'Content-Type': 'application/json', 'X-XSRF-TOKEN': xsrf(sessao), 'Idempotency-Key': chave }
  const r = http.post(`${BASE}/api/v1/movements`, corpo, { headers: h, tags: { cenario: 'estoque', rota: 'entrada' } })
  if (check(r, { 'entrada 201': (x) => x.status === 201 })) entradas.add(1)
  if (__ITER % 5 === 0) { // repetição com a mesma chave não pode duplicar
    const rr = http.post(`${BASE}/api/v1/movements`, corpo, { headers: h, tags: { cenario: 'estoque', rota: 'replay' } })
    if (check(rr, { 'replay 200': (x) => x.status === 200 && x.headers['Idempotent-Replayed'] === 'true' })) replays.add(1)
  }
  sleep(0.2)
}
