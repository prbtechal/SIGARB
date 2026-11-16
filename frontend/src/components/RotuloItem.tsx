/** Item físico: número de série em destaque e o código do sistema (ARM-000001…) como texto secundário. Sem série, o código. */
export function RotuloItem({ serie, codigo, lote }: { serie?: string | null; codigo?: string | null; lote?: string | null }) {
  if (lote) return <span>Lote <strong className="mono">{lote}</strong></span>
  if (!serie) return <strong className="mono">{codigo ?? '—'}</strong>
  return <span className="rotulo-item"><strong className="mono">{serie}</strong>{codigo && <><br /><small className="mono">{codigo}</small></>}</span>
}

/** Texto para selects/opções: "série (código)". */
export const textoItem = (serie?: string | null, codigo?: string | null) => (serie ? `${serie}${codigo ? ` (${codigo})` : ''}` : (codigo ?? '—'))
