import { render, screen, within } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { MemoryRouter } from 'react-router'
import { GraficoBarras } from './GraficoBarras'

describe('GraficoBarras (alternativa textual nativa)', () => {
  it('é uma tabela com rótulo, valor e percentual legíveis; barras são decorativas', () => {
    render(<MemoryRouter><GraficoBarras titulo="Por natureza" dados={[{ rotulo: 'MUNICAO', valor: 75 }, { rotulo: 'ARMAMENTO', valor: 25 }]} rotulos={{ MUNICAO: 'Munição', ARMAMENTO: 'Armamento' }} link={(r) => `/estoque?natureza=${r}`} /></MemoryRouter>)
    expect(screen.getByRole('figure', { name: 'Por natureza' })).toBeInTheDocument()
    const linha = screen.getByRole('row', { name: /Munição/ })
    expect(within(linha).getByRole('rowheader')).toHaveTextContent('Munição')
    expect(linha).toHaveTextContent('75')
    expect(linha).toHaveTextContent('75%')
    expect(screen.getByRole('img', { name: /Por natureza: Munição 75, Armamento 25/ })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: /Munição 75/ })).toBeInTheDocument() // atalho por teclado para a lista filtrada
  })
  it('estado vazio explícito', () => {
    render(<MemoryRouter><GraficoBarras titulo="Vazio" dados={[]} /></MemoryRouter>)
    expect(screen.getByText('Sem dados no seu escopo.')).toBeInTheDocument()
    expect(screen.queryByRole('table')).not.toBeInTheDocument()
  })
})
