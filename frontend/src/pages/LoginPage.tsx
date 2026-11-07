import { useState, type FormEvent } from 'react'
import { Navigate, useNavigate } from 'react-router'
import { Eye, EyeOff, LogIn } from 'lucide-react'
import Logo from '../components/Logo'
import { ErroApi } from '../api/client'
import { useAuth } from '../auth/AuthContext'
import { Aviso, Campo } from '../components/ui'

/** Login no padrão das telas de acesso do RondaSocial e do Visita-Segura: fundo da marca, cartão translúcido, marca + sobrescrito. */
export default function LoginPage() {
  const { perfil, entrar, expirada } = useAuth()
  const nav = useNavigate()
  const [login, setLogin] = useState('')
  const [senha, setSenha] = useState('')
  const [ver, setVer] = useState(false)
  const [erro, setErro] = useState<string | null>(null)
  const [enviando, setEnviando] = useState(false)
  if (perfil) return <Navigate to="/" replace />

  const enviar = async (e: FormEvent) => {
    e.preventDefault()
    setErro(null)
    setEnviando(true)
    try {
      await entrar(login.trim(), senha)
      nav('/', { replace: true })
    } catch (x) {
      setErro(x instanceof ErroApi ? x.erro.message : 'Falha de comunicação com o servidor.')
      setSenha('')
    } finally {
      setEnviando(false)
    }
  }

  return (
    <div className="acesso">
      <main className="acesso-cartao" id="conteudo">
        <header className="acesso-topo">
          <Logo grande />
          <div>
            <p className="acesso-sobrescrito">SIGARB</p>
            <h1>Acesso ao sistema</h1>
          </div>
        </header>
        <p className="acesso-desc">Entre com o seu identificador e a sua senha.</p>
        {expirada && <Aviso tipo="info">Sua sessão expirou ou foi encerrada. Entre novamente.</Aviso>}
        {erro && <div className="aviso aviso-perigo" role="alert">{erro}</div>}
        <form onSubmit={enviar} noValidate className="acesso-form">
          <Campo rotulo="Identificador" obrigatorio>{(p) => <input {...p} autoComplete="username" value={login} onChange={(e) => setLogin(e.target.value)} autoFocus />}</Campo>
          <Campo rotulo="Senha" obrigatorio>{(p) => (
            <div className="campo-senha">
              <input {...p} type={ver ? 'text' : 'password'} autoComplete="current-password" value={senha} onChange={(e) => setSenha(e.target.value)} />
              <button type="button" className="ver-senha" aria-label={ver ? 'Ocultar senha' : 'Mostrar senha'} aria-pressed={ver} onClick={() => setVer((v) => !v)}>
                {ver ? <EyeOff size={20} aria-hidden="true" /> : <Eye size={20} aria-hidden="true" />}
              </button>
            </div>)}</Campo>
          <button className="btn btn-pri btn-bloco btn-grande" disabled={enviando || !login || !senha}><LogIn size={20} aria-hidden="true" />{enviando ? 'Entrando…' : 'Entrar'}</button>
        </form>
        <footer className="acesso-rodape">
          <p>Sistema Integrado de Gestão de Armamentos e Reserva Bélica</p>
          <p>Acesso restrito e registrado em trilha de auditoria.</p>
        </footer>
      </main>
    </div>
  )
}
