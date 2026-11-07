import { useMemo, useRef, useState } from 'react'
import { useNavigate } from 'react-router'
import { ErroApi, api, novaChave, type Pagina as Pag } from '../api/client'
import type { Local, Material, MovDetalhe } from '../api/tipos'
import { AtalhoCadastro, Aviso, Campo, Carregando, Dialogo, Pagina, numero, useNotificar } from '../components/ui'
import { useAuth } from '../auth/AuthContext'
import { useRecurso } from '../components/useRecurso'

type Linha = { k: string; material_id: string; identificador: string; numero_serie: string; lote_codigo: string; validade: string; quantidade: string }
const linhaVazia = (): Linha => ({ k: novaChave(), material_id: '', identificador: '', numero_serie: '', lote_codigo: '', validade: '', quantidade: '1' })

/** UC-04 — entrada administrativa (registro, não autorização institucional). */
export default function NovaEntradaPage() {
  const nav = useNavigate()
  const notificar = useNotificar()
  const { pode } = useAuth()
  const locais = useRecurso(() => api<{ data: Local[] }>('/locations', { query: { somente_ativos: 1 } }), [])
  const mats = useRecurso(() => api<Pag<Material>>('/items', { query: { ativo: 1, per_page: 100 } }), [])
  const [cab, setCab] = useState({ local_destino_id: '', origem_descricao: '', documento_referencia: '', justificativa: '' })
  const [linhas, setLinhas] = useState<Linha[]>([linhaVazia()])
  const [erro, setErro] = useState<ErroApi | null>(null)
  const [revisar, setRevisar] = useState(false)
  const [enviando, setEnviando] = useState(false)
  const chave = useRef(novaChave())
  const porId = useMemo(() => new Map(mats.dados?.data.map((m) => [String(m.id), m])), [mats.dados])
  const fe = (k: string) => erro?.erro.fields?.[k]
  const muda = (k: string, campo: keyof Linha, v: string) => setLinhas((ls) => ls.map((l) => (l.k === k ? { ...l, [campo]: v } : l)))

  const payload = () => ({
    tipo: 'ENTRADA', ...cab, local_destino_id: Number(cab.local_destino_id), justificativa: cab.justificativa || null,
    itens: linhas.map((l) => porId.get(l.material_id)?.modo_controle === 'INDIVIDUAL'
      ? { material_id: Number(l.material_id), identificador: l.identificador.trim(), numero_serie: l.numero_serie.trim() || null }
      : { material_id: Number(l.material_id), lote_codigo: l.lote_codigo.trim(), validade: l.validade || null, quantidade: Number(l.quantidade) }),
  })

  const enviar = async () => {
    setEnviando(true); setErro(null)
    try {
      const r = await api<MovDetalhe>('/movements', { method: 'POST', body: payload(), headers: { 'Idempotency-Key': chave.current } })
      notificar('ok', `Entrada #${r.id} registrada.`)
      nav(`/movimentacoes/${r.id}`)
    } catch (e) {
      if (e instanceof ErroApi) { setErro(e); setRevisar(false); if (e.status !== 409) chave.current = novaChave() }
    } finally { setEnviando(false) }
  }

  if (locais.carregando || mats.carregando) return <Pagina titulo="Registrar entrada"><Carregando /></Pagina>
  const total = linhas.reduce((s, l) => s + (porId.get(l.material_id)?.modo_controle === 'LOTE' ? Number(l.quantidade) || 0 : 1), 0)

  return (
    <Pagina titulo="Registrar entrada" trilha={[{ rotulo: 'Início', para: '/' }, { rotulo: 'Movimentações', para: '/movimentacoes' }, { rotulo: 'Nova entrada' }]}
      descricao="Registro administrativo de entrada em estoque. Não constitui autorização institucional de recebimento.">
      {erro && <div className="aviso aviso-perigo" role="alert"><strong>Não foi possível registrar. </strong>{erro.erro.code === 'ACESSO_NEGADO' ? 'Você não tem permissão de entrada neste local.' : erro.erro.message}</div>}
      <form noValidate onSubmit={(e) => { e.preventDefault(); setRevisar(true) }}>
        <fieldset className="bloco">
          <legend>Dados da entrada</legend>
          <div className="grade">
            <Campo rotulo="Local de destino" obrigatorio erro={fe('local_destino_id')}>{(p) => (
              <select {...p} value={cab.local_destino_id} onChange={(e) => setCab({ ...cab, local_destino_id: e.target.value })}>
                <option value="">Selecione…</option>{locais.dados?.data.map((l) => <option key={l.id} value={l.id}>{l.codigo} — {l.nome} ({l.unidade.sigla})</option>)}
              </select>)}</Campo>
            <Campo rotulo="Documento de referência" obrigatorio dica="Número do documento de suporte (RN-005)." erro={fe('documento_referencia')}>{(p) => <input {...p} value={cab.documento_referencia} maxLength={120} onChange={(e) => setCab({ ...cab, documento_referencia: e.target.value })} />}</Campo>
            <Campo rotulo="Origem" obrigatorio dica="De onde vem o material." erro={fe('origem_descricao')}>{(p) => <input {...p} value={cab.origem_descricao} maxLength={255} onChange={(e) => setCab({ ...cab, origem_descricao: e.target.value })} />}</Campo>
            <Campo rotulo="Observação" erro={fe('justificativa')}>{(p) => <input {...p} value={cab.justificativa} maxLength={500} onChange={(e) => setCab({ ...cab, justificativa: e.target.value })} />}</Campo>
          </div>
        </fieldset>
        <fieldset className="bloco">
          <legend>Itens</legend>
          {fe('itens') && <p className="msg-erro">{fe('itens')?.join(' ')}</p>}
          {linhas.map((l, n) => {
            const m = porId.get(l.material_id)
            return (
              <div key={l.k} className="linha-item" role="group" aria-label={`Item ${n + 1}`}>
                <Campo rotulo={`Material (item ${n + 1})`} obrigatorio erro={fe(`itens.${n}.material_id`)}>{(p) => (
                  <select {...p} value={l.material_id} onChange={(e) => muda(l.k, 'material_id', e.target.value)}>
                    <option value="">Selecione…</option>{mats.dados?.data.map((x) => <option key={x.id} value={x.id}>{x.codigo} — {x.nome} ({x.modo_controle === 'LOTE' ? 'lote' : 'individual'})</option>)}
                  </select>)}</Campo>
                {n === 0 && pode('catalogo.gerir') && <AtalhoCadastro para="/materiais?novo=1" rotulo="Material não está na lista? Cadastrar material" aoVoltar={mats.recarregar} />}
                {m?.modo_controle === 'INDIVIDUAL' && <>
                  <Campo rotulo="Identificador patrimonial" obrigatorio erro={fe(`itens.${n}.identificador`)}>{(p) => <input {...p} value={l.identificador} onChange={(e) => muda(l.k, 'identificador', e.target.value)} />}</Campo>
                  <Campo rotulo="Número de série" erro={fe(`itens.${n}.numero_serie`)}>{(p) => <input {...p} value={l.numero_serie} onChange={(e) => muda(l.k, 'numero_serie', e.target.value)} />}</Campo>
                </>}
                {m?.modo_controle === 'LOTE' && <>
                  <Campo rotulo="Código do lote" obrigatorio erro={fe(`itens.${n}.lote_codigo`)}>{(p) => <input {...p} value={l.lote_codigo} onChange={(e) => muda(l.k, 'lote_codigo', e.target.value)} />}</Campo>
                  <Campo rotulo="Validade" erro={fe(`itens.${n}.validade`)}>{(p) => <input {...p} type="date" value={l.validade} onChange={(e) => muda(l.k, 'validade', e.target.value)} />}</Campo>
                  <Campo rotulo="Quantidade" obrigatorio erro={fe(`itens.${n}.quantidade`)}>{(p) => <input {...p} type="number" min={1} inputMode="numeric" value={l.quantidade} onChange={(e) => muda(l.k, 'quantidade', e.target.value)} />}</Campo>
                </>}
                {linhas.length > 1 && <button type="button" className="btn btn-sec" onClick={() => setLinhas((ls) => ls.filter((x) => x.k !== l.k))}>Remover item {n + 1}</button>}
              </div>
            )
          })}
          <button type="button" className="btn btn-sec" onClick={() => setLinhas((ls) => [...ls, linhaVazia()])}>Adicionar item</button>
        </fieldset>
        <div className="barra-acoes">
          <button type="button" className="btn btn-sec" onClick={() => nav(-1)}>Cancelar</button>
          <button className="btn btn-pri" disabled={!cab.local_destino_id || linhas.some((l) => !l.material_id)}>Revisar entrada</button>
        </div>
      </form>
      <Dialogo aberto={revisar} titulo="Confirmar entrada" onFechar={() => setRevisar(false)}
        rodape={<><button className="btn btn-sec" onClick={() => setRevisar(false)}>Voltar e corrigir</button><button className="btn btn-pri" disabled={enviando} onClick={enviar}>{enviando ? 'Registrando…' : 'Confirmar e registrar'}</button></>}>
        <p>Local: <strong>{locais.dados?.data.find((l) => String(l.id) === cab.local_destino_id)?.codigo}</strong> · Documento: <strong>{cab.documento_referencia || '—'}</strong></p>
        <p>{linhas.length} linha(s), {numero(total)} unidade(s) no total.</p>
        <Aviso tipo="alerta">Após registrada, a entrada não pode ser editada. Correções são feitas por estorno auditado.</Aviso>
      </Dialogo>
    </Pagina>
  )
}
