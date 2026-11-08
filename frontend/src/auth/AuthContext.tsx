import { createContext, useCallback, useContext, useEffect, useState, type ReactNode } from 'react'
import { api, garantirCsrf, onErroGlobal } from '../api/client'
import type { Perfil } from '../api/tipos'

type Ctx = {
  perfil: Perfil | null
  carregando: boolean
  expirada: boolean
  entrar: (login: string, password: string) => Promise<void>
  sair: () => Promise<void>
  pode: (perm: string) => boolean
}
const AuthCtx = createContext<Ctx | null>(null)

export function AuthProvider({ children }: { children: ReactNode }) {
  const [perfil, setPerfil] = useState<Perfil | null>(null)
  const [carregando, setCarregando] = useState(true)
  const [expirada, setExpirada] = useState(false)

  useEffect(() => {
    api<Perfil>('/me').then(setPerfil).catch(() => setPerfil(null)).finally(() => setCarregando(false))
    return onErroGlobal((s) => { if (s === 401) { setPerfil((p) => { if (p) setExpirada(true); return null }) } })
  }, [])

  const entrar = useCallback(async (login: string, password: string) => {
    await garantirCsrf(true)
    const p = await api<Perfil>('/auth/login', { method: 'POST', body: { login, password } })
    await garantirCsrf(true) // sessão regenerada → novo token
    setExpirada(false)
    setPerfil(p)
  }, [])

  const sair = useCallback(async () => {
    try { await api('/auth/logout', { method: 'POST' }) } finally { setPerfil(null); await garantirCsrf(true) }
  }, [])

  // "a|b" = qualquer uma das permissões (só UX; o servidor sempre revalida).
  const pode = useCallback((perm: string) => perm.split('|').some((p) => !!perfil?.permissoes.includes(p)), [perfil])

  return <AuthCtx.Provider value={{ perfil, carregando, expirada, entrar, sair, pode }}>{children}</AuthCtx.Provider>
}

export function useAuth() {
  const c = useContext(AuthCtx)
  if (!c) throw new Error('useAuth fora do AuthProvider')
  return c
}
