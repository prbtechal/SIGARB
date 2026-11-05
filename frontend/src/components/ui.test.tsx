import { render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { Campo, Erro, Selo, Tabela } from './ui'

describe('componentes', () => {
  it('Tabela expõe rótulo da coluna em cada célula (modo cartão no celular)', () => {
    render(<Tabela legenda="Teste" linhas={[{ id: 1, n: 'A' }]} chave={(l) => l.id} colunas={[{ titulo: 'Nome', celula: (l) => l.n }]} />)
    expect(screen.getByRole('table', { name: 'Teste' })).toBeInTheDocument()
    expect(screen.getByRole('cell')).toHaveAttribute('data-rotulo', 'Nome')
  })

  it('Campo associa rótulo, dica e erro com aria', () => {
    render(<Campo rotulo="Matrícula" dica="Formato X" erro={['Inválida']} obrigatorio>{(p) => <input {...p} />}</Campo>)
    const input = screen.getByLabelText(/Matrícula/)
    expect(input).toHaveAttribute('aria-invalid', 'true')
    expect(input).toBeRequired()
    expect(input).toHaveAccessibleDescription('Formato X Inválida')
  })

  it('Selo usa texto, não só cor', () => {
    render(<Selo valor="DISPONIVEL" />)
    expect(screen.getByText('Disponível')).toHaveClass('selo-ok')
  })

  it('Erro de permissão usa mensagem amigável e mostra referência', () => {
    render(<Erro erro={{ code: 'ACESSO_NEGADO', message: 'x', request_id: 'REQ1' }} />)
    expect(screen.getByRole('alert')).toHaveTextContent('não tem permissão')
    expect(screen.getByRole('alert')).toHaveTextContent('REQ1')
  })
})
