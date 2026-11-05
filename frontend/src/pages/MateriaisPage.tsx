import { useState, type FormEvent } from 'react'
import { ErroApi, api, type Pagina as Pag } from '../api/client'
import type { Material } from '../api/tipos'
import { useAuth } from '../auth/AuthContext'
import { Campo, Carregando, Dialogo, Erro, Pagina, Paginacao, Selo, Tabela, Vazio, useNotificar } from '../components/ui'
import { useRecurso } from '../components/useRecurso'

type Cat = { id: number; nome: string; natureza: string }
export default function MateriaisPage() {
  const { pode } = useAuth()
  const notificar = useNotificar()
  const [f, setF] = useState({ q: '', modo_controle: '', page: 1 })
  const [busca, setBusca] = useState('')
  const { dados, erro, carregando, recarregar } = useRecurso(() => api<Pag<Material>>('/items', { query: f }), [f.q, f.modo_controle, f.page])
  const cats = useRecurso(() => api<{ data: Cat[] }>('/catalog/categorias'), [])
  const fabs = useRecurso(() => api<{ data: { id: number; nome: string }[] }>('/catalog/fabricantes'), [])
  const [aberto, setAberto] = useState(false)
  const [n, setN] = useState({ codigo: '', nome: '', modelo: '', categoria_id: '', fabricante_id: '', modo_controle: 'INDIVIDUAL' })
  const [erroN, setErroN] = useState<ErroApi | null>(null)

  const criar = async (e?: FormEvent) => {
    e?.preventDefault(); setErroN(null)
    try {
      await api('/items', { method: 'POST', body: { ...n, categoria_id: Number(n.categoria_id), fabricante_id: n.fabricante_id ? Number(n.fabricante_id) : null, modelo: n.modelo || null } })
      setAberto(false); notificar('ok', 'Material cadastrado.'); setN({ ...n, codigo: '', nome: '', modelo: '' }); recarregar()
    } catch (x) { if (x instanceof ErroApi) setErroN(x) }
  }
  const fe = (k: string) => erroN?.erro.fields?.[k]

  return (
    <Pagina titulo="Catálogo de materiais" trilha={[{ rotulo: 'Início', para: '/' }, { rotulo: 'Catálogo' }]}
      descricao="O modo de controle (individual ou lote) é definido por material e não pode mudar após existir estoque (P-04)."
      acoes={pode('catalogo.gerir') && <button className="btn btn-pri" onClick={() => setAberto(true)}>Cadastrar material</button>}>
      <form className="filtros" role="search" onSubmit={(e) => { e.preventDefault(); setF({ ...f, q: busca, page: 1 }) }}>
        <label>Buscar<input type="search" value={busca} onChange={(e) => setBusca(e.target.value)} placeholder="Nome, código ou modelo" /></label>
        <label>Controle<select value={f.modo_controle} onChange={(e) => setF({ ...f, modo_controle: e.target.value, page: 1 })}><option value="">Todos</option><option value="INDIVIDUAL">Individual</option><option value="LOTE">Lote</option></select></label>
        <button className="btn btn-sec">Filtrar</button>
      </form>
      {carregando && <Carregando />}
      {erro && <Erro erro={erro} tentar={recarregar} />}
      {dados && !carregando && (dados.data.length === 0 ? <Vazio titulo="Nenhum material encontrado." /> : <>
        <Tabela legenda="Materiais" linhas={dados.data} chave={(m) => m.id} colunas={[
          { titulo: 'Código', celula: (m) => <span className="mono">{m.codigo}</span> },
          { titulo: 'Material', celula: (m) => <><strong>{m.nome}</strong>{m.modelo && <><br /><small>Modelo {m.modelo}</small></>}</> },
          { titulo: 'Categoria', celula: (m) => m.categoria.nome },
          { titulo: 'Controle', celula: (m) => <Selo valor={m.modo_controle} tom="info" /> },
          { titulo: 'Fabricante', celula: (m) => m.fabricante?.nome ?? '—' },
          { titulo: 'Situação', celula: (m) => <Selo valor={m.ativo ? 'ATIVO' : 'INATIVO'} /> },
        ]} />
        <Paginacao meta={dados.meta} ir={(p) => setF({ ...f, page: p })} />
      </>)}
      <Dialogo aberto={aberto} titulo="Cadastrar material" onFechar={() => setAberto(false)}
        rodape={<><button className="btn btn-sec" onClick={() => setAberto(false)}>Cancelar</button><button className="btn btn-pri" onClick={() => criar()}>Salvar</button></>}>
        {erroN && !erroN.erro.fields && <div className="aviso aviso-perigo" role="alert">{erroN.erro.message}</div>}
        <form onSubmit={criar} className="grade">
          <Campo rotulo="Código" obrigatorio dica="Maiúsculas, números, ponto, hífen." erro={fe('codigo')}>{(p) => <input {...p} value={n.codigo} onChange={(e) => setN({ ...n, codigo: e.target.value.toUpperCase() })} />}</Campo>
          <Campo rotulo="Nome" obrigatorio erro={fe('nome')}>{(p) => <input {...p} value={n.nome} onChange={(e) => setN({ ...n, nome: e.target.value })} />}</Campo>
          <Campo rotulo="Modelo" erro={fe('modelo')}>{(p) => <input {...p} value={n.modelo} onChange={(e) => setN({ ...n, modelo: e.target.value })} />}</Campo>
          <Campo rotulo="Categoria" obrigatorio erro={fe('categoria_id')}>{(p) => <select {...p} value={n.categoria_id} onChange={(e) => setN({ ...n, categoria_id: e.target.value })}><option value="">Selecione…</option>{cats.dados?.data.map((c) => <option key={c.id} value={c.id}>{c.nome}</option>)}</select>}</Campo>
          <Campo rotulo="Fabricante" erro={fe('fabricante_id')}>{(p) => <select {...p} value={n.fabricante_id} onChange={(e) => setN({ ...n, fabricante_id: e.target.value })}><option value="">—</option>{fabs.dados?.data.map((c) => <option key={c.id} value={c.id}>{c.nome}</option>)}</select>}</Campo>
          <fieldset className="campo"><legend>Modo de controle</legend>
            <label className="opcao"><input type="radio" name="modo" checked={n.modo_controle === 'INDIVIDUAL'} onChange={() => setN({ ...n, modo_controle: 'INDIVIDUAL' })} /> Individual (identificador/série)</label>
            <label className="opcao"><input type="radio" name="modo" checked={n.modo_controle === 'LOTE'} onChange={() => setN({ ...n, modo_controle: 'LOTE' })} /> Lote/quantidade</label>
          </fieldset>
        </form>
      </Dialogo>
    </Pagina>
  )
}
