import { Boxes } from 'lucide-react'
import { useState } from 'react'
import { Link, useSearchParams } from 'react-router'
import { api, type Pagina as Pag } from '../api/client'
import type { Local, Saldo } from '../api/tipos'
import { useAuth } from '../auth/AuthContext'
import { Aviso, Carregando, Erro, Pagina, Paginacao, Selo, Tabela, Vazio, data, numero } from '../components/ui'
import { useRecurso } from '../components/useRecurso'

export default function SaldosPage() {
  const { pode } = useAuth()
  // Filtros na URL: voltar do detalhe do material ou recarregar mantém a lista como estava.
  const [params, setParams] = useSearchParams()
  const f = { q: params.get('q') ?? '', local_id: params.get('local_id') ?? '', natureza: params.get('natureza') ?? '', page: Number(params.get('page') ?? 1) }
  const setF = (n: typeof f) => setParams(Object.fromEntries(Object.entries(n).filter(([k, v]) => v !== '' && !(k === 'page' && v === 1)).map(([k, v]) => [k, String(v)])), { replace: true })
  const [busca, setBusca] = useState(f.q)
  const locais = useRecurso(() => api<{ data: Local[] }>('/locations'), [])
  const { dados, erro, carregando, recarregar } = useRecurso(() => api<Pag<Saldo>>('/stock/balances', { query: f }), [f.q, f.local_id, f.natureza, f.page])
  const hoje = new Date(); const limite = new Date(hoje.getTime() + 90 * 864e5)

  return (
    <Pagina icone={Boxes} titulo="Saldos de estoque" trilha={[{ rotulo: 'Início', para: '/' }, { rotulo: 'Saldos de estoque' }]}
      descricao="Quantidade física, reservada e disponível por local. Itens individuais aparecem agregados por material."
      acoes={pode('estoque.entrada') && <Link className="btn btn-pri" to="/movimentacoes/nova-entrada">Registrar entrada</Link>}>
      <Aviso tipo="info">Você vê apenas os locais do seu escopo organizacional.</Aviso>
      <form className="filtros" role="search" onSubmit={(e) => { e.preventDefault(); setF({ ...f, q: busca, page: 1 }) }}>
        <label>Buscar<input type="search" value={busca} onChange={(e) => setBusca(e.target.value)} placeholder="Material, nº de série ou lote" /></label>
        <label>Local<select value={f.local_id} onChange={(e) => setF({ ...f, local_id: e.target.value, page: 1 })}>
          <option value="">Todos</option>{locais.dados?.data.map((l) => <option key={l.id} value={l.id}>{l.codigo} — {l.nome}</option>)}
        </select></label>
        <label>Natureza<select value={f.natureza} onChange={(e) => setF({ ...f, natureza: e.target.value, page: 1 })}>
          <option value="">Todas</option>{['ARMAMENTO', 'MUNICAO', 'EQUIPAMENTO', 'PRODUTO', 'TREINAMENTO'].map((n) => <option key={n}>{n}</option>)}
        </select></label>
        <button className="btn btn-sec">Filtrar</button>
      </form>
      {carregando && <Carregando />}
      {erro && <Erro erro={erro} tentar={recarregar} />}
      {dados && !carregando && (dados.data.length === 0
        ? <Vazio titulo="Nenhum saldo encontrado.">{pode('estoque.entrada') ? <Link to="/movimentacoes/nova-entrada">Registrar a primeira entrada</Link> : 'Ajuste os filtros.'}</Vazio>
        : <>
          <Tabela legenda="Saldos de estoque" linhas={dados.data} chave={(s) => `${s.local.id}-${s.material.id}-${s.lote?.id ?? 'i'}`} colunas={[
            { titulo: 'Material', celula: (s) => <><Link to={`/estoque/materiais/${s.material.id}${f.local_id ? `?local=${f.local_id}` : ''}`} state={{ voltar: `/estoque?${params}` }}><strong>{s.material.nome}</strong></Link><br /><small>{[s.material.categoria, s.material.tipo].filter(Boolean).join(' · ')}</small></> },
            { titulo: 'Controle', celula: (s) => <Selo valor={s.material.modo_controle} tom="info" /> },
            { titulo: 'Lote / validade', celula: (s) => s.lote ? <>{s.lote.codigo}<br /><small className={s.lote.validade && new Date(s.lote.validade) <= limite ? 'texto-alerta' : ''}>Val.: {data(s.lote.validade)}</small></> : '—' },
            { titulo: 'Local', celula: (s) => <>{s.local.codigo}<br /><small>{s.unidade.sigla}</small></> },
            { titulo: 'Quantidade', num: true, celula: (s) => numero(s.quantidade) },
            { titulo: 'Reservada', num: true, celula: (s) => numero(s.reservada) },
            { titulo: 'Disponível', num: true, celula: (s) => <strong>{numero(s.disponivel)}</strong> },
          ]} />
          <Paginacao meta={dados.meta} ir={(p) => setF({ ...f, page: p })} />
        </>)}
    </Pagina>
  )
}
