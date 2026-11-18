import { useEffect, useId, useRef } from 'react'
import { BarController, BarElement, CategoryScale, Chart, Legend, LinearScale, Tooltip, type ChartConfiguration } from 'chart.js'
import { numero } from './ui'

Chart.register(BarController, BarElement, CategoryScale, LinearScale, Tooltip, Legend)

// Duas séries em ordem fixa (1 = azul da marca, 2 = laranja), validadas com o dataviz validate_palette nas superfícies clara e escura.
const CORES = { claro: ['#1d4ed8', '#c2410c'], escuro: ['#5b7cf7', '#d9692a'] }
const escuro = () => document.documentElement.dataset.theme === 'dark'
  || (document.documentElement.dataset.theme !== 'light' && typeof matchMedia === 'function' && matchMedia('(prefers-color-scheme: dark)').matches)

export type Serie = { nome: string; valores: number[] }

/**
 * Barras verticais agrupadas por período (Chart.js), um eixo só, legenda sempre visível e dica com o valor de cada série.
 * Alternativa acessível: a mesma informação em tabela semântica (sempre no DOM, dentro de "Ver dados em tabela").
 */
export function GraficoSeries({ titulo, periodos, series }: { titulo: string; periodos: string[]; series: Serie[] }) {
  const id = useId()
  const canvas = useRef<HTMLCanvasElement>(null)
  const total = series.reduce((s, x) => s + x.valores.reduce((a, b) => a + b, 0), 0)

  useEffect(() => {
    const ctx = canvas.current?.getContext?.('2d')
    if (!ctx || total === 0) return // jsdom e estado vazio: só a tabela
    const e = escuro()
    const cores = e ? CORES.escuro : CORES.claro
    const tinta = e ? '#cbd5e1' : '#475569'
    const grade = e ? 'rgba(148,163,184,.18)' : 'rgba(15,23,42,.08)'
    const cfg: ChartConfiguration<'bar'> = {
      type: 'bar',
      data: { labels: periodos, datasets: series.map((s, i) => ({ label: s.nome, data: s.valores, backgroundColor: cores[i % cores.length],
        borderRadius: 4, borderSkipped: 'start', maxBarThickness: 28, borderColor: e ? '#1a1a19' : '#ffffff', borderWidth: { left: 1, right: 1 } })) },
      options: {
        responsive: true, maintainAspectRatio: false, animation: { duration: 250 },
        interaction: { mode: 'index', intersect: false },
        scales: {
          x: { grid: { display: false }, border: { display: false }, ticks: { color: tinta, maxRotation: 0, autoSkip: true } },
          y: { beginAtZero: true, grid: { color: grade }, border: { display: false }, ticks: { color: tinta, precision: 0, callback: (v) => numero(Number(v)) } },
        },
        plugins: {
          legend: { position: 'top', align: 'start', labels: { color: tinta, boxWidth: 12, boxHeight: 12, useBorderRadius: true, borderRadius: 2 } },
          tooltip: { backgroundColor: '#0f172a', padding: 10, callbacks: { label: (c) => `${c.dataset.label}: ${numero(Number(c.raw))}` } },
        },
      },
    }
    let g: Chart<'bar'> | null = null
    try { g = new Chart(ctx, cfg) } catch { return }
    return () => g?.destroy()
  }, [JSON.stringify(periodos), JSON.stringify(series), total]) // eslint-disable-line react-hooks/exhaustive-deps

  return (
    <figure className="grafico" aria-labelledby={id}>
      <figcaption id={id}>{titulo}</figcaption>
      {periodos.length === 0 || total === 0 ? <p className="dica">Nenhuma entrega ou devolução neste período.</p> : (
        <div className="grafico-area" style={{ blockSize: '280px' }}>
          <canvas ref={canvas} role="img" aria-label={`${titulo}: ${series.map((s) => `${s.nome} ${numero(s.valores.reduce((a, b) => a + b, 0))}`).join(', ')} no período`} />
        </div>)}
      <details className="grafico-tabela-detalhes">
        <summary>Ver dados em tabela</summary>
        <table className="grafico-tabela" aria-labelledby={id}>
          <thead><tr><th scope="col">Período</th>{series.map((s) => <th key={s.nome} scope="col">{s.nome}</th>)}</tr></thead>
          <tbody>
            {periodos.map((p, i) => <tr key={p}><th scope="row">{p}</th>{series.map((s) => <td key={s.nome}>{numero(s.valores[i] ?? 0)}</td>)}</tr>)}
          </tbody>
        </table>
      </details>
    </figure>
  )
}
