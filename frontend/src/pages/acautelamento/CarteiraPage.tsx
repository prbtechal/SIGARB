import { useEffect, useState } from 'react'
import { useParams } from 'react-router'
import QRCode from 'qrcode'
import { Printer } from 'lucide-react'
import { api } from '../../api/client'
import { Carregando, Erro, Pagina, data, dataHora } from '../../components/ui'
import { useRecurso } from '../../components/useRecurso'

type Carteira = { codigo: string; token: string; emitida_em: string; unidade: string; proxima_inspecao: string | null
  efetivo: { nome: string; nome_guerra: string; posto: string; matricula: string }; item: { identificador: string; numero_serie: string | null; material: string; calibre: string | null } }

/** RF-017 provisório (P-07): formato para impressão; "Salvar como PDF" pelo navegador. O QR leva só um link com token opaco. */
export default function CarteiraPage() {
  const { codigo = '' } = useParams()
  const valido = /^[0-9A-HJKMNP-TV-Z]{26}$/.test(codigo)
  const { dados: c, erro, carregando } = useRecurso(() => valido ? api<Carteira>(`/acautelamentos/${encodeURIComponent(codigo)}/carteira`) : Promise.reject({ code: 'NAO_ENCONTRADO', message: 'Carteira não encontrada.' }), [codigo])
  const [qr, setQr] = useState('')
  useEffect(() => { if (c) QRCode.toDataURL(`${location.origin}/carteiras/${c.token}`, { margin: 1, width: 240, errorCorrectionLevel: 'M' }).then(setQr) }, [c])
  const trilha = [{ rotulo: 'Início', para: '/' }, { rotulo: 'Acautelamentos', para: '/acautelamentos' }, { rotulo: 'Detalhe', para: `/acautelamentos/${codigo}` }, { rotulo: 'Carteira' }]
  if (carregando) return <Pagina titulo="Carteira" trilha={trilha}><Carregando /></Pagina>
  if (erro || !c) return <Pagina titulo="Carteira" trilha={trilha}>{erro && <Erro erro={erro} />}</Pagina>
  return (
    <Pagina titulo="Carteira de acautelamento" trilha={trilha} acoes={<button className="btn btn-pri" onClick={() => print()}><Printer size={18} aria-hidden="true" />Imprimir ou salvar PDF</button>}>
      <article className="carteira" aria-label="Carteira de acautelamento">
        <header><strong>SIGARB</strong><span>Carteira de acautelamento</span></header>
        <dl className="detalhes">
          <div><dt>Efetivo</dt><dd>{c.efetivo.posto} {c.efetivo.nome_guerra}</dd></div>
          <div><dt>Nome</dt><dd>{c.efetivo.nome}</dd></div>
          <div><dt>Matrícula</dt><dd className="mono">{c.efetivo.matricula}</dd></div>
          <div><dt>Unidade</dt><dd>{c.unidade}</dd></div>
          <div><dt>Armamento</dt><dd>{c.item.material}{c.item.calibre ? ` (${c.item.calibre})` : ''}</dd></div>
          <div><dt>Identificação</dt><dd className="mono">{c.item.identificador}{c.item.numero_serie ? ` · série ${c.item.numero_serie}` : ''}</dd></div>
          <div><dt>Próxima inspeção</dt><dd>{data(c.proxima_inspecao)}</dd></div>
          <div><dt>Emitida em</dt><dd>{dataHora(c.emitida_em)}</dd></div>
        </dl>
        {qr && <img src={qr} width={160} height={160} alt="QR Code de verificação da carteira (exige acesso ao SIGARB)" />}
      </article>
    </Pagina>
  )
}
