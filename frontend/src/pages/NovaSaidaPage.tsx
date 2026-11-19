import { useEffect, useRef, useState } from 'react'
import { useNavigate, useSearchParams } from 'react-router'
import { PackageMinus, Trash2 } from 'lucide-react'
import { ErroApi, api, novaChave, type Pagina as Pag } from '../api/client'
import type { Local, MovDetalhe, Saldo } from '../api/tipos'
import { useAuth } from '../auth/AuthContext'
import { CampoAnexo, enviarAnexo } from '../components/Anexo'
import { ListaComOutro, type OpcaoLista } from '../components/ListaComOutro'
import { RotuloItem } from '../components/RotuloItem'
import { Aviso, Campo, Carregando, Dialogo, Pagina, Vazio, numero, useNotificar } from '../components/ui'
import { useRecurso } from '../components/useRecurso'

type Item = { id: number; identificador: string; numero_serie: string | null; situacao: string; material: { id: number; codigo: string; nome: string } }
type Selecao = { chave: string; material: string; rotulo: string; item_individual_id?: number; lote_id?: number; max: number; quantidade: string; unidade?: string }

/** Tipos de saída (espelham o servidor: OperacaoEstoqueService::ABERTURAS e EstoqueController::PERMISSAO). */
export const TIPOS_SAIDA = {
  SAIDA: { rotulo: 'Saída definitiva', perm: 'estoque.saida', situacoes: ['DISPONIVEL'], texto: 'O material deixa o estoque e vai para outro destino (doação, recolhimento, devolução ao fornecedor). Exige destinação.' },
  BAIXA: { rotulo: 'Baixa', perm: 'estoque.baixar', situacoes: ['DISPONIVEL', 'MANUTENCAO'], texto: 'Material inservível, extraviado ou destruído deixa de constar no estoque. Vale também para item em manutenção.' },
} as const
type TipoSaida = keyof typeof TIPOS_SAIDA
const MAX_MOTIVO = 450

/**
 * Registrar saída (pedido de 2026-11-19) a partir de Saldos de estoque. Usa as operações SAIDA e BAIXA já existentes:
 * registro imutável e idempotente (Idempotency-Key), saldo nunca negativo (409 no servidor), motivo obrigatório, auditado.
 * Estrutura pelas referências Mobbin (arsenal-saida-documentos.md): motivo em cartões de rádio, busca do material com seleção
 * mantida, resumo do selecionado, anexo opcional e confirmação antes de gravar.
 */
export default function NovaSaidaPage() {
  const nav = useNavigate()
  const notificar = useNotificar()
  const { perfil, pode } = useAuth()
  const [params] = useSearchParams()
  const liberados = (Object.keys(TIPOS_SAIDA) as TipoSaida[]).filter((t) => pode(TIPOS_SAIDA[t].perm) && perfil?.operacoes.find((o) => o.codigo === t)?.estado !== 'BLOQUEADA')
  const inicial = params.get('tipo') as TipoSaida | null
  const [tipo, setTipo] = useState<TipoSaida | ''>(inicial && liberados.includes(inicial) ? inicial : liberados[0] ?? '')
  const [local, setLocal] = useState(params.get('local') ?? '')
  const [busca, setBusca] = useState(params.get('q') ?? '')
  const [termo, setTermo] = useState(busca)
  const [sel, setSel] = useState<Map<string, Selecao>>(new Map())
  const [cab, setCab] = useState({ destinacao: '', documento_referencia: '', justificativa: '' })
  const [arquivo, setArquivo] = useState<File | null>(null)
  const [erroArq, setErroArq] = useState<string | null>(null)
  const [erro, setErro] = useState<ErroApi | null>(null)
  const [revisar, setRevisar] = useState(false)
  const [enviando, setEnviando] = useState(false)
  const chave = useRef(novaChave())
  const preSelecionado = useRef(false)
  const cfg = tipo ? TIPOS_SAIDA[tipo] : null
  const podeAnexar = pode('documentos.enviar')

  const locais = useRecurso(() => api<{ data: Local[] }>('/locations', { query: { somente_ativos: 1 } }), [])
  const destinos = useRecurso(() => (pode('estoque.saida') ? api<{ data: OpcaoLista[] }>('/stock/origins', { query: { tipo: 'SAIDA' } }) : Promise.resolve({ data: [] })), [])
  useEffect(() => { // um único local no escopo: já vem escolhido
    if (!local && locais.dados?.data.length === 1) setLocal(String(locais.dados.data[0].id))
  }, [locais.dados, local])
  useEffect(() => { const t = setTimeout(() => setTermo(busca.trim()), 300); return () => clearTimeout(t) }, [busca])
  const estoque = useRecurso(async () => {
    if (!local) return null
    const q = termo ? { q: termo } : {}
    const [i, s] = await Promise.all([
      api<Pag<Item>>('/stock/individual-items', { query: { local_id: local, per_page: 50, ...q } }),
      api<Pag<Saldo>>('/stock/balances', { query: { local_id: local, per_page: 50, ...q } }),
    ])
    return { itens: i.data, lotes: s.data.filter((x) => x.lote && x.disponivel > 0), maisItens: i.meta.total > i.data.length, maisLotes: s.meta.total > s.data.length }
  }, [local, termo])
  const itens = estoque.dados?.itens.filter((i) => (cfg?.situacoes as readonly string[] | undefined)?.includes(i.situacao)) ?? []
  const lotes = estoque.dados?.lotes ?? []

  // Vindo da linha de Saldos (?lote=): o lote já entra na seleção, só falta a quantidade.
  useEffect(() => {
    const lote = Number(params.get('lote'))
    if (preSelecionado.current || !lote || !estoque.dados) return
    const s = estoque.dados.lotes.find((x) => x.lote?.id === lote)
    if (s) { preSelecionado.current = true; marcarLote(s, '', true) }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [estoque.dados])

  const marcarItem = (i: Item, on: boolean) => setSel((m) => {
    const n = new Map(m); const k = `i${i.id}`
    if (on) n.set(k, { chave: k, material: i.material.nome, rotulo: i.numero_serie || i.identificador, item_individual_id: i.id, max: 1, quantidade: '1' }); else n.delete(k)
    return n
  })
  const marcarLote = (l: Saldo, qtd: string, manter = false) => setSel((m) => {
    const n = new Map(m); const k = `l${l.lote!.id}`
    if (qtd === '' && !manter) { n.delete(k); return n }
    n.set(k, { chave: k, material: l.material.nome, rotulo: `Lote ${l.lote!.codigo}`, lote_id: l.lote!.id, max: l.disponivel, quantidade: qtd, unidade: l.material.unidade_medida })
    return n
  })
  const tirar = (k: string) => setSel((m) => { const n = new Map(m); n.delete(k); return n })

  const selecao = [...sel.values()]
  const validas = selecao.filter((s) => Number(s.quantidade) > 0)
  const excedidos = selecao.filter((s) => Number(s.quantidade) > s.max)
  const motivoOk = cab.justificativa.trim().length >= 5
  const pronto = !!tipo && !!local && validas.length > 0 && excedidos.length === 0 && motivoOk && cab.documento_referencia.trim().length >= 3
    && (tipo !== 'SAIDA' || cab.destinacao.trim().length >= 3) && !erroArq
  const fe = (k: string) => erro?.erro.fields?.[k]
  const totalUn = validas.reduce((t, s) => t + Number(s.quantidade), 0)

  const enviar = async () => {
    if (!tipo) return
    setEnviando(true); setErro(null)
    try {
      const corpo = {
        tipo, local_origem_id: Number(local), justificativa: cab.justificativa.trim(), documento_referencia: cab.documento_referencia.trim(),
        ...(tipo === 'SAIDA' ? { destinacao: cab.destinacao.trim() } : {}),
        itens: validas.map((s) => (s.item_individual_id ? { item_individual_id: s.item_individual_id } : { lote_id: s.lote_id, quantidade: Number(s.quantidade) })),
      }
      const r = await api<MovDetalhe>('/movements', { method: 'POST', body: corpo, headers: { 'Idempotency-Key': chave.current } })
      notificar('ok', `${cfg?.rotulo} #${r.id} registrada.`)
      if (arquivo && podeAnexar) {
        try { await enviarAnexo(r.id, arquivo); notificar('ok', 'Documento anexado à saída.') }
        catch (x) { notificar('perigo', `A saída foi registrada, mas o anexo não foi enviado${x instanceof ErroApi ? `: ${x.erro.message}` : ''}. Anexe em Documentos › Registros sem documento.`) }
      }
      nav(`/movimentacoes/${r.id}`)
    } catch (e) {
      if (e instanceof ErroApi) { setErro(e); setRevisar(false); if (e.status !== 409) chave.current = novaChave() }
    } finally { setEnviando(false) }
  }

  const trilha = [{ rotulo: 'Início', para: '/' }, { rotulo: 'Saldos de estoque', para: '/estoque' }, { rotulo: 'Registrar saída' }]
  if (locais.carregando) return <Pagina titulo="Registrar saída" trilha={trilha}><Carregando /></Pagina>
  if (!liberados.length) return (
    <Pagina titulo="Registrar saída" icone={PackageMinus} trilha={trilha}>
      <Vazio titulo="Você não pode registrar saídas.">Seu perfil não tem permissão de saída nem de baixa.</Vazio>
    </Pagina>)
  const nomeLocal = locais.dados?.data.find((l) => String(l.id) === local)

  return (
    <Pagina titulo="Registrar saída" icone={PackageMinus} trilha={trilha}
      descricao="Tira material do estoque de forma definitiva. O registro não pode ser editado depois; correções são feitas por novo registro auditado.">
      {erro && <div className="aviso aviso-perigo" role="alert"><strong>Não foi possível registrar. </strong>
        {erro.erro.code === 'SALDO_INSUFICIENTE' ? 'O saldo disponível mudou e não cobre a quantidade informada. Confira a lista e tente de novo.'
          : erro.erro.code === 'ACESSO_NEGADO' ? 'Você não tem permissão de saída neste local.' : erro.erro.message}</div>}
      <form noValidate onSubmit={(e) => { e.preventDefault(); if (pronto) setRevisar(true) }}>
        <fieldset className="bloco">
          <legend>Tipo de saída</legend>
          <div className="opcoes-cartao">
            {liberados.map((t) => (
              <label key={t} className={`opcao-cartao${tipo === t ? ' marcada' : ''}`}>
                <input type="radio" name="tipo-saida" value={t} checked={tipo === t} onChange={() => { setTipo(t); setSel(new Map()) }} />
                <span><strong>{TIPOS_SAIDA[t].rotulo}</strong><small>{TIPOS_SAIDA[t].texto}</small></span>
              </label>))}
          </div>
        </fieldset>

        <fieldset className="bloco">
          <legend>Material</legend>
          <div className="grade">
            <Campo rotulo="Local de origem" obrigatorio erro={fe('local_origem_id')}>{(p) => (
              <select {...p} value={local} onChange={(e) => { setLocal(e.target.value); setSel(new Map()) }}>
                <option value="">Selecione…</option>{locais.dados?.data.map((l) => <option key={l.id} value={l.id}>{l.codigo} — {l.nome} ({l.unidade.sigla})</option>)}
              </select>)}</Campo>
            <Campo rotulo="Buscar no local" dica="Material, nº de série, código do item ou lote.">{(p) => (
              <input {...p} type="search" value={busca} disabled={!local} onChange={(e) => setBusca(e.target.value)} />)}</Campo>
          </div>
          {fe('itens') && <p className="msg-erro">{fe('itens')?.join(' ')}</p>}
          {!local ? <p className="dica">Escolha o local de origem para ver o que está disponível.</p>
            : estoque.carregando ? <Carregando />
            : !itens.length && !lotes.length ? <Vazio titulo={termo ? `Nada disponível com “${termo}” neste local.` : 'Nada disponível para este tipo de saída neste local.'} />
            : <div className="tabela-wrap"><table className="tabela">
              <caption className="sr-only">Material disponível em {nomeLocal?.codigo}</caption>
              <thead><tr><th scope="col">Material</th><th scope="col">Identificação</th><th scope="col" className="num">Disponível</th><th scope="col">Sai</th></tr></thead>
              <tbody>
                {itens.map((i) => (
                  <tr key={`i${i.id}`}>
                    <td data-rotulo="Material">{i.material.nome}</td>
                    <td data-rotulo="Identificação"><RotuloItem serie={i.numero_serie} codigo={i.identificador} />{i.situacao !== 'DISPONIVEL' ? ' (em manutenção)' : ''}</td>
                    <td data-rotulo="Disponível" className="num">1</td>
                    <td data-rotulo="Sai"><input type="checkbox" className="check-grande" aria-label={`Incluir ${i.numero_serie || i.identificador}`} checked={sel.has(`i${i.id}`)} onChange={(e) => marcarItem(i, e.target.checked)} /></td>
                  </tr>))}
                {lotes.map((l) => {
                  const s = sel.get(`l${l.lote!.id}`)
                  const acima = Number(s?.quantidade) > l.disponivel
                  return (
                    <tr key={`l${l.lote!.id}`}>
                      <td data-rotulo="Material">{l.material.nome}</td>
                      <td data-rotulo="Identificação" className="mono">Lote {l.lote!.codigo}</td>
                      <td data-rotulo="Disponível" className="num">{numero(l.disponivel)} {l.material.unidade_medida}</td>
                      <td data-rotulo="Sai"><input type="number" min={0} max={l.disponivel} inputMode="numeric" className="qtd" aria-label={`Quantidade que sai do lote ${l.lote!.codigo}`}
                        aria-invalid={acima || undefined} value={s?.quantidade ?? ''} onChange={(e) => marcarLote(l, e.target.value)} /></td>
                    </tr>)
                })}
              </tbody>
            </table></div>}
          {(estoque.dados?.maisItens || estoque.dados?.maisLotes) && <p className="dica">Mostrando os 50 primeiros. Refine a busca para achar outros.</p>}
          {selecao.length > 0 && (
            <div className="selecionados" aria-live="polite">
              <h3>Vai sair ({validas.length} linha{validas.length === 1 ? '' : 's'}, {numero(totalUn)} un.)</h3>
              <ul>{selecao.map((s) => (
                <li key={s.chave} className={Number(s.quantidade) > s.max ? 'texto-perigo' : undefined}>
                  <span><strong>{s.material}</strong> · {s.rotulo}{s.lote_id ? ` · ${s.quantidade || '0'} de ${numero(s.max)} ${s.unidade ?? ''}` : ''}</span>
                  <button type="button" className="btn btn-sec btn-peq" onClick={() => tirar(s.chave)}><Trash2 size={16} aria-hidden="true" />Tirar<span className="sr-only"> {s.rotulo}</span></button>
                </li>))}</ul>
            </div>)}
          {excedidos.length > 0 && <p className="msg-erro" role="alert">Quantidade acima do disponível em {excedidos.map((s) => s.rotulo).join(', ')}.</p>}
        </fieldset>

        <fieldset className="bloco">
          <legend>Dados da saída</legend>
          <div className="grade">
            {tipo === 'SAIDA' && <Campo rotulo="Destinação" obrigatorio dica="Para onde o material vai. Digite para buscar; se não estiver na lista, escolha “Outro…”." erro={fe('destinacao')}>{(p) => (
              <ListaComOutro p={p} opcoes={destinos.dados?.data ?? []} carregando={destinos.carregando} valor={cab.destinacao} rotuloOutro="Nova destinação"
                onChange={(v) => setCab((c) => ({ ...c, destinacao: v }))} />)}</Campo>}
            <Campo rotulo="Documento de referência" obrigatorio dica="Número do termo, ofício ou laudo que autoriza a saída." erro={fe('documento_referencia')}>{(p) => (
              <input {...p} value={cab.documento_referencia} maxLength={120} onChange={(e) => setCab({ ...cab, documento_referencia: e.target.value })} />)}</Campo>
          </div>
          <Campo rotulo="Motivo" obrigatorio dica={`Mínimo de 5 caracteres · ${MAX_MOTIVO - cab.justificativa.length} restantes.`} erro={fe('justificativa')}>{(p) => (
            <textarea {...p} rows={3} value={cab.justificativa} maxLength={MAX_MOTIVO} onChange={(e) => setCab({ ...cab, justificativa: e.target.value })} />)}</Campo>
          {podeAnexar && <CampoAnexo rotulo="Anexar o documento da saída" arquivo={arquivo} erro={erroArq} onChange={(f, e) => { setArquivo(f); setErroArq(e) }} />}
        </fieldset>

        <div className="barra-acoes">
          <button type="button" className="btn btn-sec" onClick={() => nav(-1)}>Cancelar</button>
          <button className="btn btn-pri" disabled={!pronto}>Revisar saída</button>
        </div>
      </form>
      <Dialogo aberto={revisar} titulo={`Confirmar ${cfg?.rotulo.toLowerCase() ?? 'saída'}`} onFechar={() => setRevisar(false)}
        rodape={<><button className="btn btn-sec" onClick={() => setRevisar(false)}>Voltar e corrigir</button><button className="btn btn-pri" disabled={enviando} onClick={enviar}>{enviando ? 'Registrando…' : 'Confirmar e registrar'}</button></>}>
        <p>Local: <strong>{nomeLocal?.codigo}</strong> · Documento: <strong>{cab.documento_referencia}</strong>{tipo === 'SAIDA' && <> · Destinação: <strong>{cab.destinacao}</strong></>}</p>
        <ul className="lista-simples">{validas.map((s) => <li key={s.chave}>{s.material} · {s.rotulo}{s.lote_id ? ` · ${numero(Number(s.quantidade))} ${s.unidade ?? ''}` : ''}</li>)}</ul>
        <p>Motivo: {cab.justificativa}</p>
        <p>Anexo: <strong>{arquivo ? arquivo.name : 'nenhum'}</strong></p>
        <Aviso tipo="alerta">Depois de registrada, a saída não pode ser editada nem desfeita por esta tela.</Aviso>
      </Dialogo>
    </Pagina>
  )
}
