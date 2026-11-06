import { render, screen, within } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { GraficoBarras } from './GraficoBarras'

describe('GraficoBarras (alternativa textual nativa)', () => {
  it('é uma tabela com rótulo, valor e percentual legíveis; barras são decorativas', () => {
    const { container } = render(<GraficoBarras titulo="Por natureza" dados={[{ rotulo: 'MUNICAO', valor: 75 }, { rotulo: 'ARMAMENTO', valor: 25 }]} rotulos={{ MUNICAO: 'Munição', ARMAMENTO: 'Armamento' }} />)
    expect(screen.getByRole('figure', { name: 'Por natureza' })).toBeInTheDocument()
    const linha = screen.getByRole('row', { name: /Munição/ })
    expect(within(linha).getByRole('rowheader')).toHaveTextContent('Munição')
    expect(linha).toHaveTextContent('75')
    expect(linha).toHaveTextContent('75%')
    container.querySelectorAll('.grafico-barra').forEach((b) => expect(b).toHaveAttribute('aria-hidden', 'true'))
  })
  it('estado vazio explícito', () => {
    render(<GraficoBarras titulo="Vazio" dados={[]} />)
    expect(screen.getByText('Sem dados no seu escopo.')).toBeInTheDocument()
    expect(screen.queryByRole('table')).not.toBeInTheDocument()
  })
})
