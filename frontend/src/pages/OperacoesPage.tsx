import { useState } from 'react'
import { Link } from 'react-router'
import { ListChecks, Plus } from 'lucide-react'
import { ErroApi, api, novaChave, type Pagina as Pag } from '../api/client'
import type { MovDetalhe, MovResumo, PendenteDevolucao } from '../api/tipos'
import { useAuth } from '../auth/AuthContext'
import { Aviso, Carregando, Dialogo, Erro, Pagina, Selo, Tabela, Vazio, data, dataHora, useNotificar } from '../components/ui'
import { useRecurso } from '../components/useRecurso'
import { TIPOS_OPERACAO } from './NovaOperacaoPage'

// Abertura pendente → conclusão e permissão exigida (espelha OperacaoEstoqueService::CONCLUSOES).
const CONCLUIR: Record<string, { tipo: string; rotulo: string; perm: string }> = {
  TRANSFERENCIA: { tipo: 'RECEBIMENTO', rotulo: 'Registrar recebimento', perm: 'estoque.transferir' },
  RESERVA: { tipo: 'LIBERACAO_RESERVA', rotulo: 'Liberar reserva', perm: 'estoque.reservar' },
  MANUTENCAO: { tipo: 'RETORNO_MANUTENCAO', rotulo: 'Registrar retorno', perm: 'estoque.manutencao' },
  ENTREGA: { tipo: 'DEVOLUCAO', rotulo: 'Registrar devolução', perm: 'estoque.entregar' },
}

export default function OperacoesPage() {
  const { perfil, pode } = useAuth()
  const notificar = useNotificar()
  const [ocupado, setOcupado] = useState<number | null>(null)
  const [devolver, setDevolver] = useState<MovResumo | null>(null)
  const algumaAtiva = perfil?.operacoes.some((o) => o.estado !== 'BLOQUEADA' && o.codigo in TIPOS_OPERACAO && pode(TIPOS_OPERACAO[o.codigo as keyof typeof TIPOS_OPERACAO].perm))
  const pend = useRecurso(() => pode('estoque.ver') ? api<Pag<MovResumo>>('/movements', { query: { pendente: 1, per_page: 50 } }) : Promise.resolve(null), [])

  const concluir = async (m: MovResumo) => {
    if (m.tipo === 'ENTREGA') { setDevolver(m); return } // CP-13: devolução total ou parcial pelo diálogo
    setOcupado(m.id)
    try {
      await api('/movements', { method: 'POST', body: { tipo: CONCLUIR[m.tipo].tipo, vinculo_id: m.id }, headers: { 'Idempotency-Key': novaChave() } })
      notificar('ok', `${CONCLUIR[m.tipo].rotulo}: operação #${m.id} concluída.`)
      pend.recarregar()
    } catch (e) {
      if (e instanceof ErroApi) notificar('perigo', e.erro.code === 'ACESSO_NEGADO' ? 'Você não tem permissão no local desta operação.' : e.erro.message)
    } finally { setOcupado(null) }
  }

  return (
    <Pagina icone={ListChecks} titulo="Operações e pendências" trilha={[{ rotulo: 'Início', para: '/' }, { rotulo: 'Operações' }]}
      descricao="Pendências em aberto e situação de cada tipo de operação."
      acoes={algumaAtiva ? <Link className="btn btn-pri" to="/operacoes/nova"><Plus size={18} aria-hidden="true" />Nova operação</Link> : undefined}>

      {pode('estoque.ver') && <section aria-labelledby="pend-t">
        <h2 id="pend-t">Pendências em aberto</h2>
        {pend.carregando && <Carregando />}{pend.erro && <Erro erro={pend.erro} tentar={pend.recarregar} />}
        {pend.dados && (pend.dados.data.length === 0 ? <Vazio titulo="Nenhuma transferência, reserva, manutenção ou entrega com retorno em aberto." />
          : <Tabela legenda="Operações aguardando conclusão" linhas={pend.dados.data} chave={(m) => m.id} colunas={[
            { titulo: 'Operação', celula: (m) => <Link to={`/movimentacoes/${m.id}`}><Selo valor={m.tipo} /> #{m.id}</Link> },
            { titulo: 'Data', celula: (m) => dataHora(m.ocorrido_em) },
            { titulo: 'Origem → destino', celula: (m) => `${m.local_origem?.codigo ?? '—'}${m.local_destino ? ' → ' + m.local_destino.codigo : ''}${m.militar ? ' → ' + m.militar.nome_guerra : ''}` },
            { titulo: 'Retorno previsto', celula: (m) => data(m.previsao_retorno ?? null) },
            { titulo: 'Itens', celula: (m) => m.itens_count },
            { titulo: 'Ação', celula: (m) => CONCLUIR[m.tipo] && pode(CONCLUIR[m.tipo].perm)
              ? <button type="button" className="btn btn-sec" disabled={ocupado === m.id} onClick={() => concluir(m)}>{CONCLUIR[m.tipo].rotulo}<span className="sr-only"> da operação #{m.id}</span></button> : '—' },
          ]} />)}
      </section>}

      {devolver && <DevolucaoDialogo entrega={devolver} onFechar={() => setDevolver(null)} onConcluir={() => { setDevolver(null); pend.recarregar() }} />}

      <h2>Situação das operações</h2>
      <ul className="grade-ops">
        {perfil?.operacoes.map((o) => (
          <li key={o.codigo} className={`op op-${o.estado.toLowerCase()}`} aria-disabled={o.estado === 'BLOQUEADA'}>
            <h3>{o.rotulo}</h3>
            <Selo valor={o.estado} />
            <p>{o.estado === 'BLOQUEADA' ? 'Indisponível no momento.' : o.regra_provisoria ?? 'Disponível para os perfis autorizados.'}</p>
          </li>
        ))}
      </ul>
    </Pagina>
  )
}

const CONSERVACAO = ['NOVO', 'BOM', 'REGULAR', 'RUIM', 'DANIFICADO', 'INSERVIVEL']

/** CP-13 (P-05): devolução total ou parcial — itens marcados e quantidades por lote; o restante continua pendente. */
function DevolucaoDialogo({ entrega, onFechar, onConcluir }: { entrega: MovResumo; onFechar: () => void; onConcluir: () => void }) {
  const notificar = useNotificar()
  const det = useRecurso(() => api<MovDetalhe>(`/movements/${entrega.id}`), [entrega.id])
  const p: PendenteDevolucao | null | undefined = det.dados?.pendente_devolucao
  const [marcados, setMarcados] = useState<Record<number, boolean>>({})
  const [estado, setEstado] = useState<Record<number, string>>({})
  const [qtd, setQtd] = useState<Record<number, string>>({})
  const [erro, setErro] = useState<string | null>(null)
  const [enviando, setEnviando] = useState(false)
  const chave = useState(novaChave)[0]
  const marcado = (id: number) => marcados[id] ?? true
  const quantidade = (l: PendenteDevolucao['lotes'][number]) => qtd[l.lote_id] ?? String(l.quantidade)
  const itens = p ? [
    ...p.itens.filter((i) => marcado(i.item_individual_id)).map((i) => ({ item_individual_id: i.item_individual_id, ...(estado[i.item_individual_id] ? { estado_conservacao: estado[i.item_individual_id] } : {}) })),
    ...p.lotes.filter((l) => Number(quantidade(l)) > 0).map((l) => ({ lote_id: l.lote_id, quantidade: Number(quantidade(l)) })),
  ] : []
  const total = p ? p.itens.length + p.lotes.length : 0
  const parcial = p ? itens.length < total || p.lotes.some((l) => Number(quantidade(l)) < l.quantidade) : false
  const invalido = !itens.length || (p?.lotes.some((l) => { const n = Number(quantidade(l)); return !Number.isInteger(n) || n < 0 || n > l.quantidade }) ?? true)

  const enviar = async () => {
    setEnviando(true); setErro(null)
    try {
      await api('/movements', { method: 'POST', body: { tipo: 'DEVOLUCAO', vinculo_id: entrega.id, itens }, headers: { 'Idempotency-Key': chave } })
      notificar('ok', parcial ? `Devolução parcial registrada; o restante da entrega #${entrega.id} continua pendente.` : `Entrega #${entrega.id} devolvida por completo.`)
      onConcluir()
    } catch (e) {
      setErro(e instanceof ErroApi ? (e.erro.code === 'ACESSO_NEGADO' ? 'Você não tem permissão no local desta entrega.' : e.erro.message) : 'Falha ao registrar a devolução.')
    } finally { setEnviando(false) }
  }

  return (
    <Dialogo aberto titulo={`Devolução da entrega #${entrega.id}${entrega.militar ? ' — ' + entrega.militar.nome_guerra : ''}`} onFechar={onFechar}
      rodape={<><button type="button" className="btn btn-sec" onClick={onFechar}>Cancelar</button>
        <button type="button" className="btn btn-pri" disabled={!p || invalido || enviando} onClick={enviar}>{enviando ? 'Registrando…' : parcial ? 'Registrar devolução parcial' : 'Registrar devolução'}</button></>}>
      {det.carregando && <Carregando />}{det.erro && <Erro erro={det.erro} tentar={det.recarregar} />}
      {det.dados && !p && <Vazio titulo="Esta entrega não tem itens pendentes de devolução." />}
      {p && <>
        <p className="dica">Desmarque o que ainda não voltou. O que ficar de fora continua pendente com o efetivo.</p>
        {p.itens.length > 0 && <fieldset className="campo"><legend>Itens individuais</legend>
          {p.itens.map((i) => (
            <div key={i.item_individual_id} className="acoes-linha">
              <label><input type="checkbox" checked={marcado(i.item_individual_id)} onChange={(e) => setMarcados({ ...marcados, [i.item_individual_id]: e.target.checked })} /> <span className="mono">{i.identificador}</span> — {i.material.nome}</label>
              <label>Estado na devolução <select value={estado[i.item_individual_id] ?? ''} disabled={!marcado(i.item_individual_id)} onChange={(e) => setEstado({ ...estado, [i.item_individual_id]: e.target.value })}>
                <option value="">Sem alteração ({i.estado_conservacao ?? '—'})</option>{CONSERVACAO.map((c) => <option key={c} value={c}>{c}</option>)}</select></label>
            </div>))}
        </fieldset>}
        {p.lotes.length > 0 && <fieldset className="campo"><legend>Lotes</legend>
          {p.lotes.map((l) => (
            <label key={l.lote_id} className="devolucao-qtd">{l.material.nome} (lote <span className="mono">{l.codigo}</span>)
              <input type="number" min={0} max={l.quantidade} step={1} value={quantidade(l)} aria-describedby={`max-${l.lote_id}`} onChange={(e) => setQtd({ ...qtd, [l.lote_id]: e.target.value })} />
              <span id={`max-${l.lote_id}`}>/ {l.quantidade} pendente(s)</span></label>))}
        </fieldset>}
        {!itens.length && <Aviso tipo="alerta">Marque ao menos um item ou informe uma quantidade.</Aviso>}
        {erro && <Aviso tipo="perigo">{erro}</Aviso>}
      </>}
    </Dialogo>
  )
}
