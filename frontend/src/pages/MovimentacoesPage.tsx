import { useState } from 'react'
import { Link } from 'react-router'
import { api, type Pagina as Pag } from '../api/client'
import type { MovResumo } from '../api/tipos'
import { useAuth } from '../auth/AuthContext'
import { Carregando, Erro, Pagina, Paginacao, Selo, Tabela, Vazio, dataHora } from '../components/ui'
import { useRecurso } from '../components/useRecurso'

export default function MovimentacoesPage() {
  const { pode } = useAuth()
  const [f, setF] = useState({ tipo: '', de: '', ate: '', page: 1 })
  const { dados, erro, carregando, recarregar } = useRecurso(() => api<Pag<MovResumo>>('/movements', { query: f }), [f.tipo, f.de, f.ate, f.page])
  return (
    <Pagina titulo="Movimentações" trilha={[{ rotulo: 'Início', para: '/' }, { rotulo: 'Movimentações' }]}
      descricao="Razão imutável: registros não são editados nem apagados; correções são feitas por estorno vinculado."
      acoes={pode('estoque.entrada') && <Link className="btn btn-pri" to="/movimentacoes/nova-entrada">Registrar entrada</Link>}>
      <div className="filtros">
        <label>Tipo<select value={f.tipo} onChange={(e) => setF({ ...f, tipo: e.target.value, page: 1 })}><option value="">Todos</option><option>ENTRADA</option><option>ESTORNO</option></select></label>
        <label>De<input type="date" value={f.de} onChange={(e) => setF({ ...f, de: e.target.value, page: 1 })} /></label>
        <label>Até<input type="date" value={f.ate} onChange={(e) => setF({ ...f, ate: e.target.value, page: 1 })} /></label>
      </div>
      {carregando && <Carregando />}
      {erro && <Erro erro={erro} tentar={recarregar} />}
      {dados && !carregando && (dados.data.length === 0 ? <Vazio titulo="Nenhuma movimentação encontrada." /> : <>
        <Tabela legenda="Movimentações" linhas={dados.data} chave={(m) => m.id} colunas={[
          { titulo: 'Nº', celula: (m) => <Link to={`/movimentacoes/${m.id}`}>#{m.id}</Link> },
          { titulo: 'Tipo', celula: (m) => <>{<Selo valor={m.tipo} />} {m.estornada && <Selo valor="ESTORNADA" tom="neutro" />}</> },
          { titulo: 'Data/hora', celula: (m) => <time dateTime={m.ocorrido_em}>{dataHora(m.ocorrido_em)}</time> },
          { titulo: 'Local', celula: (m) => m.local_destino?.codigo ?? m.local_origem?.codigo },
          { titulo: 'Documento', celula: (m) => m.documento_referencia ?? '—' },
          { titulo: 'Itens', num: true, celula: (m) => m.itens_count },
          { titulo: 'Responsável', celula: (m) => m.responsavel.nome },
        ]} />
        <Paginacao meta={dados.meta} ir={(p) => setF({ ...f, page: p })} />
      </>)}
    </Pagina>
  )
}
