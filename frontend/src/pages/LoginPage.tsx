import { mascaraCpf } from '../components/cpf'
import { useState, type FormEvent } from 'react'
import { Link, Navigate, useNavigate } from 'react-router'
import { ArrowLeft, Eye, EyeOff } from 'lucide-react'
import { ErroApi } from '../api/client'
import { useAuth } from '../auth/AuthContext'
import { Aviso, Campo } from '../components/ui'

/** Login no padrão do ecossistema (Visita-Segura, RondaSocial, Sistema-de-escalas): faixa institucional, logo do Ronda no Bairro, emblema SEPREV e rodapé.
 *  Formulário idêntico ao login do Ronda Social (aprovado em 2026-11-18): rótulos sem *, "Esqueci minha senha" à direita sob a senha,
 *  botão "Entrar" #1e3a5f/#2d5a8e sem ícone e sem trava antes de preencher (a validação acontece no envio). Só CPF: não há entrada por e-mail. */
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
  // O Arsenal não tem recuperação por e-mail: "Esqueci minha senha" só orienta onde pedir a redefinição.
  const [verEsqueci, setVerEsqueci] = useState(false)
  if (perfil) return <Navigate to="/" replace />

  const enviar = async (e: FormEvent) => {
    e.preventDefault()
    if (etapaMfa ? codigo.trim().length < 6 : !login.trim() || !senha) {
      setErro(etapaMfa ? 'Digite o código de verificação.' : 'Preencha CPF e senha.')
      return
    }
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
        {etapaMfa ? (
          <form onSubmit={enviar} noValidate className="acesso-form acesso-form-rs">
            <Campo rotulo="Código de verificação" dica="Sem o celular? Use um dos códigos de recuperação (ex.: ABCDE-12345).">{(p) => (
              <input {...p} aria-required="true" autoComplete="one-time-code" inputMode="text" maxLength={20} value={codigo} onChange={(e) => setCodigo(e.target.value)} autoFocus />)}</Campo>
            {erro && <div className="aviso aviso-perigo" role="alert">{erro}</div>}
            <button className="btn btn-entrar" disabled={enviando}>{enviando ? 'Verificando…' : 'Verificar e entrar'}</button>
            <button type="button" className="btn btn-sec btn-bloco" onClick={() => { setEtapaMfa(false); setCodigo(''); setErro(null) }}><ArrowLeft size={18} aria-hidden="true" />Voltar</button>
          </form>
        ) : (
        <form onSubmit={enviar} noValidate className="acesso-form acesso-form-rs">
          <Campo rotulo="CPF">{(p) => <input {...p} aria-required="true" autoComplete="username" inputMode="numeric" placeholder="000.000.000-00" value={login} onChange={(e) => setLogin(mascaraCpf(e.target.value))} autoFocus />}</Campo>
          <Campo rotulo="Senha">{(p) => (
            <div className="campo-senha">
              <input {...p} aria-required="true" type={ver ? 'text' : 'password'} autoComplete="current-password" value={senha} onChange={(e) => setSenha(e.target.value)} />
              <button type="button" className="ver-senha" aria-label={ver ? 'Ocultar senha' : 'Mostrar senha'} aria-pressed={ver} onClick={() => setVer((v) => !v)}>
                {ver ? <EyeOff size={20} aria-hidden="true" /> : <Eye size={20} aria-hidden="true" />}
              </button>
            </div>)}</Campo>
          <div className="acesso-link-sob acesso-link-direita">
            <button type="button" className="acesso-link" aria-expanded={verEsqueci} aria-controls="esqueci-aviso" onClick={() => setVerEsqueci((v) => !v)}>Esqueci minha senha</button>
          </div>
          <div id="esqueci-aviso" role="status" className="acesso-status">
            {verEsqueci && <p className="aviso aviso-info acesso-aviso">Para redefinir a senha, procure o setor de T.I. ou o gestor da sua unidade.</p>}
          </div>
          {erro && <div className="aviso aviso-perigo" role="alert">{erro}</div>}
          <button className="btn btn-entrar" disabled={enviando}>{enviando ? 'Entrando…' : 'Entrar'}</button>
        </form>
        )}
        <RodapeAcesso />
        </div>
      </main>
    </div>
  )
}

/** Rodapé das telas públicas: selos do Ronda no Bairro e SEPREV/Governo de Alagoas, copyright do PRB e link para o Sobre. */
export function RodapeAcesso() {
  return (
    <>
      <div className="acesso-emblema">
        <img src={`${import.meta.env.BASE_URL}logo-ronda-do-bairro.png`} alt="Programa Ronda no Bairro" width="48" height="48" className="acesso-emblema-ronda" />
        <img src={`${import.meta.env.BASE_URL}Logo-seprev.png`} alt="Seprev — Secretaria de Estado de Prevenção à Violência, Governo de Alagoas" width="529" height="95" />
      </div>
      <footer className="acesso-rodape">
        <p>Copyright © {new Date().getFullYear()} PRB · AL — Programa Ronda no Bairro, Governo de Alagoas. Todos os direitos reservados.</p>
        <p><Link to="/sobre" className="acesso-sobre">Sobre o sistema</Link></p>
      </footer>
    </>
  )
}
