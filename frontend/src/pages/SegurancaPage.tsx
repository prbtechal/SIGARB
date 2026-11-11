import { useState } from 'react'
import { ShieldCheck } from 'lucide-react'
import { ErroApi, api } from '../api/client'
import type { Perfil } from '../api/tipos'
import { useAuth } from '../auth/AuthContext'
import { Aviso, Campo, Dialogo, Pagina, Selo, useNotificar } from '../components/ui'

type Cadastro = { segredo: string; uri: string }

/**
 * Verificação em duas etapas por aplicativo autenticador (PSI-PRB cap. 11). Opcional, salvo quando o papel exige
 * (config `sigarb.mfa.papeis_obrigatorios`): nesse caso a tela abre sozinha e é o único caminho até concluir o cadastro.
 */
export default function SegurancaPage({ obrigatoria }: { obrigatoria?: boolean }) {
  const { perfil, atualizarPerfil, sair } = useAuth()
  const notificar = useNotificar()
  const [cad, setCad] = useState<Cadastro | null>(null)
  const [codigo, setCodigo] = useState('')
  const [recuperacao, setRecuperacao] = useState<string[] | null>(null)
  const [desligar, setDesligar] = useState(false)
  const [erro, setErro] = useState<string | null>(null)
  const [enviando, setEnviando] = useState(false)
  const [pendente, setPendente] = useState<Perfil | null>(null)
  const ativo = !!perfil?.usuario.mfa_ativo

  const rodar = async (fn: () => Promise<void>) => {
    setErro(null); setEnviando(true)
    try { await fn() } catch (x) { setErro(x instanceof ErroApi ? x.erro.message : 'Falha de comunicação com o servidor.') } finally { setEnviando(false) }
  }
  const iniciar = () => rodar(async () => { setCodigo(''); setCad(await api<Cadastro>('/me/mfa/setup', { method: 'POST' })) })
  const confirmar = () => rodar(async () => {
    const r = await api<{ codigos_recuperacao: string[]; perfil: Perfil }>('/me/mfa/confirm', { method: 'POST', body: { codigo: codigo.trim() } })
    setCad(null); setCodigo(''); setRecuperacao(r.codigos_recuperacao)
    // Atualiza o perfil só depois que a pessoa guardar os códigos (na obrigatória, isso libera o sistema).
    setPendente(r.perfil)
  })
  const concluir = () => { setRecuperacao(null); if (pendente) atualizarPerfil(pendente); notificar('ok', 'Verificação em duas etapas ativada.') }
  const desativar = () => rodar(async () => {
    atualizarPerfil(await api<Perfil>('/me/mfa', { method: 'DELETE', body: { codigo: codigo.trim() } }))
    setDesligar(false); setCodigo(''); notificar('ok', 'Verificação em duas etapas desativada.')
  })

  const chaveEmGrupos = cad?.segredo.match(/.{1,4}/g)?.join(' ')
  const corpo = (
    <section className="cartao seguranca-mfa" aria-labelledby="mfa-t">
      <div className="seguranca-cab">
        <h2 id="mfa-t">Verificação em duas etapas</h2>
        {ativo ? <Selo valor="Ativa" tom="ok" /> : <Selo valor="Recomendado" tom="info" />}
      </div>
      <p className="dica">Além da senha, o SIGARB pede um código de 6 dígitos gerado no seu celular por um aplicativo autenticador (ex.: Google Authenticator, Microsoft Authenticator, FreeOTP).</p>
      {erro && !cad && !desligar && <div className="aviso aviso-perigo" role="alert">{erro}</div>}

      {recuperacao ? (
        <div className="mfa-passo">
          <Aviso tipo="ok" titulo="Pronto! Guarde os códigos de recuperação">Cada código entra uma única vez, no lugar do código do aplicativo, se você perder o celular. Eles não serão mostrados de novo.</Aviso>
          <ul className="codigos-recuperacao mono" aria-label="Códigos de recuperação">{recuperacao.map((c) => <li key={c}>{c}</li>)}</ul>
          <button className="btn btn-pri" onClick={concluir}>Já guardei os códigos</button>
        </div>
      ) : ativo ? (
        <div className="acoes-linha">
          <p>Ativa nesta conta. Ao entrar, você informa o código do aplicativo.</p>
          {!perfil?.usuario.mfa_obrigatorio && <button className="btn btn-sec" onClick={() => { setErro(null); setCodigo(''); setDesligar(true) }}>Desativar</button>}
        </div>
      ) : cad ? (
        <ol className="mfa-passo lista-passos">
          <li>No aplicativo autenticador, escolha <strong>adicionar conta</strong> → <strong>inserir chave de configuração</strong>.</li>
          <li>Digite a chave (tipo: baseada em tempo):
            <p className="chave-mfa mono" aria-label={`Chave: ${cad.segredo.split('').join(' ')}`}>{chaveEmGrupos}</p>
            <details><summary>Endereço para outros aplicativos (otpauth)</summary><p className="mono quebra">{cad.uri}</p></details>
          </li>
          <li>
            {erro && <div className="aviso aviso-perigo" role="alert">{erro}</div>}
            <Campo rotulo="Código de 6 dígitos mostrado no aplicativo" obrigatorio>{(p) => <input {...p} inputMode="numeric" autoComplete="one-time-code" maxLength={6} value={codigo} onChange={(e) => setCodigo(e.target.value.replace(/\D/g, ''))} />}</Campo>
            <span className="acoes-linha">
              <button className="btn btn-pri" disabled={enviando || codigo.length !== 6} onClick={confirmar}>{enviando ? 'Verificando…' : 'Confirmar e ativar'}</button>
              {!obrigatoria && <button className="btn btn-sec" onClick={() => { setCad(null); setErro(null) }}>Cancelar</button>}
            </span>
          </li>
        </ol>
      ) : (
        <button className="btn btn-pri" disabled={enviando} onClick={iniciar}><ShieldCheck size={18} aria-hidden="true" />Ativar verificação em duas etapas (recomendado)</button>
      )}

      <Dialogo aberto={desligar} titulo="Desativar verificação em duas etapas?" onFechar={() => setDesligar(false)}
        rodape={<><button className="btn btn-sec" onClick={() => setDesligar(false)}>Cancelar</button><button className="btn btn-perigo" disabled={enviando || codigo.trim().length < 6} onClick={desativar}>Desativar</button></>}>
        <p>Sua conta volta a ser protegida só pela senha. Para confirmar, informe um código do aplicativo (ou de recuperação).</p>
        {erro && <div className="aviso aviso-perigo" role="alert">{erro}</div>}
        <Campo rotulo="Código" obrigatorio>{(p) => <input {...p} autoComplete="one-time-code" maxLength={20} value={codigo} onChange={(e) => setCodigo(e.target.value)} />}</Campo>
      </Dialogo>
    </section>
  )

  if (obrigatoria) {
    return (
      <div className="acesso">
        <main className="acesso-cartao" id="conteudo" aria-labelledby="mfa-ob">
          <div className="acesso-faixa" aria-hidden="true" />
          <div className="acesso-corpo">
            <header className="acesso-topo"><div><p className="acesso-sobrescrito">Arsenal</p><h1 id="mfa-ob">Proteja a sua conta</h1></div></header>
            <p className="acesso-desc">O seu perfil exige a verificação em duas etapas. Cadastre o aplicativo autenticador para continuar.</p>
            {corpo}
            <button type="button" className="btn btn-sec btn-bloco" onClick={() => sair()}>Sair</button>
          </div>
        </main>
      </div>
    )
  }
  return (
    <Pagina icone={ShieldCheck} titulo="Segurança da conta" trilha={[{ rotulo: 'Início', para: '/' }, { rotulo: 'Segurança da conta' }]}
      descricao="Senha trocada a cada 90 dias e bloqueio após 10 tentativas erradas seguidas (Política de Segurança da Informação do PRB).">
      {corpo}
    </Pagina>
  )
}
