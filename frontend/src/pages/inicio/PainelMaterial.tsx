import { AlertTriangle, ArrowRight, Boxes, ClipboardCheck, Crosshair, Hourglass, Link as Elo, Package, PackageCheck, Radio, ShieldCheck, Target, Zap, type LucideIcon } from 'lucide-react'
import { Link } from 'react-router'
import type { CartaoGrupo, GrupoMaterial, PainelMaterial } from '../../api/tipos'
import { haQuanto } from '../../components/quando'
import { Vazio, numero } from '../../components/ui'

/**
 * Início por tipo de material (decisão do responsável, 2026-11-18). Estrutura das referências Mobbin (n8n Overview, Plausible,
 * Searchable): faixa de números grandes com rótulo curto e uma linha secundária, agrupada em seções; o que pede ação vem primeiro.
 * Estética do design system (cartão, selos, tokens). Cada número leva à lista filtrada pelo mesmo grupo (`grupo=` na API).
 */
const ICONES: Record<GrupoMaterial, LucideIcon> = {
  ARMA_FOGO: Crosshair, MUNICAO: Target, COLETE: ShieldCheck, TASER: Zap, CARTUCHO_TASER: Zap, RADIO: Radio, ALGEMA: Elo, OUTROS: Package,
}
/** Lote (contagem em unidades) — o número grande vem com "unid.". */
const EM_UNIDADES: GrupoMaterial[] = ['MUNICAO', 'CARTUCHO_TASER']

/** Linha secundária objetiva de cada card (texto, nunca só cor). */
export function linhaSecundaria(g: CartaoGrupo): string {
  if (EM_UNIDADES.includes(g.chave)) {
    return `Hoje: +${numero(g.entradas_hoje)} entrada · −${numero(g.saidas_hoje)} saída · 7 dias: +${numero(g.entradas_7_dias)} / −${numero(g.saidas_7_dias)}`
  }
  const com = g.com_efetivos > 0 ? `${numero(g.com_efetivos)} com ${g.efetivos === 1 ? '1 efetivo' : `${numero(g.efetivos)} efetivos`}` : 'Nenhum com efetivos'
  return com
}

function CartaoMaterial({ g, validadeDias }: { g: CartaoGrupo; validadeDias: number }) {
  const Icone = ICONES[g.chave]
  const unid = EM_UNIDADES.includes(g.chave)
  const id = `grupo-${g.chave.toLowerCase()}`
  return (
    <article className={`cartao cartao-material${g.disponiveis === 0 ? ' cartao-zerado' : ''}`} aria-labelledby={id}>
      <header className="cartao-material-topo">
        <h3 id={id} className="cartao-rotulo">{g.rotulo}</h3>
        <span className="cartao-icone cartao-icone-p" aria-hidden="true"><Icone size={18} /></span>
      </header>
      <p className="cartao-valor">{numero(g.disponiveis)}{unid && <span className="cartao-unid"> unid.</span>}<span className="sr-only"> disponíveis</span></p>
      <p className="cartao-extra">disponíveis{unid && g.com_efetivos > 0 ? ` · ${numero(g.com_efetivos)} com efetivos` : ''}</p>
      <p className="cartao-extra">{linhaSecundaria(g)}</p>
      {(g.vencidos > 0 || g.vencendo > 0) && <p className="cartao-selos">
        {g.vencidos > 0 && <span className="selo selo-perigo"><AlertTriangle size={14} aria-hidden="true" />{numero(g.vencidos)} vencido{g.vencidos > 1 ? 's' : ''}</span>}
        {g.vencendo > 0 && <span className="selo selo-alerta"><Hourglass size={14} aria-hidden="true" />{numero(g.vencendo)} vence{g.vencendo > 1 ? 'm' : ''} em até {validadeDias} dias</span>}
      </p>}
      {g.tem_validade && g.vencidos === 0 && g.vencendo === 0 && <p className="cartao-selos"><span className="selo selo-ok">Validade em dia</span></p>}
      <p className="cartao-links">
        <Link to={`/estoque?grupo=${g.chave}`} aria-label={`Ver saldos de ${g.rotulo}`}>Ver saldos</Link>
        {g.com_efetivos > 0 && <Link to={`/entregas?grupo=${g.chave}`} aria-label={`Com efetivos: ${g.rotulo}`}>Com efetivos</Link>}
      </p>
    </article>
  )
}

/** Seção "Disponível na base": os 8 grupos do catálogo oficial, sempre na mesma ordem (o zero também é informação). */
export function DisponivelNaBase({ p, titulo }: { p: PainelMaterial; titulo: string }) {
  const vazio = p.grupos.every((g) => g.disponiveis === 0 && g.com_efetivos === 0)
  return (
    <section aria-labelledby="sec-disponivel" className="secao-painel">
      <div className="secao-topo"><h2 id="sec-disponivel">{titulo}</h2><Link to="/estoque" className="link-secao">Todos os saldos <ArrowRight size={16} aria-hidden="true" /></Link></div>
      {vazio
        ? <Vazio titulo="Nenhum material na base ainda." icone={Boxes}><p>Quando houver entrada de material nos locais do seu escopo, os números aparecem aqui por tipo.</p></Vazio>
        : <div className="cartoes-material">{p.grupos.map((g) => <CartaoMaterial key={g.chave} g={g} validadeDias={p.validade_dias} />)}</div>}
    </section>
  )
}

type Op = { rotulo: string; valor: number; extra: string; para: string; icone: LucideIcon; acao: string }

/** Seção "Operação do dia": o que pede ação do armeiro (pedidos e devoluções atrasadas). */
export function OperacaoDoDia({ p, pedidos }: { p: PainelMaterial; pedidos: boolean }) {
  const o = p.operacao
  const cards: Op[] = [
    ...(pedidos ? [
      { rotulo: 'Pedidos pendentes de aprovação', valor: o.pedidos_pendentes ?? 0, icone: ClipboardCheck, para: '/pedidos?status=PENDENTE', acao: 'Revisar pedidos',
        extra: o.pendente_mais_antigo ? `Mais antigo ${haQuanto(o.pendente_mais_antigo)}` : 'Nenhum aguardando' },
      { rotulo: 'Aprovados aguardando retirada', valor: o.pedidos_aprovados ?? 0, icone: PackageCheck, para: '/pedidos?status=APROVADO', acao: 'Ver aprovados',
        extra: (o.pedidos_aprovados ?? 0) > 0 ? 'Confira a identidade na retirada' : 'Nenhum a retirar' },
    ] : []),
    { rotulo: 'Entregas com devolução atrasada', valor: o.entregas_atrasadas, icone: AlertTriangle, para: '/entregas?atrasados=1', acao: 'Ver atrasadas',
      extra: o.entregas_atrasadas > 0 ? 'Previsão de retorno vencida' : 'Tudo dentro do prazo' },
  ]
  return (
    <section aria-labelledby="sec-operacao" className="secao-painel">
      <h2 id="sec-operacao">Operação do dia</h2>
      <div className="cartoes-material cartoes-operacao">
        {cards.map((c) => {
          const atencao = c.valor > 0
          return (
            <article key={c.para} className={`cartao cartao-material${atencao ? ' cartao-atencao' : ''}`} aria-label={c.rotulo}>
              <header className="cartao-material-topo">
                <h3 className="cartao-rotulo">{c.rotulo}</h3>
                <span className="cartao-icone cartao-icone-p" aria-hidden="true"><c.icone size={18} /></span>
              </header>
              <p className="cartao-valor">{numero(c.valor)}</p>
              <p className="cartao-extra">{c.extra}</p>
              {atencao && <p className="cartao-selos"><span className="selo selo-alerta">Requer ação</span></p>}
              <p className="cartao-links"><Link to={c.para}>{c.acao}</Link></p>
            </article>)
        })}
      </div>
    </section>
  )
}

/** Esqueleto do carregamento (mesma grade; leitor de tela ouve só o texto do status). */
export function EsqueletoCartoes({ n = 8 }: { n?: number }) {
  return (
    <div className="cartoes-material" role="status" aria-label="Carregando os números da base…">
      {Array.from({ length: n }, (_, i) => <div key={i} className="cartao cartao-material esqueleto" aria-hidden="true"><span /><span /><span /></div>)}
    </div>
  )
}
