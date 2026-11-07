import { useState } from 'react'
import { Link } from 'react-router'
import { ListChecks, Plus } from 'lucide-react'
import { ErroApi, api, novaChave, type Pagina as Pag } from '../api/client'
import type { MovResumo } from '../api/tipos'
import { useAuth } from '../auth/AuthContext'
import { Carregando, Erro, Pagina, Selo, Tabela, Vazio, data, dataHora, useNotificar } from '../components/ui'
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
  const algumaAtiva = perfil?.operacoes.some((o) => o.estado === 'PROVISORIA' && o.codigo in TIPOS_OPERACAO && pode(TIPOS_OPERACAO[o.codigo as keyof typeof TIPOS_OPERACAO].perm))
  const pend = useRecurso(() => pode('estoque.ver') ? api<Pag<MovResumo>>('/movements', { query: { pendente: 1, per_page: 50 } }) : Promise.resolve(null), [])

  const concluir = async (m: MovResumo) => {
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
