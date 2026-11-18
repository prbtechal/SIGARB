import type { Unidade } from '../../api/tipos'

/** Unidades que encabeçam o escopo (a superior não está na lista). Uma só = o usuário opera uma base. */
export function raizesEscopo(unidades: Unidade[]): Unidade[] {
  const ids = new Set(unidades.map((u) => u.id))
  return unidades.filter((u) => u.unidade_superior_id === null || !ids.has(u.unidade_superior_id))
}

/**
 * Filtro "Unidade" (decisão 2026-11-18): lista só as unidades do escopo (o servidor já filtra GET /units).
 * Com uma base só, a opção vazia vira "Toda a base <sigla>" — nunca "Todas", que sugeriria outras bases.
 */
export function FiltroUnidade({ unidades, valor, onChange }: { unidades: Unidade[] | undefined; valor: string; onChange: (v: string) => void }) {
  const lista = unidades ?? []
  const raizes = raizesEscopo(lista)
  const minimo = lista.length ? Math.min(...lista.map((u) => u.nivel)) : 0
  const vazio = raizes.length === 1 ? `Toda a base ${raizes[0].sigla}` : 'Todas do meu escopo'
  return (
    <label>Unidade<select value={valor} onChange={(e) => onChange(e.target.value)}>
      <option value="">{vazio}</option>
      {lista.map((u) => <option key={u.id} value={u.id}>{'— '.repeat(Math.max(0, u.nivel - minimo))}{u.sigla}</option>)}
    </select></label>
  )
}
