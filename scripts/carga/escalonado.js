// Carga escalonada contra o ambiente production-like LOCAL (Apache + PHP-FPM + OPcache). Dados sintéticos.
import http from 'k6/http'
import { check, sleep } from 'k6'
import { Trend, Counter } from 'k6/metrics'
import exec from 'k6/execution'

const BASE = __ENV.BASE || 'http://127.0.0.1:18490'
const VUS = Number(__ENV.VUS || 10)
const loginMs = new Trend('sigarb_login_ms', true)
const entradas = new Counter('sigarb_entradas_ok')
const replays = new Counter('sigarb_replays_ok')
const DOCS = (__ENV.DOCS || '').split(',').filter(Boolean)
const DUR = __ENV.DURACAO || '60s'
// Cenários isolados (diagnóstico) e o cenário 'completo' (idêntico à linha de base original: misto VUS + 5 escritores)
const CENARIO = __ENV.CENARIO || 'completo'
// Rampa de subida (RAMPA, padrão 30s) antes do patamar: sem ela, todos os VUs fazem login (bcrypt, CPU-bound)
// no mesmo instante e o teste mede uma rajada artificial, não operação contínua. Rajada de login: CENARIO=login RAMPA=0s.
const RAMPA = __ENV.RAMPA || '30s'
const cv = (vus, fn) => RAMPA === '0s'
  ? { executor: 'constant-vus', vus, duration: DUR, exec: fn }
  : { executor: 'ramping-vus', startVUs: 0, stages: [{ duration: RAMPA, target: vus }, { duration: DUR, target: vus }], exec: fn }
const CENARIOS = {
  completo: { misto: cv(VUS, 'misto'), estoque: cv(5, 'estoque') },
  leitura: { misto: cv(VUS, 'misto') },
  login: { login: cv(VUS, 'somenteLogin') },
  escrita_mesmo_lote: { estoque: cv(VUS, 'estoque') },
  escrita_lotes: { estoque: cv(VUS, 'estoqueLotes') },
}
if (!CENARIOS[CENARIO]) throw new Error(`CENARIO desconhecido: ${CENARIO}`)

export const options = {
  noCookiesReset: true,
  summaryTrendStats: ['avg', 'min', 'med', 'p(90)', 'p(95)', 'p(99)', 'max'],
  scenarios: CENARIOS[CENARIO],
  thresholds: {
    http_req_failed: [{ threshold: 'rate<0.05', abortOnFail: true, delayAbortEval: '15s' }],
    'http_req_duration{cenario:misto}': [{ threshold: 'p(95)<5000', abortOnFail: true, delayAbortEval: '15s' }],
  },
}
const xsrf = (jar) => { const c = jar.cookiesForURL(BASE + '/')['XSRF-TOKEN']; return c ? decodeURIComponent(c[0]) : '' }
const usuario = () => `carga.${String(exec.vu.idInTest).padStart(3, '0')}`
const H = { Accept: 'application/json' }
let jar = null
function entrar(tag) {
  jar = http.cookieJar()
  http.get(`${BASE}/api/v1/csrf`, { tags: { cenario: tag, rota: 'csrf' } })
  const r = http.post(`${BASE}/api/v1/auth/login`, JSON.stringify({ login: usuario(), password: __ENV.SENHA }),
    { headers: { ...H, 'Content-Type': 'application/json', 'X-XSRF-TOKEN': xsrf(jar) }, tags: { cenario: tag, rota: 'login' } })
  loginMs.add(r.timings.duration)
  check(r, { 'login 200': (x) => x.status === 200 })
  http.get(`${BASE}/api/v1/csrf`, { tags: { cenario: tag, rota: 'csrf' } })
}
const get = (rota, nome) => http.get(`${BASE}/api/v1/${rota}`, { headers: H, tags: { cenario: 'misto', rota: nome } })

export function misto() {
  if (!jar || exec.vu.iterationInScenario % 25 === 24) entrar('misto') // sessão renovada periodicamente
  const i = exec.vu.iterationInScenario
  const rs = [
    get('dashboard', 'dashboard'),
    get(`militares?q=a&page=${(i % 3) + 1}&per_page=10`, 'militares_busca'),
    get(`stock/balances?per_page=20&page=${(i % 2) + 1}`, 'saldos'),
    get('movements?per_page=20', 'movimentacoes'),
    get('document-types', 'referencias'),
    get('catalog/categorias', 'referencias'),
    get('documents?per_page=20', 'documentos'),
  ]
  if (DOCS.length) {
    const d = DOCS[i % DOCS.length]
    rs.push(get(`documents/${d}`, 'documento_detalhe'))
    if (i % 4 === 0) rs.push(get(`documents/${d}/download`, 'documento_download'))
  }
  for (const r of rs) check(r, { 'status 200': (x) => x.status === 200 })
  sleep(1)
}

export function somenteLogin() {
  entrar('login')
  const r = get('me', 'me')
  check(r, { 'me 200': (x) => x.status === 200 })
  sleep(1)
}

export function estoqueLotes() { estoque(`LOTE-CARGA-PL-${exec.vu.idInTest}`) }

export function estoque(lote = 'LOTE-CARGA-PL') {
  if (!jar) entrar('estoque')
  const chave = `pl-${exec.vu.idInTest}-${exec.vu.iterationInScenario}-${Date.now()}`
  const corpo = JSON.stringify({ tipo: 'ENTRADA', local_destino_id: Number(__ENV.LOCAL), origem_descricao: 'Carga sintética', documento_referencia: 'DOC-CARGA-PL',
    itens: [{ material_id: Number(__ENV.MATERIAL), lote_codigo: lote, quantidade: 1 }] })
  const h = { ...H, 'Content-Type': 'application/json', 'X-XSRF-TOKEN': xsrf(jar), 'Idempotency-Key': chave }
  const r = http.post(`${BASE}/api/v1/movements`, corpo, { headers: h, tags: { cenario: 'estoque', rota: 'entrada' } })
  if (check(r, { 'entrada 201': (x) => x.status === 201 })) entradas.add(1)
  if (exec.vu.iterationInScenario % 5 === 0) {
    const rr = http.post(`${BASE}/api/v1/movements`, corpo, { headers: h, tags: { cenario: 'estoque', rota: 'replay' } })
    if (check(rr, { 'replay 200': (x) => x.status === 200 })) replays.add(1)
  }
  sleep(0.5)
}
