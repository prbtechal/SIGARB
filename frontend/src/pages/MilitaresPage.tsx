import { useState } from 'react'
import { Link } from 'react-router'
import { api, type Pagina as Pag } from '../api/client'
import type { Militar } from '../api/tipos'
import { useAuth } from '../auth/AuthContext'
import { Aviso, Carregando, Erro, Pagina, Paginacao, Selo, Tabela, Vazio } from '../components/ui'
import { useRecurso } from '../components/useRecurso'

export default function MilitaresPage() {
  const { pode } = useAuth()
  const [f, setF] = useState({ q: '', situacao: '', page: 1 })
  const [busca, setBusca] = useState('')
  const { dados, erro, carregando, recarregar } = useRecurso(() => api<Pag<Militar>>('/militares', { query: f }), [f.q, f.situacao, f.page])
  return (
    <Pagina titulo="Militares" trilha={[{ rotulo: 'Início', para: '/' }, { rotulo: 'Militares' }]}
      descricao="Registro institucional de pessoas, independente das contas de acesso."
      acoes={pode('militares.gerir') && <Link className="btn btn-pri" to="/militares/novo">Cadastrar militar</Link>}>
      {!pode('militares.ver_detalhe') && <Aviso tipo="info">Matrículas aparecem parcialmente ocultas para o seu perfil.</Aviso>}
      <form className="filtros" role="search" onSubmit={(e) => { e.preventDefault(); setF({ ...f, q: busca, page: 1 }) }}>
        <label>Buscar<input type="search" value={busca} onChange={(e) => setBusca(e.target.value)} placeholder="Nome ou nome de guerra" /></label>
        <label>Situação<select value={f.situacao} onChange={(e) => setF({ ...f, situacao: e.target.value, page: 1 })}><option value="">Todas</option><option>ATIVO</option><option>AFASTADO</option><option>INATIVO</option></select></label>
        <button className="btn btn-sec">Filtrar</button>
      </form>
      {carregando && <Carregando />}
      {erro && <Erro erro={erro} tentar={recarregar} />}
      {dados && !carregando && (dados.data.length === 0 ? <Vazio titulo="Nenhum militar encontrado." /> : <>
        <Tabela legenda="Militares" linhas={dados.data} chave={(m) => m.id} colunas={[
          { titulo: 'Nome de guerra', celula: (m) => <><strong>{m.posto_graduacao.sigla} {m.nome_guerra}</strong><br /><small>{m.nome}</small></> },
          { titulo: 'Matrícula', celula: (m) => <span className="mono">{m.matricula}</span> },
          { titulo: 'Unidade', celula: (m) => m.unidade.sigla },
          { titulo: 'Situação', celula: (m) => <Selo valor={m.situacao} /> },
          { titulo: 'Ações', celula: (m) => m.pode_editar ? <Link to={`/militares/${m.id}`}>Editar <span className="sr-only">{m.nome_guerra}</span></Link> : <Link to={`/militares/${m.id}`}>Ver <span className="sr-only">{m.nome_guerra}</span></Link> },
        ]} />
        <Paginacao meta={dados.meta} ir={(p) => setF({ ...f, page: p })} />
      </>)}
    </Pagina>
  )
}
