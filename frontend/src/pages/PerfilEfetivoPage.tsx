import { useRef, useState } from 'react'
import { Link, useParams } from 'react-router'
import { Camera, IdCard, KeyRound, Pencil, ShieldHalf, Trash2, Truck, UserRound } from 'lucide-react'
import { ErroApi, api, enviarArquivo, type Pagina as Pag } from '../api/client'
import type { AcautResumo, Militar, MovResumo } from '../api/tipos'
import { useAuth } from '../auth/AuthContext'
import { Carregando, Erro, Pagina, Selo, Vazio, data, dataHora, useNotificar } from '../components/ui'
import { useRecurso } from '../components/useRecurso'

type Efetivo = Militar & { foto_versao: number | null }
const SITUACAO: Record<string, string> = { ATIVO: 'ok', AFASTADO: 'alerta', INATIVO: 'neutro' }
const iniciais = (n: string) => n.split(/\s+/).filter(Boolean).slice(0, 2).map((p) => p[0]).join('').toUpperCase()

/** Perfil do efetivo: identificação com foto, dados funcionais, conta, armamento acautelado e entregas. */
export default function PerfilEfetivoPage() {
  const { id = '' } = useParams()
  const { pode } = useAuth()
  const notificar = useNotificar()
  const valido = /^\d+$/.test(id)
  const ef = useRecurso(() => valido ? api<Efetivo>(`/militares/${id}`) : Promise.reject({ code: 'NAO_ENCONTRADO', message: 'Efetivo não encontrado.' }), [id])
  const acaut = useRecurso(() => valido && pode('estoque.ver') ? api<Pag<AcautResumo>>('/acautelamentos', { query: { militar_id: id, per_page: 20 } }) : Promise.resolve(null), [id])
  const entregas = useRecurso(() => valido && pode('estoque.ver') ? api<Pag<MovResumo>>('/movements', { query: { militar_id: id, per_page: 10 } }) : Promise.resolve(null), [id])
  const arquivo = useRef<HTMLInputElement>(null)
  const [enviando, setEnviando] = useState(false)

  const trocarFoto = async (f: File | undefined) => {
    if (!f) return
    if (f.size > 3 * 1024 * 1024) { notificar('perigo', 'A foto deve ter até 3 MB.'); return }
    const fd = new FormData(); fd.append('foto', f)
    setEnviando(true)
    try { await enviarArquivo(`/militares/${id}/foto`, fd); notificar('ok', 'Foto atualizada.'); ef.recarregar() }
    catch (e) { notificar('perigo', e instanceof ErroApi ? e.erro.message : 'Não foi possível enviar a foto.') }
    finally { setEnviando(false); if (arquivo.current) arquivo.current.value = '' }
  }
  const removerFoto = async () => {
    try { await api(`/militares/${id}/foto`, { method: 'DELETE' }); notificar('ok', 'Foto removida.'); ef.recarregar() }
    catch (e) { if (e instanceof ErroApi) notificar('perigo', e.erro.message) }
  }

  const trilha = [{ rotulo: 'Início', para: '/' }, { rotulo: 'Efetivos', para: '/militares' }, { rotulo: 'Perfil' }]
  if (ef.carregando) return <Pagina titulo="Efetivo" trilha={trilha}><Carregando /></Pagina>
  if (ef.erro || !ef.dados) return <Pagina titulo="Efetivo" trilha={trilha}>{ef.erro && <Erro erro={ef.erro} />}</Pagina>
  const m = ef.dados
  const ativos = acaut.dados?.data.filter((a) => a.estado === 'ATIVO') ?? []

  return (
    <Pagina icone={UserRound} titulo={`${m.posto_graduacao.sigla} ${m.nome_guerra}`} trilha={trilha}
      acoes={m.pode_editar ? <Link className="btn btn-sec" to={`/militares/${m.id}/editar`}><Pencil size={18} aria-hidden="true" />Editar dados</Link> : undefined}>
      <section className="perfil" aria-label="Identificação do efetivo">
        <div className="perfil-capa" aria-hidden="true" />
        <div className="perfil-corpo">
          <div className="perfil-foto">
            <div className="moldura">
              {m.foto_versao
                ? <img src={`/api/v1/militares/${m.id}/foto?v=${m.foto_versao}`} alt={`Foto de ${m.nome}`} />
                : <span className="moldura-vazia" role="img" aria-label="Sem foto cadastrada">{iniciais(m.nome)}</span>}
            </div>
            {m.pode_editar && <div className="perfil-foto-acoes">
              <input ref={arquivo} type="file" accept="image/jpeg,image/png" hidden onChange={(e) => trocarFoto(e.target.files?.[0])} />
              <button type="button" className="btn btn-sec btn-peq" disabled={enviando} onClick={() => arquivo.current?.click()}><Camera size={16} aria-hidden="true" />{enviando ? 'Enviando…' : m.foto_versao ? 'Trocar foto' : 'Adicionar foto'}</button>
              {m.foto_versao && <button type="button" className="btn btn-sec btn-peq" onClick={removerFoto}><Trash2 size={16} aria-hidden="true" />Remover<span className="sr-only"> foto</span></button>}
            </div>}
          </div>
          <div className="perfil-ident">
            <p className="perfil-nome">{m.nome}</p>
            <p className="perfil-posto">{m.posto_graduacao.sigla} · {m.nome_guerra}</p>
            <ul className="perfil-chips">
              <li><Selo valor={m.situacao === 'ATIVO' ? 'Ativo' : m.situacao === 'AFASTADO' ? 'Afastado' : 'Inativo'} tom={SITUACAO[m.situacao]} /></li>
              <li className="chip"><IdCard size={16} aria-hidden="true" />Matrícula <span className="mono">{m.matricula}</span></li>
              <li className="chip">{m.unidade.sigla}</li>
            </ul>
          </div>
          <div className="perfil-resumo">
            <div><strong>{ativos.length}</strong><span>arma(s) acautelada(s)</span></div>
            <div><strong>{entregas.dados?.meta.total ?? '—'}</strong><span>entrega(s) e devolução(ões)</span></div>
          </div>
        </div>
      </section>

      <div className="perfil-grade">
        <section className="bloco" aria-labelledby="pf-dados">
          <h2 id="pf-dados"><UserRound size={20} aria-hidden="true" />Dados funcionais</h2>
          <dl className="detalhes">
            <div><dt>Nome completo</dt><dd>{m.nome}</dd></div>
            <div><dt>Nome de guerra</dt><dd>{m.nome_guerra}</dd></div>
            <div><dt>Posto/graduação</dt><dd>{m.posto_graduacao.sigla}</dd></div>
            <div><dt>Matrícula</dt><dd className="mono">{m.matricula}{!m.matricula_completa && <span className="dica"> (parcial)</span>}</dd></div>
            <div><dt>Unidade</dt><dd>{m.unidade.sigla}</dd></div>
            <div><dt>Situação</dt><dd>{m.situacao[0] + m.situacao.slice(1).toLowerCase()}</dd></div>
          </dl>
        </section>

        {m.usuario !== undefined && <section className="bloco" aria-labelledby="pf-conta">
          <h2 id="pf-conta"><KeyRound size={20} aria-hidden="true" />Conta de acesso</h2>
          {m.usuario ? <p>Vinculado ao usuário <strong className="mono">{m.usuario.login}</strong>.</p> : <p className="dica">Este efetivo não possui conta de acesso ao sistema.</p>}
        </section>}

        {acaut.dados && <section className="bloco perfil-largo" aria-labelledby="pf-acaut">
          <div className="bloco-topo">
            <h2 id="pf-acaut"><ShieldHalf size={20} aria-hidden="true" />Armamento acautelado</h2>
            {pode('acautelamento.solicitar') && m.situacao === 'ATIVO' && <Link className="btn btn-sec btn-peq" to={`/acautelamentos/novo?militar_id=${m.id}`}>Solicitar acautelamento</Link>}
          </div>
          {acaut.dados.data.length === 0 ? <Vazio titulo="Nenhum acautelamento registrado." icone={ShieldHalf} /> : (
            <ul className="lista-cartoes">{acaut.dados.data.map((a) => (
              <li key={a.codigo}>
                <Link to={`/acautelamentos/${a.codigo}`}><span className="mono">{a.item.identificador}</span> · {a.item.material}</Link>
                <span><Selo valor={a.estado} tom={a.estado === 'ATIVO' ? 'ok' : a.estado === 'SOLICITADO' ? 'alerta' : undefined} />
                  {a.estado === 'ATIVO' && <span className={a.inspecao_atrasada ? 'txt-perigo' : 'dica'}> Próxima inspeção: {data(a.proxima_inspecao)}</span>}</span>
              </li>))}</ul>)}
        </section>}

        {entregas.dados && <section className="bloco perfil-largo" aria-labelledby="pf-entregas">
          <h2 id="pf-entregas"><Truck size={20} aria-hidden="true" />Entregas e devoluções</h2>
          {entregas.dados.data.length === 0 ? <Vazio titulo="Nenhuma entrega registrada." icone={Truck} /> : (
            <ul className="lista-cartoes">{entregas.dados.data.map((mv) => (
              <li key={mv.id}>
                <Link to={`/movimentacoes/${mv.id}`}><Selo valor={mv.tipo} /> #{mv.id} · {mv.itens_count} item(ns)</Link>
                <span className="dica"><time dateTime={mv.ocorrido_em}>{dataHora(mv.ocorrido_em)}</time>{mv.previsao_retorno ? ` · retorno previsto ${data(mv.previsao_retorno)}` : ''}</span>
              </li>))}</ul>)}
        </section>}
      </div>
    </Pagina>
  )
}
