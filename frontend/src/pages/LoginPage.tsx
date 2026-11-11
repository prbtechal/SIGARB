import { mascaraCpf } from '../components/cpf'
import { useState, type FormEvent } from 'react'
import { Navigate, useNavigate } from 'react-router'
import { ArrowLeft, Eye, EyeOff, LogIn, ShieldCheck } from 'lucide-react'
import { ErroApi } from '../api/client'
import { useAuth } from '../auth/AuthContext'
import { Aviso, Campo } from '../components/ui'

/** Login no padrão do ecossistema (Visita-Segura, RondaSocial, Sistema-de-escalas): faixa institucional, logo do Ronda no Bairro, emblema SEPREV e rodapé. */
export default function LoginPage() {
  const { perfil, entrar, confirmarMfa, expirada } = useAuth()
  const nav = useNavigate()
  const [login, setLogin] = useState('')
  const [senha, setSenha] = useState('')
  const [ver, setVer] = useState(false)
  const [erro, setErro] = useState<string | null>(null)
  const [enviando, setEnviando] = useState(false)
  // Segunda etapa (PSI-PRB cap. 11): só aparece para quem ativou a verificação em duas etapas.
  const [etapaMfa, setEtapaMfa] = useState(false)
  const [codigo, setCodigo] = useState('')
  if (perfil) return <Navigate to="/" replace />

  const enviar = async (e: FormEvent) => {
    e.preventDefault()
    setErro(null)
    setEnviando(true)
    try {
      if (etapaMfa) {
        await confirmarMfa(codigo.trim())
        nav('/', { replace: true })
      } else if ((await entrar(login.trim(), senha)) === 'MFA') {
        setEtapaMfa(true)
        setSenha('')
      } else {
        nav('/', { replace: true })
      }
    } catch (x) {
      setErro(x instanceof ErroApi ? x.erro.message : 'Falha de comunicação com o servidor.')
      setSenha('')
      setCodigo('')
      if (x instanceof ErroApi && x.erro.code === 'MFA_EXPIRADO') setEtapaMfa(false)
    } finally {
      setEnviando(false)
    }
  }

  return (
    <div className="acesso">
      <main className="acesso-cartao" id="conteudo">
        <div className="acesso-faixa" aria-hidden="true" />
        <div className="acesso-corpo">
        <header className="acesso-topo">
          <img src={`${import.meta.env.BASE_URL}logo-ronda-do-bairro.png`} alt="" className="acesso-logo" width="64" height="64" />
          <div>
            <p className="acesso-sobrescrito">SIGARB</p>
            <h1>Acesso ao sistema</h1>
          </div>
        </header>
        <p className="acesso-desc">{etapaMfa ? 'Abra o aplicativo autenticador no celular e digite o código de 6 dígitos do SIGARB.' : 'Entre com o seu CPF e a sua senha.'}</p>
        {expirada && !etapaMfa && <Aviso tipo="info">Sua sessão expirou ou foi encerrada. Entre novamente.</Aviso>}
        {erro && <div className="aviso aviso-perigo" role="alert">{erro}</div>}
        {etapaMfa ? (
          <form onSubmit={enviar} noValidate className="acesso-form">
            <Campo rotulo="Código de verificação" obrigatorio dica="Sem o celular? Use um dos códigos de recuperação (ex.: ABCDE-12345).">{(p) => (
              <input {...p} autoComplete="one-time-code" inputMode="text" maxLength={20} value={codigo} onChange={(e) => setCodigo(e.target.value)} autoFocus />)}</Campo>
            <button className="btn btn-pri btn-bloco btn-grande" disabled={enviando || codigo.trim().length < 6}><ShieldCheck size={20} aria-hidden="true" />{enviando ? 'Verificando…' : 'Verificar e entrar'}</button>
            <button type="button" className="btn btn-sec btn-bloco" onClick={() => { setEtapaMfa(false); setCodigo(''); setErro(null) }}><ArrowLeft size={18} aria-hidden="true" />Voltar</button>
          </form>
        ) : (
        <form onSubmit={enviar} noValidate className="acesso-form">
          <Campo rotulo="CPF" obrigatorio>{(p) => <input {...p} autoComplete="username" inputMode="numeric" placeholder="000.000.000-00" value={login} onChange={(e) => setLogin(mascaraCpf(e.target.value))} autoFocus />}</Campo>
          <Campo rotulo="Senha" obrigatorio>{(p) => (
            <div className="campo-senha">
              <input {...p} type={ver ? 'text' : 'password'} autoComplete="current-password" value={senha} onChange={(e) => setSenha(e.target.value)} />
              <button type="button" className="ver-senha" aria-label={ver ? 'Ocultar senha' : 'Mostrar senha'} aria-pressed={ver} onClick={() => setVer((v) => !v)}>
                {ver ? <EyeOff size={20} aria-hidden="true" /> : <Eye size={20} aria-hidden="true" />}
              </button>
            </div>)}</Campo>
          <button className="btn btn-pri btn-bloco btn-grande" disabled={enviando || !login || !senha}><LogIn size={20} aria-hidden="true" />{enviando ? 'Entrando…' : 'Entrar'}</button>
        </form>
        )}
        <div className="acesso-emblema">
          <img src={`${import.meta.env.BASE_URL}Logo-seprev.png`} alt="Seprev — Secretaria de Estado de Prevenção à Violência" width="529" height="95" />
        </div>
        <footer className="acesso-rodape">
          <p>Copyright © {new Date().getFullYear()} SEPREV · AL — Secretaria de Estado de Prevenção à Violência de Alagoas. Todos os direitos reservados.</p>
          <p>Desenvolvido por José Filho • <a href="mailto:contato@joseok.cloud">contato@joseok.cloud</a></p>
        </footer>
        </div>
      </main>
    </div>
  )
}
