import { useMemo, useRef, useState } from 'react'
import { useNavigate, useSearchParams } from 'react-router'
import { ArrowLeftRight } from 'lucide-react'
import { ErroApi, api, novaChave, type Pagina as Pag } from '../api/client'
import type { Local, Militar, MovDetalhe, Saldo } from '../api/tipos'
import { useAuth } from '../auth/AuthContext'
import { AtalhoCadastro, Aviso, Campo, Carregando, Dialogo, Pagina, Vazio, numero, useNotificar } from '../components/ui'
import { useRecurso } from '../components/useRecurso'

type Item = { id: number; identificador: string; numero_serie: string | null; situacao: string; material: { id: number; codigo: string; nome: string } }

/** Aberturas de regra provisória: rótulo, permissão, situações aceitas e campos exigidos (espelham o servidor). */
export const TIPOS_OPERACAO = {
  SAIDA: { rotulo: 'Saída definitiva', perm: 'estoque.saida', situacoes: ['DISPONIVEL'], lotes: true, destinacao: true, doc: true },
  TRANSFERENCIA: { rotulo: 'Transferência (envio)', perm: 'estoque.transferir', situacoes: ['DISPONIVEL'], lotes: true, destinacao: false, doc: true },
  RESERVA: { rotulo: 'Reserva', perm: 'estoque.reservar', situacoes: ['DISPONIVEL'], lotes: true, destinacao: false, doc: false },
  MANUTENCAO: { rotulo: 'Envio para manutenção', perm: 'estoque.manutencao', situacoes: ['DISPONIVEL'], lotes: false, destinacao: false, doc: false },
  BAIXA: { rotulo: 'Baixa', perm: 'estoque.baixar', situacoes: ['DISPONIVEL', 'MANUTENCAO'], lotes: true, destinacao: false, doc: true },
  ENTREGA: { rotulo: 'Entrega a efetivo', perm: 'estoque.entregar', situacoes: ['DISPONIVEL'], lotes: true, destinacao: false, doc: false },
} as const
export type TipoOperacao = keyof typeof TIPOS_OPERACAO

export default function NovaOperacaoPage() {
  const nav = useNavigate()
  const notificar = useNotificar()
  const { perfil, pode } = useAuth()
  const [params] = useSearchParams()
  const liberadas = (Object.keys(TIPOS_OPERACAO) as TipoOperacao[]).filter((t) =>
    pode(TIPOS_OPERACAO[t].perm) && perfil?.operacoes.find((o) => o.codigo === t)?.estado !== 'BLOQUEADA')
  const inicial = params.get('tipo') as TipoOperacao | null
  const [tipo, setTipo] = useState<TipoOperacao | ''>(inicial && liberadas.includes(inicial) ? inicial : liberadas[0] ?? '')
  const [cab, setCab] = useState({ local_origem_id: '', local_destino_id: '', destinacao: '', documento_referencia: '', justificativa: '', militar_id: '', previsao_retorno: '' })
  const [marcados, setMarcados] = useState<Set<number>>(new Set())
  const [qtds, setQtds] = useState<Record<number, string>>({})
  const [erro, setErro] = useState<ErroApi | null>(null)
  const [revisar, setRevisar] = useState(false)
  const [enviando, setEnviando] = useState(false)
  const chave = useRef(novaChave())
  const locais = useRecurso(() => api<{ data: Local[] }>('/locations', { query: { somente_ativos: 1 } }), [])
  const efetivos = useRecurso(() => tipo === 'ENTREGA' ? api<Pag<Militar>>('/militares', { query: { per_page: 100, situacao: 'ATIVO' } }) : Promise.resolve(null), [tipo])
  const origem = cab.local_origem_id
  const estoque = useRecurso(async () => {
    if (!origem) return null
    const [i, s] = await Promise.all([
      api<Pag<Item>>('/stock/individual-items', { query: { local_id: origem, per_page: 100 } }),
      api<Pag<Saldo>>('/stock/balances', { query: { local_id: origem, per_page: 100 } }),
    ])
    return { itens: i.data, lotes: s.data.filter((x) => x.lote && x.disponivel > 0) }
  }, [origem])
  const cfg = tipo ? TIPOS_OPERACAO[tipo] : null
  const itens = useMemo(() => estoque.dados?.itens.filter((i) => cfg?.situacoes.includes(i.situacao as 'DISPONIVEL')) ?? [], [estoque.dados, cfg])
  const lotes = cfg?.lotes ? estoque.dados?.lotes ?? [] : []
  const fe = (k: string) => erro?.erro.fields?.[k]

  const linhas = [
    ...[...marcados].filter((id) => itens.some((i) => i.id === id)).map((id) => ({ item_individual_id: id })),
    ...lotes.filter((l) => Number(qtds[l.lote!.id]) > 0).map((l) => ({ lote_id: l.lote!.id, quantidade: Number(qtds[l.lote!.id]) })),
  ]
  const excedidos = lotes.filter((l) => Number(qtds[l.lote!.id]) > l.disponivel)

  const enviar = async () => {
    if (!tipo) return
    setEnviando(true); setErro(null)
    try {
      const corpo = {
        tipo, local_origem_id: Number(cab.local_origem_id), justificativa: cab.justificativa, itens: linhas,
        ...(tipo === 'TRANSFERENCIA' ? { local_destino_id: Number(cab.local_destino_id) } : {}),
        ...(cfg?.destinacao ? { destinacao: cab.destinacao } : {}),
        ...(tipo === 'ENTREGA' ? { militar_id: Number(cab.militar_id), ...(cab.previsao_retorno ? { previsao_retorno: cab.previsao_retorno } : {}) } : {}),
        ...(cab.documento_referencia ? { documento_referencia: cab.documento_referencia } : {}),
      }
      const r = await api<MovDetalhe>('/movements', { method: 'POST', body: corpo, headers: { 'Idempotency-Key': chave.current } })
      notificar('ok', `${cfg?.rotulo} #${r.id} registrada.`)
      nav(`/movimentacoes/${r.id}`)
    } catch (e) {
      if (e instanceof ErroApi) { setErro(e); setRevisar(false); if (e.status !== 409) chave.current = novaChave() }
    } finally { setEnviando(false) }
  }

  const trilha = [{ rotulo: 'Início', para: '/' }, { rotulo: 'Operações', para: '/operacoes' }, { rotulo: 'Nova operação' }]
  if (locais.carregando) return <Pagina titulo="Nova operação" trilha={trilha}><Carregando /></Pagina>
  if (!liberadas.length) return (
    <Pagina titulo="Nova operação" icone={ArrowLeftRight} trilha={trilha}>
      <Vazio titulo="Nenhuma operação disponível para você."><p>Seu perfil não tem permissão para registrar operações de estoque.</p></Vazio>
    </Pagina>)

  return (
    <Pagina titulo="Nova operação" icone={ArrowLeftRight} trilha={trilha}
      descricao="Saída, entrega, transferência, reserva, manutenção e baixa de materiais.">
      <Aviso tipo="info" titulo="Como funciona:">{perfil?.operacoes.find((o) => o.codigo === tipo)?.regra_provisoria ?? ''}</Aviso>
      {erro && <div className="aviso aviso-perigo" role="alert"><strong>Não foi possível registrar. </strong>{erro.erro.code === 'ACESSO_NEGADO' ? 'Você não tem permissão para esta operação neste local.' : erro.erro.message}</div>}
      <form noValidate onSubmit={(e) => { e.preventDefault(); setRevisar(true) }}>
        <fieldset className="bloco">
          <legend>Dados da operação</legend>
          <div className="grade">
            <Campo rotulo="Operação" obrigatorio>{(p) => (
              <select {...p} value={tipo} onChange={(e) => { setTipo(e.target.value as TipoOperacao); setMarcados(new Set()); setQtds({}) }}>
                {liberadas.map((t) => <option key={t} value={t}>{TIPOS_OPERACAO[t].rotulo}</option>)}
              </select>)}</Campo>
            <Campo rotulo="Local de origem" obrigatorio erro={fe('local_origem_id')}>{(p) => (
              <select {...p} value={cab.local_origem_id} onChange={(e) => { setCab({ ...cab, local_origem_id: e.target.value }); setMarcados(new Set()); setQtds({}) }}>
                <option value="">Selecione…</option>{locais.dados?.data.map((l) => <option key={l.id} value={l.id}>{l.codigo} — {l.nome} ({l.unidade.sigla})</option>)}
              </select>)}</Campo>
            {tipo === 'TRANSFERENCIA' && <Campo rotulo="Local de destino" obrigatorio erro={fe('local_destino_id')}>{(p) => (
              <select {...p} value={cab.local_destino_id} onChange={(e) => setCab({ ...cab, local_destino_id: e.target.value })}>
                <option value="">Selecione…</option>{locais.dados?.data.filter((l) => String(l.id) !== origem).map((l) => <option key={l.id} value={l.id}>{l.codigo} — {l.nome} ({l.unidade.sigla})</option>)}
              </select>)}</Campo>}
            {tipo === 'ENTREGA' && <>
              <Campo rotulo="Efetivo" obrigatorio erro={fe('militar_id')}>{(p) => (
                <select {...p} value={cab.militar_id} onChange={(e) => setCab({ ...cab, militar_id: e.target.value })}>
                  <option value="">Selecione…</option>{efetivos.dados?.data.map((m) => <option key={m.id} value={m.id}>{m.posto_graduacao.sigla} {m.nome_guerra} ({m.unidade.sigla})</option>)}
                </select>)}</Campo>
              {pode('militares.gerir') && <AtalhoCadastro para="/militares/novo" rotulo="Cadastrar efetivo" aoVoltar={efetivos.recarregar} />}
              <Campo rotulo="Previsão de retorno" dica="Deixe em branco para entrega sem retorno (consumo); com data, fica pendente de devolução." erro={fe('previsao_retorno')}>{(p) => <input {...p} type="date" value={cab.previsao_retorno} onChange={(e) => setCab({ ...cab, previsao_retorno: e.target.value })} />}</Campo>
            </>}
            {cfg?.destinacao && <Campo rotulo="Destinação" obrigatorio dica="Para onde o material vai (RN-006)." erro={fe('destinacao')}>{(p) => <input {...p} value={cab.destinacao} maxLength={255} onChange={(e) => setCab({ ...cab, destinacao: e.target.value })} />}</Campo>}
            <Campo rotulo="Documento de referência" obrigatorio={cfg?.doc} erro={fe('documento_referencia')}>{(p) => <input {...p} value={cab.documento_referencia} maxLength={120} onChange={(e) => setCab({ ...cab, documento_referencia: e.target.value })} />}</Campo>
            <Campo rotulo={tipo === 'RESERVA' ? 'Finalidade' : tipo === 'MANUTENCAO' ? 'Ocorrência' : 'Motivo'} obrigatorio dica="Mínimo de 5 caracteres." erro={fe('justificativa')}>{(p) => <input {...p} value={cab.justificativa} maxLength={500} onChange={(e) => setCab({ ...cab, justificativa: e.target.value })} />}</Campo>
          </div>
        </fieldset>

        <fieldset className="bloco">
          <legend>Itens do local de origem</legend>
          {fe('itens') && <p className="msg-erro">{fe('itens')?.join(' ')}</p>}
          {!origem ? <p className="dica">Escolha o local de origem para ver o estoque disponível.</p>
            : estoque.carregando ? <Carregando />
            : !itens.length && !lotes.length ? <Vazio titulo="Nada disponível para esta operação neste local." />
            : <div className="tabela-wrap"><table className="tabela">
              <caption className="sr-only">Estoque disponível na origem</caption>
              <thead><tr><th scope="col">Material</th><th scope="col">Identificação</th><th scope="col">Disponível</th><th scope="col">Selecionar</th></tr></thead>
              <tbody>
                {itens.map((i) => (
                  <tr key={`i${i.id}`}>
                    <td data-rotulo="Material">{i.material.nome}</td>
                    <td data-rotulo="Identificação" className="mono">{i.identificador}{i.situacao !== 'DISPONIVEL' ? ` (${i.situacao.toLowerCase()})` : ''}</td>
                    <td data-rotulo="Disponível">1</td>
                    <td data-rotulo="Selecionar"><input type="checkbox" aria-label={`Selecionar ${i.identificador}`} checked={marcados.has(i.id)}
                      onChange={(e) => setMarcados((s) => { const n = new Set(s); if (e.target.checked) n.add(i.id); else n.delete(i.id); return n })} /></td>
                  </tr>))}
                {lotes.map((l) => (
                  <tr key={`l${l.lote!.id}`}>
                    <td data-rotulo="Material">{l.material.nome}</td>
                    <td data-rotulo="Identificação" className="mono">Lote {l.lote!.codigo}</td>
                    <td data-rotulo="Disponível">{numero(l.disponivel)} {l.material.unidade_medida}</td>
                    <td data-rotulo="Selecionar"><input type="number" min={0} max={l.disponivel} inputMode="numeric" className="qtd"
                      aria-label={`Quantidade do lote ${l.lote!.codigo}`} aria-invalid={Number(qtds[l.lote!.id]) > l.disponivel || undefined}
                      value={qtds[l.lote!.id] ?? ''} onChange={(e) => setQtds({ ...qtds, [l.lote!.id]: e.target.value })} /></td>
                  </tr>))}
              </tbody>
            </table></div>}
          {excedidos.length > 0 && <p className="msg-erro" role="alert">Quantidade acima do disponível em {excedidos.map((l) => l.lote!.codigo).join(', ')}.</p>}
        </fieldset>
        <div className="barra-acoes">
          <button type="button" className="btn btn-sec" onClick={() => nav(-1)}>Cancelar</button>
          <button className="btn btn-pri" disabled={!tipo || !origem || !linhas.length || excedidos.length > 0 || (tipo === 'ENTREGA' && !cab.militar_id)}>Revisar operação</button>
        </div>
      </form>
      <Dialogo tour="confirmar-operacao" aberto={revisar} titulo={`Confirmar ${cfg?.rotulo.toLowerCase()}`} onFechar={() => setRevisar(false)}
        rodape={<><button className="btn btn-sec" onClick={() => setRevisar(false)}>Voltar e corrigir</button><button className="btn btn-pri" disabled={enviando} onClick={enviar}>{enviando ? 'Registrando…' : 'Confirmar e registrar'}</button></>}>
        <p>Origem: <strong>{locais.dados?.data.find((l) => String(l.id) === origem)?.codigo}</strong>
          {tipo === 'TRANSFERENCIA' && <> · Destino: <strong>{locais.dados?.data.find((l) => String(l.id) === cab.local_destino_id)?.codigo ?? '—'}</strong></>}</p>
        <p>{linhas.length} linha(s) selecionada(s).</p>
        <Aviso tipo="alerta">O registro não pode ser editado depois. {tipo === 'TRANSFERENCIA' ? 'Os itens ficam em trânsito até o destino registrar o recebimento.' : ''}</Aviso>
      </Dialogo>
    </Pagina>
  )
}
