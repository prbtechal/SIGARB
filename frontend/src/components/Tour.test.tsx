import { act, fireEvent, render, screen } from '@testing-library/react'
import { MemoryRouter } from 'react-router'
import { afterEach, beforeEach, expect, it, vi } from 'vitest'
import { Tour } from './Tour'

vi.mock('../auth/AuthContext', () => ({ useAuth: () => ({ perfil: { usuario: { id: 7 } } }) }))
beforeEach(() => {
  localStorage.clear(); vi.useFakeTimers(); Element.prototype.scrollIntoView = () => {}
  // jsdom não tem layout: finge que todo elemento é visível para os passos com alvo.
  vi.spyOn(Element.prototype, 'getBoundingClientRect').mockReturnValue({ top: 10, left: 10, bottom: 30, right: 110, width: 100, height: 20, x: 10, y: 10, toJSON: () => ({}) })
})
afterEach(() => { vi.useRealTimers(); vi.restoreAllMocks() })
const esperar = () => act(() => { vi.advanceTimersByTime(700) })

it('mostra o tour da tela só na primeira visita e permite rever', () => {
  const r = render(<MemoryRouter initialEntries={['/']}><Tour /></MemoryRouter>); esperar()
  expect(screen.getByRole('dialog', { name: 'Bem-vindo(a) ao SIGARB' })).toBeTruthy()
  fireEvent.click(screen.getByRole('button', { name: 'Pular dicas' }))
  expect(localStorage.getItem('sigarb.tour.7.inicio')).toBe('1')
  r.unmount(); render(<MemoryRouter initialEntries={['/']}><Tour /></MemoryRouter>); esperar()
  expect(screen.queryByRole('dialog')).toBeNull()
  fireEvent.click(screen.getByRole('button', { name: /Tour/ }))
  expect(screen.getByRole('dialog')).toBeTruthy()
})

it('abre as dicas do formulário quando o diálogo aparece, dentro dele', async () => {
  localStorage.setItem('sigarb.tour.7.usuarios', '1')
  render(<MemoryRouter initialEntries={['/usuarios']}><Tour /></MemoryRouter>); esperar()
  expect(screen.queryByRole('dialog')).toBeNull()
  const d = document.createElement('dialog'); d.setAttribute('open', ''); d.dataset.tour = 'criar-conta-de-acesso'
  d.innerHTML = '<div data-tour="cpf"></div><div data-tour="senha-inicial"></div><div class="dialogo-rodape"><button class="btn-pri">Criar</button></div>'
  await act(async () => { document.body.appendChild(d) }); esperar()
  const balao = screen.getByRole('dialog', { name: 'CPF' })
  expect(d.contains(balao)).toBe(true)
  expect(screen.getByText('1 de 3')).toBeTruthy()
  d.remove()
})

it('"Não mostrar dicas automáticas" desliga as próximas', () => {
  render(<MemoryRouter initialEntries={['/']}><Tour /></MemoryRouter>); esperar()
  fireEvent.click(screen.getByRole('button', { name: 'Não mostrar dicas automáticas' }))
  expect(localStorage.getItem('sigarb.tour.7.desligado')).toBe('1')
})
