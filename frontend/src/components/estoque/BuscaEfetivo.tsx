import { useEffect, useId, useState } from 'react'
import { api } from '../../api/client'
import type { Militar } from '../../api/tipos'
import { AvisoAptidao, SeloAptidao } from '../AptidaoEfetivo'
import { Carregando } from '../ui'
import { rotuloEfetivo } from '../efetivo'

/**
 * Escolha de efetivo por nome, nome de guerra ou matrícula (a busca por matrícula segue a regra de visibilidade do servidor).
 * Mostra o selo de aptidão (pode ou não arma de fogo/munição) em cada resultado e o aviso completo do escolhido.
 */
export function BuscaEfetivo({ rotulo = 'Efetivo', valor, onEscolher, excluir, obrigatorio }: {
  rotulo?: string; valor: Militar | null; onEscolher: (m: Militar | null) => void; excluir?: number; obrigatorio?: boolean
}) {
  const id = useId()
  const [termo, setTermo] = useState('')
  const [res, setRes] = useState<Militar[] | null>(null)
  const [buscando, setBuscando] = useState(false)

  useEffect(() => {
    const t = termo.trim()
    if (t.length < 2) { setRes(null); return }
    let vivo = true
    const h = setTimeout(() => {
      setBuscando(true)
      // Decisão 2026-11-18: busca do balcão (escopo de entrega/pedidos/acautelamento), sem exigir a tela Efetivos.
      api<{ data: Militar[] }>('/stock/recipients', { query: { q: t, per_page: 10 } })
        .then((r) => { if (vivo) setRes(r.data.filter((m) => m.id !== excluir)) })
        .catch(() => { if (vivo) setRes([]) })
        .finally(() => { if (vivo) setBuscando(false) })
    }, 250)
    return () => { vivo = false; clearTimeout(h) }
  }, [termo, excluir])

  if (valor) return (
    <div className="campo busca-efetivo">
      <span className="rotulo-campo">{rotulo}{obrigatorio && <span aria-hidden="true" className="obrig"> *</span>}</span>
      <div className="efetivo-escolhido">
        <div><strong>{rotuloEfetivo(valor)}</strong> <small>({valor.unidade?.sigla})</small><br /><small>{valor.nome}</small></div>
        <SeloAptidao m={valor} />
        <button type="button" className="btn btn-sec btn-peq" onClick={() => { onEscolher(null); setTermo('') }}>Trocar<span className="sr-only"> o efetivo</span></button>
      </div>
      <AvisoAptidao m={valor} />
    </div>)

  return (
    <div className="campo busca-efetivo">
      <label htmlFor={id}>{rotulo}{obrigatorio && <span aria-hidden="true" className="obrig"> *</span>}</label>
      <input id={id} type="search" value={termo} autoComplete="off" placeholder="Nome, nome de guerra, matrícula ou CPF" onChange={(e) => setTermo(e.target.value)} aria-describedby={`${id}-d`} />
      <small id={`${id}-d`} className="dica">Digite ao menos 2 letras. Só efetivos ativos do seu escopo.</small>
      {buscando && <Carregando rotulo="Buscando…" />}
      {res && !buscando && (res.length === 0 ? <p className="dica" role="status">Nenhum efetivo encontrado.</p> : (
        <ul className="resultados-efetivo" aria-label="Efetivos encontrados">
          {res.map((m) => (
            <li key={m.id}>
              <button type="button" className="resultado-efetivo" onClick={() => onEscolher(m)}>
                <span><strong>{rotuloEfetivo(m)}</strong> · {m.nome} <small>({m.unidade.sigla} · mat. {m.matricula})</small></span>
                <SeloAptidao m={m} />
              </button>
            </li>))}
        </ul>))}
    </div>)
}
