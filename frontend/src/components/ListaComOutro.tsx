import { useEffect, useId, useMemo, useRef, useState } from 'react'

export type OpcaoLista = { valor: string; usos?: number }

/** Sem acento e sem diferença de maiúsculas (igual à collation *_ci do servidor). */
export const normalizar = (s: string) => s.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().replace(/\s+/g, ' ').trim()

type Props = {
  p: { id: string; 'aria-invalid'?: boolean; 'aria-describedby'?: string; required?: boolean }
  opcoes: OpcaoLista[]
  valor: string
  onChange: (v: string) => void
  /** Rótulo do campo livre que aparece ao escolher "Outro…" (ex.: "Nova origem"). */
  rotuloOutro: string
  placeholder?: string
  maxLength?: number
  carregando?: boolean
}

/**
 * Lista com busca + "Outro…" (pedido de 2026-11-19; padrão das referências Mobbin em arsenal-saida-documentos.md):
 * digitar filtra (palavra por palavra, sem acento); ↑/↓ navegam; Enter escolhe; Esc fecha. "Outro…" (ou Enter sem
 * nenhuma opção que case) libera o campo livre já preenchido com o que foi digitado. O valor só é aceito quando vem
 * da lista ou do "Outro…"; sair do campo com um texto que não está na lista passa para o "Outro…" com esse texto.
 */
export function ListaComOutro({ p, opcoes, valor, onChange, rotuloOutro, placeholder = 'Digite para buscar…', maxLength = 255, carregando }: Props) {
  const base = useId()
  const listaId = `${base}-lista`
  const conhecido = (v: string) => opcoes.find((o) => normalizar(o.valor) === normalizar(v))
  const [modo, setModo] = useState<'lista' | 'outro'>('lista')
  const [texto, setTexto] = useState(valor)
  const [aberto, setAberto] = useState(false)
  const [ativo, setAtivo] = useState(0)
  const [filtrando, setFiltrando] = useState(false) // depois de escolher, reabrir mostra todas as opções
  const outroRef = useRef<HTMLInputElement>(null)
  const listaRef = useRef<HTMLInputElement>(null)
  const focarOutro = useRef(false)

  // Valor vindo de fora (rascunho, retorno) que não está na lista: abre direto no "Outro…".
  useEffect(() => {
    if (valor && !carregando && !conhecido(valor) && modo === 'lista') setModo('outro')
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [carregando])
  useEffect(() => { if (modo === 'outro' && focarOutro.current) { focarOutro.current = false; outroRef.current?.focus() } }, [modo])

  const filtradas = useMemo(() => {
    const palavras = filtrando ? normalizar(texto).split(' ').filter(Boolean) : []
    return opcoes.filter((o) => palavras.every((w) => normalizar(o.valor).includes(w)))
  }, [opcoes, texto, filtrando])
  const total = filtradas.length + 1 // + "Outro…"

  const escolher = (i: number) => {
    if (i < filtradas.length) {
      const v = filtradas[i].valor
      setTexto(v); onChange(v); setAberto(false); setFiltrando(false)
      return
    }
    focarOutro.current = true
    onChange(texto.trim()); setAberto(false); setModo('outro')
  }

  if (modo === 'outro') return (
    <div className="lista-outro">
      <input {...p} ref={outroRef} value={valor} maxLength={maxLength} placeholder={rotuloOutro}
        onChange={(e) => onChange(e.target.value)} />
      <button type="button" className="link" onClick={() => { setModo('lista'); const c = conhecido(valor); setTexto(c?.valor ?? ''); onChange(c?.valor ?? ''); setTimeout(() => listaRef.current?.focus(), 0) }}>
        Escolher da lista ({opcoes.length})
      </button>
    </div>
  )

  const idOpcao = (i: number) => `${base}-op-${i}`
  return (
    <div className="lista-combo">
      <input {...p} ref={listaRef} role="combobox" aria-expanded={aberto} aria-controls={listaId} aria-autocomplete="list" autoComplete="off"
        aria-activedescendant={aberto ? idOpcao(ativo) : undefined} value={texto} placeholder={placeholder} maxLength={maxLength}
        onFocus={() => setAberto(true)} onClick={() => setAberto(true)}
        onBlur={() => {
          setAberto(false)
          // Texto novo digitado e o usuário seguiu adiante: vira "Outro…" com esse texto (visível, reversível).
          const t = texto.trim()
          if (t && !conhecido(t)) { onChange(t); setModo('outro') }
        }}
        onChange={(e) => {
          const t = e.target.value
          setTexto(t); setAberto(true); setAtivo(0); setFiltrando(true)
          const c = t.trim() ? conhecido(t) : undefined
          onChange(c ? c.valor : '')
        }}
        onKeyDown={(e) => {
          if (e.key === 'ArrowDown') { e.preventDefault(); if (!aberto) setAberto(true); else setAtivo((a) => (a + 1) % total) }
          else if (e.key === 'ArrowUp') { e.preventDefault(); setAberto(true); setAtivo((a) => (a - 1 + total) % total) }
          else if (e.key === 'Enter') {
            if (!aberto) return
            e.preventDefault()
            // Sem opção que case com o texto digitado, Enter leva ao "Outro…" com o texto.
            escolher(filtradas.length === 0 ? filtradas.length : ativo)
          } else if (e.key === 'Escape' && aberto) { e.preventDefault(); e.stopPropagation(); setAberto(false) }
        }} />
      {aberto && (
        <ul id={listaId} role="listbox" className="lista-combo-opcoes" aria-label="Opções">
          {carregando && <li className="lista-combo-info" role="presentation">Carregando…</li>}
          {filtradas.map((o, i) => (
            <li key={o.valor} id={idOpcao(i)} role="option" aria-selected={i === ativo} className={i === ativo ? 'ativa' : undefined}
              onMouseDown={(e) => e.preventDefault()} onClick={() => escolher(i)} onMouseEnter={() => setAtivo(i)}>
              <span>{o.valor}</span>{o.usos ? <small>{o.usos}×</small> : null}
            </li>))}
          {!carregando && filtradas.length === 0 && opcoes.length > 0 && <li className="lista-combo-info" role="presentation">Nenhuma na lista.</li>}
          <li id={idOpcao(filtradas.length)} role="option" aria-selected={ativo === filtradas.length} className={`lista-combo-outro${ativo === filtradas.length ? ' ativa' : ''}`}
            onMouseDown={(e) => e.preventDefault()} onClick={() => escolher(filtradas.length)} onMouseEnter={() => setAtivo(filtradas.length)}>
            <span>Outro…</span>{filtrando && texto.trim() && !conhecido(texto) ? <small>usar “{texto.trim()}”</small> : null}
          </li>
        </ul>)}
    </div>
  )
}
