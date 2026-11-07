import { FileText } from 'lucide-react'
import { useState } from 'react'
import { Link } from 'react-router'
import { api, type Pagina as Pag } from '../api/client'
import type { DocResumo } from '../api/tipos'
import { useAuth } from '../auth/AuthContext'
import { tamanho } from '../components/arquivos'
import { Aviso, Carregando, Erro, Pagina, Paginacao, Selo, Tabela, Vazio, dataHora } from '../components/ui'
import { useRecurso } from '../components/useRecurso'

/** RF-014 — lista de documentos do escopo do usuário. */
export default function DocumentosPage() {
  const { pode } = useAuth()
  const [f, setF] = useState({ q: '', tipo_documento_id: '', situacao: 'ATIVO', page: 1 })
  const [busca, setBusca] = useState('')
  const tipos = useRecurso(() => api<{ data: { id: number; nome: string }[] }>('/document-types'), [])
  const { dados, erro, carregando, recarregar } = useRecurso(() => api<Pag<DocResumo>>('/documents', { query: f }), [f.q, f.tipo_documento_id, f.situacao, f.page])
  return (
    <Pagina icone={FileText} titulo="Documentos" trilha={[{ rotulo: 'Início', para: '/' }, { rotulo: 'Documentos' }]}
      descricao="Arquivos guardados em área privada. O download é registrado na auditoria."
      acoes={pode('documentos.enviar') && <Link className="btn btn-pri" to="/documentos/enviar">Enviar documento</Link>}>
      <Aviso tipo="info">Você vê apenas documentos das unidades do seu escopo. Classificação de sigilo e prazo de retenção ainda dependem de decisão institucional (P-08, P-09).</Aviso>
      <form className="filtros" role="search" onSubmit={(e) => { e.preventDefault(); setF({ ...f, q: busca, page: 1 }) }}>
        <label>Buscar<input type="search" value={busca} onChange={(e) => setBusca(e.target.value)} placeholder="Título, arquivo ou referência" /></label>
        <label>Tipo<select value={f.tipo_documento_id} onChange={(e) => setF({ ...f, tipo_documento_id: e.target.value, page: 1 })}>
          <option value="">Todos</option>{tipos.dados?.data.map((t) => <option key={t.id} value={t.id}>{t.nome}</option>)}
        </select></label>
        <label>Situação<select value={f.situacao} onChange={(e) => setF({ ...f, situacao: e.target.value, page: 1 })}>
          <option value="">Todas</option><option value="ATIVO">Ativos</option><option value="ARQUIVADO">Arquivados</option>
        </select></label>
        <button className="btn btn-sec">Filtrar</button>
      </form>
      {carregando && <Carregando />}
      {erro && <Erro erro={erro} tentar={recarregar} />}
      {dados && !carregando && (dados.data.length === 0
        ? <Vazio titulo="Nenhum documento encontrado.">{pode('documentos.enviar') ? <Link to="/documentos/enviar">Enviar o primeiro documento</Link> : 'Ajuste os filtros.'}</Vazio>
        : <>
          <Tabela legenda="Documentos" linhas={dados.data} chave={(d) => d.codigo} colunas={[
            { titulo: 'Documento', celula: (d) => <><Link to={`/documentos/${d.codigo}`}><strong>{d.titulo}</strong></Link><br /><small>{d.nome_original}</small></> },
            { titulo: 'Tipo', celula: (d) => d.tipo.nome },
            { titulo: 'Referência', celula: (d) => d.numero_referencia ?? '—' },
            { titulo: 'Unidade', celula: (d) => d.unidade.sigla },
            { titulo: 'Tamanho', num: true, celula: (d) => tamanho(d.tamanho_bytes) },
            { titulo: 'Enviado', celula: (d) => <>{dataHora(d.enviado_em)}<br /><small>{d.enviado_por}</small></> },
            { titulo: 'Situação', celula: (d) => <Selo valor={d.situacao} tom={d.situacao === 'ATIVO' ? 'ok' : 'neutro'} /> },
          ]} />
          <Paginacao meta={dados.meta} ir={(p) => setF({ ...f, page: p })} />
        </>)}
    </Pagina>
  )
}
