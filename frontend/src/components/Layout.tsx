import { useEffect, useRef, useState } from 'react'
import { NavLink, Outlet, useLocation, useNavigate } from 'react-router'
import { useAuth } from '../auth/AuthContext'

const MENU: { para: string; rotulo: string; perm?: string; grupo: string }[] = [
  { para: '/', rotulo: 'Início', grupo: 'Geral' },
  { para: '/estoque', rotulo: 'Saldos de estoque', perm: 'estoque.ver', grupo: 'Estoque' },
  { para: '/movimentacoes', rotulo: 'Movimentações', perm: 'estoque.ver', grupo: 'Estoque' },
  { para: '/documentos', rotulo: 'Documentos', perm: 'documentos.ver', grupo: 'Estoque' },
  { para: '/operacoes', rotulo: 'Operações e pendências', grupo: 'Estoque' },
  { para: '/militares', rotulo: 'Militares', perm: 'militares.ver', grupo: 'Cadastros' },
  { para: '/materiais', rotulo: 'Catálogo de materiais', perm: 'catalogo.ver', grupo: 'Cadastros' },
  { para: '/organizacao', rotulo: 'Unidades e locais', perm: 'organizacao.ver', grupo: 'Cadastros' },
  { para: '/usuarios', rotulo: 'Usuários e acessos', perm: 'usuarios.ver', grupo: 'Administração' },
  { para: '/auditoria', rotulo: 'Auditoria', perm: 'auditoria.ver', grupo: 'Administração' },
]

export default function Layout() {
  const { perfil, sair, pode } = useAuth()
  const [aberto, setAberto] = useState(false)
  const loc = useLocation()
  const nav = useNavigate()
  const principal = useRef<HTMLElement>(null)

  // Ao trocar de rota: fecha a gaveta e leva o foco ao título (anúncio para leitores de tela).
  useEffect(() => {
    setAberto(false)
    const t = setTimeout(() => (document.getElementById('titulo-pagina') ?? principal.current)?.focus(), 0)
    return () => clearTimeout(t)
  }, [loc.pathname])

  const itens = MENU.filter((m) => !m.perm || pode(m.perm))
  const grupos = [...new Set(itens.map((i) => i.grupo))]

  return (
    <div className="app">
      <a href="#conteudo" className="pular">Pular para o conteúdo</a>
      <div className="faixa-ambiente" role="note">Ambiente de DEMONSTRAÇÃO — dados fictícios. Sem validade institucional.</div>
      <header className="topo">
        <button className="btn-menu" aria-expanded={aberto} aria-controls="menu-lateral" onClick={() => setAberto((a) => !a)}>
          <span aria-hidden="true">☰</span><span className="sr-only">Menu</span>
        </button>
        <span className="marca" aria-label="SIGARB">SIGARB</span>
        <div className="usuario">
          <span className="usuario-nome">{perfil?.usuario.nome}</span>
          <span className="usuario-papeis">{perfil?.papeis.map((p) => `${p.papel_nome}${p.unidade ? ' · ' + p.unidade.sigla : ' · global'}`).join(', ')}</span>
        </div>
        <button className="btn btn-sec" onClick={async () => { await sair(); nav('/login') }}>Sair</button>
      </header>
      <nav id="menu-lateral" className={`lateral${aberto ? ' aberto' : ''}`} aria-label="Navegação principal">
        {grupos.map((g) => (
          <div key={g} className="grupo">
            <p className="grupo-titulo">{g}</p>
            <ul>{itens.filter((i) => i.grupo === g).map((i) => <li key={i.para}><NavLink to={i.para} end={i.para === '/'}>{i.rotulo}</NavLink></li>)}</ul>
          </div>
        ))}
        <div className="grupo"><ul><li><NavLink to="/sessoes">Minhas sessões</NavLink></li></ul></div>
      </nav>
      {aberto && <div className="veu" onClick={() => setAberto(false)} aria-hidden="true" />}
      <main id="conteudo" ref={principal} tabIndex={-1}><Outlet /></main>
    </div>
  )
}
