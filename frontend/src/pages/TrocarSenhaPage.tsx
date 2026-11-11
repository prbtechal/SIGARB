import { useState, type FormEvent } from 'react'
import { Check, Circle, KeyRound, LogOut } from 'lucide-react'
import { ErroApi, api } from '../api/client'
import type { Perfil } from '../api/tipos'
import { useAuth } from '../auth/AuthContext'
import { Aviso, Campo } from '../components/ui'

/** PSI-PRB 10.1 (mínimo 8 por decisão do responsável). O servidor é quem decide; a lista só orienta enquanto a pessoa digita. */
export const SENHA_MIN = 8
const simples = (s: string) => s.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase()
const PARTICULAS = ['das', 'dos', 'des', 'del']

export function requisitosSenha(senha: string, nome = '', login = '') {
  const s = simples(senha)
  const partes = simples(nome).split(/[^a-z]+/).filter((p) => p.length >= 3 && !PARTICULAS.includes(p))
  const doc = login.replace(/\D/g, '')
  return [
    { ok: senha.length >= SENHA_MIN, texto: `Pelo menos ${SENHA_MIN} caracteres` },
    { ok: /\p{Lu}/u.test(senha), texto: 'Uma letra maiúscula' },
    { ok: /\p{Ll}/u.test(senha), texto: 'Uma letra minúscula' },
    { ok: /\d/.test(senha), texto: 'Um número' },
    { ok: /[^\p{L}\p{N}]/u.test(senha), texto: 'Um caractere especial (ex.: ! @ # -)' },
    { ok: !!senha && !partes.some((p) => s.includes(p)), texto: 'Não contém o seu nome' },
    { ok: !!senha && !(doc.length >= 4 && senha.replace(/\D/g, '').includes(doc)), texto: 'Não contém o seu CPF' },
  ]
}

/** Senha padrão/redefinida (primeiro acesso) ou vencida (troca a cada 90 dias): troca obrigatória antes de usar o sistema. */
export default function TrocarSenhaPage({ obrigatoria, motivo = 'provisoria' }: { obrigatoria?: boolean; motivo?: 'provisoria' | 'expirada' }) {
  const { atualizarPerfil, sair, perfil } = useAuth()
  const [f, setF] = useState({ senha_atual: '', nova_senha: '', nova_senha_confirmation: '' })
  const [erro, setErro] = useState<ErroApi | null>(null)
  const [enviando, setEnviando] = useState(false)
  const fe = (k: string) => erro?.erro.fields?.[k]
  const reqs = requisitosSenha(f.nova_senha, perfil?.usuario.nome, perfil?.usuario.login)
  const tudoOk = reqs.every((r) => r.ok)

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
        {obrigatoria && <p className="acesso-desc">{motivo === 'expirada'
          ? <>Olá, {perfil?.usuario.nome}. A sua senha venceu: pela Política de Segurança da Informação do PRB, ela é trocada a cada 90 dias. Crie uma senha nova para continuar.</>
          : <>Olá, {perfil?.usuario.nome}. Você entrou com uma senha provisória. Para continuar, crie uma senha só sua.</>}</p>}
        {erro && !erro.erro.fields && <div className="aviso aviso-perigo" role="alert">{erro.erro.message}</div>}
        <form onSubmit={enviar} noValidate className="acesso-form">
          <Campo rotulo="Senha atual" obrigatorio dica={motivo === 'expirada' ? 'A senha que você usou para entrar.' : 'A senha provisória que você usou para entrar.'} erro={fe('senha_atual')}>{(p) => <input {...p} type="password" autoComplete="current-password" value={f.senha_atual} onChange={(e) => setF({ ...f, senha_atual: e.target.value })} autoFocus />}</Campo>
          <Campo rotulo="Nova senha" obrigatorio erro={fe('nova_senha')}>{(p) => <input {...p} aria-describedby={[p['aria-describedby'], 'ts-req'].filter(Boolean).join(' ')} type="password" autoComplete="new-password" value={f.nova_senha} onChange={(e) => setF({ ...f, nova_senha: e.target.value })} />}</Campo>
          <ul id="ts-req" className="requisitos-senha" aria-label="Requisitos da nova senha">
            {reqs.map((r) => <li key={r.texto} className={r.ok ? 'ok' : undefined}>
              {r.ok ? <Check size={16} aria-hidden="true" /> : <Circle size={16} aria-hidden="true" />}<span>{r.texto}<span className="sr-only">{r.ok ? ': atendido' : ': pendente'}</span></span>
            </li>)}
            <li className="dica"><span>Diferente das suas últimas 5 senhas (conferido ao salvar)</span></li>
          </ul>
          <Campo rotulo="Repita a nova senha" obrigatorio erro={fe('nova_senha_confirmation')}>{(p) => <input {...p} type="password" autoComplete="new-password" value={f.nova_senha_confirmation} onChange={(e) => setF({ ...f, nova_senha_confirmation: e.target.value })} />}</Campo>
          {f.nova_senha && f.nova_senha_confirmation && f.nova_senha !== f.nova_senha_confirmation && <Aviso tipo="alerta">As senhas não conferem.</Aviso>}
          <button className="btn btn-pri btn-bloco btn-grande" disabled={enviando || !f.senha_atual || !tudoOk || f.nova_senha !== f.nova_senha_confirmation}><KeyRound size={20} aria-hidden="true" />{enviando ? 'Salvando…' : 'Salvar senha e entrar'}</button>
          <button type="button" className="btn btn-sec btn-bloco" onClick={() => sair()}><LogOut size={18} aria-hidden="true" />Sair</button>
        </form>
        </div>
      </main>
    </div>
  )
}
