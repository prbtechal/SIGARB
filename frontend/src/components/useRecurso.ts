import { useCallback, useEffect, useRef, useState } from 'react'
import { ErroApi, type ApiErro } from '../api/client'

/** Carrega dados com estados explícitos: carregando, erro, dados; ignora respostas obsoletas. */
export function useRecurso<T>(carregar: () => Promise<T>, deps: unknown[]) {
  const [dados, setDados] = useState<T | null>(null)
  const [erro, setErro] = useState<ApiErro | null>(null)
  const [carregando, setCarregando] = useState(true)
  const seq = useRef(0)
  // eslint-disable-next-line react-hooks/exhaustive-deps
  const fn = useCallback(carregar, deps)
  const recarregar = useCallback(() => {
    const n = ++seq.current
    setCarregando(true)
    setErro(null)
    fn().then((d) => { if (n === seq.current) setDados(d) })
      .catch((e) => { if (n === seq.current) setErro(e instanceof ErroApi ? e.erro : { code: 'ERRO', message: String(e) }) })
      .finally(() => { if (n === seq.current) setCarregando(false) })
  }, [fn])
  useEffect(() => { recarregar() }, [recarregar])
  return { dados, erro, carregando, recarregar }
}
