import { useMemo, useState } from 'react'
import { KeyRound, Lock } from 'lucide-react'
import { ErroApi, api } from '../api/client'
import { Aviso, Campo, Carregando, Dialogo, Erro, Pagina, useNotificar } from '../components/ui'
import { useRecurso } from '../components/useRecurso'

type Matriz = { permissoes: { codigo: string; descricao: string }[]; papeis: { codigo: string; nome: string; descricao: string | null; permissoes: string[]; travadas: string[] }[] }

const GRUPOS: Record<string, string> = {
  organizacao: 'Unidades e locais', usuarios: 'Contas de acesso', sessoes: 'Contas de acesso', militares: 'Efetivo', catalogo: 'Catálogo',
  referencias: 'Catálogo', estoque: 'Estoque', acautelamento: 'Cautelas', inventario: 'Inventário', relatorios: 'Relatórios e dados',
  dados: 'Relatórios e dados', documentos: 'Documentos', auditoria: 'Auditoria', suporte: 'Suporte', sistema: 'Sistema',
}
const chave = (papel: string, perm: string) => `${papel}|${perm}`

/** Matriz papéis × permissões: marque/desmarque e salve tudo de uma vez com um motivo (vai para a auditoria). */
export default function PermissoesPage() {
  const notificar = useNotificar()
  const m = useRecurso(() => api<Matriz>('/permissions'), [])
  const [mudancas, setMudancas] = useState<Map<string, boolean>>(new Map())
  const [motivo, setMotivo] = useState('')
  const [confirmar, setConfirmar] = useState(false)
  const [salvando, setSalvando] = useState(false)
  const [falha, setFalha] = useState<string | null>(null)

  const grupos = useMemo(() => {
    const g = new Map<string, Matriz['permissoes']>()
    for (const p of m.dados?.permissoes ?? []) {
      const nome = GRUPOS[p.codigo.split('.')[0]] ?? 'Outras'
      g.set(nome, [...(g.get(nome) ?? []), p])
    }
    return [...g.entries()]
  }, [m.dados])

  const marcado = (papel: Matriz['papeis'][number], perm: string) => mudancas.get(chave(papel.codigo, perm)) ?? papel.permissoes.includes(perm)
  const alternar = (papel: Matriz['papeis'][number], perm: string) => {
    const k = chave(papel.codigo, perm), novo = !marcado(papel, perm), n = new Map(mudancas)
    if (novo === papel.permissoes.includes(perm)) n.delete(k); else n.set(k, novo)
    setMudancas(n)
  }

  const salvar = async () => {
    setSalvando(true); setFalha(null)
    let feitas = 0
    try {
      for (const [k, concedida] of mudancas) {
        const [papel, perm] = k.split('|')
        await api(`/roles/${papel}/permissions/${perm}`, { method: 'PUT', body: { concedida, motivo } })
        feitas++
      }
      notificar('ok', `${feitas} permissão(ões) alterada(s). Vale no próximo carregamento de página de cada usuário.`)
      setMudancas(new Map()); setConfirmar(false); setMotivo('')
    } catch (e) {
      setFalha(`${feitas} de ${mudancas.size} alterações gravadas. ${e instanceof ErroApi ? e.erro.message : 'Falha ao gravar.'}`)
    } finally { setSalvando(false); m.recarregar() }
  }

  return (
    <Pagina icone={KeyRound} titulo="Permissões" trilha={[{ rotulo: 'Início', para: '/' }, { rotulo: 'Permissões' }]}
      descricao="O que cada papel pode fazer. Marque ou desmarque e salve; cada alteração pede um motivo e fica na auditoria. A unidade em que o papel vale é definida em Usuários › Papéis."
      acoes={<>
        {mudancas.size > 0 && <button type="button" className="btn btn-sec" onClick={() => setMudancas(new Map())}>Descartar</button>}
        <button type="button" className="btn btn-pri" disabled={!mudancas.size} onClick={() => setConfirmar(true)}>Salvar alterações{mudancas.size ? ` (${mudancas.size})` : ''}</button>
      </>}>
      {m.carregando && <Carregando />}{m.erro && <Erro erro={m.erro} tentar={m.recarregar} />}
      {falha && <Aviso tipo="perigo" titulo="Nem tudo foi gravado">{falha}</Aviso>}
      {m.dados && <div className="matriz-wrap">
        <table className="matriz">
          <caption className="sr-only">Permissões por papel</caption>
          <thead><tr><th scope="col">Permissão</th>{m.dados.papeis.map((p) => <th key={p.codigo} scope="col" title={p.descricao ?? undefined}>{p.nome}</th>)}</tr></thead>
          {grupos.map(([g, perms]) => <tbody key={g}>
            <tr className="matriz-grupo"><th scope="rowgroup" colSpan={m.dados!.papeis.length + 1}>{g}</th></tr>
            {perms.map((pe) => <tr key={pe.codigo}>
              <th scope="row"><span className="matriz-nome">{pe.descricao}</span><code>{pe.codigo}</code></th>
              {m.dados!.papeis.map((pa) => {
                const trava = pa.travadas.includes(pe.codigo), alterada = mudancas.has(chave(pa.codigo, pe.codigo))
                return <td key={pa.codigo} className={alterada ? 'matriz-alterada' : undefined}>
                  <label className="matriz-celula">
                    <input type="checkbox" checked={marcado(pa, pe.codigo)} disabled={trava} onChange={() => alternar(pa, pe.codigo)}
                      aria-label={`${pa.nome}: ${pe.descricao}`} />
                    {trava && <Lock size={14} aria-hidden="true" />}
                  </label>
                </td>
              })}
            </tr>)}
          </tbody>)}
        </table>
      </div>}
      <p className="dica"><Lock size={14} aria-hidden="true" /> Travadas: o administrador não pode perder o acesso que permite desfazer uma mudança.</p>

      <Dialogo tour="permissoes" aberto={confirmar} titulo={`Salvar ${mudancas.size} alteração(ões)`} onFechar={() => setConfirmar(false)}
        rodape={<><button type="button" className="btn btn-sec" onClick={() => setConfirmar(false)}>Cancelar</button>
          <button type="button" className="btn btn-pri" disabled={salvando || motivo.trim().length < 5} onClick={salvar}>{salvando ? 'Salvando…' : 'Confirmar'}</button></>}>
        <ul className="lista-simples">{[...mudancas].map(([k, v]) => {
          const [papel, perm] = k.split('|')
          return <li key={k}>{v ? 'Conceder' : 'Retirar'} <code>{perm}</code> de <strong>{m.dados?.papeis.find((p) => p.codigo === papel)?.nome}</strong></li>
        })}</ul>
        <Campo rotulo="Motivo" obrigatorio dica="Mínimo de 5 caracteres. Fica na auditoria.">{(p) => <textarea {...p} value={motivo} maxLength={500} onChange={(e) => setMotivo(e.target.value)} />}</Campo>
      </Dialogo>
    </Pagina>
  )
}
