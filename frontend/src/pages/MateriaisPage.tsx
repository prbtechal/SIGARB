import { Package } from 'lucide-react'
import { useState, type FormEvent } from 'react'
import { useSearchParams } from 'react-router'
import { ErroApi, api, type Pagina as Pag } from '../api/client'
import type { Material } from '../api/tipos'
import { useAuth } from '../auth/AuthContext'
import { Campo, Carregando, Dialogo, Erro, Pagina, Paginacao, Selo, Tabela, Vazio, useNotificar } from '../components/ui'
import { useRecurso } from '../components/useRecurso'

type Cat = { id: number; nome: string; natureza: string }
export default function MateriaisPage() {
  const { pode } = useAuth()
  const notificar = useNotificar()
  const [f, setF] = useState({ q: '', modo_controle: new URLSearchParams(location.search).get('modo_controle') ?? '', page: 1 })
  const [busca, setBusca] = useState('')
  const { dados, erro, carregando, recarregar } = useRecurso(() => api<Pag<Material>>('/items', { query: f }), [f.q, f.modo_controle, f.page])
  const cats = useRecurso(() => api<{ data: Cat[] }>('/catalog/categorias'), [])
  const fabs = useRecurso(() => api<{ data: { id: number; nome: string }[] }>('/catalog/fabricantes'), [])
  const [params] = useSearchParams()
  const [aberto, setAberto] = useState(() => params.get('novo') === '1' && pode('catalogo.gerir'))
  const [n, setN] = useState({ codigo: '', nome: '', modelo: '', categoria_id: '', fabricante_id: '', modo_controle: 'INDIVIDUAL' })
  const [erroN, setErroN] = useState<ErroApi | null>(null)
  // RF-007 — ficha técnica provisória, só para natureza armamento.
  const [ficha, setFicha] = useState({ especie: '', sistema_funcionamento: '', capacidade_carregador: '', comprimento_cano_mm: '', numero_raias: '', acabamento: '' })
  const armamento = cats.dados?.data.find((c) => String(c.id) === n.categoria_id)?.natureza === 'ARMAMENTO'
  const fichaPreenchida = Object.fromEntries(Object.entries(ficha).filter(([, v]) => v !== '').map(([k, v]) => [k, /^\d+$/.test(v) && k !== 'acabamento' ? Number(v) : v]))

  const criar = async (e?: FormEvent) => {
    e?.preventDefault(); setErroN(null)
    try {
      await api('/items', { method: 'POST', body: { ...n, categoria_id: Number(n.categoria_id), fabricante_id: n.fabricante_id ? Number(n.fabricante_id) : null, modelo: n.modelo || null, ...(armamento && Object.keys(fichaPreenchida).length ? { ficha_tecnica: fichaPreenchida } : {}) } })
      setAberto(false); notificar('ok', 'Material cadastrado.'); setN({ ...n, codigo: '', nome: '', modelo: '' }); recarregar()
    } catch (x) { if (x instanceof ErroApi) setErroN(x) }
  }
  const fe = (k: string) => erroN?.erro.fields?.[k]

  return (
    <Pagina icone={Package} titulo="Catálogo de materiais" trilha={[{ rotulo: 'Início', para: '/' }, { rotulo: 'Catálogo' }]}
      descricao="O modo de controle (individual ou lote) é definido por material e não pode mudar após existir estoque."
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
          { titulo: 'Material', celula: (m) => <><strong>{m.nome}</strong>{m.modelo && <><br /><small>Modelo {m.modelo}</small></>}{m.ficha_tecnica?.especie && <><br /><small>{String(m.ficha_tecnica.especie).toLowerCase()}{m.ficha_tecnica.capacidade_carregador ? ` · ${m.ficha_tecnica.capacidade_carregador} tiros` : ''}</small></>}</> },
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
          {armamento && <fieldset className="campo"><legend>Ficha técnica</legend>
            <div className="grade">
              <Campo rotulo="Espécie" erro={fe('ficha_tecnica.especie')}>{(p) => <select {...p} value={ficha.especie} onChange={(e) => setFicha({ ...ficha, especie: e.target.value })}><option value="">—</option>{['PISTOLA', 'REVOLVER', 'ESPINGARDA', 'CARABINA', 'FUZIL', 'SUBMETRALHADORA', 'OUTRA'].map((x) => <option key={x} value={x}>{x[0] + x.slice(1).toLowerCase()}</option>)}</select>}</Campo>
              <Campo rotulo="Funcionamento" erro={fe('ficha_tecnica.sistema_funcionamento')}>{(p) => <select {...p} value={ficha.sistema_funcionamento} onChange={(e) => setFicha({ ...ficha, sistema_funcionamento: e.target.value })}><option value="">—</option><option value="REPETICAO">Repetição</option><option value="SEMIAUTOMATICO">Semiautomático</option><option value="AUTOMATICO">Automático</option></select>}</Campo>
              <Campo rotulo="Capacidade do carregador" erro={fe('ficha_tecnica.capacidade_carregador')}>{(p) => <input {...p} type="number" min={1} inputMode="numeric" value={ficha.capacidade_carregador} onChange={(e) => setFicha({ ...ficha, capacidade_carregador: e.target.value })} />}</Campo>
              <Campo rotulo="Comprimento do cano (mm)" erro={fe('ficha_tecnica.comprimento_cano_mm')}>{(p) => <input {...p} type="number" min={10} inputMode="numeric" value={ficha.comprimento_cano_mm} onChange={(e) => setFicha({ ...ficha, comprimento_cano_mm: e.target.value })} />}</Campo>
              <Campo rotulo="Número de raias" erro={fe('ficha_tecnica.numero_raias')}>{(p) => <input {...p} type="number" min={0} inputMode="numeric" value={ficha.numero_raias} onChange={(e) => setFicha({ ...ficha, numero_raias: e.target.value })} />}</Campo>
              <Campo rotulo="Acabamento" erro={fe('ficha_tecnica.acabamento')}>{(p) => <input {...p} maxLength={60} value={ficha.acabamento} onChange={(e) => setFicha({ ...ficha, acabamento: e.target.value })} />}</Campo>
            </div>
          </fieldset>}
          <fieldset className="campo"><legend>Modo de controle</legend>
            <label className="opcao"><input type="radio" name="modo" checked={n.modo_controle === 'INDIVIDUAL'} onChange={() => setN({ ...n, modo_controle: 'INDIVIDUAL' })} /> Individual (identificador/série)</label>
            <label className="opcao"><input type="radio" name="modo" checked={n.modo_controle === 'LOTE'} onChange={() => setN({ ...n, modo_controle: 'LOTE' })} /> Lote/quantidade</label>
          </fieldset>
        </form>
      </Dialogo>
    </Pagina>
  )
}
