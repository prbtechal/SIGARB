import { ArrowLeftRight, ClipboardList, Package, PackagePlus, Pencil } from 'lucide-react'
import { useState, type FormEvent } from 'react'
import { Link, useLocation, useParams, useSearchParams } from 'react-router'
import { RotuloItem } from '../components/RotuloItem'
import { ErroApi, api, type Pagina as Pag } from '../api/client'
import type { ItemIndividual, Material, MovResumo, Ref, Saldo } from '../api/tipos'
import { Aviso, Campo, Carregando, Dialogo, Erro, Pagina, Paginacao, Selo, Tabela, Vazio, data, dataHora, numero, useNotificar } from '../components/ui'
import { useRecurso } from '../components/useRecurso'

export type MaterialEstoque = Material & { tipo: { id: number; nome: string; perfil: string } | null
  saldos: Saldo[]; total: { quantidade: number; reservada: number; disponivel: number }
  pode: { editar_catalogo: boolean; entrada: boolean; operar: boolean; inventario: boolean } }
type Item = ItemIndividual & { local: Ref; unidade: Ref | null }

/** Detalhe de um material no estoque (catálogo, saldo por local, itens e histórico). Somente leitura: alterações de saldo só pelos fluxos com motivo. */
export default function MaterialEstoquePage() {
  const { id } = useParams()
  const notificar = useNotificar()
  // ?local=ID vem da lista de saldos filtrada: mostra só aquele local até a pessoa pedir todos.
  const [params, setParams] = useSearchParams()
  const local = params.get('local') ?? ''
  const voltar = (useLocation().state as { voltar?: string } | null)?.voltar ?? (local ? `/estoque?local_id=${local}` : '/estoque')
  const [qItem, setQItem] = useState('')
  const m = useRecurso(() => api<MaterialEstoque>(`/stock/materials/${id}`, { query: local ? { local_id: local } : {} }), [id, local])
  const [pagItens, setPagItens] = useState(1)
  const [pagMov, setPagMov] = useState(1)
  const individual = m.dados?.modo_controle === 'INDIVIDUAL'
  const itens = useRecurso(() => (individual ? api<Pag<Item>>('/stock/individual-items', { query: { material_id: id, page: pagItens, ...(local ? { local_id: local } : {}), ...(qItem ? { q: qItem } : {}) } }) : Promise.resolve(null)), [id, individual, pagItens, local, qItem])
  const movs = useRecurso(() => api<Pag<MovResumo>>('/movements', { query: { material_id: id, page: pagMov } }), [id, pagMov])
  const [editar, setEditar] = useState(false)
  const [form, setForm] = useState({ nome: '', modelo: '' })
  const [erroForm, setErroForm] = useState<ErroApi | null>(null)

  const d = m.dados
  const salvar = async (e: FormEvent) => {
    e.preventDefault()
    setErroForm(null)
    try {
      await api(`/items/${id}`, { method: 'PUT', body: { nome: form.nome, modelo: form.modelo || null } })
      setEditar(false); notificar('ok', 'Catálogo do material atualizado.'); m.recarregar()
    } catch (err) { if (err instanceof ErroApi) setErroForm(err); else throw err }
  }

  return (
    <Pagina icone={Package} titulo={d ? d.nome : 'Material'} trilha={[{ rotulo: 'Início', para: '/' }, { rotulo: 'Saldos de estoque', para: voltar }, { rotulo: d?.nome ?? 'Material' }]}
      descricao="Dados do catálogo, saldo nos locais do seu escopo, itens e histórico de movimentações."
      acoes={d && <>
        {d.pode.editar_catalogo && <button type="button" className="btn btn-sec" onClick={() => { setForm({ nome: d.nome, modelo: d.modelo ?? '' }); setErroForm(null); setEditar(true) }}><Pencil size={18} aria-hidden="true" />Editar catálogo</button>}
        {d.pode.entrada && <Link className="btn btn-pri" to="/movimentacoes/nova-entrada"><PackagePlus size={18} aria-hidden="true" />Registrar entrada</Link>}
        {d.pode.operar && <Link className="btn btn-sec" to="/operacoes/nova"><ArrowLeftRight size={18} aria-hidden="true" />Nova operação</Link>}
        {d.pode.inventario && <Link className="btn btn-sec" to="/inventarios"><ClipboardList size={18} aria-hidden="true" />Inventário</Link>}
      </>}>
      {m.carregando && <Carregando />}
      {m.erro && <Erro erro={m.erro} tentar={m.recarregar} />}
      {d && !m.carregando && <>
        {(d.pode.entrada || d.pode.operar || d.pode.inventario) && <Aviso tipo="info">O saldo não é editado diretamente: use entrada, operação (saída, baixa, transferência…) ou inventário, que exigem motivo e ficam registrados.</Aviso>}
        {local && <p className="filtro-ativo" role="status">Filtrado por <strong>{d.saldos[0]?.local.nome ?? 'local selecionado'}</strong> · <button type="button" className="link" onClick={() => { setParams({}, { replace: true }); setPagItens(1) }}>Ver todos os locais</button></p>}
        <h2>Catálogo</h2>
        <dl className="detalhes">
          <div><dt>Categoria</dt><dd>{d.categoria.nome}</dd></div>
          <div><dt>Tipo</dt><dd>{d.tipo?.nome ?? '—'}</dd></div>
          <div><dt>Fabricante</dt><dd>{d.fabricante?.nome ?? '—'}</dd></div>
          <div><dt>Calibre</dt><dd>{d.calibre?.nome ?? '—'}</dd></div>
          <div><dt>Modelo</dt><dd>{d.modelo ?? '—'}</dd></div>
          <div><dt>Modo de controle</dt><dd><Selo valor={d.modo_controle} tom="info" /></dd></div>
          <div><dt>Situação</dt><dd><Selo valor={d.ativo ? 'ATIVO' : 'INATIVO'} /></dd></div>
        </dl>
        <p className="dica">Código do catálogo: <span className="mono">{d.codigo}</span></p>

        <h2>Saldo por local{individual ? '' : ' e lote'}</h2>
        {d.saldos.length === 0 ? <Vazio titulo="Sem saldo nos locais do seu escopo." /> : <>
          <Tabela legenda="Saldo por local" linhas={d.saldos} chave={(s) => `${s.local.id}-${s.lote?.id ?? 'i'}`} colunas={[
            { titulo: 'Local', celula: (s) => <>{s.local.codigo} — {s.local.nome}<br /><small>{s.unidade.sigla}</small></> },
            { titulo: 'Lote / validade', celula: (s) => s.lote ? <>{s.lote.codigo}<br /><small>Val.: {data(s.lote.validade)}</small></> : '—' },
            { titulo: 'Quantidade', num: true, celula: (s) => numero(s.quantidade) },
            { titulo: 'Reservada', num: true, celula: (s) => numero(s.reservada) },
            { titulo: 'Disponível', num: true, celula: (s) => <strong>{numero(s.disponivel)}</strong> },
          ]} />
          <p className="dica">Total {local ? 'neste local' : 'no escopo'}: {numero(d.total.quantidade)} ({numero(d.total.disponivel)} disponível, {numero(d.total.reservada)} reservada).</p>
        </>}

        {individual && <>
          <h2>Itens individuais</h2>
          <form className="filtros" role="search" onSubmit={(e) => { e.preventDefault(); setQItem(String(new FormData(e.currentTarget).get('q') ?? '').trim()); setPagItens(1) }}>
            <label>Buscar item<input name="q" type="search" placeholder="Nº de série ou código" defaultValue={qItem} /></label>
            <button className="btn btn-sec">Buscar</button>
          </form>
          {itens.carregando && <Carregando />}{itens.erro && <Erro erro={itens.erro} tentar={itens.recarregar} />}
          {itens.dados && !itens.carregando && (itens.dados.data.length === 0 ? <Vazio titulo="Nenhum item no seu escopo." /> : <>
            <Tabela legenda="Itens individuais" linhas={itens.dados.data} chave={(i) => i.id} colunas={[
              { titulo: 'Nº de série', celula: (i) => <RotuloItem serie={i.numero_serie} codigo={i.identificador} /> },
              { titulo: 'Situação', celula: (i) => <Selo valor={i.situacao} /> },
              { titulo: 'Conservação', celula: (i) => <Selo valor={i.estado_conservacao} tom="neutro" /> },
              { titulo: 'Local', celula: (i) => <>{i.local.codigo}{i.unidade && <><br /><small>{i.unidade.sigla}</small></>}</> },
              { titulo: 'Histórico', celula: (i) => <Link to={`/estoque/itens?q=${encodeURIComponent(i.numero_serie || i.identificador)}`}>Ver item</Link> },
            ]} />
            <Paginacao meta={itens.dados.meta} ir={setPagItens} />
          </>)}
        </>}

        <h2>Histórico de movimentações</h2>
        {movs.carregando && <Carregando />}{movs.erro && <Erro erro={movs.erro} tentar={movs.recarregar} />}
        {movs.dados && !movs.carregando && (movs.dados.data.length === 0 ? <Vazio titulo="Nenhuma movimentação deste material no seu escopo." /> : <>
          <Tabela legenda="Movimentações do material" linhas={movs.dados.data} chave={(v) => v.id} colunas={[
            { titulo: 'Nº', celula: (v) => <Link to={`/movimentacoes/${v.id}`}>#{v.id}</Link> },
            { titulo: 'Tipo', celula: (v) => <Selo valor={v.tipo} /> },
            { titulo: 'Data/hora', celula: (v) => <time dateTime={v.ocorrido_em}>{dataHora(v.ocorrido_em)}</time> },
            { titulo: 'Local', celula: (v) => v.local_destino?.codigo ?? v.local_origem?.codigo },
            { titulo: 'Responsável', celula: (v) => v.responsavel.nome },
          ]} />
          <Paginacao meta={movs.dados.meta} ir={setPagMov} />
        </>)}
      </>}

      <Dialogo aberto={editar} titulo="Editar catálogo do material" onFechar={() => setEditar(false)}
        rodape={<><button type="button" className="btn btn-sec" onClick={() => setEditar(false)}>Cancelar</button><button className="btn btn-pri" form="form-editar-material">Salvar</button></>}>
        <form id="form-editar-material" onSubmit={salvar}>
          {erroForm && !erroForm.erro.fields && <Aviso tipo="perigo">{erroForm.erro.message}</Aviso>}
          <Campo rotulo="Nome" obrigatorio erro={erroForm?.erro.fields?.nome}>{(p) => <input {...p} value={form.nome} onChange={(e) => setForm({ ...form, nome: e.target.value })} />}</Campo>
          <Campo rotulo="Modelo" erro={erroForm?.erro.fields?.modelo}>{(p) => <input {...p} value={form.modelo} onChange={(e) => setForm({ ...form, modelo: e.target.value })} />}</Campo>
          <p className="dica">Código, categoria e modo de controle não são alterados aqui.</p>
        </form>
      </Dialogo>
    </Pagina>
  )
}
