import { useState, type FormEvent } from 'react'
import { KeyRound, LogOut } from 'lucide-react'
import { ErroApi, api } from '../api/client'
import type { Perfil } from '../api/tipos'
import { useAuth } from '../auth/AuthContext'
import { Aviso, Campo } from '../components/ui'

/** Primeiro acesso (senha padrão ou redefinida pelo administrador): troca obrigatória antes de usar o sistema. */
export default function TrocarSenhaPage({ obrigatoria }: { obrigatoria?: boolean }) {
  const { atualizarPerfil, sair, perfil } = useAuth()
  const [f, setF] = useState({ senha_atual: '', nova_senha: '', nova_senha_confirmation: '' })
  const [erro, setErro] = useState<ErroApi | null>(null)
  const [enviando, setEnviando] = useState(false)
  const fe = (k: string) => erro?.erro.fields?.[k]

  const enviar = async (e: FormEvent) => {
    e.preventDefault(); setEnviando(true); setErro(null)
    try { atualizarPerfil(await api<Perfil>('/me/password', { method: 'POST', body: f })) } catch (x) { if (x instanceof ErroApi) setErro(x) } finally { setEnviando(false) }
  }

  return (
    <div className="acesso">
      <main className="acesso-cartao" id="conteudo" aria-labelledby="ts-t">
        <div className="acesso-faixa" aria-hidden="true" />
        <div className="acesso-corpo">
        <header className="acesso-topo">
          <img src={`${import.meta.env.BASE_URL}logo-ronda-do-bairro.png`} alt="" className="acesso-logo" width="64" height="64" />
          <div><p className="acesso-sobrescrito">Arsenal</p><h1 id="ts-t">Crie a sua senha</h1></div>
        </header>
        {obrigatoria && <p className="acesso-desc">Olá, {perfil?.usuario.nome}. Você entrou com a senha padrão. Para continuar, crie uma senha só sua.</p>}
        {erro && !erro.erro.fields && <div className="aviso aviso-perigo" role="alert">{erro.erro.message}</div>}
        <form onSubmit={enviar} noValidate className="acesso-form">
          <Campo rotulo="Senha atual" obrigatorio dica="A senha padrão que você usou para entrar." erro={fe('senha_atual')}>{(p) => <input {...p} type="password" autoComplete="current-password" value={f.senha_atual} onChange={(e) => setF({ ...f, senha_atual: e.target.value })} autoFocus />}</Campo>
          <Campo rotulo="Nova senha" obrigatorio dica="Mínimo de 8 caracteres, com letras e números." erro={fe('nova_senha')}>{(p) => <input {...p} type="password" autoComplete="new-password" value={f.nova_senha} onChange={(e) => setF({ ...f, nova_senha: e.target.value })} />}</Campo>
          <Campo rotulo="Repita a nova senha" obrigatorio erro={fe('nova_senha_confirmation')}>{(p) => <input {...p} type="password" autoComplete="new-password" value={f.nova_senha_confirmation} onChange={(e) => setF({ ...f, nova_senha_confirmation: e.target.value })} />}</Campo>
          {f.nova_senha && f.nova_senha_confirmation && f.nova_senha !== f.nova_senha_confirmation && <Aviso tipo="alerta">As senhas não conferem.</Aviso>}
          <button className="btn btn-pri btn-bloco btn-grande" disabled={enviando || !f.senha_atual || !f.nova_senha || f.nova_senha !== f.nova_senha_confirmation}><KeyRound size={20} aria-hidden="true" />{enviando ? 'Salvando…' : 'Salvar senha e entrar'}</button>
          <button type="button" className="btn btn-sec btn-bloco" onClick={() => sair()}><LogOut size={18} aria-hidden="true" />Sair</button>
        </form>
        </div>
      </main>
    </div>
  )
}
