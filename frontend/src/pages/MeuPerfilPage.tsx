import { useEffect, useState, type FormEvent } from 'react'
import { IdCard } from 'lucide-react'
import { ErroApi, api } from '../api/client'
import type { DadosFuncionais, Posto } from '../api/tipos'
import { exibirLogin } from '../components/cpf'
import { Campo, Carregando, Erro, Pagina, useNotificar } from '../components/ui'
import { useRecurso } from '../components/useRecurso'

export const vazioFuncional = { posto_graduacao_id: '', cargo: '', matricula: '', telefone: '' }
export type FormFuncional = typeof vazioFuncional
export const deFuncionais = (d: Partial<DadosFuncionais>): FormFuncional => ({
  posto_graduacao_id: d.posto_graduacao?.id ? String(d.posto_graduacao.id) : '', cargo: d.cargo ?? '', matricula: d.matricula ?? '', telefone: d.telefone ?? '',
})
export const paraEnvio = (f: FormFuncional) => ({ posto_graduacao_id: f.posto_graduacao_id ? Number(f.posto_graduacao_id) : null, cargo: f.cargo || null, matricula: f.matricula || null, telefone: f.telefone || null })

/** Posto, cargo, matrícula e telefone da conta (mesmos campos na edição pelo administrador). Posto vazio = civil. */
export function CamposFuncionais({ f, set, postos, erro }: { f: FormFuncional; set: (f: FormFuncional) => void; postos: Posto[]; erro?: ErroApi | null }) {
  const fe = (k: string) => erro?.erro.fields?.[k]
  return <>
    <Campo rotulo="Posto/graduação" dica="Vazio para quem é civil." erro={fe('posto_graduacao_id')}>{(p) => <select {...p} value={f.posto_graduacao_id} onChange={(e) => set({ ...f, posto_graduacao_id: e.target.value })}>
      <option value="">Civil / sem posto</option>{postos.map((x) => <option key={x.id} value={x.id}>{x.sigla} — {x.nome}</option>)}
    </select>}</Campo>
    <Campo rotulo="Cargo/função" dica='Ex.: "Auxiliar Administrativo".' erro={fe('cargo')}>{(p) => <input {...p} maxLength={80} value={f.cargo} onChange={(e) => set({ ...f, cargo: e.target.value })} />}</Campo>
    <Campo rotulo="Matrícula" erro={fe('matricula')}>{(p) => <input {...p} maxLength={30} value={f.matricula} onChange={(e) => set({ ...f, matricula: e.target.value })} />}</Campo>
    <Campo rotulo="Telefone" erro={fe('telefone')}>{(p) => <input {...p} type="tel" inputMode="tel" autoComplete="tel" maxLength={20} placeholder="(82) 90000-0000" value={f.telefone} onChange={(e) => set({ ...f, telefone: e.target.value })} />}</Campo>
  </>
}

type MeuPerfil = DadosFuncionais & { nome: string; login: string; postos: Posto[] }

/** A própria pessoa mantém os dados funcionais da conta; nome, CPF e papéis só o administrador altera. */
export default function MeuPerfilPage() {
  const notificar = useNotificar()
  const r = useRecurso(() => api<MeuPerfil>('/me/profile'), [])
  const [f, setF] = useState(vazioFuncional)
  const [erro, setErro] = useState<ErroApi | null>(null)
  const [salvando, setSalvando] = useState(false)
  useEffect(() => { if (r.dados) setF(deFuncionais(r.dados)) }, [r.dados])

  const salvar = async (e: FormEvent) => {
    e.preventDefault(); setErro(null); setSalvando(true)
    try { await api('/me/profile', { method: 'PUT', body: paraEnvio(f) }); notificar('ok', 'Dados atualizados.'); r.recarregar() } catch (x) { if (x instanceof ErroApi) setErro(x) } finally { setSalvando(false) }
  }
  const m = r.dados?.militar
  return (
    <Pagina icone={IdCard} titulo="Meu perfil" trilha={[{ rotulo: 'Início', para: '/' }, { rotulo: 'Meu perfil' }]} descricao="Nome e CPF só o administrador altera.">
      {r.carregando && <Carregando />}{r.erro && <Erro erro={r.erro} tentar={r.recarregar} />}
      {r.dados && <form onSubmit={salvar} noValidate className="cartao form-perfil">
        <p><strong>{r.dados.nome}</strong><br /><small className="mono">CPF {exibirLogin(r.dados.login)}</small></p>
        {m && <p className="dica">Efetivo vinculado: {m.posto} {m.nome_guerra}{m.matricula ? ` · matrícula ${m.matricula}` : ''}{m.telefone ? ` · ${m.telefone}` : ''} (referência; os campos abaixo são da conta).</p>}
        {erro && !erro.erro.fields && <div className="aviso aviso-perigo" role="alert">{erro.erro.message}</div>}
        <div className="grade"><CamposFuncionais f={f} set={setF} postos={r.dados.postos} erro={erro} /></div>
        <div className="barra-acoes"><button className="btn btn-pri" disabled={salvando}>{salvando ? 'Salvando…' : 'Salvar'}</button></div>
      </form>}
    </Pagina>
  )
}
