import { Link } from 'react-router'
import { api } from '../api/client'
import { useAuth } from '../auth/AuthContext'
import { Aviso, Carregando, Erro, Pagina, Selo, dataHora, numero } from '../components/ui'
import { useRecurso } from '../components/useRecurso'

type Painel = {
  pendencias_institucionais: number; operacoes_bloqueadas: number; militares_ativos?: number
  estoque?: { locais: number; itens_individuais_disponiveis: number; lotes_com_saldo: number; quantidade_em_lotes: number; lotes_validade_90_dias: number; movimentacoes_7_dias: number }
  ultimas_movimentacoes?: { id: number; tipo: string; ocorrido_em: string; responsavel: string; local: string }[]
}

export default function InicioPage() {
  const { perfil, pode } = useAuth()
  const { dados, erro, carregando, recarregar } = useRecurso(() => api<Painel>('/dashboard'), [])
  return (
    <Pagina titulo="Início" descricao={`Olá, ${perfil?.usuario.nome}. Indicadores restritos ao seu escopo autorizado.`}
      acoes={pode('estoque.entrada') && <Link className="btn btn-pri" to="/movimentacoes/nova-entrada">Registrar entrada</Link>}>
      {carregando && <Carregando />}
      {erro && <Erro erro={erro} tentar={recarregar} />}
      {dados && <>
        <div className="cartoes">
          {dados.estoque && <>
            <Cartao rotulo="Itens individuais disponíveis" valor={dados.estoque.itens_individuais_disponiveis} />
            <Cartao rotulo="Lotes com saldo" valor={dados.estoque.lotes_com_saldo} extra={`${numero(dados.estoque.quantidade_em_lotes)} unidades`} />
            <Cartao rotulo="Locais de estoque" valor={dados.estoque.locais} />
            <Cartao rotulo="Movimentações (7 dias)" valor={dados.estoque.movimentacoes_7_dias} />
          </>}
          {dados.militares_ativos !== undefined && <Cartao rotulo="Militares ativos" valor={dados.militares_ativos} />}
        </div>
        {dados.estoque && dados.estoque.lotes_validade_90_dias > 0 && (
          <Aviso tipo="alerta" titulo="Validade próxima:">{dados.estoque.lotes_validade_90_dias} lote(s) vencem em até 90 dias. <Link to="/estoque">Ver saldos</Link>. (Informativo — regras de alerta dependem de P-03.)</Aviso>
        )}
        <Aviso tipo="info" titulo="Pendências institucionais:">{dados.pendencias_institucionais} decisões em aberto; {dados.operacoes_bloqueadas} tipos de operação permanecem bloqueados até aprovação. <Link to="/operacoes">Ver detalhes</Link>.</Aviso>
        {dados.ultimas_movimentacoes && (
          <section className="bloco" aria-labelledby="ult-mov">
            <h2 id="ult-mov">Últimas movimentações</h2>
            {dados.ultimas_movimentacoes.length === 0 ? <p>Nenhuma movimentação no seu escopo.</p> : (
              <ul className="lista-simples">
                {dados.ultimas_movimentacoes.map((m) => (
                  <li key={m.id}><Link to={`/movimentacoes/${m.id}`}>#{m.id}</Link> <Selo valor={m.tipo} /> {m.local} · {m.responsavel} · <time dateTime={m.ocorrido_em}>{dataHora(m.ocorrido_em)}</time></li>
                ))}
              </ul>
            )}
          </section>
        )}
      </>}
    </Pagina>
  )
}

function Cartao({ rotulo, valor, extra }: { rotulo: string; valor: number; extra?: string }) {
  return <div className="cartao"><p className="cartao-rotulo">{rotulo}</p><p className="cartao-valor">{numero(valor)}</p>{extra && <p className="cartao-extra">{extra}</p>}</div>
}
