import { useCallback, useEffect, useRef, useState } from 'react'
import { Tour } from './Tour'
import { Link, NavLink, Outlet, useLocation, useNavigate } from 'react-router'
import {
  ArrowLeftRight, Bell, Boxes, CalendarClock, UserCheck, Building2, FileText, Headset, LayoutDashboard, LifeBuoy, ListChecks, ListTree, LogOut, Menu, MonitorSmartphone,
  MoonStar, Package, IdCard, ShieldCheck, ShieldEllipsis, ShieldHalf, ClipboardList, BellRing, Download, SunMedium, UserCog, Users, X, FileSpreadsheet, SlidersHorizontal, KeyRound, Info, Inbox, PackagePlus, PackageCheck, PackageOpen, type LucideIcon } from 'lucide-react'
import { api } from '../api/client'
import { soPortal } from '../api/tipos'
import { useAuth } from '../auth/AuthContext'
import Logo from './Logo'
import { dataHora } from './ui'
import { AbrirChamadoCentral, LinkCentral, useCentralSuporte } from './CentralSuporte'
import { quandoCentral } from './centralSuporte'

type Item = { para: string; rotulo: string; icone: LucideIcon; perm?: string; grupo: string; contador?: 'atendimento' | 'pedidos' }

// Visibilidade do menu é só conveniência: o servidor autoriza cada requisição (RNF-03).
export const MENU: Item[] = [
  { para: '/', rotulo: 'Início', icone: LayoutDashboard, grupo: 'Geral' },
  { para: '/pedidos', rotulo: 'Pedidos de material', icone: Inbox, perm: 'pedidos.gerir', grupo: 'Estoque', contador: 'pedidos' },
  // Decisão 2026-11-18: a operação do balcão vem primeiro; telas de gestão exigem permissões que o armeiro não tem.
  { para: '/entregas', rotulo: 'Entrega de material', icone: UserCheck, perm: 'estoque.ver', grupo: 'Estoque' },
  { para: '/devolucoes', rotulo: 'Devolução de material', icone: PackageOpen, perm: 'estoque.entregar', grupo: 'Estoque' },
  { para: '/estoque', rotulo: 'Saldos de estoque', icone: Boxes, perm: 'estoque.ver', grupo: 'Estoque' },
  { para: '/estoque/itens', rotulo: 'Itens e validades', icone: CalendarClock, perm: 'estoque.itens', grupo: 'Estoque' },
  { para: '/acautelamentos', rotulo: 'Acautelamentos', icone: ShieldHalf, perm: 'estoque.ver', grupo: 'Estoque' },
  { para: '/relatorios', rotulo: 'Relatórios', icone: Download, perm: 'relatorios.exportar|relatorios.entregas', grupo: 'Estoque' },
  { para: '/alertas', rotulo: 'Alertas', icone: BellRing, grupo: 'Estoque' },
  { para: '/movimentacoes', rotulo: 'Movimentações', icone: ArrowLeftRight, perm: 'movimentacoes.ver', grupo: 'Gestão' },
  { para: '/operacoes', rotulo: 'Operações e pendências', icone: ListChecks, perm: 'movimentacoes.ver', grupo: 'Gestão' },
  { para: '/inventarios', rotulo: 'Inventários', icone: ClipboardList, perm: 'inventario.ver', grupo: 'Gestão' },
  { para: '/documentos', rotulo: 'Documentos', icone: FileText, perm: 'documentos.ver', grupo: 'Gestão' },
  { para: '/militares', rotulo: 'Efetivos', icone: Users, perm: 'militares.ver', grupo: 'Cadastros' },
  { para: '/materiais', rotulo: 'Catálogo de materiais', icone: Package, perm: 'catalogo.ver', grupo: 'Cadastros' },
  { para: '/dados', rotulo: 'Importar e exportar', icone: FileSpreadsheet, perm: 'dados.importar|relatorios.exportar', grupo: 'Cadastros' },
  { para: '/organizacao', rotulo: 'Unidades e locais', icone: Building2, perm: 'organizacao.ver', grupo: 'Cadastros' },
  { para: '/usuarios', rotulo: 'Usuários e acessos', icone: UserCog, perm: 'usuarios.ver', grupo: 'Administração' },
  { para: '/referencias', rotulo: 'Listas de referência', icone: ListTree, perm: 'referencias.gerir', grupo: 'Administração' },
  { para: '/permissoes', rotulo: 'Permissões', icone: KeyRound, perm: 'sistema.configurar', grupo: 'Administração' },
  { para: '/configuracoes', rotulo: 'Configurações do sistema', icone: SlidersHorizontal, perm: 'sistema.configurar', grupo: 'Administração' },
  { para: '/auditoria', rotulo: 'Auditoria', icone: ShieldCheck, perm: 'auditoria.ver', grupo: 'Administração' },
  { para: '/suporte', rotulo: 'Central de suporte', icone: LifeBuoy, perm: 'suporte.abrir', grupo: 'Suporte' },
  { para: '/suporte/atendimento', rotulo: 'Atendimento', icone: Headset, perm: 'suporte.atender', grupo: 'Suporte', contador: 'atendimento' },
  { para: '/perfil', rotulo: 'Meu perfil', icone: IdCard, grupo: 'Conta' },
  { para: '/seguranca', rotulo: 'Segurança da conta', icone: ShieldEllipsis, grupo: 'Conta' },
  { para: '/sessoes', rotulo: 'Minhas sessões', icone: MonitorSmartphone, grupo: 'Conta' },
]

/** Portal do efetivo (decisão do responsável, 2026-11-18). Menu enxuto de quem só tem o portal; quem também gere vê o grupo "Meu portal". */
const PORTAL: Item[] = [
  { para: '/portal/novo', rotulo: 'Novo pedido', icone: PackagePlus, perm: 'portal.efetivo', grupo: 'Meu portal' },
  { para: '/portal/pedidos', rotulo: 'Meus pedidos', icone: ClipboardList, perm: 'portal.efetivo', grupo: 'Meu portal' },
  { para: '/portal/material', rotulo: 'Material comigo', icone: PackageCheck, perm: 'portal.efetivo', grupo: 'Meu portal' },
]
const CONTA = MENU.filter((m) => m.grupo === 'Conta')
export const MENU_PORTAL: Item[] = [{ para: '/', rotulo: 'Início', icone: LayoutDashboard, grupo: 'Meu portal' }, ...PORTAL, ...CONTA]

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

type Notificacao = { id: number; texto: string; lida: boolean; criada_em: string; chamado: { codigo: string; protocolo: string } | null; link?: string | null }

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

function useContadorPedidos(ativo: boolean) {
  const [total, setTotal] = useState(0)
  useEffect(() => {
    if (!ativo) return
    const ler = () => api<{ pendentes: number }>('/material-requests/summary').then((r) => setTotal(r.pendentes)).catch(() => {})
    ler()
    const t = setInterval(() => { if (!document.hidden) ler() }, 60_000)
    return () => clearInterval(t)
  }, [ativo])
  return total
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
                  <NavLink to={i.para} end={i.para === '/' || i.para === '/suporte' || i.para === '/estoque'} className="item-menu" onClick={aoNavegar} title={i.rotulo}>
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
  // Avisos da Central de Suporte PRB (outro sistema) somam no contador do sino; cada um abre na Central em nova aba.
  const central = useCentralSuporte(!!perfil, 60_000)
  const naoLidas = notif.nao_lidas + central.nao_lidos
  const contadores = { atendimento: useContadorAtendimento(pode('suporte.atender')), pedidos: useContadorPedidos(pode('pedidos.gerir')) }

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

  const portal = soPortal(perfil?.permissoes)
  const itens = portal ? MENU_PORTAL : [...MENU.filter((m) => m.grupo !== 'Conta'), ...PORTAL, ...CONTA].filter((m) => !m.perm || pode(m.perm))
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
          <Link to="/" className="lateral-marca" aria-label="Arsenal — início">
            <Logo />
            <span className="lateral-marca-texto">
              <span className="lateral-marca-nome">Arsenal</span>
              <span className="lateral-marca-sub bloco-linha">Reserva de armamento</span>
            </span>
          </Link>
          <div className="lateral-perfil">
            <span className="item-chip" aria-hidden="true">{iniciais(perfil?.usuario.nome)}</span>
            <span><span className="lateral-perfil-nome bloco-linha">{perfil?.usuario.nome}</span><span className="lateral-perfil-papel bloco-linha">{papelPrincipal}</span></span>
          </div>
          <nav aria-label="Navegação principal"><ItensMenu itens={itens} contadores={contadores} /></nav>
          <NavLink to="/sobre" className="item-menu" title="Sobre o sistema"><span className="item-chip"><Info size={20} aria-hidden="true" /></span><span>Sobre o sistema</span></NavLink>
          <button type="button" className="item-menu lateral-sair" onClick={async () => { await sair(); nav('/login') }} title="Sair do sistema">
            <span className="item-chip"><LogOut size={20} aria-hidden="true" /></span><span>Sair</span>
          </button>
          <div className="lateral-emblema"><div><img src={`${import.meta.env.BASE_URL}Logo-seprev.png`} alt="Seprev — Secretaria de Estado de Prevenção à Violência" width="529" height="95" /></div></div>
        </div>
      </aside>

      <div className="topo-movel">
        <button ref={btnMenu} type="button" className="btn-claro" aria-expanded={aberto} aria-controls="menu-movel" onClick={() => setAberto(true)}>
          <Menu size={22} aria-hidden="true" />Menu
        </button>
        <Logo />
        <p className="topo-movel-titulo">{atual?.rotulo ?? 'Arsenal'}</p>
      </div>
      {aberto && (
        <>
          <div className="gaveta-fundo" onClick={() => setAberto(false)} aria-hidden="true" />
          <div ref={gaveta} id="menu-movel" className="gaveta" role="dialog" aria-modal="true" aria-label="Menu principal">
            <div className="gaveta-topo">
              <Logo />
              <div className="estica">
                <p className="lateral-marca-nome">Arsenal</p>
                <p className="lateral-marca-sub">{papelPrincipal}</p>
              </div>
              <button type="button" className="btn-claro btn-icone" aria-label="Fechar menu" onClick={() => setAberto(false)}><X size={22} aria-hidden="true" /></button>
            </div>
            <nav aria-label="Navegação principal (celular)"><ItensMenu itens={itens} contadores={contadores} aoNavegar={() => setAberto(false)} /></nav>
            <NavLink to="/sobre" className="item-menu" onClick={() => setAberto(false)}><span className="item-chip"><Info size={20} aria-hidden="true" /></span><span>Sobre o sistema</span></NavLink>
          </div>
        </>
      )}

      <div className="conteudo">
        <header className="topo" aria-label="Sua conta">
          <div className="topo-usuario">
            <span className="avatar" aria-hidden="true">{iniciais(perfil?.usuario.nome)}</span>
            <div className="estica">
              <p className="topo-saudacao">Bem-vindo(a)</p>
              <p className="topo-nome">{perfil?.usuario.nome}</p>
              <div className="topo-papeis">
                {papeis.map((p, i) => <span key={i} className="selo selo-info">{p.papel_nome}{p.unidade ? ` · ${p.unidade.sigla}` : ' · global'}</span>)}
                {/* PSI-PRB 7.5 / Anexo III: o sistema trata informação de nível RESTRITO. */}
                <span className="selo selo-alerta selo-classificacao" title="Informação restrita – Política de Segurança da Informação do PRB">Uso restrito</span>
              </div>
            </div>
          </div>
          <div className="topo-acoes">
            <Tour />
            <div className="notif-sino" ref={sino}>
              <button type="button" className="btn btn-sec" aria-expanded={notifAberta} aria-controls="painel-notificacoes" onClick={() => setNotifAberta((a) => !a)}>
                <Bell size={18} aria-hidden="true" /><span className="rotulo-largo" aria-hidden="true">Notificações</span>
                {/* Nome acessível sempre presente: no celular o rótulo visível fica oculto. */}
                <span className="sr-only">{'Notificações'}{naoLidas ? ` (${naoLidas} não lida${naoLidas === 1 ? '' : 's'})` : ''}</span>
                {naoLidas > 0 && <span className="contador" aria-hidden="true">{naoLidas > 99 ? '99+' : naoLidas}</span>}
              </button>
              {notifAberta && (
                <div id="painel-notificacoes" className="painel-notif" role="region" aria-label="Notificações">
                  <div className="painel-notif-topo">
                    <h2>Notificações</h2>
                    {notif.nao_lidas > 0 && <button type="button" className="btn btn-sec" onClick={async () => { await api('/notifications/read-all', { method: 'POST' }).catch(() => {}); notif.atualizar() }}>Marcar todas como lidas</button>}
                  </div>
                  {notif.data.length === 0 && central.avisos.length === 0 ? <p className="dica painel-notif-vazio">Nenhuma notificação.</p> : notif.data.length > 0 && (
                    <ul>
                      {notif.data.map((n) => (
                        <li key={n.id} className={n.lida ? undefined : 'nao-lida'}>
                          <Link to={n.link ?? (n.chamado ? `/suporte/chamados/${encodeURIComponent(n.chamado.codigo)}` : '/suporte')} onClick={() => abrirNotificacao(n)}>
                            {!n.lida && <span className="sr-only">Não lida: </span>}{n.texto}<time dateTime={n.criada_em}>{dataHora(n.criada_em)}</time>
                          </Link>
                        </li>
                      ))}
                    </ul>
                  )}
                  {central.avisos.length > 0 && (
                    <>
                      <h3 className="painel-notif-secao">Central de Suporte PRB</h3>
                      <ul aria-label="Avisos da Central de Suporte PRB">
                        {central.avisos.map((a) => (
                          <li key={`central-${a.id}`} className="nao-lida">
                            {a.link ? (
                              <LinkCentral href={a.link}><span className="sr-only">Não lida: </span>{a.texto}{a.criada_em && <time dateTime={a.criada_em.replace(' ', 'T')}>{quandoCentral(a.criada_em)}</time>}</LinkCentral>
                            ) : <span className="painel-notif-texto">{a.texto}</span>}
                          </li>
                        ))}
                      </ul>
                    </>
                  )}
                  {central.url_abrir && <div className="painel-notif-rodape"><AbrirChamadoCentral url={central.url_abrir} /></div>}
                </div>
              )}
            </div>
            <button type="button" className="btn btn-sec" onClick={alternar}>
              {tema === 'dark' ? <SunMedium size={18} aria-hidden="true" /> : <MoonStar size={18} aria-hidden="true" />}
              <span className="rotulo-largo" aria-hidden="true">{tema === 'dark' ? 'Tema claro' : 'Tema escuro'}</span>
              <span className="sr-only">{tema === 'dark' ? 'Ativar tema claro' : 'Ativar tema escuro'}</span>
            </button>
            <button type="button" className="btn btn-sec-perigo so-movel" onClick={async () => { await sair(); nav("/login") }}>
              <LogOut size={18} aria-hidden="true" />Sair
            </button>
          </div>
        </header>
        <main id="conteudo" ref={principal} tabIndex={-1}><Outlet /></main>
      </div>
    </div>
  )
}
