import { Building2, Plus } from 'lucide-react'
import { useState } from 'react'
import { ErroApi, api } from '../api/client'
import type { Local, Unidade } from '../api/tipos'
import { useAuth } from '../auth/AuthContext'
import { Campo, Carregando, Dialogo, Erro, Pagina, Selo, Tabela, Vazio, useNotificar } from '../components/ui'
import { useRecurso } from '../components/useRecurso'

export default function OrganizacaoPage() {
  const { pode } = useAuth()
  const notificar = useNotificar()
  const un = useRecurso(() => api<{ data: Unidade[] }>('/units'), [])
  const lo = useRecurso(() => api<{ data: Local[] }>('/locations'), [])
  const [aberto, setAberto] = useState(false)
  const [n, setN] = useState({ unidade_id: '', codigo: '', nome: '', tipo: 'RESERVA' })
  const [erro, setErro] = useState<ErroApi | null>(null)
  // Cadastro de unidade/subunidade (a API já existia; faltava a entrada na tela).
  const [abertoU, setAbertoU] = useState(false)
  const [nu, setNu] = useState({ unidade_superior_id: '', sigla: '', nome: '' })
  const [erroU, setErroU] = useState<ErroApi | null>(null)
  const criarUnidade = async () => {
    setErroU(null)
    const sup = un.dados?.data.find((u) => String(u.id) === nu.unidade_superior_id)
    const org = sup?.organizacao.id ?? un.dados?.data[0]?.organizacao.id
    try {
      await api('/units', { method: 'POST', body: { organizacao_id: org, unidade_superior_id: sup ? sup.id : null, sigla: nu.sigla, nome: nu.nome, tipo: sup ? 'SUBUNIDADE' : 'UNIDADE' } })
      setAbertoU(false); setNu({ unidade_superior_id: '', sigla: '', nome: '' }); notificar('ok', 'Unidade cadastrada.'); un.recarregar()
    } catch (x) { if (x instanceof ErroApi) setErroU(x) }
  }
  const criar = async () => {
    setErro(null)
    try { await api('/locations', { method: 'POST', body: { ...n, unidade_id: Number(n.unidade_id) } }); setAberto(false); notificar('ok', 'Local cadastrado.'); lo.recarregar() }
    catch (x) { if (x instanceof ErroApi) setErro(x) }
  }
  return (
    <Pagina icone={Building2} titulo="Unidades e locais" trilha={[{ rotulo: 'Início', para: '/' }, { rotulo: 'Unidades e locais' }]}
      descricao="Estrutura organizacional: unidades, subunidades e locais de estoque."
      acoes={pode('organizacao.gerir') && <>
        <button className="btn btn-sec" onClick={() => { setErroU(null); setAbertoU(true) }}><Plus size={18} aria-hidden="true" />Cadastrar unidade</button>
        <button className="btn btn-pri" onClick={() => setAberto(true)}><Plus size={18} aria-hidden="true" />Cadastrar local</button>
      </>}>
      <h2>Unidades</h2>
      {un.carregando && <Carregando />}{un.erro && <Erro erro={un.erro} />}
      {un.dados && (un.dados.data.length === 0 ? <Vazio titulo="Nenhuma unidade no seu escopo." /> :
        <ul className="arvore">{un.dados.data.map((u) => <li key={u.id} style={{ marginInlineStart: `${u.nivel * 1.5}rem` }}><strong>{u.sigla}</strong> — {u.nome} <Selo valor={u.tipo} tom="neutro" /> {!u.ativo && <Selo valor="INATIVO" />}</li>)}</ul>)}
      <h2>Locais de estoque</h2>
      {lo.carregando && <Carregando />}{lo.erro && <Erro erro={lo.erro} />}
      {lo.dados && (lo.dados.data.length === 0 ? <Vazio titulo="Nenhum local cadastrado." /> :
        <Tabela legenda="Locais de estoque" linhas={lo.dados.data} chave={(l) => l.id} colunas={[
          { titulo: 'Código', celula: (l) => <span className="mono">{l.codigo}</span> },
          { titulo: 'Nome', celula: (l) => l.nome },
          { titulo: 'Tipo', celula: (l) => l.tipo === 'RESERVA' ? 'Reserva' : 'Depósito' },
          { titulo: 'Unidade', celula: (l) => l.unidade.sigla },
          { titulo: 'Situação', celula: (l) => <Selo valor={l.ativo ? 'ATIVO' : 'INATIVO'} /> },
        ]} />)}
      <Dialogo aberto={aberto} titulo="Cadastrar local de estoque" onFechar={() => setAberto(false)}
        rodape={<><button className="btn btn-sec" onClick={() => setAberto(false)}>Cancelar</button><button className="btn btn-pri" onClick={criar}>Salvar</button></>}>
        {erro && <div className="aviso aviso-perigo" role="alert">{erro.erro.code === 'ACESSO_NEGADO' ? 'Sem permissão de gestão nesta unidade.' : erro.erro.message}</div>}
        <div className="grade">
          <Campo rotulo="Unidade" obrigatorio erro={erro?.erro.fields?.unidade_id}>{(p) => <select {...p} value={n.unidade_id} onChange={(e) => setN({ ...n, unidade_id: e.target.value })}><option value="">Selecione…</option>{un.dados?.data.map((u) => <option key={u.id} value={u.id}>{u.sigla}</option>)}</select>}</Campo>
          <Campo rotulo="Código" obrigatorio erro={erro?.erro.fields?.codigo}>{(p) => <input {...p} value={n.codigo} onChange={(e) => setN({ ...n, codigo: e.target.value.toUpperCase() })} />}</Campo>
          <Campo rotulo="Nome" obrigatorio erro={erro?.erro.fields?.nome}>{(p) => <input {...p} value={n.nome} onChange={(e) => setN({ ...n, nome: e.target.value })} />}</Campo>
          <Campo rotulo="Tipo" obrigatorio>{(p) => <select {...p} value={n.tipo} onChange={(e) => setN({ ...n, tipo: e.target.value })}><option value="RESERVA">Reserva</option><option value="DEPOSITO">Depósito</option></select>}</Campo>
        </div>
      </Dialogo>
      <Dialogo aberto={abertoU} titulo="Cadastrar unidade" onFechar={() => setAbertoU(false)}
        rodape={<><button className="btn btn-sec" onClick={() => setAbertoU(false)}>Cancelar</button><button className="btn btn-pri" disabled={!nu.sigla || nu.nome.trim().length < 3} onClick={criarUnidade}>Salvar</button></>}>
        {erroU && <div className="aviso aviso-perigo" role="alert">{erroU.erro.code === 'ACESSO_NEGADO' ? 'Sem permissão para criar unidade neste nível.' : erroU.erro.message}</div>}
        <div className="grade">
          <Campo rotulo="Subordinada a" dica="Deixe em branco para uma unidade de primeiro nível (exige escopo global)." erro={erroU?.erro.fields?.unidade_superior_id}>{(p) => (
            <select {...p} value={nu.unidade_superior_id} onChange={(e) => setNu({ ...nu, unidade_superior_id: e.target.value })}>
              <option value="">— primeiro nível —</option>{un.dados?.data.map((u) => <option key={u.id} value={u.id}>{u.sigla} — {u.nome}</option>)}
            </select>)}</Campo>
          <Campo rotulo="Sigla" obrigatorio dica="Maiúsculas, números, espaço, ponto, barra ou hífen." erro={erroU?.erro.fields?.sigla}>{(p) => <input {...p} maxLength={30} value={nu.sigla} onChange={(e) => setNu({ ...nu, sigla: e.target.value.toUpperCase() })} />}</Campo>
          <Campo rotulo="Nome" obrigatorio erro={erroU?.erro.fields?.nome}>{(p) => <input {...p} maxLength={160} value={nu.nome} onChange={(e) => setNu({ ...nu, nome: e.target.value })} />}</Campo>
        </div>
      </Dialogo>
    </Pagina>
  )
}
