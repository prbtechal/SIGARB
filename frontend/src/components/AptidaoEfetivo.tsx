import { Aviso } from './ui'
import { CATEGORIAS_EFETIVO, type Militar } from '../api/tipos'

type Ef = Pick<Militar, 'categoria_efetivo' | 'apto_arma_fogo'>

/** Regra do PRB: RR (PM/BM) e PM/BM da ativa recebem tudo, inclusive arma de fogo e munição; R1 e Vigilante, o resto. */
export function SeloAptidao({ m }: { m: Ef }) {
  if (!m.categoria_efetivo) return <span className="selo selo-perigo">Tipo não cadastrado</span>
  const tipo = CATEGORIAS_EFETIVO[m.categoria_efetivo] ?? m.categoria_efetivo
  return m.apto_arma_fogo
    ? <span className="selo selo-ok">{tipo} · pode arma de fogo</span>
    : <span className="selo selo-alerta">{tipo} · sem arma de fogo</span>
}

/** Aviso mostrado ao escolher o efetivo numa entrega ou cautela. */
export function AvisoAptidao({ m }: { m: Ef & { nome_guerra?: string } | undefined | null }) {
  if (!m) return null
  if (!m.categoria_efetivo) return (
    <Aviso tipo="perigo" titulo="Tipo de efetivo não cadastrado.">
      {m.nome_guerra ? `${m.nome_guerra} não` : 'Este efetivo não'} tem a categoria (RR, PM/BM ativo, R1 ou Vigilante) no cadastro e não pode receber arma de fogo nem munição. Procure a T.I. / suporte técnico para regularizar o cadastro.
    </Aviso>)
  const tipo = CATEGORIAS_EFETIVO[m.categoria_efetivo] ?? m.categoria_efetivo
  return m.apto_arma_fogo
    ? <Aviso tipo="ok" titulo={`${tipo}:`}>pode receber todos os materiais, inclusive arma de fogo e munição.</Aviso>
    : <Aviso tipo="alerta" titulo={`${tipo}:`}>pode receber os demais materiais, mas não arma de fogo nem munição.</Aviso>
}

export const marcaSemTipo = (m: Ef) => (m.categoria_efetivo ? '' : ' — sem tipo de efetivo')
