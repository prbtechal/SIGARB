import type { EntregaAgrupada } from '../../api/tipos'
import { RotuloItem } from '../RotuloItem'
import { numero, rotulo } from '../ui'

/** "Bom" quando tudo está igual; senão "2 Bom · 1 Regular" (só itens individuais têm conservação). */
export function resumoConservacao(c: Record<string, number>): string {
  const e = Object.entries(c)
  if (!e.length) return '—'
  if (e.length === 1) return rotulo(e[0][0])
  return e.map(([k, n]) => `${n} ${rotulo(k)}`).join(' · ')
}

/** Nomes resumidos: até 2 nomes distintos e "+N". */
export function nomesResumidos(e: EntregaAgrupada): string {
  const nomes = [...new Set(e.itens.map((i) => i.material.nome))]
  return nomes.length <= 2 ? nomes.join(', ') : `${nomes.slice(0, 2).join(', ')} +${nomes.length - 2}`
}

/** Resumo dos materiais de uma entrega, expansível para ver séries, lotes e quantidades. */
export function ResumoMateriais({ e }: { e: EntregaAgrupada }) {
  const n = e.itens.length
  return (
    <details className="entrega-materiais">
      <summary><strong>{n} {n === 1 ? 'material' : 'materiais'}</strong> <span className="dica">{nomesResumidos(e)}</span></summary>
      <ul className="lista-simples">
        {e.itens.map((i) => (
          <li key={`${i.item_individual_id ?? 'l' + i.lote_id}`}>
            {i.material.nome}{i.material.natureza_rotulo && <small> · {i.material.natureza_rotulo}</small>} —{' '}
            {i.lote_id ? <><RotuloItem lote={i.lote_codigo ?? i.referencia} /> · <strong>{numero(i.quantidade)}</strong> {i.material.unidade_medida ?? ''}</>
              : <RotuloItem serie={i.numero_serie} codigo={i.referencia} />}
            {i.estado_conservacao && <small> · {rotulo(i.estado_conservacao)}</small>}
          </li>))}
      </ul>
    </details>
  )
}
