import Logo from '../components/Logo'
import { useState, type FormEvent } from 'react'
import { Navigate, useNavigate } from 'react-router'
import { ErroApi } from '../api/client'
import { useAuth } from '../auth/AuthContext'
import { Aviso, Campo } from '../components/ui'

export default function LoginPage() {
  const { perfil, entrar, expirada } = useAuth()
  const nav = useNavigate()
  const [login, setLogin] = useState('')
  const [senha, setSenha] = useState('')
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
    <main className="login" id="conteudo">
      <div className="login-cartao">
        <div className="login-marca">
          <Logo grande />
          <div>
            <p className="marca-grande">SIGARB</p>
            <p className="login-sub">Sistema Integrado de Gestão de Armamentos e Reserva Bélica</p>
          </div>
        </div>
        <h1>Acesso ao sistema</h1>
        <p className="descricao">Entre com sua conta individual. O acesso é registrado na trilha de auditoria.</p>
        <Aviso tipo="alerta">Ambiente de demonstração com dados fictícios. Não utilize credenciais ou informações reais.</Aviso>
        {expirada && <Aviso tipo="info">Sua sessão expirou ou foi encerrada. Entre novamente.</Aviso>}
        {erro && <div className="aviso aviso-perigo" role="alert">{erro}</div>}
        <form onSubmit={enviar} noValidate>
          <Campo rotulo="Identificador" obrigatorio>{(p) => <input {...p} autoComplete="username" value={login} onChange={(e) => setLogin(e.target.value)} autoFocus />}</Campo>
          <Campo rotulo="Senha" obrigatorio>{(p) => <input {...p} type="password" autoComplete="current-password" value={senha} onChange={(e) => setSenha(e.target.value)} />}</Campo>
          <button className="btn btn-pri btn-bloco" disabled={enviando || !login || !senha}>{enviando ? 'Entrando…' : 'Entrar'}</button>
        </form>
      </div>
    </main>
  )
}
