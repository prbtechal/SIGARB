import { useState } from 'react'
import { Link } from 'react-router'
import { LifeBuoy, Plus } from 'lucide-react'
import { api, type Pagina as Pag } from '../../api/client'
import type { ChamadoResumo } from '../../api/tipos'
import { Carregando, Erro, Pagina, Paginacao, Selo, Tabela, Vazio, dataHora } from '../../components/ui'
import { useRecurso } from '../../components/useRecurso'
import { CATEGORIAS, STATUS } from './rotulos'

/** Chamados do PRÓPRIO usuário (o servidor nunca devolve chamados de outras pessoas nesta rota). */
export default function SuportePage() {
  const [pagina, setPagina] = useState(1)
  const [status, setStatus] = useState('')
  const r = useRecurso(() => api<Pag<ChamadoResumo>>('/support/tickets', { query: { page: pagina, status } }), [pagina, status])
  return (
    <Pagina titulo="Central de suporte" icone={LifeBuoy} descricao="Relate problemas, tire dúvidas e acompanhe as respostas da equipe de atendimento."
      acoes={<Link className="btn btn-pri" to="/suporte/novo"><Plus size={18} aria-hidden="true" />Novo chamado</Link>}>
      <div className="filtros">
        <label>Situação
          <select value={status} onChange={(e) => { setStatus(e.target.value); setPagina(1) }}>
            <option value="">Todas</option>
            {Object.entries(STATUS).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
          </select>
        </label>
      </div>
      {r.carregando ? <Carregando /> : r.erro ? <Erro erro={r.erro} tentar={r.recarregar} /> : !r.dados?.data.length ? (
        <Vazio titulo="Você ainda não abriu chamados" icone={LifeBuoy}>
          <p>Encontrou um problema ou tem uma dúvida? Abra um chamado e acompanhe por aqui.</p>
          <Link className="btn btn-pri" to="/suporte/novo"><Plus size={18} aria-hidden="true" />Abrir chamado</Link>
        </Vazio>
      ) : (
        <>
          <Tabela legenda="Meus chamados" chave={(c) => c.codigo} linhas={r.dados.data} colunas={[
            { titulo: 'Protocolo', celula: (c) => <Link to={`/suporte/chamados/${encodeURIComponent(c.codigo)}`} className="mono">{c.protocolo}</Link> },
            { titulo: 'Assunto', celula: (c) => c.assunto },
            { titulo: 'Categoria', celula: (c) => CATEGORIAS[c.categoria] ?? c.categoria },
            { titulo: 'Situação', celula: (c) => <Selo valor={c.status} /> },
            { titulo: 'Atualizado', celula: (c) => dataHora(c.atualizado_em) },
          ]} />
          <Paginacao meta={r.dados.meta} ir={setPagina} />
        </>
      )}
    </Pagina>
  )
}
