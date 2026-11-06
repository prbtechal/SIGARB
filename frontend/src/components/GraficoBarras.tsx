import { useId } from 'react'
import { numero } from './ui'

/**
 * Gráfico de barras acessível: é uma tabela semântica; as barras são decorativas.
 * Leitores de tela leem rótulo, valor e percentual; nada depende só de cor.
 */
export function GraficoBarras({ titulo, dados, rotulos = {}, unidade = '' }: { titulo: string; dados: { rotulo: string; valor: number }[]; rotulos?: Record<string, string>; unidade?: string }) {
  const total = dados.reduce((s, d) => s + Number(d.valor), 0)
  const max = Math.max(1, ...dados.map((d) => Number(d.valor)))
  const id = useId()
  return (
    <figure className="grafico" aria-labelledby={id}>
      <figcaption id={id}>{titulo}</figcaption>
      {dados.length === 0 || total === 0 ? <p className="dica">Sem dados no seu escopo.</p> : (
        <table className="grafico-tabela" aria-labelledby={id}>
          <thead className="sr-only"><tr><th scope="col">Categoria</th><th scope="col">Valor</th><th scope="col">Percentual</th></tr></thead>
          <tbody>
            {dados.map((d) => {
              const pct = Math.round((Number(d.valor) / total) * 100)
              return (
                <tr key={d.rotulo}>
                  <th scope="row">{rotulos[d.rotulo] ?? d.rotulo}</th>
                  <td className="grafico-barra-cel">
                    <span className="grafico-barra" aria-hidden="true" style={{ inlineSize: `${(Number(d.valor) / max) * 100}%` }} />
                    <span className="grafico-valor">{numero(Number(d.valor))}{unidade}</span>
                  </td>
                  <td className="grafico-pct">{pct}%</td>
                </tr>
              )
            })}
          </tbody>
        </table>
      )}
    </figure>
  )
}
