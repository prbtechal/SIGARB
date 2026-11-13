import { UserCog } from 'lucide-react'
import { useState } from 'react'
import { ErroApi, api, type Pagina as Pag } from '../api/client'
import type { Militar, Posto, Unidade, Usuario } from '../api/tipos'
import { Link } from 'react-router'
import { CamposFuncionais, deFuncionais, paraEnvio, type FormFuncional } from './MeuPerfilPage'
import { exibirLogin, mascaraCpf } from '../components/cpf'
import { useAuth } from '../auth/AuthContext'
import { Campo, Carregando, Dialogo, Erro, Pagina, Paginacao, Selo, Tabela, Vazio, data, dataHora, useNotificar } from '../components/ui'
import { useRecurso } from '../components/useRecurso'

export default function UsuariosPage() {
  const { pode, perfil } = useAuth()
  const notificar = useNotificar()
  const [page, setPage] = useState(1)
  const lista = useRecurso(() => api<Pag<Usuario>>('/users', { query: { page } }), [page])
  const papeis = useRecurso(() => api<{ data: { codigo: string; nome: string }[] }>('/roles'), [])
  const unidades = useRecurso(() => api<{ data: Unidade[] }>('/units'), [])
  const [sel, setSel] = useState<Usuario | null>(null)
  const [novo, setNovo] = useState(false)
  const [nf, setNf] = useState({ nome: '', login: '', password: '', militar_id: '' })
  const [atr, setAtr] = useState({ papel: '', unidade_id: '', expira_em: '' })
  const [confirmar, setConfirmar] = useState<Usuario | null>(null)
  // PSI-PRB 10.3 (desbloqueio) e cap. 11 (redefinir verificação em duas etapas): sempre com motivo, que vai para a auditoria.
  const [acaoMotivo, setAcaoMotivo] = useState<{ u: Usuario; tipo: 'unlock' | 'mfa' } | null>(null)
  const [motivo, setMotivo] = useState('')
  const [ed, setEd] = useState<{ id: number; nome: string; login: string; email: string; militar_id: string; senha_padrao: boolean; original: string; fn: FormFuncional; fnOriginal: FormFuncional; motivo: string } | null>(null)
  const postos = useRecurso(() => ed ? api<{ data: Posto[] }>('/catalog/postos-graduacoes') : Promise.resolve(null), [!!ed])
  const fnMudou = !!ed && JSON.stringify(ed.fn) !== JSON.stringify(ed.fnOriginal)
  const [erro, setErro] = useState<ErroApi | null>(null)
  const gerir = pode('usuarios.gerir')

  const exec = async (fn: () => Promise<unknown>, ok: string) => {
    setErro(null)
    try { await fn(); notificar('ok', ok); lista.recarregar(); return true } catch (x) { if (x instanceof ErroApi) setErro(x); return false }
  }
  return (
    <Pagina icone={UserCog} titulo="Usuários e acessos" trilha={[{ rotulo: 'Início', para: '/' }, { rotulo: 'Usuários' }]}
      descricao="Contas são desativadas, nunca apagadas. Ninguém altera os próprios papéis. Senha inicial: o CPF da pessoa (troca obrigatória no primeiro acesso)."
      acoes={gerir && <button className="btn btn-pri" onClick={() => { setErro(null); setNovo(true) }}>Criar conta</button>}>
      {erro && !novo && !sel && !ed && !acaoMotivo && <div className="aviso aviso-perigo" role="alert">{erro.erro.message}</div>}
      {lista.carregando && <Carregando />}{lista.erro && <Erro erro={lista.erro} tentar={lista.recarregar} />}
      {lista.dados && (lista.dados.data.length === 0 ? <Vazio titulo="Nenhum usuário." /> : <>
        <Tabela legenda="Usuários" linhas={lista.dados.data} chave={(u) => u.id} colunas={[
          { titulo: 'Usuário', celula: (u) => <><strong>{u.nome}</strong><br /><small className="mono">{exibirLogin(u.login)}</small></> },
          { titulo: 'Papéis', celula: (u) => u.atribuicoes.length ? u.atribuicoes.map((a) => `${a.papel}${a.unidade ? '@' + a.unidade.sigla : ' (global)'}`).join(', ') : '—' },
          { titulo: 'Efetivo / cargo', celula: (u) => <>{u.militar?.nome_guerra ?? '—'}<br /><small>{[u.posto_graduacao?.sigla ?? 'Civil / sem posto', u.cargo].filter(Boolean).join(' · ')}</small></> },
          { titulo: 'Último acesso', celula: (u) => (u.ultimo_login_em ? dataHora(u.ultimo_login_em) : '—') },
          { titulo: 'Situação', celula: (u) => <span className="acoes-linha"><Selo valor={u.ativo ? 'ATIVO' : 'INATIVO'} />{u.bloqueado_em && <Selo valor="Bloqueada" tom="perigo" />}{u.mfa_ativo && <Selo valor="2 etapas" tom="ok" />}</span> },
          { titulo: 'Ações', celula: (u) => gerir && u.id !== perfil?.usuario.id ? <span className="acoes-linha">
            <button className="btn btn-sec" onClick={() => { setErro(null); setEd({ id: u.id, nome: u.nome, login: exibirLogin(u.login), email: u.email ?? '', militar_id: u.militar ? String(u.militar.id) : '', senha_padrao: false, original: exibirLogin(u.login), fn: deFuncionais(u), fnOriginal: deFuncionais(u), motivo: '' }) }}>Editar <span className="sr-only">{u.login}</span></button>
            <button className="btn btn-sec" onClick={() => { setErro(null); setSel(u) }}>Papéis <span className="sr-only">de {u.login}</span></button>
            <button className="btn btn-sec" onClick={() => setConfirmar(u)}>{u.ativo ? 'Desativar' : 'Reativar'} <span className="sr-only">{u.login}</span></button>
            {u.bloqueado_em && <button className="btn btn-pri" onClick={() => { setErro(null); setMotivo(''); setAcaoMotivo({ u, tipo: 'unlock' }) }}>Desbloquear <span className="sr-only">{u.login}</span></button>}
            {u.mfa_ativo && <button className="btn btn-sec" onClick={() => { setErro(null); setMotivo(''); setAcaoMotivo({ u, tipo: 'mfa' }) }}>Redefinir 2 etapas <span className="sr-only">de {u.login}</span></button>}
          </span> : '—' },
        ]} />
        <Paginacao meta={lista.dados.meta} ir={setPage} />
      </>)}
      <Dialogo aberto={novo} titulo="Criar conta de acesso" onFechar={() => setNovo(false)}
        rodape={<><button className="btn btn-sec" onClick={() => setNovo(false)}>Cancelar</button><button className="btn btn-pri" onClick={async () => { if (await exec(() => api('/users', { method: 'POST', body: { ...nf, password: nf.password || undefined, militar_id: nf.militar_id ? Number(nf.militar_id) : null } }), 'Conta criada.')) { setNovo(false); setNf({ nome: '', login: '', password: '', militar_id: '' }) } }}>Criar</button></>}>
        <div className="grade">
          <EscolherEfetivo valor={nf.militar_id} erro={erro?.erro.fields?.militar_id} escolher={(m) => setNf({ ...nf, militar_id: String(m.id), nome: nf.nome || m.nome })} />
          <Campo rotulo="Nome" obrigatorio erro={erro?.erro.fields?.nome}>{(p) => <input {...p} value={nf.nome} onChange={(e) => setNf({ ...nf, nome: e.target.value })} />}</Campo>
          <Campo rotulo="CPF" obrigatorio dica="É o que a pessoa digita para entrar." erro={erro?.erro.fields?.login}>{(p) => <input {...p} inputMode="numeric" placeholder="000.000.000-00" value={nf.login} onChange={(e) => setNf({ ...nf, login: mascaraCpf(e.target.value) })} />}</Campo>
          <Campo rotulo="Senha inicial" dica="Em branco = o CPF da pessoa (troca obrigatória no primeiro acesso). Se preencher, siga as regras de senha da PSI." erro={erro?.erro.fields?.password}>{(p) => <input {...p} type="password" autoComplete="new-password" value={nf.password} onChange={(e) => setNf({ ...nf, password: e.target.value })} />}</Campo>
        </div>
      </Dialogo>
      <Dialogo aberto={!!ed} titulo="Editar conta" onFechar={() => setEd(null)}
        rodape={<><button className="btn btn-sec" onClick={() => setEd(null)}>Cancelar</button><button className="btn btn-pri" onClick={async () => {
          if (!ed) return
          const { id, militar_id, email, senha_padrao, original, login, nome, fn, motivo } = ed
          // Login e dados funcionais só vão se mudaram (dados funcionais de outra pessoa pedem motivo, que vai para a auditoria).
          const body = { nome, ...(login !== original ? { login } : {}), email: email || null, ...(militar_id ? { militar_id: Number(militar_id) } : {}), ...(senha_padrao ? { senha_padrao } : {}),
            ...(fnMudou ? { ...paraEnvio(fn), motivo } : {}) }
          if (await exec(() => api(`/users/${id}`, { method: 'PUT', body }), 'Conta atualizada.')) setEd(null)
        }}>Salvar</button></>}>
        {ed && <div className="grade">
          <Campo rotulo="Nome" obrigatorio erro={erro?.erro.fields?.nome}>{(p) => <input {...p} value={ed.nome} onChange={(e) => setEd({ ...ed, nome: e.target.value })} />}</Campo>
          <Campo rotulo="CPF" obrigatorio dica="É o que a pessoa digita para entrar." erro={erro?.erro.fields?.login}>{(p) => <input {...p} inputMode="numeric" placeholder="000.000.000-00" value={ed.login} onChange={(e) => setEd({ ...ed, login: mascaraCpf(e.target.value) })} />}</Campo>
          <Campo rotulo="E-mail" erro={erro?.erro.fields?.email}>{(p) => <input {...p} type="email" value={ed.email} onChange={(e) => setEd({ ...ed, email: e.target.value })} />}</Campo>
          <EscolherEfetivo valor={ed.militar_id} atual={lista.dados?.data.find((x) => x.id === ed.id)?.militar?.nome_guerra} erro={erro?.erro.fields?.militar_id} escolher={(m) => setEd({ ...ed, militar_id: String(m.id) })} />
          {ed.militar_id && (() => { const m = lista.dados?.data.find((x) => x.id === ed.id)?.militar
            return m && String(m.id) === ed.militar_id ? <p className="dica">Dados do efetivo vinculado (referência): {m.posto ?? ''} {m.nome_guerra}{m.matricula ? ` · matrícula ${m.matricula}` : ''}{m.telefone ? ` · ${m.telefone}` : ''}</p> : null })()}
          <CamposFuncionais f={ed.fn} set={(fn) => setEd({ ...ed, fn })} postos={postos.dados?.data ?? []} erro={erro} />
          {fnMudou && <Campo rotulo="Motivo da alteração dos dados funcionais" obrigatorio dica="Fica registrado na auditoria." erro={erro?.erro.fields?.motivo}>{(p) => <input {...p} maxLength={300} value={ed.motivo} onChange={(e) => setEd({ ...ed, motivo: e.target.value })} />}</Campo>}
          <div className="campo"><label className="opcao"><input type="checkbox" checked={ed.senha_padrao} onChange={(e) => setEd({ ...ed, senha_padrao: e.target.checked })} /> Redefinir a senha para o CPF da pessoa</label>
            <small className="dica">A pessoa é desconectada de todos os aparelhos, entra com o CPF como senha e cria uma senha nova no próximo acesso. Conta de Administrador recebe a senha padrão do sistema.</small></div>
        </div>}
      </Dialogo>
      <Dialogo tour="papeis" aberto={!!sel} titulo={`Papéis de ${sel?.login ?? ''}`} onFechar={() => setSel(null)} rodape={<button className="btn btn-sec" onClick={() => setSel(null)}>Fechar</button>}>
        {erro && <div className="aviso aviso-perigo" role="alert">{erro.erro.message}</div>}
        <ul className="lista-simples">{(lista.dados?.data.find((u) => u.id === sel?.id) ?? sel)?.atribuicoes.map((a) => (
          <li key={a.id}>{a.papel} {a.unidade ? `@ ${a.unidade.sigla}` : '(global)'}{a.expira_em && <> — {a.vencida ? <Selo valor="Delegação vencida" tom="perigo" /> : `até ${data(a.expira_em)}`}</>} <button className="btn btn-sec" onClick={() => exec(() => api(`/users/${sel!.id}/roles/${a.id}`, { method: 'DELETE' }), 'Papel removido.')}>Remover</button></li>
        ))}</ul>
        <div className="grade">
          <Campo rotulo="Papel">{(p) => <select {...p} value={atr.papel} onChange={(e) => setAtr({ ...atr, papel: e.target.value })}><option value="">Selecione…</option>{papeis.dados?.data.map((x) => <option key={x.codigo} value={x.codigo}>{x.nome}</option>)}</select>}</Campo>
          <Campo rotulo="Escopo (unidade)" dica="Vazio = global">{(p) => <select {...p} value={atr.unidade_id} onChange={(e) => setAtr({ ...atr, unidade_id: e.target.value })}><option value="">Global</option>{unidades.dados?.data.map((u) => <option key={u.id} value={u.id}>{u.sigla}</option>)}</select>}</Campo>
          <Campo rotulo="Válido até" dica="Opcional. Use para delegação ou substituição temporária: no dia seguinte o papel deixa de valer.">{(p) => <input {...p} type="date" value={atr.expira_em} onChange={(e) => setAtr({ ...atr, expira_em: e.target.value })} />}</Campo>
        </div>
        <button className="btn btn-pri" disabled={!atr.papel} onClick={() => exec(() => api(`/users/${sel!.id}/roles`, { method: 'POST', body: { papel: atr.papel, unidade_id: atr.unidade_id ? Number(atr.unidade_id) : null, expira_em: atr.expira_em || null } }), 'Papel atribuído.')}>Atribuir papel</button>
      </Dialogo>
      <Dialogo aberto={!!acaoMotivo} titulo={acaoMotivo?.tipo === 'unlock' ? 'Desbloquear conta?' : 'Redefinir verificação em duas etapas?'} onFechar={() => setAcaoMotivo(null)}
        rodape={<><button className="btn btn-sec" onClick={() => setAcaoMotivo(null)}>Cancelar</button><button className="btn btn-pri" disabled={motivo.trim().length < 5} onClick={async () => {
          const a = acaoMotivo!
          if (await exec(() => api(a.tipo === 'unlock' ? `/users/${a.u.id}/unlock` : `/users/${a.u.id}/mfa/reset`, { method: 'POST', body: { motivo: motivo.trim() } }), a.tipo === 'unlock' ? 'Conta desbloqueada.' : 'Verificação em duas etapas redefinida.')) setAcaoMotivo(null)
        }}>Confirmar</button></>}>
        {erro && <div className="aviso aviso-perigo" role="alert">{erro.erro.message}</div>}
        <p>{acaoMotivo?.tipo === 'unlock'
          ? `A conta ${exibirLogin(acaoMotivo?.u.login ?? '')} foi bloqueada após 10 tentativas de acesso erradas seguidas. Confirme com o titular antes de desbloquear.`
          : `A pessoa deixa de usar o aplicativo autenticador atual (ex.: celular perdido), é desconectada de todos os aparelhos e pode cadastrar outro depois.`}</p>
        <Campo rotulo="Motivo" obrigatorio dica="Fica registrado na auditoria. Mínimo de 5 caracteres." erro={erro?.erro.fields?.motivo}>{(p) => <textarea {...p} rows={2} maxLength={300} value={motivo} onChange={(e) => setMotivo(e.target.value)} />}</Campo>
      </Dialogo>
      <Dialogo aberto={!!confirmar} titulo={confirmar?.ativo ? 'Desativar conta?' : 'Reativar conta?'} onFechar={() => setConfirmar(null)}
        rodape={<><button className="btn btn-sec" onClick={() => setConfirmar(null)}>Cancelar</button><button className={`btn ${confirmar?.ativo ? 'btn-perigo' : 'btn-pri'}`} onClick={async () => { await exec(() => api(`/users/${confirmar!.id}`, { method: 'PUT', body: { ativo: !confirmar!.ativo } }), 'Conta atualizada.'); setConfirmar(null) }}>Confirmar</button></>}>
        <p>{confirmar?.ativo ? `A conta ${confirmar?.login} perderá acesso imediatamente e todas as sessões serão encerradas. O histórico é preservado.` : `A conta ${confirmar?.login} voltará a poder entrar.`}</p>
      </Dialogo>
    </Pagina>
  )
}

/** Toda conta pertence a um efetivo: busca por nome, matrícula ou CPF entre os efetivos ainda sem conta. */
export function EscolherEfetivo({ valor, atual, erro, escolher }: { valor: string; atual?: string; erro?: string[]; escolher: (m: Militar) => void }) {
  const [q, setQ] = useState('')
  const [nome, setNome] = useState(atual ?? '')
  const res = useRecurso(() => q.trim().length >= 3 ? api<Pag<Militar>>('/militares', { query: { q: q.trim(), sem_conta: 1, per_page: 8 } }) : Promise.resolve(null), [q])
  return (
    <Campo rotulo="Efetivo" obrigatorio dica={valor ? `Escolhido: ${nome || 'efetivo #' + valor}. Busque outro para trocar.` : 'Busque por nome, matrícula ou CPF. Não encontrou? Cadastre em Efetivos e volte aqui.'} erro={erro}>
      {(p) => <div>
        <input {...p} type="search" placeholder="Nome, matrícula ou CPF" value={q} onChange={(e) => setQ(e.target.value)} />
        {res.dados && (res.dados.data.length === 0
          ? <p className="dica">Nenhum efetivo sem conta encontrado. <Link to="/militares/novo">Cadastrar efetivo</Link></p>
          : <ul className="lista-simples" aria-label="Efetivos encontrados">{res.dados.data.map((m) => <li key={m.id}>
            <button type="button" className="btn btn-sec" aria-pressed={String(m.id) === valor} onClick={() => { escolher(m); setNome(m.nome); setQ('') }}>
              {m.nome} <small>· {m.matricula}{m.cpf ? ` · CPF ${m.cpf}` : ''} · {m.unidade.sigla}{m.origem === 'LOCAL' ? ' · local' : ''}</small></button></li>)}</ul>)}
      </div>}
    </Campo>
  )
}
