import { SeloAptidao } from '../components/AptidaoEfetivo'
import { Users } from 'lucide-react'
import { useState } from 'react'
import { Link, useSearchParams } from 'react-router'
import { api, type Pagina as Pag } from '../api/client'
import type { Militar } from '../api/tipos'
import { useAuth } from '../auth/AuthContext'
import { Aviso, Carregando, Erro, Pagina, Paginacao, Selo, Tabela, Vazio } from '../components/ui'
import { useRecurso } from '../components/useRecurso'
import { rotuloEfetivo } from '../components/efetivo'

export default function MilitaresPage() {
  const { pode } = useAuth()
  const [f, setF] = useState({ q: '', situacao: '', page: 1 })
  const [busca, setBusca] = useState('')
  // Filtro "posto a atualizar" (decisão 2026-11-18): RR/PM/BM com posto CIV; vem do alerta da T.I. pela URL.
  const [params, setParams] = useSearchParams()
  const postoAAtualizar = params.get('posto_a_atualizar') === '1'
  const filtrarPosto = (sim: boolean) => { const n = new URLSearchParams(params); if (sim) n.set('posto_a_atualizar', '1'); else n.delete('posto_a_atualizar'); setParams(n); setF({ ...f, page: 1 }) }
  const { dados, erro, carregando, recarregar } = useRecurso(() => api<Pag<Militar>>('/militares', { query: { ...f, ...(postoAAtualizar ? { posto_a_atualizar: 1 } : {}) } }), [f.q, f.situacao, f.page, postoAAtualizar])
  return (
    <Pagina icone={Users} titulo="Efetivos" trilha={[{ rotulo: 'Início', para: '/' }, { rotulo: 'Efetivos' }]}
      descricao="Vem do cadastro de funcionários (Sistema de Escalas), atualizado automaticamente. Quem não consta lá é cadastrado aqui (Local). Toda conta de acesso pertence a um efetivo."
      acoes={pode('militares.gerir') && <Link className="btn btn-pri" to="/militares/novo">Cadastrar efetivo</Link>}>
      {!pode('militares.ver_detalhe') && <Aviso tipo="info">Matrículas aparecem parcialmente ocultas para o seu perfil.</Aviso>}
      <form className="filtros" role="search" onSubmit={(e) => { e.preventDefault(); setF({ ...f, q: busca, page: 1 }) }}>
        <label>Buscar<input type="search" value={busca} onChange={(e) => setBusca(e.target.value)} placeholder="Nome, matrícula ou CPF" /></label>
        <label>Situação<select value={f.situacao} onChange={(e) => setF({ ...f, situacao: e.target.value, page: 1 })}><option value="">Todas</option><option>ATIVO</option><option>AFASTADO</option><option>INATIVO</option></select></label>
        <label className="check"><input type="checkbox" checked={postoAAtualizar} onChange={(e) => filtrarPosto(e.target.checked)} />Posto a atualizar (RR/PM/BM com posto CIV)</label>
        <button className="btn btn-sec">Filtrar</button>
      </form>
      {carregando && <Carregando />}
      {erro && <Erro erro={erro} tentar={recarregar} />}
      {dados && !carregando && (dados.data.length === 0 ? <Vazio titulo="Nenhum efetivo encontrado." /> : <>
        <Tabela legenda="Efetivos" linhas={dados.data} chave={(m) => m.id} colunas={[
          { titulo: 'Efetivo', celula: (m) => (
            <Link to={`/militares/${m.id}`} className="efetivo-celula">
              <span className="avatar-mini" aria-hidden="true">{m.foto_versao ? <img src={`${import.meta.env.BASE_URL}api/v1/militares/${m.id}/foto?v=${m.foto_versao}`} alt="" loading="lazy" /> : m.nome_guerra.slice(0, 2)}</span>
              <span><strong>{rotuloEfetivo(m)}</strong><br /><small>{m.nome}</small>{m.posto_a_atualizar && <><br /><span className="selo selo-alerta">Posto a atualizar</span></>}</span>
            </Link>) },
          { titulo: 'Matrícula', celula: (m) => <span className="mono">{m.matricula}</span> },
          { titulo: 'Unidade', celula: (m) => m.unidade.sigla },
          { titulo: 'Origem', celula: (m) => m.origem === 'FUNCIONARIOS' ? 'Cadastro de funcionários' : 'Local' },
          { titulo: 'Acesso ao sistema', celula: (m) => m.tem_acesso ? (pode('usuarios.ver') ? <Link to="/usuarios">Tem conta</Link> : 'Tem conta') : '—' },
          { titulo: 'Tipo de efetivo', celula: (m) => <SeloAptidao m={m} /> },
          { titulo: 'Situação', celula: (m) => <Selo valor={m.situacao} /> },
          { titulo: 'Ações', celula: (m) => <Link to={`/militares/${m.id}`}>Ver perfil <span className="sr-only">de {m.nome_guerra}</span></Link> },
        ]} />
        <Paginacao meta={dados.meta} ir={(p) => setF({ ...f, page: p })} />
      </>)}
    </Pagina>
  )
}
