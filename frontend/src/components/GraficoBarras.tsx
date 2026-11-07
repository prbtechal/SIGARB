import { useEffect, useId, useRef } from 'react'
import { useNavigate } from 'react-router'
import { BarController, BarElement, CategoryScale, Chart, LinearScale, Tooltip, type ChartConfiguration } from 'chart.js'
import { numero } from './ui'

Chart.register(BarController, BarElement, CategoryScale, LinearScale, Tooltip)

// Série única: uma cor da marca, validada (dataviz validate_palette) contra as superfícies clara e escura.
const COR = { claro: '#1d4ed8', escuro: '#5b7cf7' }
const escuro = () => document.documentElement.dataset.theme === 'dark'
  || (document.documentElement.dataset.theme !== 'light' && typeof matchMedia === 'function' && matchMedia('(prefers-color-scheme: dark)').matches)

/**
 * Barras horizontais interativas (Chart.js): dica com valor e percentual; clique leva à lista filtrada (`link`).
 * Alternativa acessível: a mesma informação em tabela semântica ("Ver dados em tabela") e lista de atalhos por teclado.
 */
export function GraficoBarras({ titulo, dados, rotulos = {}, unidade = '', link }: {
  titulo: string; dados: { rotulo: string; valor: number }[]; rotulos?: Record<string, string>; unidade?: string; link?: (rotulo: string) => string
}) {
  const id = useId()
  const nav = useNavigate()
  const canvas = useRef<HTMLCanvasElement>(null)
  const total = dados.reduce((s, d) => s + Number(d.valor), 0)
  const nome = (r: string) => rotulos[r] ?? r

  useEffect(() => {
    const ctx = canvas.current?.getContext?.('2d')
    if (!ctx || total === 0) return // jsdom e estado vazio: só a tabela
    const e = escuro()
    const tinta = e ? '#cbd5e1' : '#475569'
    const grade = e ? 'rgba(148,163,184,.18)' : 'rgba(15,23,42,.08)'
    const cfg: ChartConfiguration<'bar'> = {
      type: 'bar',
      data: { labels: dados.map((d) => nome(d.rotulo)), datasets: [{ data: dados.map((d) => Number(d.valor)), backgroundColor: e ? COR.escuro : COR.claro,
        hoverBackgroundColor: e ? '#8aa2ff' : '#00008b', borderRadius: 4, borderSkipped: 'start', maxBarThickness: 28, categoryPercentage: 0.7 }] },
      options: {
        indexAxis: 'y', responsive: true, maintainAspectRatio: false, animation: { duration: 250 },
        onClick: (_ev, els) => { if (link && els[0]) nav(link(dados[els[0].index].rotulo)) },
        onHover: (ev, els) => { const t = ev.native?.target as HTMLElement | undefined; if (t) t.style.cursor = link && els.length ? 'pointer' : 'default' },
        scales: {
          x: { beginAtZero: true, grid: { color: grade }, border: { display: false }, ticks: { color: tinta, precision: 0, callback: (v) => numero(Number(v)) } },
          y: { grid: { display: false }, border: { display: false }, ticks: { color: tinta, font: { size: 13, weight: 600 } } },
        },
        plugins: {
          legend: { display: false },
          tooltip: { backgroundColor: '#0f172a', padding: 10, displayColors: false,
            callbacks: { label: (c) => `${numero(Number(c.raw))}${unidade} · ${Math.round((Number(c.raw) / total) * 100)}% do total`,
              footer: () => (link ? 'Clique para ver a lista' : '') } },
        },
      },
    }
    let g: Chart<'bar'> | null = null
    try { g = new Chart(ctx, cfg) } catch { return }
    return () => g?.destroy()
  }, [JSON.stringify(dados), total]) // eslint-disable-line react-hooks/exhaustive-deps

  return (
    <figure className="grafico" aria-labelledby={id}>
      <figcaption id={id}>{titulo}</figcaption>
      {dados.length === 0 || total === 0 ? <p className="dica">Sem dados no seu escopo.</p> : <>
        <div className="grafico-area" style={{ blockSize: `${Math.max(140, dados.length * 44 + 40)}px` }}>
          <canvas ref={canvas} role="img" aria-label={`${titulo}: ${dados.map((d) => `${nome(d.rotulo)} ${numero(Number(d.valor))}`).join(', ')}`} />
        </div>
        {link && <ul className="grafico-atalhos" aria-label={`Abrir lista filtrada — ${titulo}`}>
          {dados.map((d) => <li key={d.rotulo}><button type="button" onClick={() => nav(link(d.rotulo))}>{nome(d.rotulo)} <strong>{numero(Number(d.valor))}</strong></button></li>)}
        </ul>}
        <details className="grafico-tabela-detalhes">
          <summary>Ver dados em tabela</summary>
          <table className="grafico-tabela" aria-labelledby={id}>
            <thead><tr><th scope="col">Categoria</th><th scope="col">Valor</th><th scope="col">Percentual</th></tr></thead>
            <tbody>
              {dados.map((d) => (
                <tr key={d.rotulo}>
                  <th scope="row">{nome(d.rotulo)}</th>
                  <td>{numero(Number(d.valor))}{unidade}</td>
                  <td>{Math.round((Number(d.valor) / total) * 100)}%</td>
                </tr>))}
            </tbody>
          </table>
        </details>
      </>}
    </figure>
  )
}
