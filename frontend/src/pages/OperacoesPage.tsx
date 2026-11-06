import { ListChecks } from 'lucide-react'
import { useAuth } from '../auth/AuthContext'
import { Aviso, Pagina, Selo } from '../components/ui'

export default function OperacoesPage() {
  const { perfil } = useAuth()
  return (
    <Pagina icone={ListChecks} titulo="Operações e pendências" trilha={[{ rotulo: 'Início', para: '/' }, { rotulo: 'Operações' }]}
      descricao="Somente operações com política definida estão habilitadas. As demais aguardam decisão institucional e não podem ser simuladas.">
      <Aviso tipo="alerta">Nenhuma aprovação, acautelamento ou carteira é gerada neste ambiente. Operações bloqueadas não produzem registros nem documentos.</Aviso>
      <ul className="grade-ops">
        {perfil?.operacoes.map((o) => (
          <li key={o.codigo} className={`op op-${o.estado.toLowerCase()}`} aria-disabled={o.estado === 'BLOQUEADA'}>
            <h2>{o.rotulo}</h2>
            <Selo valor={o.estado} />
            <p>{o.estado === 'BLOQUEADA' ? `Aguardando decisão institucional ${o.pendencia}.` : `Habilitada (pendência relacionada: ${o.pendencia}).`}</p>
          </li>
        ))}
      </ul>
    </Pagina>
  )
}
