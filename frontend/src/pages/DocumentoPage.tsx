import { useState } from 'react'
import { Link, useParams } from 'react-router'
import { ErroApi, api } from '../api/client'
import type { DocDetalhe } from '../api/tipos'
import { useAuth } from '../auth/AuthContext'
import { tamanho } from '../components/arquivos'
import { Aviso, Campo, Carregando, Dialogo, Erro, Pagina, Selo, dataHora, useNotificar } from '../components/ui'
import { useRecurso } from '../components/useRecurso'

export default function DocumentoPage() {
  const { codigo } = useParams()
  const { pode } = useAuth()
  const notificar = useNotificar()
  const { dados: d, erro, carregando, recarregar } = useRecurso(() => api<DocDetalhe>(`/documents/${codigo}`), [codigo])
  const [editar, setEditar] = useState(false)
  const [meta, setMeta] = useState({ titulo: '', numero_referencia: '', descricao: '' })
  const [situacao, setSituacao] = useState(false)
  const [just, setJust] = useState('')
  const [vinc, setVinc] = useState(false)
  const [mov, setMov] = useState('')
  const [erroAcao, setErroAcao] = useState<ErroApi | null>(null)

  const exec = async (fn: () => Promise<unknown>, ok: string, fechar: () => void) => {
    setErroAcao(null)
    try { await fn(); notificar('ok', ok); fechar(); recarregar() } catch (x) { if (x instanceof ErroApi) setErroAcao(x) }
  }
  const fe = (k: string) => erroAcao?.erro.fields?.[k]

  return (
    <Pagina titulo={d ? d.titulo : 'Documento'} trilha={[{ rotulo: 'Início', para: '/' }, { rotulo: 'Documentos', para: '/documentos' }, { rotulo: 'Detalhe' }]}
      acoes={d && <>
        <a className="btn btn-pri" href={`${import.meta.env.BASE_URL}api/v1/documents/${d.codigo}/download`} download>Baixar <span className="sr-only">{d.nome_original}</span></a>
        {d.pode_gerir && d.situacao === 'ATIVO' && <button className="btn btn-sec" onClick={() => { setMeta({ titulo: d.titulo, numero_referencia: d.numero_referencia ?? '', descricao: d.descricao ?? '' }); setErroAcao(null); setEditar(true) }}>Editar dados</button>}
        {d.pode_gerir && <button className="btn btn-sec" onClick={() => { setJust(''); setErroAcao(null); setSituacao(true) }}>{d.situacao === 'ATIVO' ? 'Arquivar…' : 'Reativar…'}</button>}
      </>}>
      {carregando && <Carregando />}
      {erro && <Erro erro={erro.code === 'NAO_ENCONTRADO' ? { ...erro, message: 'Documento não encontrado ou fora do seu escopo.' } : erro} tentar={recarregar} />}
      {d && <>
        {d.situacao === 'ARQUIVADO' && <Aviso tipo="alerta">Documento arquivado em {d.arquivado_em && dataHora(d.arquivado_em)}. Continua disponível para consulta; os dados não podem ser editados.</Aviso>}
        <p className="dica">O download é registrado na auditoria. O arquivo é sempre entregue para salvar, nunca aberto dentro do sistema.</p>
        <dl className="detalhes">
          <div><dt>Situação</dt><dd><Selo valor={d.situacao} tom={d.situacao === 'ATIVO' ? 'ok' : 'neutro'} /></dd></div>
          <div><dt>Tipo</dt><dd>{d.tipo.nome}</dd></div>
          <div><dt>Unidade</dt><dd>{d.unidade.sigla}</dd></div>
          <div><dt>Número de referência</dt><dd>{d.numero_referencia ?? '—'}</dd></div>
          <div><dt>Arquivo</dt><dd>{d.nome_original} · {tamanho(d.tamanho_bytes)} · {d.extensao.toUpperCase()}</dd></div>
          <div><dt>Enviado por</dt><dd>{d.enviado_por} em {dataHora(d.enviado_em)}</dd></div>
          <div><dt>Descrição</dt><dd>{d.descricao ?? '—'}</dd></div>
          <div><dt>SHA-256</dt><dd className="mono quebra">{d.sha256}</dd></div>
        </dl>
        <h2>Vínculos</h2>
        {d.vinculos.length === 0 && d.vinculos_ocultos === 0 ? <p>Sem vínculos.</p> : (
          <ul className="lista-simples">
            {d.vinculos.map((v) => <li key={v.movimentacao_id}><Link to={`/movimentacoes/${v.movimentacao_id}`}>Movimentação #{v.movimentacao_id}</Link> <Selo valor={v.tipo} /></li>)}
            {d.vinculos_ocultos > 0 && <li>{d.vinculos_ocultos} vínculo(s) fora do seu escopo.</li>}
          </ul>
        )}
        {d.pode_gerir && pode('estoque.ver') && <button className="btn btn-sec" onClick={() => { setMov(''); setErroAcao(null); setVinc(true) }}>Vincular a movimentação…</button>}
      </>}
      <Dialogo aberto={editar} titulo="Editar dados do documento" onFechar={() => setEditar(false)}
        rodape={<><button className="btn btn-sec" onClick={() => setEditar(false)}>Cancelar</button><button className="btn btn-pri" onClick={() => exec(() => api(`/documents/${codigo}`, { method: 'PUT', body: { ...meta, numero_referencia: meta.numero_referencia || null, descricao: meta.descricao || null } }), 'Dados atualizados.', () => setEditar(false))}>Salvar</button></>}>
        {erroAcao && !erroAcao.erro.fields && <div className="aviso aviso-perigo" role="alert">{erroAcao.erro.message}</div>}
        <Campo rotulo="Título" obrigatorio erro={fe('titulo')}>{(p) => <input {...p} value={meta.titulo} onChange={(e) => setMeta({ ...meta, titulo: e.target.value })} />}</Campo>
        <Campo rotulo="Número de referência" erro={fe('numero_referencia')}>{(p) => <input {...p} value={meta.numero_referencia} onChange={(e) => setMeta({ ...meta, numero_referencia: e.target.value })} />}</Campo>
        <Campo rotulo="Descrição" erro={fe('descricao')}>{(p) => <textarea {...p} rows={3} value={meta.descricao} onChange={(e) => setMeta({ ...meta, descricao: e.target.value })} />}</Campo>
        <p className="dica">O arquivo, a unidade e o checksum não podem ser alterados.</p>
      </Dialogo>
      <Dialogo aberto={situacao} titulo={d?.situacao === 'ATIVO' ? 'Arquivar documento' : 'Reativar documento'} onFechar={() => setSituacao(false)}
        rodape={<><button className="btn btn-sec" onClick={() => setSituacao(false)}>Cancelar</button><button className="btn btn-pri" disabled={just.trim().length < 10} onClick={() => exec(() => api(`/documents/${codigo}/${d?.situacao === 'ATIVO' ? 'archive' : 'reactivate'}`, { method: 'POST', body: { justificativa: just } }), 'Situação atualizada.', () => setSituacao(false))}>Confirmar</button></>}>
        <p>{d?.situacao === 'ATIVO' ? 'O documento deixa de aparecer na lista padrão, mas não é apagado.' : 'O documento volta à lista de ativos.'} A ação é auditada.</p>
        {erroAcao && <div className="aviso aviso-perigo" role="alert">{erroAcao.erro.message}</div>}
        <Campo rotulo="Justificativa" obrigatorio dica="Mínimo de 10 caracteres.">{(p) => <textarea {...p} rows={3} value={just} onChange={(e) => setJust(e.target.value)} />}</Campo>
      </Dialogo>
      <Dialogo aberto={vinc} titulo="Vincular a movimentação" onFechar={() => setVinc(false)}
        rodape={<><button className="btn btn-sec" onClick={() => setVinc(false)}>Cancelar</button><button className="btn btn-pri" disabled={!mov} onClick={() => exec(() => api(`/documents/${codigo}/links`, { method: 'POST', body: { movimentacao_id: Number(mov) } }), 'Vínculo registrado.', () => setVinc(false))}>Vincular</button></>}>
        {erroAcao && <div className="aviso aviso-perigo" role="alert">{erroAcao.erro.message}</div>}
        <Campo rotulo="Número da movimentação" obrigatorio dica="O vínculo não pode ser desfeito.">{(p) => <input {...p} inputMode="numeric" value={mov} onChange={(e) => setMov(e.target.value.replace(/\D/g, ''))} />}</Campo>
      </Dialogo>
    </Pagina>
  )
}
