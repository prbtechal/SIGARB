import { FileText, Paperclip } from 'lucide-react'
import { useEffect, useState } from 'react'
import { Link, useSearchParams } from 'react-router'
import { api, type Pagina as Pag } from '../api/client'
import type { DocResumo, RegistroSemDoc } from '../api/tipos'
import { useAuth } from '../auth/AuthContext'
import { AnexarDialogo, ROTULO_REGISTRO } from '../components/Anexo'
import { tamanho } from '../components/arquivos'
import { Aviso, Carregando, Erro, Pagina, Paginacao, Selo, Tabela, Vazio, dataHora, rotulo, useNotificar } from '../components/ui'
import { useRecurso } from '../components/useRecurso'

const REGISTROS = { ENTRADA: 'Entrada', SAIDA: 'Saída', BAIXA: 'Baixa', OUTRO: 'Outras movimentações', SEM_REGISTRO: 'Sem registro ligado' } as const
const rotuloRegistro = (t: string) => ROTULO_REGISTRO[t] ?? rotulo(t)

/**
 * RF-014 + pedido de 2026-11-19: raiz de busca de todo documento enviado no sistema (envio avulso, anexo da entrada/saída,
 * nova versão) e fila de registros salvos sem documento, com "Anexar". Filtros na URL (voltar mantém a busca).
 * Estrutura pelas referências Mobbin (arsenal-saida-documentos.md): abas com contador, busca única + filtros em linha, tabela densa.
 */
export default function DocumentosPage() {
  const { pode } = useAuth()
  const notificar = useNotificar()
  const [params, setParams] = useSearchParams()
  const podeEnviar = pode('documentos.enviar')
  const aba = params.get('aba') === 'pendentes' && podeEnviar ? 'pendentes' : 'documentos'
  const f = {
    q: params.get('q') ?? '', tipo_documento_id: params.get('tipo_documento_id') ?? '', situacao: params.get('situacao') ?? 'ATIVO',
    registro_tipo: params.get('registro_tipo') ?? '', de: params.get('de') ?? '', ate: params.get('ate') ?? '', meus: params.get('meus') ?? '',
    versoes: params.get('versoes') ?? '', page: Number(params.get('page') ?? 1),
  }
  const p = { q: params.get('pq') ?? '', tipo: params.get('ptipo') ?? '', meus: params.get('pmeus') ?? '', page: Number(params.get('ppage') ?? 1) }
  const mudar = (n: Record<string, string | number>) => {
    const m = new URLSearchParams(params)
    Object.entries(n).forEach(([k, v]) => { if (v === '' || ((k === 'page' || k === 'ppage') && v === 1)) m.delete(k); else m.set(k, String(v)) })
    setParams(m, { replace: true })
  }
  const [busca, setBusca] = useState(f.q)
  const [buscaP, setBuscaP] = useState(p.q)
  const [anexar, setAnexar] = useState<RegistroSemDoc | null>(null)
  const tipos = useRecurso(() => api<{ data: { id: number; nome: string }[] }>('/document-types'), [])
  const consulta = { ...f, situacao: f.situacao === 'TODAS' ? '' : f.situacao }
  const docs = useRecurso(() => (aba === 'documentos' ? api<Pag<DocResumo>>('/documents', { query: consulta }) : Promise.resolve(null)),
    [aba, f.q, f.tipo_documento_id, f.situacao, f.registro_tipo, f.de, f.ate, f.meus, f.versoes, f.page])
  // Contador da aba sempre visível para quem envia (1 registro por página basta para ler o total).
  const pend = useRecurso(() => (podeEnviar ? api<Pag<RegistroSemDoc>>('/documents/pending-records', { query: aba === 'pendentes' ? { ...p, per_page: 20 } : { per_page: 1 } }) : Promise.resolve(null)),
    [aba, p.q, p.tipo, p.meus, p.page])
  const nPend = aba === 'documentos' || (!p.q && !p.tipo && !p.meus) ? pend.dados?.meta.total : undefined
  const [nPendGuardado, setNPend] = useState<number | undefined>()
  useEffect(() => { if (nPend !== undefined) setNPend(nPend) }, [nPend])

  return (
    <Pagina icone={FileText} titulo="Documentos" trilha={[{ rotulo: 'Início', para: '/' }, { rotulo: 'Documentos' }]}
      descricao="Todo documento enviado no sistema — avulso ou anexado a uma entrada, saída ou baixa. Arquivos em área privada; o download é registrado na auditoria."
      acoes={podeEnviar && <Link className="btn btn-pri" to="/documentos/enviar">Enviar documento</Link>}>
      {podeEnviar && (
        <div className="abas abas-contador" role="group" aria-label="Exibir">
          <button type="button" className={`aba${aba === 'documentos' ? ' ativa' : ''}`} aria-pressed={aba === 'documentos'} onClick={() => mudar({ aba: '' })}>Documentos</button>
          <button type="button" className={`aba${aba === 'pendentes' ? ' ativa' : ''}`} aria-pressed={aba === 'pendentes'} onClick={() => mudar({ aba: 'pendentes' })}>
            Registros sem documento{nPendGuardado !== undefined && <span className="aba-contador">{nPendGuardado}<span className="sr-only"> registro(s)</span></span>}
          </button>
        </div>)}

      {aba === 'documentos' ? <>
        <Aviso tipo="info">Você vê apenas documentos das unidades do seu escopo. A busca procura no título, arquivo, número, tipo, quem enviou e no registro ligado (origem, destinação, material, nº de série, “#123”).</Aviso>
        <form className="filtros" role="search" onSubmit={(e) => { e.preventDefault(); mudar({ q: busca.trim(), page: 1 }) }}>
          <label className="filtro-largo">Buscar<input type="search" value={busca} onChange={(e) => setBusca(e.target.value)} placeholder="Ex.: NF-778, SENASP, colete, #152" /></label>
          <label>Tipo<select value={f.tipo_documento_id} onChange={(e) => mudar({ tipo_documento_id: e.target.value, page: 1 })}>
            <option value="">Todos</option>{tipos.dados?.data.map((t) => <option key={t.id} value={t.id}>{t.nome}</option>)}
          </select></label>
          <label>Registro<select value={f.registro_tipo} onChange={(e) => mudar({ registro_tipo: e.target.value, page: 1 })}>
            <option value="">Todos</option>{Object.entries(REGISTROS).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
          </select></label>
          <details className="mais-filtros" open={!!(f.de || f.ate || f.meus || f.versoes || params.get('situacao'))}>
            <summary>Mais filtros</summary>
            <div className="mais-filtros-corpo">
          <label>Enviado de<input type="date" value={f.de} max={f.ate || undefined} onChange={(e) => mudar({ de: e.target.value, page: 1 })} /></label>
          <label>até<input type="date" value={f.ate} min={f.de || undefined} onChange={(e) => mudar({ ate: e.target.value, page: 1 })} /></label>
          <label>Situação<select value={f.situacao} onChange={(e) => mudar({ situacao: e.target.value === 'ATIVO' ? '' : e.target.value, page: 1 })}>
            <option value="ATIVO">Ativos</option><option value="ARQUIVADO">Arquivados</option><option value="TODAS">Todas</option>
          </select></label>
          <label className="check"><input type="checkbox" checked={f.meus === '1'} onChange={(e) => mudar({ meus: e.target.checked ? '1' : '', page: 1 })} />Enviados por mim</label>
          <label className="check"><input type="checkbox" checked={f.versoes === 'TODAS'} onChange={(e) => mudar({ versoes: e.target.checked ? 'TODAS' : '', page: 1 })} />Versões anteriores</label>
            </div>
          </details>
          <button className="btn btn-sec">Buscar</button>
        </form>
        {docs.carregando && <Carregando />}
        {docs.erro && <Erro erro={docs.erro} tentar={docs.recarregar} />}
        {docs.dados && !docs.carregando && (docs.dados.data.length === 0
          ? <Vazio titulo={f.q ? `Nenhum documento com “${f.q}”.` : 'Nenhum documento encontrado.'}>{podeEnviar ? <Link to="/documentos/enviar">Enviar um documento</Link> : 'Ajuste os filtros.'}</Vazio>
          : <>
            <Tabela legenda="Documentos" linhas={docs.dados.data} chave={(d) => d.codigo} colunas={[
              { titulo: 'Documento', celula: (d) => <><Link to={`/documentos/${d.codigo}`}><strong>{d.titulo}</strong></Link>{(d.versao ?? 1) > 1 && <> <Selo valor={`v${d.versao}`} tom="info" /></>}
                {d.substituido_por && <> <Selo valor="Substituído" tom="neutro" /></>}<br /><small>{d.nome_original} · {tamanho(d.tamanho_bytes)}</small></> },
              { titulo: 'Registro', celula: (d) => d.registros?.length
                ? <>{d.registros.map((g) => <span key={g.movimentacao_id} className="registro-doc"><Link to={`/movimentacoes/${g.movimentacao_id}`}>{rotuloRegistro(g.tipo)} #{g.movimentacao_id}</Link>{g.origem && <small> · {g.origem}</small>}</span>)}</>
                : <small className="dica">Avulso</small> },
              { titulo: 'Tipo', celula: (d) => d.tipo.nome },
              { titulo: 'Número', celula: (d) => d.numero_referencia ?? '—' },
              { titulo: 'Enviado', celula: (d) => <>{dataHora(d.enviado_em)}<br /><small>{d.enviado_por} · {d.unidade.sigla}</small></> },
              { titulo: 'Situação', celula: (d) => <Selo valor={d.situacao} tom={d.situacao === 'ATIVO' ? 'ok' : 'neutro'} /> },
            ]} />
            <Paginacao meta={docs.dados.meta} ir={(n) => mudar({ page: n })} />
          </>)}
      </> : <>
        <Aviso tipo="info">Entradas, saídas e baixas registradas sem documento. Anexe o arquivo aqui; ele fica ligado ao registro e aparece na busca de documentos. Só quem registrou ou a gestão de documentos pode anexar.</Aviso>
        <form className="filtros" role="search" onSubmit={(e) => { e.preventDefault(); mudar({ pq: buscaP.trim(), ppage: 1 }) }}>
          <label className="filtro-largo">Buscar<input type="search" value={buscaP} onChange={(e) => setBuscaP(e.target.value)} placeholder="Origem, destinação, nº do documento ou #registro" /></label>
          <label>Registro<select value={p.tipo} onChange={(e) => mudar({ ptipo: e.target.value, ppage: 1 })}>
            <option value="">Todos</option><option value="ENTRADA">Entrada</option><option value="SAIDA">Saída</option><option value="BAIXA">Baixa</option>
          </select></label>
          <label className="check"><input type="checkbox" checked={p.meus === '1'} onChange={(e) => mudar({ pmeus: e.target.checked ? '1' : '', ppage: 1 })} />Registrados por mim</label>
          <button className="btn btn-sec">Buscar</button>
        </form>
        {pend.carregando && <Carregando />}
        {pend.erro && <Erro erro={pend.erro} tentar={pend.recarregar} />}
        {pend.dados && !pend.carregando && (pend.dados.data.length === 0
          ? <Vazio titulo="Nenhum registro sem documento.">Toda entrada, saída e baixa do seu escopo já tem documento anexado.</Vazio>
          : <>
            <Tabela legenda="Registros sem documento" linhas={pend.dados.data} chave={(m) => m.id} colunas={[
              { titulo: 'Registro', celula: (m) => <><Link to={`/movimentacoes/${m.id}`}><strong>{rotuloRegistro(m.tipo)} #{m.id}</strong></Link><br /><small>{m.itens_count} linha(s) · {m.local?.codigo}</small></> },
              { titulo: 'Origem / destinação', celula: (m) => m.origem ?? '—' },
              { titulo: 'Nº do documento', celula: (m) => m.documento_referencia ?? '—' },
              { titulo: 'Registrado', celula: (m) => <>{dataHora(m.ocorrido_em)}<br /><small>{m.responsavel}</small></> },
              { titulo: 'Ação', celula: (m) => m.pode_anexar
                ? <button type="button" className="btn btn-pri btn-peq" onClick={() => setAnexar(m)}><Paperclip size={16} aria-hidden="true" />Anexar<span className="sr-only"> documento à {rotuloRegistro(m.tipo)} #{m.id}</span></button>
                : <small className="dica">Só quem registrou ou a gestão</small> },
            ]} />
            <Paginacao meta={pend.dados.meta} ir={(n) => mudar({ ppage: n })} />
          </>)}
      </>}
      <AnexarDialogo registro={anexar} onFechar={() => setAnexar(null)} onSalvo={(d) => { notificar('ok', `Documento “${d.titulo}” anexado.`); pend.recarregar() }} />
    </Pagina>
  )
}
