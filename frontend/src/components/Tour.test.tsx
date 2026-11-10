import { act, fireEvent, render, screen } from '@testing-library/react'
import { MemoryRouter } from 'react-router'
import { afterEach, beforeEach, expect, it, vi } from 'vitest'
import { Tour } from './Tour'

vi.mock('../auth/AuthContext', () => ({ useAuth: () => ({ perfil: { usuario: { id: 7 } } }) }))
beforeEach(() => { localStorage.clear(); vi.useFakeTimers() })
afterEach(() => vi.useRealTimers())

const montar = () => render(<MemoryRouter initialEntries={['/']}><Tour /></MemoryRouter>)

it('mostra o tour na primeira visita e não depois de concluído', () => {
  const r = montar()
  act(() => { vi.advanceTimersByTime(800) })
  expect(screen.getByRole('dialog', { name: 'Bem-vindo(a) ao SIGARB' })).toBeTruthy()
  // Alvos não existem no jsdom (sem layout): só o passo central sobra, então já é o último.
  fireEvent.click(screen.getByRole('button', { name: 'Concluir' }))
  expect(screen.queryByRole('dialog')).toBeNull()
  expect(localStorage.getItem('sigarb.tour.7./')).toBe('1')
  r.unmount(); montar()
  act(() => { vi.advanceTimersByTime(800) })
  expect(screen.queryByRole('dialog')).toBeNull()
  fireEvent.click(screen.getByRole('button', { name: /Tour/ }))
  expect(screen.getByRole('dialog')).toBeTruthy()
})
