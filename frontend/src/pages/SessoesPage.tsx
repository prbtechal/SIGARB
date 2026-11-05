import { api } from '../api/client'
import { Carregando, Erro, Pagina, Tabela, dataHora, useNotificar } from '../components/ui'
import { useRecurso } from '../components/useRecurso'

type S = { ref: string; ip: string; user_agent: string; ultima_atividade: string; atual: boolean }
export default function SessoesPage() {
  const notificar = useNotificar()
  const { dados, erro, carregando, recarregar } = useRecurso(() => api<{ data: S[] }>('/me/sessions'), [])
  return (
    <Pagina titulo="Minhas sessões" trilha={[{ rotulo: 'Início', para: '/' }, { rotulo: 'Minhas sessões' }]} descricao="Encerre sessões que você não reconhece. Limite provisório: 3 simultâneas (P-11).">
      {carregando && <Carregando />}{erro && <Erro erro={erro} tentar={recarregar} />}
      {dados && <Tabela legenda="Sessões ativas" linhas={dados.data} chave={(s) => s.ref} colunas={[
        { titulo: 'Última atividade', celula: (s) => dataHora(s.ultima_atividade) },
        { titulo: 'IP', celula: (s) => s.ip },
        { titulo: 'Navegador', celula: (s) => <small>{s.user_agent}</small> },
        { titulo: 'Ação', celula: (s) => s.atual ? <strong>Sessão atual</strong> : <button className="btn btn-sec" onClick={async () => { await api(`/me/sessions/${s.ref}`, { method: 'DELETE' }); notificar('ok', 'Sessão encerrada.'); recarregar() }}>Encerrar</button> },
      ]} />}
    </Pagina>
  )
}
