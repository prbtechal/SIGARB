import { useState } from 'react'
import { SlidersHorizontal } from 'lucide-react'
import { ErroApi, api } from '../api/client'
import { Campo, Carregando, Dialogo, Erro, Pagina, dataHora, useNotificar } from '../components/ui'
import { useRecurso } from '../components/useRecurso'

type Valor = boolean | number | string[]
type Ajuste = { chave: string; grupo: string; rotulo: string; descricao: string; tipo: 'operacao' | 'bool' | 'int' | 'papeis'
  min?: number; max?: number; valor: Valor; padrao: Valor; alterado: boolean; motivo: string | null; alterado_por: string | null; alterado_em: string | null }
type Resp = { data: Ajuste[]; papeis_autoaprovacao: string[] }
type Pedido = { ajuste: Ajuste; valor: Valor | null } // null = voltar ao padrão

const PAPEL: Record<string, string> = { ARMEIRO: 'Armeiro', GESTOR: 'Gestor', APROVADOR: 'Aprovador' }
const igual = (a: Valor, b: Valor) => JSON.stringify(a) === JSON.stringify(b)
const texto = (a: Ajuste, v: Valor) => a.tipo === 'operacao' ? (v ? 'Desligada' : 'Ligada') : a.tipo === 'bool' ? (v ? 'Sim' : 'Não')
  : Array.isArray(v) ? (v.map((p) => PAPEL[p] ?? p).join(', ') || 'Ninguém') : String(v)

/** CP-16 — Administrador liga/desliga operações e ajusta políticas. Toda mudança pede motivo e vai para a auditoria. */
export default function ConfiguracoesPage() {
  const notificar = useNotificar()
  const r = useRecurso(() => api<Resp>('/system-settings'), [])
  const [rascunho, setRascunho] = useState<Record<string, Valor>>({})
  const [pedido, setPedido] = useState<Pedido | null>(null)
  const [motivo, setMotivo] = useState('')
  const [erro, setErro] = useState<string | null>(null)
  const [enviando, setEnviando] = useState(false)

  const abrir = (ajuste: Ajuste, valor: Valor | null) => { setPedido({ ajuste, valor }); setMotivo(''); setErro(null) }
  const confirmar = async () => {
    if (!pedido) return
    setEnviando(true)
    try {
      const { ajuste, valor } = pedido
      await api(`/system-settings/${ajuste.chave}`, valor === null ? { method: 'DELETE', body: { motivo } } : { method: 'PUT', body: { valor, motivo } })
      notificar('ok', `${ajuste.rotulo}: alteração salva e registrada na auditoria.`)
      setRascunho(({ [ajuste.chave]: _, ...resto }) => resto)
      setPedido(null)
      r.recarregar()
    } catch (e) {
      setErro(e instanceof ErroApi ? e.erro.message : 'Não foi possível salvar.')
    } finally { setEnviando(false) }
  }

  const grupos = r.dados ? [...new Set(r.dados.data.map((a) => a.grupo))] : []

  return (
    <Pagina icone={SlidersHorizontal} titulo="Configurações do sistema" trilha={[{ rotulo: 'Início', para: '/' }, { rotulo: 'Configurações' }]}
      descricao="Ligue e desligue operações e ajuste políticas e prazos. Cada mudança exige um motivo e fica na auditoria. Os demais usuários veem o efeito ao recarregar a página.">
      {r.carregando && <Carregando />}
      {r.erro && <Erro erro={r.erro} tentar={r.recarregar} />}
      {grupos.map((g) => (
        <section key={g} className="cartao config-grupo" aria-labelledby={`grupo-${g}`}>
          <h2 id={`grupo-${g}`}>{g}</h2>
          <ul className="config-lista">
            {r.dados!.data.filter((a) => a.grupo === g).map((a) => {
              const atual = rascunho[a.chave] ?? a.valor
              const sujo = !igual(atual, a.valor)
              const id = `ajuste-${a.chave.replaceAll('.', '-')}`
              return (
                <li key={a.chave} className="config-item">
                  <div className="config-texto">
                    <strong id={id}>{a.rotulo}</strong>
                    <p>{a.descricao}</p>
                    {a.alterado && <p className="config-meta">Alterado por {a.alterado_por} em {a.alterado_em ? dataHora(a.alterado_em) : '—'}: “{a.motivo}”. Padrão: {texto(a, a.padrao)}.{' '}
                      <button type="button" className="link" onClick={() => abrir(a, null)}>Voltar ao padrão</button></p>}
                  </div>
                  <div className="config-controle">
                    {(a.tipo === 'operacao' || a.tipo === 'bool') && (() => {
                      const ligado = a.tipo === 'operacao' ? !a.valor : !!a.valor
                      return <label className="interruptor">
                        <input type="checkbox" role="switch" aria-labelledby={id} checked={ligado}
                          onChange={() => abrir(a, a.tipo === 'operacao' ? ligado : !ligado)} />
                        <span aria-hidden="true" /> {a.tipo === 'operacao' ? (ligado ? 'Ligada' : 'Desligada') : (ligado ? 'Sim' : 'Não')}
                      </label>
                    })()}
                    {a.tipo === 'int' && <input type="number" aria-labelledby={id} min={a.min} max={a.max} value={String(atual)} className="config-numero"
                      onChange={(e) => setRascunho({ ...rascunho, [a.chave]: Number(e.target.value) })} />}
                    {a.tipo === 'papeis' && <fieldset className="config-papeis" aria-labelledby={id}>
                      {r.dados!.papeis_autoaprovacao.map((p) => {
                        const lista = atual as string[]
                        return <label key={p}><input type="checkbox" checked={lista.includes(p)}
                          onChange={(e) => setRascunho({ ...rascunho, [a.chave]: e.target.checked ? [...lista, p] : lista.filter((x) => x !== p) })} /> {PAPEL[p]}</label>
                      })}
                    </fieldset>}
                    {sujo && <div className="config-acoes">
                      <button type="button" className="btn btn-sec" onClick={() => setRascunho(({ [a.chave]: _, ...resto }) => resto)}>Desfazer</button>
                      <button type="button" className="btn btn-pri" onClick={() => abrir(a, atual)}>Salvar…</button>
                    </div>}
                  </div>
                </li>
              )
            })}
          </ul>
        </section>
      ))}
      <Dialogo tour="ajuste" aberto={!!pedido} titulo={pedido ? `${pedido.ajuste.rotulo}` : ''} onFechar={() => setPedido(null)}
        rodape={<><button className="btn btn-sec" onClick={() => setPedido(null)}>Cancelar</button>
          <button className={`btn ${pedido?.ajuste.tipo === 'operacao' && pedido.valor === true ? 'btn-perigo' : 'btn-pri'}`} disabled={motivo.trim().length < 5 || enviando} onClick={confirmar}>Confirmar</button></>}>
        {pedido && <p>De <strong>{texto(pedido.ajuste, pedido.ajuste.valor)}</strong> para <strong>{texto(pedido.ajuste, pedido.valor ?? pedido.ajuste.padrao)}</strong>
          {pedido.valor === null && ' (padrão)'}.
          {pedido.ajuste.tipo === 'operacao' && pedido.valor === true && ' Enquanto desligada, ninguém registra esta operação; o que já está em andamento fica aguardando.'}</p>}
        {erro && <div className="aviso aviso-perigo" role="alert">{erro}</div>}
        <Campo rotulo="Motivo" obrigatorio dica="Mínimo de 5 caracteres. Fica registrado na auditoria.">{(p) => <textarea {...p} rows={3} value={motivo} onChange={(e) => setMotivo(e.target.value)} />}</Campo>
      </Dialogo>
    </Pagina>
  )
}
