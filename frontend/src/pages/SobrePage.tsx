import { Info } from 'lucide-react'
import { Pagina } from '../components/ui'

// Conteúdo institucional estático: atualize aqui.
const DESCRICAO = [
  'O SIGARB (Arsenal) é a plataforma digital oficial de gestão de armamento, munição e material controlado do Programa Ronda no Bairro (PRB/AL). Desenvolvido sob medida para a rotina de reserva de armamento, o software substitui controles manuais por um registro único, seguro e auditável.',
  'A ferramenta acompanha cada material e item individual, das entradas às cautelas, devoluções, transferências, inspeções e inventários, sempre vinculados ao militar e à unidade responsáveis. Com foco na rastreabilidade e na segurança, cada movimentação fica registrada em uma trilha de auditoria que não pode ser alterada.',
  'O resultado prático é controle preciso do material em cada reserva e prestação de contas confiável para a gestão.',
]
const GOVERNANCA = [
  { nome: 'Josine Lima dos Santos', cargo: 'Superintendente · TC PMAL', atribuicao: 'Direcionamento estratégico e governança institucional.' },
  { nome: 'Cristiano Gleiky Lima de Santana', cargo: 'Gestor de TI · SGT PMAL', atribuicao: 'Coordenação técnica, infraestrutura e segurança da informação.' },
]
const EQUIPE = [
  { nome: 'José Paulo Neto Filho', funcao: 'Desenvolvedor' },
  { nome: 'Victor Seixas dos Santos Zuza', funcao: 'Desenvolvedor' },
  { nome: 'Albert Douglas', funcao: 'Suporte Técnico' },
]

export default function SobrePage() {
  return (
    <Pagina icone={Info} titulo="Sobre o Sistema" trilha={[{ rotulo: 'Início', para: '/' }, { rotulo: 'Sobre o Sistema' }]}>
      <div className="bloco">{DESCRICAO.map((p) => <p key={p}>{p}</p>)}</div>
      <section aria-labelledby="sobre-governanca">
        <h2 id="sobre-governanca">Corpo Técnico e Governança</h2>
        <ul className="grade-ops">
          {GOVERNANCA.map((p) => (
            <li key={p.nome} className="cartao"><p><strong>{p.nome}</strong></p><p className="cartao-extra">{p.cargo}</p><p>{p.atribuicao}</p></li>
          ))}
        </ul>
      </section>
      <section aria-labelledby="sobre-equipe">
        <h2 id="sobre-equipe">Equipe de Desenvolvimento de Software</h2>
        <p>O time de engenharia responsável pela arquitetura, código e interface do sistema:</p>
        <ul className="grade-ops">
          {EQUIPE.map((p) => (
            <li key={p.nome} className="cartao"><p><strong>{p.nome}</strong></p><p className="cartao-extra">{p.funcao}</p></li>
          ))}
        </ul>
      </section>
    </Pagina>
  )
}
