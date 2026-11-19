import { useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router'
import { ErroApi, api, enviarArquivo } from '../api/client'
import { CampoAnexo, ERROS_ARQUIVO, ROTULO_REGISTRO } from '../components/Anexo'
import { validarArquivo } from '../components/arquivos'
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
  // Nova versão (2026-11-19): só quem enviou; o arquivo anterior nunca é trocado, a versão nova herda dados e vínculos.
  const nav = useNavigate()
  const [versao, setVersao] = useState(false)
  const [arqVersao, setArqVersao] = useState<File | null>(null)
  const [erroArqVersao, setErroArqVersao] = useState<string | null>(null)
  const [progresso, setProgresso] = useState<number | null>(null)
  const enviarVersao = async () => {
    const v = validarArquivo(arqVersao)
    if (v) { setErroArqVersao(v); return }
    const fd = new FormData(); fd.append('arquivo', arqVersao!)
    setErroAcao(null); setProgresso(0)
    try {
      const nova = await enviarArquivo<{ codigo: string; versao: number }>(`/documents/${codigo}/versions`, fd, setProgresso)
      setVersao(false); setArqVersao(null); setProgresso(null)
      notificar('ok', `Versão ${nova.versao} enviada.`)
      nav(`/documentos/${nova.codigo}`)
    } catch (x) {
      if (x instanceof ErroApi) { setErroAcao(x); if (ERROS_ARQUIVO.includes(x.erro.code)) setErroArqVersao(x.erro.message) }
      setProgresso(null)
    }
  }

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
        {d.pode_nova_versao && <button className="btn btn-sec" onClick={() => { setArqVersao(null); setErroArqVersao(null); setErroAcao(null); setVersao(true) }}>Enviar nova versão…</button>}
        {d.pode_gerir && <button className="btn btn-sec" onClick={() => { setJust(''); setErroAcao(null); setSituacao(true) }}>{d.situacao === 'ATIVO' ? 'Arquivar…' : 'Reativar…'}</button>}
      </>}>
      {carregando && <Carregando />}
      {erro && <Erro erro={erro.code === 'NAO_ENCONTRADO' ? { ...erro, message: 'Documento não encontrado ou fora do seu escopo.' } : erro} tentar={recarregar} />}
      {d && <>
        {d.situacao === 'ARQUIVADO' && <Aviso tipo="alerta">Documento arquivado em {d.arquivado_em && dataHora(d.arquivado_em)}. Continua disponível para consulta; os dados não podem ser editados.</Aviso>}
        {d.substituido_por && <Aviso tipo="alerta">Existe uma versão mais nova deste documento. <Link to={`/documentos/${d.substituido_por}`}>Ver a versão atual</Link></Aviso>}
        <p className="dica">O download é registrado na auditoria. O arquivo é sempre entregue para salvar, nunca aberto dentro do sistema.</p>
        <dl className="detalhes">
          <div><dt>Situação</dt><dd><Selo valor={d.situacao} tom={d.situacao === 'ATIVO' ? 'ok' : 'neutro'} /></dd></div>
          <div><dt>Tipo</dt><dd>{d.tipo.nome}</dd></div>
          <div><dt>Versão</dt><dd>{d.versao ?? 1}{d.versao_anterior && <> · <Link to={`/documentos/${d.versao_anterior}`}>ver a anterior</Link></>}</dd></div>
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
            {d.vinculos.map((v) => <li key={v.movimentacao_id}><Link to={`/movimentacoes/${v.movimentacao_id}`}>{ROTULO_REGISTRO[v.tipo] ?? 'Movimentação'} #{v.movimentacao_id}</Link> <Selo valor={v.tipo} /></li>)}
            {d.vinculos_ocultos > 0 && <li>{d.vinculos_ocultos} vínculo(s) fora do seu escopo.</li>}
          </ul>
        )}
        {d.pode_gerir && pode('estoque.ver') && <button className="btn btn-sec" onClick={() => { setMov(''); setErroAcao(null); setVinc(true) }}>Vincular a movimentação…</button>}
      </>}
      <Dialogo aberto={versao} titulo="Enviar nova versão" onFechar={() => setVersao(false)}
        rodape={<><button className="btn btn-sec" disabled={progresso !== null} onClick={() => setVersao(false)}>Cancelar</button>
          <button className="btn btn-pri" disabled={progresso !== null || !arqVersao || !!erroArqVersao} onClick={enviarVersao}>{progresso !== null ? `Enviando… ${progresso}%` : 'Enviar versão'}</button></>}>
        {erroAcao && !erroArqVersao && <div className="aviso aviso-perigo" role="alert">{erroAcao.erro.message}</div>}
        <p>A versão {(d?.versao ?? 1) + 1} herda título, tipo, número e registros ligados. A versão atual continua guardada e consultável; nada é apagado.</p>
        <CampoAnexo rotulo="Arquivo da nova versão" opcional={false} arquivo={arqVersao} erro={erroArqVersao} desabilitado={progresso !== null} onChange={(f, e) => { setArqVersao(f); setErroArqVersao(e) }} />
      </Dialogo>
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
