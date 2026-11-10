import { useCallback, useEffect, useLayoutEffect, useRef, useState } from 'react'
import { useLocation } from 'react-router'
import { HelpCircle, X } from 'lucide-react'
import { useAuth } from '../auth/AuthContext'

/** Um passo do tour: `alvo` é um seletor CSS; sem alvo (ou alvo ausente/oculto na tela), o balão aparece centralizado. */
export type Passo = { alvo?: string; titulo: string; texto: string }

const TOPO = '.pagina-topo .acoes'
const FILTROS = '.filtros'
const LISTA = '.tabela-wrap'

/** Tours por rota exata. Passos cujo alvo não existe (perfil sem o botão, tela de celular) são pulados. */
export const TOURS: Record<string, Passo[]> = {
  '/': [
    { titulo: 'Bem-vindo(a) ao SIGARB', texto: 'Um tour rápido mostra o essencial de cada tela. Ele aparece só na primeira visita; depois, use o botão "Tour" no topo para rever.' },
    { alvo: '.lateral', titulo: 'Menu', texto: 'Tudo o que o seu perfil pode acessar fica aqui, agrupado por assunto. Os números indicam pendências.' },
    { alvo: '.topo-papeis', titulo: 'Seus perfis', texto: 'Mostra seus papéis e em qual unidade valem. O que você vê e pode fazer depende deles.' },
    { alvo: '.notif-sino', titulo: 'Notificações', texto: 'Avisos de chamados e pendências chegam aqui.' },
    { alvo: '.cartoes', titulo: 'Painel', texto: 'Resumo da sua área: estoque, cautelas e alertas. Clique nos cartões para ir ao detalhe.' },
  ],
  '/estoque': [
    { alvo: FILTROS, titulo: 'Filtros', texto: 'Filtre o saldo por local, material ou unidade.' },
    { alvo: LISTA, titulo: 'Saldos', texto: 'Quantidade total, reservada e disponível de cada material em cada local.' },
    { alvo: TOPO, titulo: 'Ações', texto: 'Atalhos para registrar entradas e ver os itens individuais.' },
  ],
  '/movimentacoes': [
    { alvo: FILTROS, titulo: 'Histórico', texto: 'Toda entrada, saída, transferência e estorno fica registrada aqui. Filtre por tipo ou período.' },
    { alvo: TOPO, titulo: 'Nova movimentação', texto: 'Registre uma entrada de material por aqui.' },
  ],
  '/operacoes': [
    { alvo: '.grade-ops', titulo: 'Situação das operações', texto: 'Mostra quais operações estão habilitadas, provisórias ou bloqueadas e por quê.' },
    { alvo: '#pend-t', titulo: 'Pendências', texto: 'Devoluções e operações provisórias que ainda precisam ser concluídas.' },
  ],
  '/acautelamentos': [
    { alvo: TOPO, titulo: 'Nova cautela', texto: 'Solicite a cautela de um armamento para um militar. Ela passa por aprovação antes da entrega.' },
    { alvo: LISTA, titulo: 'Cautelas', texto: 'Acompanhe a situação de cada cautela e abra para aprovar, entregar ou receber de volta.' },
  ],
  '/inventarios': [
    { alvo: TOPO, titulo: 'Novo inventário', texto: 'Abra uma conferência física de um local de estoque.' },
    { alvo: LISTA, titulo: 'Inventários', texto: 'Contagens em andamento e concluídas, com as divergências encontradas.' },
  ],
  '/alertas': [
    { alvo: '.lista-alertas', titulo: 'Alertas', texto: 'Validades vencendo, inspeções atrasadas e outras situações que pedem atenção.' },
  ],
  '/militares': [
    { alvo: FILTROS, titulo: 'Busca', texto: 'Encontre militares por nome, matrícula ou unidade.' },
    { alvo: TOPO, titulo: 'Cadastrar', texto: 'Cadastre um novo militar do efetivo.' },
    { alvo: LISTA, titulo: 'Efetivo', texto: 'Abra o perfil para ver cautelas, materiais vinculados e histórico.' },
  ],
  '/materiais': [
    { alvo: FILTROS, titulo: 'Catálogo', texto: 'Busque materiais por nome, código ou categoria.' },
    { alvo: TOPO, titulo: 'Novo material', texto: 'Cadastre um material no catálogo antes de dar entrada no estoque.' },
  ],
  '/documentos': [
    { alvo: FILTROS, titulo: 'Documentos', texto: 'Notas, termos e ofícios ligados às movimentações e cautelas.' },
    { alvo: TOPO, titulo: 'Enviar', texto: 'Envie um novo documento e vincule-o ao que ele comprova.' },
  ],
  '/dados': [
    { alvo: '#imp-t', titulo: 'Importar', texto: 'Baixe o modelo, preencha e envie. O sistema verifica tudo antes de gravar.' },
    { alvo: '#exp-t', titulo: 'Exportar', texto: 'Baixe em Excel ou CSV os cadastros que você pode ver.' },
  ],
  '/usuarios': [
    { alvo: TOPO, titulo: 'Usuários', texto: 'Crie contas e atribua papéis por unidade.' },
  ],
  '/configuracoes': [
    { alvo: '.config-grupo', titulo: 'Configurações', texto: 'Ligue e desligue operações e ajuste prazos. Toda mudança pede um motivo e fica na auditoria.' },
  ],
  '/auditoria': [
    { alvo: FILTROS, titulo: 'Auditoria', texto: 'Registro inalterável de tudo o que foi feito no sistema. Filtre por ação, usuário ou período.' },
  ],
  '/suporte': [
    { alvo: TOPO, titulo: 'Suporte', texto: 'Abra um chamado quando algo não funcionar ou tiver dúvida. Acompanhe as respostas aqui.' },
  ],
}

const chave = (uid: number, rota: string) => `sigarb.tour.${uid}.${rota}`
// shortcut: "já visto" fica no navegador (localStorage); em outro computador o tour reaparece uma vez. Se incomodar, gravar no servidor.
const visto = (k: string) => { try { return localStorage.getItem(k) === '1' } catch { return true } }
const marcar = (k: string) => { try { localStorage.setItem(k, '1') } catch { /* sem armazenamento: só não lembra */ } }

/** Retângulo visível do alvo, ou null se não existe/está oculto. */
function caixa(sel?: string) {
  const el = sel ? document.querySelector(sel) : null
  if (!el) return null
  const r = el.getBoundingClientRect()
  return r.width && r.height ? el : null
}

/** Mostra o tour da rota atual na primeira visita do usuário; o botão "Tour" permite rever. */
export function Tour() {
  const { pathname } = useLocation()
  const { perfil } = useAuth()
  const uid = perfil?.usuario.id
  const passos = TOURS[pathname]
  const [ativos, setAtivos] = useState<Passo[] | null>(null)
  const [i, setI] = useState(0)

  const abrir = useCallback(() => {
    // Só passos visíveis agora (o perfil ou a largura da tela pode esconder alvos).
    const v = (passos ?? []).filter((p) => !p.alvo || caixa(p.alvo))
    if (v.length) { setI(0); setAtivos(v) }
  }, [passos])

  useEffect(() => {
    setAtivos(null)
    if (!uid || !passos || visto(chave(uid, pathname))) return
    const t = setTimeout(abrir, 700) // espera a tela carregar os dados
    return () => clearTimeout(t)
  }, [uid, pathname, passos, abrir])

  const fechar = () => { if (uid) marcar(chave(uid, pathname)); setAtivos(null) }

  return <>
    {passos && <button type="button" className="btn btn-sec tour-rever" onClick={abrir}><HelpCircle size={18} aria-hidden="true" /><span className="rotulo-largo">Tour</span><span className="sr-only"> desta página</span></button>}
    {ativos && <Balao passo={ativos[i]} n={i} total={ativos.length} voltar={() => setI(i - 1)} avancar={() => i + 1 < ativos.length ? setI(i + 1) : fechar()} fechar={fechar} />}
  </>
}

function Balao({ passo, n, total, voltar, avancar, fechar }: { passo: Passo; n: number; total: number; voltar: () => void; avancar: () => void; fechar: () => void }) {
  const [r, setR] = useState<DOMRect | null>(null)
  const prox = useRef<HTMLButtonElement>(null)

  useLayoutEffect(() => {
    const el = caixa(passo.alvo)
    if (!el) { setR(null); return }
    el.scrollIntoView({ block: 'center', behavior: 'instant' as ScrollBehavior })
    const medir = () => setR(el.getBoundingClientRect())
    medir()
    window.addEventListener('resize', medir); window.addEventListener('scroll', medir, true)
    return () => { window.removeEventListener('resize', medir); window.removeEventListener('scroll', medir, true) }
  }, [passo])

  useEffect(() => { prox.current?.focus() }, [passo])
  useEffect(() => {
    const tecla = (e: KeyboardEvent) => { if (e.key === 'Escape') fechar() }
    window.addEventListener('keydown', tecla); return () => window.removeEventListener('keydown', tecla)
  }, [fechar])

  // Balão abaixo do alvo se couber, senão acima, senão ao lado (alvo alto, ex.: menu); centralizado sem alvo.
  const vw = window.innerWidth, vh = window.innerHeight, L = Math.min(360, vw - 32), H = 230
  const preso = (v: number, max: number) => Math.max(16, Math.min(v, max))
  const estilo: React.CSSProperties = !r ? { width: L, left: '50%', top: '50%', transform: 'translate(-50%, -50%)' }
    : r.bottom + 12 + H < vh ? { width: L, left: preso(r.left, vw - L - 16), top: r.bottom + 12 }
    : r.top - 12 - H > 0 ? { width: L, left: preso(r.left, vw - L - 16), bottom: vh - r.top + 12 }
    : { width: L, left: preso(r.right + 12, vw - L - 16), top: preso(r.top, vh - H - 16) }

  return <>
    {r ? <div className="tour-foco" aria-hidden="true" style={{ top: r.top - 6, left: r.left - 6, width: r.width + 12, height: r.height + 12 }} />
      : <div className="tour-fundo" aria-hidden="true" />}
    <div className="tour-balao" role="dialog" aria-modal="false" aria-labelledby="tour-t" aria-describedby="tour-d" style={estilo}>
      <button type="button" className="btn btn-sec btn-icone tour-x" aria-label="Pular tour" onClick={fechar}><X size={18} aria-hidden="true" /></button>
      <p className="tour-passo">{n + 1} de {total}</p>
      <h2 id="tour-t">{passo.titulo}</h2>
      <p id="tour-d">{passo.texto}</p>
      <div className="acoes">
        {n > 0 ? <button type="button" className="btn btn-sec" onClick={voltar}>Voltar</button> : <button type="button" className="btn btn-sec" onClick={fechar}>Pular</button>}
        <button ref={prox} type="button" className="btn btn-pri" onClick={avancar}>{n + 1 < total ? 'Próximo' : 'Concluir'}</button>
      </div>
    </div>
  </>
}
