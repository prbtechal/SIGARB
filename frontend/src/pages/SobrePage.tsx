import { ArrowLeft, Info } from 'lucide-react'
import { Link } from 'react-router'
import { Pagina } from '../components/ui'
import { api } from '../api/client'
import { useRecurso } from '../components/useRecurso'
import { RodapeAcesso } from './LoginPage'

// Descrição: texto fixo. Versão e equipe vêm do banco compartilhado `ecossistema` (GET /sobre);
// as listas abaixo só aparecem se o banco estiver indisponível.
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
      <SobreConteudo />
    </Pagina>
  )
}

/** Versão pública (sem login): mesma moldura das telas de acesso. */
export function SobrePublica() {
  return (
    <div className="acesso">
      <main className="acesso-cartao acesso-largo" id="conteudo">
        <div className="acesso-faixa" aria-hidden="true" />
        <div className="acesso-corpo">
          <header className="acesso-topo">
            <img src={`${import.meta.env.BASE_URL}logo-ronda-do-bairro.png`} alt="" className="acesso-logo" width="64" height="64" />
            <div>
              <p className="acesso-sobrescrito">SIGARB</p>
              <h1>Sobre o Sistema</h1>
            </div>
          </header>
          <SobreConteudo />
          <p><Link to="/login" className="acesso-voltar"><ArrowLeft size={18} aria-hidden="true" />Voltar ao login</Link></p>
          <RodapeAcesso />
        </div>
      </main>
    </div>
  )
}

type Pessoa = { nome: string; cargo: string; atribuicao?: string | null; inicio?: string | null; fim?: string | null }
type Sobre = { versao: string | null; git_sha: string | null; implantado_em: string | null; historico: { versao: string; implantado_em: string }[]
  governanca: Pessoa[] | null; equipe: Pessoa[] | null; ex_integrantes: Pessoa[] }

// Formata direto do texto (AAAA-MM-DD…): new Date('AAAA-MM-DD') é UTC e mostraria o dia anterior no fuso do Brasil.
const data = (iso?: string | null) => { const m = iso?.match(/^(\d{4})-(\d{2})-(\d{2})/); return m ? `${m[3]}/${m[2]}/${m[1]}` : '' }
const periodo = (p: Pessoa) => [p.inicio && `desde ${data(p.inicio)}`, p.fim && `até ${data(p.fim)}`].filter(Boolean).join(' ')

function SobreConteudo() {
  const { dados } = useRecurso(() => api<Sobre>('/sobre').catch(() => null), [])
  const governanca = dados?.governanca ?? GOVERNANCA
  const equipe = dados?.equipe ?? EQUIPE.map((p) => ({ nome: p.nome, cargo: p.funcao }))
  const ex = dados?.ex_integrantes ?? []
  return (
    <>
      {dados?.versao && (
        <p className="sobre-versao">
          <strong>Versão {dados.versao}</strong>{dados.implantado_em && <> · no ar desde {data(dados.implantado_em)}</>}{dados.git_sha && <span className="mono"> · {dados.git_sha}</span>}
        </p>)}
      <div className="bloco">{DESCRICAO.map((p) => <p key={p}>{p}</p>)}</div>
      <section aria-labelledby="sobre-governanca">
        <h2 id="sobre-governanca">Corpo Técnico e Governança</h2>
        <ul className="grade-ops">
          {governanca.map((p) => (
            <li key={p.nome} className="cartao"><p><strong>{p.nome}</strong></p><p className="cartao-extra">{p.cargo}</p>{p.atribuicao && <p>{p.atribuicao}</p>}</li>
          ))}
        </ul>
      </section>
      <section aria-labelledby="sobre-equipe">
        <h2 id="sobre-equipe">Equipe de Desenvolvimento de Software</h2>
        <p>O time de engenharia responsável pela arquitetura, código e interface do sistema:</p>
        <ul className="grade-ops">
          {equipe.map((p) => (
            <li key={p.nome} className="cartao"><p><strong>{p.nome}</strong></p><p className="cartao-extra">{p.cargo}</p></li>
          ))}
        </ul>
      </section>
      {ex.length > 0 && (
        <section aria-labelledby="sobre-ex">
          <h2 id="sobre-ex">Já fizeram parte</h2>
          <p>Pessoas que contribuíram com o sistema em outros períodos:</p>
          <ul className="grade-ops">
            {ex.map((p) => (
              <li key={p.nome + p.cargo} className="cartao"><p><strong>{p.nome}</strong></p><p className="cartao-extra">{p.cargo}{periodo(p) && ` · ${periodo(p)}`}</p></li>
            ))}
          </ul>
        </section>)}
      {dados && dados.historico.length > 1 && (
        <section aria-labelledby="sobre-versoes">
          <h2 id="sobre-versoes">Histórico de versões</h2>
          <ul className="sobre-historico">{dados.historico.map((v) => <li key={v.versao + v.implantado_em}><strong>{v.versao}</strong> · {data(v.implantado_em)}</li>)}</ul>
        </section>)}
    </>
  )
}
