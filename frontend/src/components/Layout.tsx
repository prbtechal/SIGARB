import { useCallback, useEffect, useRef, useState } from 'react'
import { Link, NavLink, Outlet, useLocation, useNavigate } from 'react-router'
import {
  ArrowLeftRight, Bell, Boxes, Building2, FileText, Headset, LayoutDashboard, LifeBuoy, ListChecks, ListTree, LogOut, Menu, MonitorSmartphone,
  MoonStar, Package, ShieldCheck, SunMedium, TriangleAlert, UserCog, Users, X, type LucideIcon,
} from 'lucide-react'
import { api } from '../api/client'
import { useAuth } from '../auth/AuthContext'
import Logo from './Logo'
import { dataHora } from './ui'

type Item = { para: string; rotulo: string; icone: LucideIcon; perm?: string; grupo: string; contador?: 'atendimento' }

// Visibilidade do menu é só conveniência: o servidor autoriza cada requisição (RNF-03).
export const MENU: Item[] = [
  { para: '/', rotulo: 'Início', icone: LayoutDashboard, grupo: 'Geral' },
  { para: '/estoque', rotulo: 'Saldos de estoque', icone: Boxes, perm: 'estoque.ver', grupo: 'Estoque' },
  { para: '/movimentacoes', rotulo: 'Movimentações', icone: ArrowLeftRight, perm: 'estoque.ver', grupo: 'Estoque' },
  { para: '/documentos', rotulo: 'Documentos', icone: FileText, perm: 'documentos.ver', grupo: 'Estoque' },
  { para: '/operacoes', rotulo: 'Operações e pendências', icone: ListChecks, grupo: 'Estoque' },
  { para: '/militares', rotulo: 'Militares', icone: Users, perm: 'militares.ver', grupo: 'Cadastros' },
  { para: '/materiais', rotulo: 'Catálogo de materiais', icone: Package, perm: 'catalogo.ver', grupo: 'Cadastros' },
  { para: '/organizacao', rotulo: 'Unidades e locais', icone: Building2, perm: 'organizacao.ver', grupo: 'Cadastros' },
  { para: '/usuarios', rotulo: 'Usuários e acessos', icone: UserCog, perm: 'usuarios.ver', grupo: 'Administração' },
  { para: '/referencias', rotulo: 'Listas de referência', icone: ListTree, perm: 'referencias.gerir', grupo: 'Administração' },
  { para: '/auditoria', rotulo: 'Auditoria', icone: ShieldCheck, perm: 'auditoria.ver', grupo: 'Administração' },
  { para: '/suporte', rotulo: 'Central de suporte', icone: LifeBuoy, perm: 'suporte.abrir', grupo: 'Suporte' },
  { para: '/suporte/atendimento', rotulo: 'Atendimento', icone: Headset, perm: 'suporte.atender', grupo: 'Suporte', contador: 'atendimento' },
  { para: '/sessoes', rotulo: 'Minhas sessões', icone: MonitorSmartphone, grupo: 'Conta' },
]

const iniciais = (nome?: string) => (nome ?? '?').split(/\s+/).filter(Boolean).slice(0, 2).map((p) => p[0]).join('').toUpperCase()

/** Tema: claro/escuro escolhido pelo usuário; sem escolha, segue o sistema. Armazenamento é só conveniência. */
function useTema() {
  const ler = () => { try { return localStorage.getItem('sigarb-tema') } catch { return null } }
  const sistemaEscuro = () => typeof matchMedia === 'function' && matchMedia('(prefers-color-scheme: dark)').matches
  const [tema, setTema] = useState<'light' | 'dark'>(() => (ler() as 'light' | 'dark' | null) ?? (sistemaEscuro() ? 'dark' : 'light'))
  useEffect(() => { document.documentElement.dataset.theme = tema }, [tema])
  const alternar = () => setTema((t) => {
    const n = t === 'dark' ? 'light' : 'dark'
    try { localStorage.setItem('sigarb-tema', n) } catch { /* sem armazenamento: vale só nesta aba */ }
    return n
  })
  return { tema, alternar }
}

type Notificacao = { id: number; texto: string; lida: boolean; criada_em: string; chamado: { codigo: string; protocolo: string } | null }

function useNotificacoes(ativo: boolean) {
  const [dados, setDados] = useState<{ nao_lidas: number; data: Notificacao[] }>({ nao_lidas: 0, data: [] })
  const atualizar = useCallback(async () => {
    try { setDados(await api('/notifications')) } catch { /* falha de rede: mantém o último estado */ }
  }, [])
  useEffect(() => {
    if (!ativo) return
    atualizar()
    const t = setInterval(() => { if (!document.hidden) atualizar() }, 60_000)
    window.addEventListener('sigarb:suporte-alterado', atualizar)
    return () => { clearInterval(t); window.removeEventListener('sigarb:suporte-alterado', atualizar) }
  }, [ativo, atualizar])
  return { ...dados, atualizar }
}

function useContadorAtendimento(ativo: boolean) {
  const [total, setTotal] = useState(0)
  useEffect(() => {
    if (!ativo) return
    const ler = () => api<{ sem_responsavel: number; meus: number }>('/support/queue/summary').then((r) => setTotal(r.sem_responsavel + r.meus)).catch(() => {})
    ler()
    const t = setInterval(() => { if (!document.hidden) ler() }, 60_000)
    window.addEventListener('sigarb:suporte-alterado', ler)
    return () => { clearInterval(t); window.removeEventListener('sigarb:suporte-alterado', ler) }
  }, [ativo])
  return total
}

function ItensMenu({ itens, contadores, aoNavegar }: { itens: Item[]; contadores: Record<string, number>; aoNavegar?: () => void }) {
  const grupos = [...new Set(itens.map((i) => i.grupo))]
  return (
    <>
      {grupos.map((g) => (
        <div key={g} className="grupo">
          <p className="grupo-titulo">{g}</p>
          <ul>
            {itens.filter((i) => i.grupo === g).map((i) => {
              const total = i.contador ? contadores[i.contador] ?? 0 : 0
              return (
                <li key={i.para}>
                  <NavLink to={i.para} end={i.para === '/' || i.para === '/suporte'} className="item-menu" onClick={aoNavegar} title={i.rotulo}>
                    <span className="item-chip"><i.icone size={20} aria-hidden="true" />{total > 0 && <span className="contador" aria-hidden="true">{total > 99 ? '99+' : total}</span>}</span>
                    <span>{i.rotulo}{total > 0 && <span className="sr-only"> ({total} pendente{total === 1 ? '' : 's'})</span>}</span>
                  </NavLink>
                </li>
              )
            })}
          </ul>
        </div>
      ))}
    </>
  )
}

const FOCAVEIS = 'a[href], button:not([disabled])'

export default function Layout() {
  const { perfil, sair, pode } = useAuth()
  const [aberto, setAberto] = useState(false)
  const [notifAberta, setNotifAberta] = useState(false)
  const loc = useLocation()
  const nav = useNavigate()
  const principal = useRef<HTMLElement>(null)
  const gaveta = useRef<HTMLDivElement>(null)
  const btnMenu = useRef<HTMLButtonElement>(null)
  const sino = useRef<HTMLDivElement>(null)
  const { tema, alternar } = useTema()
  const notif = useNotificacoes(!!perfil)
  const contadores = { atendimento: useContadorAtendimento(pode('suporte.atender')) }

  // Ao trocar de rota: fecha gaveta/painel e leva o foco ao título (anúncio para leitores de tela).
  useEffect(() => {
    setAberto(false)
    setNotifAberta(false)
    const t = setTimeout(() => (document.getElementById('titulo-pagina') ?? principal.current)?.focus(), 0)
    return () => clearTimeout(t)
  }, [loc.pathname])

  // Gaveta (celular): Esc fecha, foco preso dentro, foco volta ao botão Menu, rolagem do fundo travada.
  useEffect(() => {
    if (!aberto) return
    const gatilho = btnMenu.current
    document.body.style.overflow = 'hidden'
    gaveta.current?.querySelector<HTMLElement>(FOCAVEIS)?.focus()
    const tecla = (e: KeyboardEvent) => {
      if (e.key === 'Escape') { setAberto(false); return }
      if (e.key !== 'Tab' || !gaveta.current) return
      const itens = [...gaveta.current.querySelectorAll<HTMLElement>(FOCAVEIS)]
      if (!itens.length) return
      if (e.shiftKey && document.activeElement === itens[0]) { e.preventDefault(); itens[itens.length - 1].focus() }
      else if (!e.shiftKey && document.activeElement === itens[itens.length - 1]) { e.preventDefault(); itens[0].focus() }
    }
    document.addEventListener('keydown', tecla)
    return () => { document.removeEventListener('keydown', tecla); document.body.style.overflow = ''; gatilho?.focus() }
  }, [aberto])

  // Painel de notificações: Esc ou clique fora fecha.
  useEffect(() => {
    if (!notifAberta) return
    const fora = (e: MouseEvent) => { if (!sino.current?.contains(e.target as Node)) setNotifAberta(false) }
    const esc = (e: KeyboardEvent) => { if (e.key === 'Escape') setNotifAberta(false) }
    document.addEventListener('mousedown', fora)
    document.addEventListener('keydown', esc)
    return () => { document.removeEventListener('mousedown', fora); document.removeEventListener('keydown', esc) }
  }, [notifAberta])

  const itens = MENU.filter((m) => !m.perm || pode(m.perm))
  const atual = [...itens].reverse().find((i) => (i.para === '/' ? loc.pathname === '/' : loc.pathname.startsWith(i.para)))
  const papeis = perfil?.papeis ?? []
  const papelPrincipal = papeis[0] ? `${papeis[0].papel_nome}${papeis[0].unidade ? ' · ' + papeis[0].unidade.sigla : ' · global'}` : 'Usuário'

  const abrirNotificacao = async (n: Notificacao) => {
    if (!n.lida) await api(`/notifications/${n.id}/read`, { method: 'POST' }).catch(() => {})
    notif.atualizar()
  }

  return (
    <div className="app">
      <a href="#conteudo" className="pular">Pular para o conteúdo</a>

      <aside className="lateral" aria-label="Menu lateral">
        <div className="lateral-miolo">
          <Link to="/" className="lateral-marca" aria-label="SIGARB — início">
            <Logo />
            <span className="lateral-marca-texto">
              <span className="lateral-marca-nome">SIGARB</span>
              <span className="lateral-marca-sub bloco-linha">Reserva de armamento</span>
            </span>
          </Link>
          <nav aria-label="Navegação principal"><ItensMenu itens={itens} contadores={contadores} /></nav>
        </div>
      </aside>

      <div className="topo-movel">
        <button ref={btnMenu} type="button" className="btn-claro" aria-expanded={aberto} aria-controls="menu-movel" onClick={() => setAberto(true)}>
          <Menu size={22} aria-hidden="true" />Menu
        </button>
        <Logo />
        <p className="topo-movel-titulo">{atual?.rotulo ?? 'SIGARB'}</p>
      </div>
      {aberto && (
        <>
          <div className="gaveta-fundo" onClick={() => setAberto(false)} aria-hidden="true" />
          <div ref={gaveta} id="menu-movel" className="gaveta" role="dialog" aria-modal="true" aria-label="Menu principal">
            <div className="gaveta-topo">
              <Logo />
              <div className="estica">
                <p className="lateral-marca-nome">SIGARB</p>
                <p className="lateral-marca-sub">{papelPrincipal}</p>
              </div>
              <button type="button" className="btn-claro btn-icone" aria-label="Fechar menu" onClick={() => setAberto(false)}><X size={22} aria-hidden="true" /></button>
            </div>
            <nav aria-label="Navegação principal (celular)"><ItensMenu itens={itens} contadores={contadores} aoNavegar={() => setAberto(false)} /></nav>
          </div>
        </>
      )}

      <div className="conteudo">
        <div className="faixa-ambiente" role="note"><TriangleAlert size={16} aria-hidden="true" />Ambiente de DEMONSTRAÇÃO — dados fictícios. Sem validade institucional.</div>
        <header className="topo" aria-label="Sua conta">
          <div className="topo-usuario">
            <span className="avatar" aria-hidden="true">{iniciais(perfil?.usuario.nome)}</span>
            <div className="estica">
              <p className="topo-saudacao">Bem-vindo(a)</p>
              <p className="topo-nome">{perfil?.usuario.nome}</p>
              <div className="topo-papeis">
                {papeis.map((p, i) => <span key={i} className="selo selo-info">{p.papel_nome}{p.unidade ? ` · ${p.unidade.sigla}` : ' · global'}</span>)}
              </div>
            </div>
          </div>
          <div className="topo-acoes">
            <div className="notif-sino" ref={sino}>
              <button type="button" className="btn btn-sec" aria-expanded={notifAberta} aria-controls="painel-notificacoes" onClick={() => setNotifAberta((a) => !a)}>
                <Bell size={18} aria-hidden="true" /><span className="rotulo-largo" aria-hidden="true">Notificações</span>
                {/* Nome acessível sempre presente: no celular o rótulo visível fica oculto. */}
                <span className="sr-only">{'Notificações'}{notif.nao_lidas ? ` (${notif.nao_lidas} não lida${notif.nao_lidas === 1 ? '' : 's'})` : ''}</span>
                {notif.nao_lidas > 0 && <span className="contador" aria-hidden="true">{notif.nao_lidas > 99 ? '99+' : notif.nao_lidas}</span>}
              </button>
              {notifAberta && (
                <div id="painel-notificacoes" className="painel-notif" role="region" aria-label="Notificações">
                  <div className="painel-notif-topo">
                    <h2>Notificações</h2>
                    {notif.nao_lidas > 0 && <button type="button" className="btn btn-sec" onClick={async () => { await api('/notifications/read-all', { method: 'POST' }).catch(() => {}); notif.atualizar() }}>Marcar todas como lidas</button>}
                  </div>
                  {notif.data.length === 0 ? <p className="dica painel-notif-vazio">Nenhuma notificação.</p> : (
                    <ul>
                      {notif.data.map((n) => (
                        <li key={n.id} className={n.lida ? undefined : 'nao-lida'}>
                          <Link to={n.chamado ? `/suporte/chamados/${encodeURIComponent(n.chamado.codigo)}` : '/suporte'} onClick={() => abrirNotificacao(n)}>
                            {!n.lida && <span className="sr-only">Não lida: </span>}{n.texto}<time dateTime={n.criada_em}>{dataHora(n.criada_em)}</time>
                          </Link>
                        </li>
                      ))}
                    </ul>
                  )}
                </div>
              )}
            </div>
            <button type="button" className="btn btn-sec" onClick={alternar}>
              {tema === 'dark' ? <SunMedium size={18} aria-hidden="true" /> : <MoonStar size={18} aria-hidden="true" />}
              <span className="rotulo-largo" aria-hidden="true">{tema === 'dark' ? 'Tema claro' : 'Tema escuro'}</span>
              <span className="sr-only">{tema === 'dark' ? 'Ativar tema claro' : 'Ativar tema escuro'}</span>
            </button>
            <button type="button" className="btn btn-sec-perigo" onClick={async () => { await sair(); nav('/login') }}>
              <LogOut size={18} aria-hidden="true" />Sair
            </button>
          </div>
        </header>
        <main id="conteudo" ref={principal} tabIndex={-1}><Outlet /></main>
      </div>
    </div>
  )
}
