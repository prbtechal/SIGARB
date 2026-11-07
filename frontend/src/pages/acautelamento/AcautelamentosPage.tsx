import { useState } from 'react'
import { Link } from 'react-router'
import { Plus, ShieldHalf } from 'lucide-react'
import { api, type Pagina as Pag } from '../../api/client'
import type { AcautResumo } from '../../api/tipos'
import { useAuth } from '../../auth/AuthContext'
import { Carregando, Erro, Pagina, Paginacao, Selo, Tabela, Vazio, data } from '../../components/ui'
import { useRecurso } from '../../components/useRecurso'

export const ESTADOS = { SOLICITADO: 'Solicitado', APROVADO: 'Aprovado', REJEITADO: 'Rejeitado', CANCELADO: 'Cancelado', ATIVO: 'Ativo', ENCERRADO: 'Encerrado' }

export default function AcautelamentosPage() {
  const { pode, perfil } = useAuth()
  const [f, setF] = useState({ estado: '', inspecao_vencendo: '', page: 1 })
  const { dados, erro, carregando, recarregar } = useRecurso(() => api<Pag<AcautResumo>>('/acautelamentos', { query: f }), [JSON.stringify(f)])
  const ativo = perfil?.operacoes.find((o) => o.codigo === 'ACAUTELAMENTO')?.estado !== 'BLOQUEADA'
  return (
    <Pagina icone={ShieldHalf} titulo="Acautelamentos" trilha={[{ rotulo: 'Início', para: '/' }, { rotulo: 'Acautelamentos' }]}
      descricao="Solicitação, decisão, entrega, inspeção e desacautelamento de armamento."
      acoes={ativo && pode('acautelamento.solicitar') ? <Link className="btn btn-pri" to="/acautelamentos/novo"><Plus size={18} aria-hidden="true" />Nova solicitação</Link> : undefined}>
      <form className="filtros" role="search" aria-label="Filtrar acautelamentos" onSubmit={(e) => e.preventDefault()}>
        <label>Situação<select value={f.estado} onChange={(e) => setF({ ...f, estado: e.target.value, page: 1 })}>
          <option value="">Todas</option>{Object.entries(ESTADOS).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
        </select></label>
        <label className="check"><input type="checkbox" checked={!!f.inspecao_vencendo} onChange={(e) => setF({ ...f, inspecao_vencendo: e.target.checked ? '1' : '', page: 1 })} />Inspeção vencendo ou atrasada</label>
      </form>
      {carregando && <Carregando />}{erro && <Erro erro={erro} tentar={recarregar} />}
      {dados && !carregando && (dados.data.length === 0 ? <Vazio titulo="Nenhum acautelamento encontrado." icone={ShieldHalf} /> : <>
        <Tabela legenda="Acautelamentos" linhas={dados.data} chave={(a) => a.codigo} colunas={[
          { titulo: 'Efetivo', celula: (a) => <Link to={`/acautelamentos/${a.codigo}`}>{a.efetivo.posto} {a.efetivo.nome_guerra}</Link> },
          { titulo: 'Item', celula: (a) => <><span className="mono">{a.item.identificador}</span> {a.item.material}</> },
          { titulo: 'Unidade', celula: (a) => a.unidade.sigla },
          { titulo: 'Situação', celula: (a) => <Selo valor={a.estado} tom={a.estado === 'ATIVO' ? 'ok' : a.estado === 'SOLICITADO' ? 'alerta' : undefined} /> },
          { titulo: 'Próxima inspeção', celula: (a) => a.inspecao_atrasada ? <Selo valor={`Atrasada (${data(a.proxima_inspecao)})`} tom="perigo" /> : data(a.proxima_inspecao) },
        ]} />
        <Paginacao meta={dados.meta} ir={(p) => setF({ ...f, page: p })} />
      </>)}
    </Pagina>
  )
}
