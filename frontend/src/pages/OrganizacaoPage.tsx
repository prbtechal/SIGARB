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
  const criar = async () => {
    setErro(null)
    try { await api('/locations', { method: 'POST', body: { ...n, unidade_id: Number(n.unidade_id) } }); setAberto(false); notificar('ok', 'Local cadastrado.'); lo.recarregar() }
    catch (x) { if (x instanceof ErroApi) setErro(x) }
  }
  return (
    <Pagina titulo="Unidades e locais" trilha={[{ rotulo: 'Início', para: '/' }, { rotulo: 'Unidades e locais' }]}
      descricao="Estrutura organizacional sintética. A hierarquia real depende da decisão P-02."
      acoes={pode('organizacao.gerir') && <button className="btn btn-pri" onClick={() => setAberto(true)}>Cadastrar local</button>}>
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
    </Pagina>
  )
}
