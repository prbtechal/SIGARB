import { createContext, useCallback, useContext, useEffect, useId, useRef, useState, type ReactNode } from 'react'
import { Link } from 'react-router'
import type { ApiErro } from '../api/client'

export function Pagina({ titulo, trilha, acoes, descricao, children }: { titulo: string; trilha?: { rotulo: string; para?: string }[]; acoes?: ReactNode; descricao?: ReactNode; children: ReactNode }) {
  useEffect(() => { document.title = `${titulo} · SIGARB` }, [titulo])
  return (
    <section className="pagina" aria-labelledby="titulo-pagina">
      {trilha && (
        <nav aria-label="Trilha de navegação" className="trilha">
          <ol>{trilha.map((t, i) => <li key={i}>{t.para ? <Link to={t.para}>{t.rotulo}</Link> : <span aria-current="page">{t.rotulo}</span>}</li>)}</ol>
        </nav>
      )}
      <header className="pagina-topo">
        <div>
          <h1 id="titulo-pagina" tabIndex={-1}>{titulo}</h1>
          {descricao && <p className="descricao">{descricao}</p>}
        </div>
        {acoes && <div className="acoes">{acoes}</div>}
      </header>
      {children}
    </section>
  )
}

const TONS: Record<string, string> = {
  DISPONIVEL: 'ok', ATIVO: 'ok', SUCESSO: 'ok', HABILITADA: 'ok', ENTRADA: 'info',
  ESTORNO: 'alerta', ESTORNADO: 'neutro', RESERVADO: 'info', AFASTADO: 'alerta', MANUTENCAO: 'alerta',
  NEGADO: 'perigo', FALHA: 'perigo', BLOQUEADA: 'neutro', INATIVO: 'neutro', BAIXADO: 'neutro', ACAUTELADO: 'info',
}
const ROTULOS: Record<string, string> = { DISPONIVEL: 'Disponível', MANUTENCAO: 'Manutenção', HABILITADA: 'Habilitada', BLOQUEADA: 'Bloqueada', ESTORNADO: 'Estornado', INDIVIDUAL: 'Individual', LOTE: 'Lote' }
export function Selo({ valor, tom }: { valor: string; tom?: string }) {
  return <span className={`selo selo-${tom ?? TONS[valor] ?? 'neutro'}`}>{ROTULOS[valor] ?? valor}</span>
}

export function Aviso({ tipo = 'info', titulo, children }: { tipo?: 'info' | 'alerta' | 'perigo' | 'ok'; titulo?: string; children: ReactNode }) {
  return <div className={`aviso aviso-${tipo}`} role={tipo === 'perigo' ? 'alert' : 'note'}>{titulo && <strong>{titulo} </strong>}{children}</div>
}

export function Carregando({ rotulo = 'Carregando…' }: { rotulo?: string }) {
  return <div className="carregando" role="status" aria-live="polite"><span className="spinner" aria-hidden="true" />{rotulo}</div>
}

export function Erro({ erro, tentar }: { erro: ApiErro; tentar?: () => void }) {
  const msg = erro.code === 'ACESSO_NEGADO' ? 'Você não tem permissão para ver este conteúdo no seu escopo.' : erro.message
  return (
    <div className="aviso aviso-perigo" role="alert">
      <strong>Não foi possível carregar. </strong>{msg}
      {erro.request_id && <small className="ref"> Ref.: {erro.request_id}</small>}
      {tentar && <div><button className="btn btn-sec" onClick={tentar}>Tentar novamente</button></div>}
    </div>
  )
}

export function Vazio({ titulo, children }: { titulo: string; children?: ReactNode }) {
  return <div className="vazio"><p className="vazio-titulo">{titulo}</p>{children}</div>
}

export function Campo({ rotulo, erro, dica, children, obrigatorio }: { rotulo: string; erro?: string[]; dica?: string; obrigatorio?: boolean; children: (p: { id: string; 'aria-invalid'?: boolean; 'aria-describedby'?: string; required?: boolean }) => ReactNode }) {
  const id = useId()
  const desc = [dica && `${id}-dica`, erro?.length && `${id}-erro`].filter(Boolean).join(' ') || undefined
  return (
    <div className={`campo${erro?.length ? ' campo-erro' : ''}`}>
      <label htmlFor={id}>{rotulo}{obrigatorio && <span aria-hidden="true" className="obrig"> *</span>}</label>
      {children({ id, 'aria-invalid': erro?.length ? true : undefined, 'aria-describedby': desc, required: obrigatorio })}
      {dica && <small id={`${id}-dica`} className="dica">{dica}</small>}
      {erro?.length ? <small id={`${id}-erro`} className="msg-erro">{erro.join(' ')}</small> : null}
    </div>
  )
}

export function Paginacao({ meta, ir }: { meta: { page: number; last_page: number; total: number }; ir: (p: number) => void }) {
  if (meta.total === 0) return null
  return (
    <nav className="paginacao" aria-label="Paginação">
      <button className="btn btn-sec" disabled={meta.page <= 1} onClick={() => ir(meta.page - 1)}>Anterior</button>
      <span aria-live="polite">Página {meta.page} de {meta.last_page} · {meta.total} registro(s)</span>
      <button className="btn btn-sec" disabled={meta.page >= meta.last_page} onClick={() => ir(meta.page + 1)}>Próxima</button>
    </nav>
  )
}

/** Tabela que vira lista de cartões em telas estreitas (CSS); cabeçalhos repetidos via data-rotulo. */
export function Tabela<T>({ legenda, colunas, linhas, chave }: { legenda: string; colunas: { titulo: string; celula: (l: T) => ReactNode; num?: boolean }[]; linhas: T[]; chave: (l: T) => string | number }) {
  return (
    <div className="tabela-wrap">
      <table className="tabela">
        <caption className="sr-only">{legenda}</caption>
        <thead><tr>{colunas.map((c) => <th key={c.titulo} scope="col" className={c.num ? 'num' : undefined}>{c.titulo}</th>)}</tr></thead>
        <tbody>
          {linhas.map((l) => (
            <tr key={chave(l)}>{colunas.map((c) => <td key={c.titulo} data-rotulo={c.titulo} className={c.num ? 'num' : undefined}>{c.celula(l)}</td>)}</tr>
          ))}
        </tbody>
      </table>
    </div>
  )
}

/** Diálogo modal nativo (<dialog>): foco preso pelo navegador, Esc fecha, foco devolvido ao gatilho. */
export function Dialogo({ aberto, titulo, onFechar, children, rodape }: { aberto: boolean; titulo: string; onFechar: () => void; children: ReactNode; rodape: ReactNode }) {
  const ref = useRef<HTMLDialogElement>(null)
  const id = useId()
  useEffect(() => {
    const d = ref.current
    if (!d) return
    if (aberto && !d.open) d.showModal?.()
    if (!aberto && d.open) d.close?.()
  }, [aberto])
  return (
    <dialog ref={ref} className="dialogo" aria-labelledby={id} onClose={onFechar} onCancel={(e) => { e.preventDefault(); onFechar() }}>
      {aberto && <>
        <h2 id={id}>{titulo}</h2>
        <div className="dialogo-corpo">{children}</div>
        <div className="dialogo-rodape">{rodape}</div>
      </>}
    </dialog>
  )
}

type Notif = { id: number; tipo: 'ok' | 'perigo'; texto: string }
const NotifCtx = createContext<(tipo: Notif['tipo'], texto: string) => void>(() => {})
export const useNotificar = () => useContext(NotifCtx)
export function NotificacoesProvider({ children }: { children: ReactNode }) {
  const [lista, setLista] = useState<Notif[]>([])
  const notificar = useCallback((tipo: Notif['tipo'], texto: string) => {
    const id = Date.now() + Math.random()
    setLista((l) => [...l, { id, tipo, texto }])
    setTimeout(() => setLista((l) => l.filter((n) => n.id !== id)), 6000)
  }, [])
  return (
    <NotifCtx.Provider value={notificar}>
      {children}
      <div className="notificacoes" role="status" aria-live="polite">
        {lista.map((n) => <div key={n.id} className={`notif notif-${n.tipo}`}>{n.texto}<button aria-label="Fechar notificação" onClick={() => setLista((l) => l.filter((x) => x.id !== n.id))}>×</button></div>)}
      </div>
    </NotifCtx.Provider>
  )
}

export const dataHora = (iso: string) => new Date(iso).toLocaleString('pt-BR', { dateStyle: 'short', timeStyle: 'short' })
export const data = (iso: string | null) => (iso ? new Date(iso + 'T00:00:00').toLocaleDateString('pt-BR') : '—')
export const numero = (n: number) => n.toLocaleString('pt-BR')
