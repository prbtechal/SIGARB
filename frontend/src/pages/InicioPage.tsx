import { HandHelping, LayoutDashboard } from 'lucide-react'
import { Link } from 'react-router'
import { api } from '../api/client'
import { useAuth } from '../auth/AuthContext'
import { GraficoBarras } from '../components/GraficoBarras'
import { Aviso, Carregando, Erro, NATUREZAS, Pagina, Selo, dataHora, numero } from '../components/ui'
import { useRecurso } from '../components/useRecurso'
import type { PainelMaterial } from '../api/tipos'
import { ListaAlertas, type Alerta } from './AlertasRelatoriosPage'
import { DisponivelNaBase, EsqueletoCartoes, OperacaoDoDia } from './inicio/PainelMaterial'

type Serie = { rotulo: string; valor: number }[]
type Painel = {
  gerado_em: string; pendencias_institucionais: number; operacoes_bloqueadas: number; militares_ativos?: number
  unidades?: { ativas: number }
  catalogo?: { materiais_ativos: number; por_modo: Serie }
  estoque?: { locais: number; itens_individuais_disponiveis: number; lotes_com_saldo: number; quantidade_em_lotes: number; lotes_validade_90_dias: number; movimentacoes_7_dias: number; por_natureza: Serie }
  ultimas_movimentacoes?: { id: number; tipo: string; ocorrido_em: string; responsavel: string; local: string }[]
  documentos?: { por_situacao: Serie; enviados_30_dias: number }
  auditoria?: { negados_24h: number; falhas_integridade_documento_30_dias: number; cadeia_integra?: boolean; atividade_recente: { id: number; acao: string; resultado: string; ocorrido_em: string; ator: string | null }[] }
}
const SITUACAO: Record<string, string> = { ATIVO: 'Ativos', ARQUIVADO: 'Arquivados' }
const MODO: Record<string, string> = { INDIVIDUAL: 'Individual', LOTE: 'Lote' }

/** RF-024 — painel: tudo calculado no servidor e limitado pelas permissões/escopo do usuário. */
export default function InicioPage() {
  const { perfil, pode } = useAuth()
  const { dados: d, erro, carregando, recarregar } = useRecurso(() => api<Painel>('/dashboard'), [])
  const alertas = useRecurso(() => api<{ data: Alerta[] }>('/alerts').catch(() => null), [])
  // Cards por tipo de material (2026-11-18): só para quem vê estoque; o armeiro (sem `movimentacoes.ver`) fica só com o objetivo da base.
  const verEstoque = pode('estoque.ver')
  const material = useRecurso(() => verEstoque ? api<PainelMaterial>('/dashboard/materials') : Promise.resolve(null), [verEstoque])
  const gestao = pode('movimentacoes.ver') || !pode('estoque.entregar')
  const base = perfil?.papeis.find((p) => p.papel === 'ARMEIRO')?.unidade?.sigla
  const podeEntregar = pode('estoque.entregar') && perfil?.operacoes.find((o) => o.codigo === 'ENTREGA')?.estado !== 'BLOQUEADA'
  const atualizar = () => { recarregar(); material.recarregar(); alertas.recarregar() }
  return (
    <Pagina icone={LayoutDashboard} titulo="Início" descricao={`Olá, ${perfil?.usuario.nome}. ${base ? `Números da base ${base}.` : 'Indicadores restritos ao seu escopo autorizado.'}`}
      acoes={<>
        {podeEntregar && <Link className="btn btn-pri" to="/entregas/nova"><HandHelping size={18} aria-hidden="true" />Registrar entrega</Link>}
        {pode('estoque.entrada') && <Link className={`btn ${podeEntregar ? 'btn-sec' : 'btn-pri'}`} to="/movimentacoes/nova-entrada">Registrar entrada</Link>}
        <button className="btn btn-sec" onClick={atualizar}>Atualizar</button></>}>
      {erro && <Erro erro={erro} tentar={recarregar} />}
      {d?.auditoria?.cadeia_integra === false && <Aviso tipo="perigo" titulo="Cadeia de auditoria inconsistente.">Acione a equipe de segurança e verifique em Auditoria.</Aviso>}
      {d?.auditoria && d.auditoria.falhas_integridade_documento_30_dias > 0 && <Aviso tipo="perigo" titulo="Integridade de documentos:">{d.auditoria.falhas_integridade_documento_30_dias} falha(s) de verificação de arquivo nos últimos 30 dias.</Aviso>}
      {alertas.dados && alertas.dados.data.length > 0 && <section className="bloco" aria-labelledby="sec-alertas"><h2 id="sec-alertas">Alertas</h2><ListaAlertas alertas={alertas.dados.data} /></section>}

      {verEstoque && (material.carregando && !material.dados
        ? <section aria-label="Carregando painel" className="secao-painel"><h2>Operação do dia</h2><EsqueletoCartoes n={pode('pedidos.gerir') ? 3 : 1} /><h2>{base ? 'Disponível na base' : 'Disponível no seu escopo'}</h2><EsqueletoCartoes /></section>
        : material.erro ? <Erro erro={material.erro} tentar={material.recarregar} />
        : material.dados && <>
          <OperacaoDoDia p={material.dados} pedidos={pode('pedidos.gerir')} />
          <DisponivelNaBase p={material.dados} titulo={base ? 'Disponível na base' : 'Disponível no seu escopo'} />
        </>)}

      {carregando && !d && <Carregando rotulo="Calculando indicadores…" />}
      {d && gestao && <>
        <section aria-labelledby="sec-estoque" className="secao-painel">
          <h2 id="sec-estoque">Indicadores gerais</h2>
          <div className="cartoes">
            {d.estoque && <>
              <Cartao rotulo="Itens individuais disponíveis" valor={d.estoque.itens_individuais_disponiveis} />
              <Cartao rotulo="Lotes com saldo" valor={d.estoque.lotes_com_saldo} extra={`${numero(d.estoque.quantidade_em_lotes)} unidades`} />
              <Cartao rotulo="Locais de estoque" valor={d.estoque.locais} />
              <Cartao rotulo="Movimentações (7 dias)" valor={d.estoque.movimentacoes_7_dias} />
            </>}
            {d.catalogo && <Cartao rotulo="Materiais ativos no catálogo" valor={d.catalogo.materiais_ativos} />}
            {d.unidades && <Cartao rotulo="Unidades no escopo" valor={d.unidades.ativas} />}
            {d.militares_ativos !== undefined && <Cartao rotulo="Efetivos ativos" valor={d.militares_ativos} />}
            {d.documentos && <Cartao rotulo="Documentos enviados (30 dias)" valor={d.documentos.enviados_30_dias} />}
            {d.auditoria && <Cartao rotulo="Acessos negados (24 h)" valor={d.auditoria.negados_24h} />}
          </div>
        </section>

        <div className="graficos">
          {d.estoque && <GraficoBarras titulo="Quantidade física por natureza" dados={d.estoque.por_natureza} rotulos={NATUREZAS} link={(r) => `/estoque?natureza=${r}`} />}
          {d.documentos && <GraficoBarras titulo="Documentos por situação" dados={d.documentos.por_situacao} rotulos={SITUACAO} link={(r) => `/documentos?situacao=${r}`} />}
          {d.catalogo && <GraficoBarras titulo="Materiais por modo de controle" dados={d.catalogo.por_modo} rotulos={MODO} link={(r) => `/materiais?modo_controle=${r}`} />}
        </div>
      </>}
      {!gestao && material.dados?.grupos.some((g) => g.com_efetivos > 0) && <div className="graficos">
        <GraficoBarras titulo="Com efetivos agora, por tipo de material"
          dados={material.dados.grupos.filter((g) => g.com_efetivos > 0).map((g) => ({ rotulo: g.chave, valor: g.com_efetivos }))}
          rotulos={Object.fromEntries(material.dados.grupos.map((g) => [g.chave, g.rotulo]))} link={(r) => `/entregas?grupo=${r}`} />
      </div>}
      {d && <>
        {gestao && d.ultimas_movimentacoes && (
          <section className="bloco" aria-labelledby="ult-mov">
            <h2 id="ult-mov">Últimas movimentações</h2>
            {d.ultimas_movimentacoes.length === 0 ? <p>Nenhuma movimentação no seu escopo.</p> : (
              <ul className="lista-simples">{d.ultimas_movimentacoes.map((m) => (
                <li key={m.id}><Link to={`/movimentacoes/${m.id}`}>#{m.id}</Link> <Selo valor={m.tipo} /> {m.local} · {m.responsavel} · <time dateTime={m.ocorrido_em}>{dataHora(m.ocorrido_em)}</time></li>
              ))}</ul>
            )}
          </section>
        )}
        {d.auditoria && (
          <section className="bloco" aria-labelledby="ativ">
            <h2 id="ativ">Atividade recente auditada</h2>
            {d.auditoria.atividade_recente.length === 0 ? <p>Sem eventos no seu escopo.</p> : (
              <ul className="lista-simples">{d.auditoria.atividade_recente.map((e) => (
                <li key={e.id}><span className="mono">{e.acao}</span> <Selo valor={e.resultado} /> {e.ator ?? 'anônimo'} · <time dateTime={e.ocorrido_em}>{dataHora(e.ocorrido_em)}</time></li>
              ))}</ul>
            )}
          </section>
        )}
        <p className="dica">Atualizado em {dataHora(material.dados?.gerado_em ?? d.gerado_em)}.</p>
      </>}
    </Pagina>
  )
}

function Cartao({ rotulo, valor, extra }: { rotulo: string; valor: number; extra?: string }) {
  return <div className="cartao"><p className="cartao-rotulo">{rotulo}</p><p className="cartao-valor">{numero(valor)}</p>{extra && <p className="cartao-extra">{extra}</p>}</div>
}
