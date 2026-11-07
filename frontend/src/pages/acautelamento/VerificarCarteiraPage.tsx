import { useParams } from 'react-router'
import { BadgeCheck } from 'lucide-react'
import { api } from '../../api/client'
import { Aviso, Carregando, Erro, Pagina, data } from '../../components/ui'
import { useRecurso } from '../../components/useRecurso'

type Verif = { valida: boolean; efetivo?: string; item?: string; proxima_inspecao?: string | null }

/** Destino do QR da carteira. Só com sessão; mostra o mínimo para conferência. */
export default function VerificarCarteiraPage() {
  const { token = '' } = useParams()
  const valido = /^[A-Za-z0-9]{43}$/.test(token)
  const { dados, erro, carregando } = useRecurso(() => valido ? api<Verif>(`/carteiras/${token}`) : Promise.resolve<Verif>({ valida: false }), [token])
  return (
    <Pagina titulo="Verificação de carteira" icone={BadgeCheck} trilha={[{ rotulo: 'Início', para: '/' }, { rotulo: 'Verificação de carteira' }]}>
      {carregando && <Carregando />}{erro && <Erro erro={erro} />}
      {dados && (dados.valida
        ? <Aviso tipo="ok" titulo="Carteira válida:">{dados.efetivo}, item {dados.item}. Próxima inspeção: {data(dados.proxima_inspecao ?? null)}.</Aviso>
        : <Aviso tipo="perigo" titulo="Carteira inválida ou encerrada.">Não corresponde a um acautelamento ativo.</Aviso>)}
    </Pagina>
  )
}
