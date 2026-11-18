import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'
import App from '../App'

// Login no padrão do Ronda Social (2026-11-18): rótulos sem *, botão "Entrar" sem trava antes de preencher,
// validação no envio e "Esqueci minha senha" com orientação inline (o Arsenal não recupera por e-mail).
afterEach(() => { vi.restoreAllMocks(); window.history.pushState({}, '', '/') })

function abrirLogin() {
  const fetch = vi.spyOn(globalThis, 'fetch').mockImplementation(async () =>
    new Response(JSON.stringify({ error: { code: 'NAO_AUTENTICADO', message: 'x' } }), { status: 401, headers: { 'Content-Type': 'application/json' } }))
  window.history.pushState({}, '', '/login')
  render(<App />)
  return fetch
}

describe('login no padrão do Ronda Social', () => {
  it('rótulos sem asterisco e botão Entrar habilitado; vazio valida no envio sem chamar a API', async () => {
    const fetch = abrirLogin()
    const cpf = await screen.findByLabelText('CPF')
    expect(cpf).toHaveAttribute('aria-required', 'true')
    expect(screen.getByLabelText('Senha')).toHaveAttribute('aria-required', 'true')
    expect(document.querySelector('.obrig')).toBeNull()
    const entrar = screen.getByRole('button', { name: 'Entrar' })
    expect(entrar).toBeEnabled()
    expect(entrar.querySelector('svg')).toBeNull()
    await userEvent.setup().click(entrar)
    expect(await screen.findByRole('alert')).toHaveTextContent('Preencha CPF e senha.')
    expect(fetch.mock.calls.some(([url]) => String(url).includes('/auth/login'))).toBe(false)
  })

  it('"Esqueci minha senha" mostra onde pedir a redefinição', async () => {
    abrirLogin()
    const u = userEvent.setup()
    const esqueci = await screen.findByRole('button', { name: 'Esqueci minha senha' })
    expect(esqueci).toHaveAttribute('aria-expanded', 'false')
    await u.click(esqueci)
    expect(esqueci).toHaveAttribute('aria-expanded', 'true')
    expect(document.getElementById('esqueci-aviso')).toHaveAttribute('role', 'status')
    expect(document.getElementById('esqueci-aviso')).toHaveTextContent('Para redefinir a senha, procure o setor de T.I. ou o gestor da sua unidade.')
  })
})
