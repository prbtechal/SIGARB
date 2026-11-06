import { useState } from 'react'
import { ErroApi, api, type Pagina as Pag } from '../api/client'
import { Aviso, Campo, Carregando, Dialogo, Erro, Pagina, Paginacao, Selo, Tabela, Vazio, useNotificar } from '../components/ui'
import { useRecurso } from '../components/useRecurso'

type Lista = { tipo: string; rotulo: string; possui_codigo: boolean }
type Valor = { id: number; nome: string; codigo?: string; natureza?: string; ativo: boolean; em_uso: number; desativado_em: string | null }
const NATUREZAS = ['ARMAMENTO', 'MUNICAO', 'EQUIPAMENTO', 'PRODUTO', 'TREINAMENTO']

/** RF-029 — administração de listas de referência (sem exclusão; desativação auditada). */
export default function ReferenciasPage() {
  const notificar = useNotificar()
  const listas = useRecurso(() => api<{ data: Lista[] }>('/reference-lists'), [])
  const [tipo, setTipo] = useState('tipos-documento')
  const [f, setF] = useState({ q: '', ativo: '1', page: 1 })
  const [busca, setBusca] = useState('')
  const valores = useRecurso(() => api<Pag<Valor>>(`/reference-lists/${tipo}`, { query: f }), [tipo, f.q, f.ativo, f.page])
  const lista = listas.dados?.data.find((l) => l.tipo === tipo)
  const [form, setForm] = useState<{ id?: number; nome: string; codigo: string; natureza: string } | null>(null)
  const [conf, setConf] = useState<Valor | null>(null)
  const [just, setJust] = useState('')
  const [erro, setErro] = useState<ErroApi | null>(null)
  const fe = (k: string) => erro?.erro.fields?.[k]

  const salvar = async () => {
    if (!form) return
    setErro(null)
    const corpo: Record<string, string> = { nome: form.nome }
    if (lista?.possui_codigo) corpo.codigo = form.codigo
    if (tipo === 'categorias') corpo.natureza = form.natureza
    try {
      await api(form.id ? `/reference-lists/${tipo}/${form.id}` : `/reference-lists/${tipo}`, { method: form.id ? 'PUT' : 'POST', body: corpo })
      notificar('ok', form.id ? 'Valor atualizado.' : 'Valor criado.'); setForm(null); valores.recarregar()
    } catch (x) { if (x instanceof ErroApi) setErro(x) }
  }
  const alternar = async () => {
    if (!conf) return
    setErro(null)
    try {
      await api(`/reference-lists/${tipo}/${conf.id}/${conf.ativo ? 'deactivate' : 'reactivate'}`, { method: 'POST', body: { justificativa: just } })
      notificar('ok', conf.ativo ? 'Valor desativado.' : 'Valor reativado.'); setConf(null); valores.recarregar()
    } catch (x) { if (x instanceof ErroApi) setErro(x) }
  }

  return (
    <Pagina titulo="Listas de referência" trilha={[{ rotulo: 'Início', para: '/' }, { rotulo: 'Listas de referência' }]}
      descricao="Valores usados em cadastros. Nada é apagado: valores desativados deixam de ser oferecidos, mas registros antigos continuam válidos."
      acoes={<button className="btn btn-pri" onClick={() => { setErro(null); setForm({ nome: '', codigo: '', natureza: 'EQUIPAMENTO' }) }}>Criar valor</button>}>
      <Aviso tipo="info">Listas provisórias de engenharia: a tabela oficial depende de decisão institucional (PE-05). Esta tela não altera regras nem desbloqueia operações.</Aviso>
      {listas.carregando && <Carregando />}{listas.erro && <Erro erro={listas.erro} />}
      {listas.dados && (
        <div className="abas" role="tablist" aria-label="Lista">
          {listas.dados.data.map((l) => (
            <button key={l.tipo} role="tab" aria-selected={l.tipo === tipo} className={`aba${l.tipo === tipo ? ' ativa' : ''}`} onClick={() => { setTipo(l.tipo); setF({ q: '', ativo: '1', page: 1 }); setBusca('') }}>{l.rotulo}</button>
          ))}
        </div>
      )}
      <form className="filtros" role="search" onSubmit={(e) => { e.preventDefault(); setF({ ...f, q: busca, page: 1 }) }}>
        <label>Buscar<input type="search" value={busca} onChange={(e) => setBusca(e.target.value)} /></label>
        <label>Situação<select value={f.ativo} onChange={(e) => setF({ ...f, ativo: e.target.value, page: 1 })}><option value="1">Ativos</option><option value="0">Desativados</option><option value="">Todos</option></select></label>
        <button className="btn btn-sec">Filtrar</button>
      </form>
      {valores.carregando && <Carregando />}{valores.erro && <Erro erro={valores.erro} tentar={valores.recarregar} />}
      {valores.dados && !valores.carregando && (valores.dados.data.length === 0 ? <Vazio titulo="Nenhum valor encontrado." /> : <>
        <Tabela legenda={lista?.rotulo ?? 'Valores'} linhas={valores.dados.data} chave={(v) => v.id} colunas={[
          ...(lista?.possui_codigo ? [{ titulo: 'Código', celula: (v: Valor) => <span className="mono">{v.codigo}</span> }] : []),
          { titulo: 'Nome', celula: (v: Valor) => v.nome },
          ...(tipo === 'categorias' ? [{ titulo: 'Natureza', celula: (v: Valor) => v.natureza }] : []),
          { titulo: 'Em uso', num: true, celula: (v: Valor) => v.em_uso },
          { titulo: 'Situação', celula: (v: Valor) => <Selo valor={v.ativo ? 'ATIVO' : 'INATIVO'} /> },
          { titulo: 'Ações', celula: (v: Valor) => <span className="acoes-linha">
            <button className="btn btn-sec" onClick={() => { setErro(null); setForm({ id: v.id, nome: v.nome, codigo: v.codigo ?? '', natureza: v.natureza ?? '' }) }}>Editar <span className="sr-only">{v.nome}</span></button>
            <button className="btn btn-sec" onClick={() => { setErro(null); setJust(''); setConf(v) }}>{v.ativo ? 'Desativar' : 'Reativar'} <span className="sr-only">{v.nome}</span></button>
          </span> },
        ]} />
        <Paginacao meta={valores.dados.meta} ir={(p) => setF({ ...f, page: p })} />
      </>)}
      <Dialogo aberto={!!form} titulo={form?.id ? 'Editar valor' : `Criar valor em ${lista?.rotulo ?? ''}`} onFechar={() => setForm(null)}
        rodape={<><button className="btn btn-sec" onClick={() => setForm(null)}>Cancelar</button><button className="btn btn-pri" onClick={salvar}>Salvar</button></>}>
        {erro && !erro.erro.fields && <div className="aviso aviso-perigo" role="alert">{erro.erro.message}</div>}
        {form && <>
          {lista?.possui_codigo && <Campo rotulo="Código" obrigatorio dica="Maiúsculas, números, _ e -. Não muda se o valor estiver em uso." erro={fe('codigo')}>{(p) => <input {...p} value={form.codigo} onChange={(e) => setForm({ ...form, codigo: e.target.value.toUpperCase() })} />}</Campo>}
          <Campo rotulo="Nome" obrigatorio erro={fe('nome')}>{(p) => <input {...p} value={form.nome} onChange={(e) => setForm({ ...form, nome: e.target.value })} />}</Campo>
          {tipo === 'categorias' && <Campo rotulo="Natureza" obrigatorio dica="Não muda se a categoria estiver em uso." erro={fe('natureza')}>{(p) => <select {...p} value={form.natureza} onChange={(e) => setForm({ ...form, natureza: e.target.value })}>{NATUREZAS.map((n) => <option key={n}>{n}</option>)}</select>}</Campo>}
        </>}
      </Dialogo>
      <Dialogo aberto={!!conf} titulo={conf?.ativo ? `Desativar "${conf?.nome}"?` : `Reativar "${conf?.nome}"?`} onFechar={() => setConf(null)}
        rodape={<><button className="btn btn-sec" onClick={() => setConf(null)}>Cancelar</button><button className={`btn ${conf?.ativo ? 'btn-perigo' : 'btn-pri'}`} disabled={just.trim().length < 10} onClick={alternar}>Confirmar</button></>}>
        <p>{conf?.ativo ? `O valor deixa de ser oferecido em novos cadastros. ${conf.em_uso} registro(s) que já o usam não são alterados.` : 'O valor volta a ser oferecido nos cadastros.'} A ação é auditada.</p>
        {erro && <div className="aviso aviso-perigo" role="alert">{erro.erro.message}</div>}
        <Campo rotulo="Justificativa" obrigatorio dica="Mínimo de 10 caracteres.">{(p) => <textarea {...p} rows={3} value={just} onChange={(e) => setJust(e.target.value)} />}</Campo>
      </Dialogo>
    </Pagina>
  )
}
