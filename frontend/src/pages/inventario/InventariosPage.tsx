import { useState } from 'react'
import { Link, useNavigate } from 'react-router'
import { ClipboardList } from 'lucide-react'
import { ErroApi, api, type Pagina as Pag } from '../../api/client'
import type { Local } from '../../api/tipos'
import { useAuth } from '../../auth/AuthContext'
import { Campo, Carregando, Dialogo, Erro, Pagina, Paginacao, Selo, Tabela, Vazio, dataHora } from '../../components/ui'
import { useRecurso } from '../../components/useRecurso'

export type InvResumo = { codigo: string; estado: string; versao: number; local: { id: number; codigo: string; nome: string }; criado_em: string; linhas: number; divergencias: number }
export const ESTADOS_INV: Record<string, string> = { EM_CONTAGEM: 'Em contagem', AGUARDANDO_APROVACAO: 'Aguardando aprovação', AJUSTADO: 'Ajustado', REJEITADO: 'Rejeitado', CANCELADO: 'Cancelado' }

export default function InventariosPage() {
  const nav = useNavigate()
  const { pode, perfil } = useAuth()
  const [f, setF] = useState({ estado: '', page: 1 })
  const { dados, erro, carregando, recarregar } = useRecurso(() => api<Pag<InvResumo>>('/inventories', { query: f }), [JSON.stringify(f)])
  const locais = useRecurso(() => api<{ data: Local[] }>('/locations', { query: { somente_ativos: 1 } }), [])
  const [abrir, setAbrir] = useState(false)
  const [local, setLocal] = useState('')
  const [erroN, setErroN] = useState<ErroApi | null>(null)
  const ativo = perfil?.operacoes.find((o) => o.codigo === 'INVENTARIO')?.estado !== 'BLOQUEADA'

  const criar = async () => {
    setErroN(null)
    try { const i = await api<InvResumo>('/inventories', { method: 'POST', body: { local_estoque_id: Number(local) } }); nav(`/inventarios/${i.codigo}`) }
    catch (e) { if (e instanceof ErroApi) setErroN(e) }
  }

  return (
    <Pagina icone={ClipboardList} titulo="Inventários" trilha={[{ rotulo: 'Início', para: '/' }, { rotulo: 'Inventários' }]}
      descricao="Contagem física por local, divergências e ajuste aprovado por outra pessoa."
      acoes={ativo && pode('inventario.contar') ? <button className="btn btn-pri" onClick={() => setAbrir(true)}>Abrir inventário</button> : undefined}>
      <form className="filtros" role="search" aria-label="Filtrar inventários" onSubmit={(e) => e.preventDefault()}>
        <label>Situação<select value={f.estado} onChange={(e) => setF({ estado: e.target.value, page: 1 })}><option value="">Todas</option>{Object.entries(ESTADOS_INV).map(([k, v]) => <option key={k} value={k}>{v}</option>)}</select></label>
      </form>
      {carregando && <Carregando />}{erro && <Erro erro={erro} tentar={recarregar} />}
      {dados && !carregando && (dados.data.length === 0 ? <Vazio titulo="Nenhum inventário encontrado." icone={ClipboardList} /> : <>
        <Tabela legenda="Inventários" linhas={dados.data} chave={(i) => i.codigo} colunas={[
          { titulo: 'Local', celula: (i) => <Link to={`/inventarios/${i.codigo}`}>{i.local.codigo} — {i.local.nome}</Link> },
          { titulo: 'Aberto em', celula: (i) => dataHora(i.criado_em) },
          { titulo: 'Situação', celula: (i) => <Selo valor={ESTADOS_INV[i.estado]} tom={i.estado === 'AGUARDANDO_APROVACAO' ? 'alerta' : i.estado === 'AJUSTADO' ? 'ok' : 'neutro'} /> },
          { titulo: 'Linhas', celula: (i) => i.linhas, num: true },
          { titulo: 'Divergências', celula: (i) => i.divergencias, num: true },
        ]} />
        <Paginacao meta={dados.meta} ir={(p) => setF({ ...f, page: p })} />
      </>)}
      <Dialogo aberto={abrir} titulo="Abrir inventário" onFechar={() => setAbrir(false)}
        rodape={<><button className="btn btn-sec" onClick={() => setAbrir(false)}>Cancelar</button><button className="btn btn-pri" disabled={!local} onClick={criar}>Abrir</button></>}>
        {erroN && <p className="msg-erro" role="alert">{erroN.erro.message}</p>}
        <Campo rotulo="Local de estoque" obrigatorio dica="O saldo atual do local fica registrado como esperado.">{(p) => (
          <select {...p} value={local} onChange={(e) => setLocal(e.target.value)}><option value="">Selecione…</option>{locais.dados?.data.map((l) => <option key={l.id} value={l.id}>{l.codigo} — {l.nome}</option>)}</select>)}</Campo>
      </Dialogo>
    </Pagina>
  )
}
