import { useCallback, useEffect, useState, type ReactNode } from 'react'
import { ExternalLink, LifeBuoy, MessageSquarePlus } from 'lucide-react'
import { api } from '../api/client'
import { CENTRAL_VAZIA, normalizarCentral, quandoCentral, tomStatusCentral, type Central } from './centralSuporte'

/**
 * Central de Suporte PRB (outro sistema). Lê GET /central-suporte (só os chamados/avisos da PRÓPRIA conta; o servidor
 * ignora qualquer CPF do cliente). Falha ou leitura desligada = blocos escondidos; a tela nunca quebra por causa da Central.
 */
export function useCentralSuporte(ativo = true, intervaloMs = 0) {
  const [dados, setDados] = useState<Central>(CENTRAL_VAZIA)
  const atualizar = useCallback(async () => {
    try { setDados(normalizarCentral(await api('/central-suporte'))) } catch { /* Central fora do ar: mantém o último estado */ }
  }, [])
  useEffect(() => {
    if (!ativo) return
    atualizar()
    if (!intervaloMs) return
    const t = setInterval(() => { if (!document.hidden) atualizar() }, intervaloMs)
    return () => clearInterval(t)
  }, [ativo, intervaloMs, atualizar])
  return { ...dados, atualizar }
}

/** Link externo para a Central: nova aba, sem opener/referrer, com aviso para leitor de tela. */
export function LinkCentral({ href, className, children }: { href: string; className?: string; children: ReactNode }) {
  return (
    <a href={href} target="_blank" rel="noopener noreferrer" className={className}>
      {children}<span className="sr-only"> (abre a Central de Suporte PRB em nova aba)</span>
    </a>
  )
}

/** Botão "Abrir chamado" na Central PRB, com o Arsenal pré-selecionado. Some se a URL não estiver configurada. */
export function AbrirChamadoCentral({ url, className = 'btn btn-sec' }: { url: string | null; className?: string }) {
  if (!url) return null
  return <LinkCentral href={url} className={className}><MessageSquarePlus size={18} aria-hidden="true" />Abrir chamado na Central PRB</LinkCentral>
}

/** "Abrir chamado" que busca a própria URL (telas de erro/página não encontrada). */
export function AbrirChamadoCentralAuto() {
  const { url_abrir } = useCentralSuporte()
  return <AbrirChamadoCentral url={url_abrir} />
}

/** Cartão "Meus chamados" da Central PRB (até 5 abertos). Escondido quando a leitura está desligada ou indisponível. */
export function MeusChamadosCentral({ central }: { central: Central }) {
  if (!central.disponivel) return null
  const abertos = central.chamados.filter((c) => c.aberto).slice(0, 5)
  return (
    <section className="bloco central-suporte" aria-labelledby="central-suporte-titulo">
      <div className="bloco-topo">
        <h2 id="central-suporte-titulo"><LifeBuoy size={20} aria-hidden="true" /> Meus chamados na Central PRB</h2>
        <span className="selo selo-info">{central.abertos} aberto{central.abertos === 1 ? '' : 's'}</span>
      </div>
      {abertos.length === 0 ? <p className="dica">Nenhum chamado aberto na Central de Suporte PRB.</p> : (
        <ul className="central-lista">
          {abertos.map((c) => (
            <li key={c.protocolo + c.titulo}>
              <div className="estica">
                <span className="mono">{c.protocolo}</span> <span className="central-titulo">{c.titulo}</span>
                {c.atualizado_em && <span className="dica bloco-linha">Atualizado em {quandoCentral(c.atualizado_em)}</span>}
              </div>
              <span className={`selo selo-${tomStatusCentral(c)}`}>{c.aguardando_voce ? 'Aguardando você' : c.status_rotulo}</span>
              {c.link && <LinkCentral href={c.link} className="btn btn-sec"><ExternalLink size={16} aria-hidden="true" />Ver na Central<span className="sr-only"> — {c.protocolo}</span></LinkCentral>}
            </li>
          ))}
        </ul>
      )}
      <div className="acoes-linha">
        {central.url_chamados && <LinkCentral href={central.url_chamados} className="btn btn-sec"><ExternalLink size={18} aria-hidden="true" />Ver todos na Central</LinkCentral>}
        <AbrirChamadoCentral url={central.url_abrir} />
      </div>
    </section>
  )
}
