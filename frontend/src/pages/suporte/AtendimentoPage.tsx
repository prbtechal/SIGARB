import { useState } from 'react'
import { Link } from 'react-router'
import { CheckCircle2, Clock, Headset, Inbox, UserRoundX } from 'lucide-react'
import { api, type Pagina as Pag } from '../../api/client'
import type { ChamadoResumo } from '../../api/tipos'
import { Carregando, Erro, Pagina, Paginacao, Selo, Tabela, Vazio, dataHora, numero } from '../../components/ui'
import { useRecurso } from '../../components/useRecurso'
import { CATEGORIAS, PRIORIDADES, STATUS } from './rotulos'

type Resumo = { por_status: Record<string, number>; sem_responsavel: number; meus: number }

/** Fila de atendimento: o servidor filtra pelo escopo de `suporte.atender` do atendente. */
export default function AtendimentoPage() {
  const [pagina, setPagina] = useState(1)
  const [f, setF] = useState({ status: '', prioridade: '', categoria: '', atribuido: '', q: '' })
  const [aplicado, setAplicado] = useState(f)
  const fila = useRecurso(() => api<Pag<ChamadoResumo>>('/support/queue', { query: { ...aplicado, page: pagina } }), [aplicado, pagina])
  const res = useRecurso(() => api<Resumo>('/support/queue/summary'), [])
  const abertos = res.dados ? res.dados.por_status.ABERTO + res.dados.por_status.EM_ATENDIMENTO : 0
  const cartoes = res.dados ? [
    { rotulo: 'Em aberto', valor: abertos, icone: Inbox, extra: 'Abertos e em atendimento' },
    { rotulo: 'Aguardando solicitante', valor: res.dados.por_status.AGUARDANDO_SOLICITANTE, icone: Clock },
    { rotulo: 'Sem responsável', valor: res.dados.sem_responsavel, icone: UserRoundX },
    { rotulo: 'Resolvidos', valor: res.dados.por_status.RESOLVIDO, icone: CheckCircle2 },
  ] : []
  const sel = (k: keyof typeof f, opcoes: Record<string, string>, rotulo: string) => (
    <label>{rotulo}
      <select value={f[k]} onChange={(e) => setF({ ...f, [k]: e.target.value })}>
        <option value="">Todas</option>{Object.entries(opcoes).map(([v, t]) => <option key={v} value={v}>{t}</option>)}
      </select>
    </label>
  )
  return (
    <Pagina titulo="Atendimento" icone={Headset} descricao="Chamados de suporte no seu escopo de atendimento. Atender suporte não dá acesso a dados de estoque ou de pessoas.">
      {res.erro ? <Erro erro={res.erro} /> : (
        <div className="cartoes" aria-label="Resumo da fila">
          {cartoes.map((c) => (
            <div key={c.rotulo} className="cartao cartao-com-icone">
              <div><p className="cartao-rotulo">{c.rotulo}</p><p className="cartao-valor">{numero(c.valor)}</p>{c.extra && <p className="cartao-extra">{c.extra}</p>}</div>
              <span className="cartao-icone" aria-hidden="true"><c.icone size={22} /></span>
            </div>
          ))}
        </div>
      )}
      <form className="filtros" onSubmit={(e) => { e.preventDefault(); setPagina(1); setAplicado(f) }}>
        <label>Buscar<input type="search" placeholder="Protocolo ou assunto" value={f.q} onChange={(e) => setF({ ...f, q: e.target.value })} /></label>
        {sel('status', STATUS, 'Situação')}
        {sel('prioridade', PRIORIDADES, 'Prioridade')}
        {sel('categoria', CATEGORIAS, 'Categoria')}
        {sel('atribuido', { eu: 'Atribuídos a mim', ninguem: 'Sem responsável' }, 'Responsável')}
        <button className="btn btn-sec">Filtrar</button>
      </form>
      {fila.carregando ? <Carregando /> : fila.erro ? <Erro erro={fila.erro} tentar={fila.recarregar} /> : !fila.dados?.data.length ? (
        <Vazio titulo="Nenhum chamado encontrado" icone={Headset}><p>Ajuste os filtros ou aguarde novos chamados.</p></Vazio>
      ) : (
        <>
          <Tabela legenda="Fila de atendimento" chave={(c) => c.codigo} linhas={fila.dados.data} colunas={[
            { titulo: 'Protocolo', celula: (c) => <Link to={`/suporte/chamados/${c.codigo}`} className="mono">{c.protocolo}</Link> },
            { titulo: 'Assunto', celula: (c) => c.assunto },
            { titulo: 'Solicitante', celula: (c) => <>{c.solicitante?.nome}<br /><small className="dica">{c.unidade?.sigla ?? 'Global'}</small></> },
            { titulo: 'Prioridade', celula: (c) => <Selo valor={c.prioridade} /> },
            { titulo: 'Situação', celula: (c) => <Selo valor={c.status} /> },
            { titulo: 'Responsável', celula: (c) => c.atribuido_a?.nome ?? <span className="dica">Ninguém</span> },
            { titulo: 'Atualizado', celula: (c) => dataHora(c.atualizado_em) },
          ]} />
          <Paginacao meta={fila.dados.meta} ir={setPagina} />
        </>
      )}
    </Pagina>
  )
}
