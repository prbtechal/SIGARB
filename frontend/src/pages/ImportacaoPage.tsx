import { useState } from 'react'
import { Download, FileSpreadsheet, Upload } from 'lucide-react'
import { ErroApi, api, enviarArquivo } from '../api/client'
import { Aviso, Campo, Carregando, Erro, Pagina, Tabela, Vazio, useNotificar } from '../components/ui'
import { useRecurso } from '../components/useRecurso'

type Entidade = { entidade: string; rotulo: string; colunas: string[]; pode_importar: boolean; pode_exportar: boolean }
type Resultado = { entidade: string; simulacao: boolean; total: number; validas: number; gravadas: number; erros: { linha: number; campo: string | null; mensagem: string }[] }

/** CP-13 — importação (modelo → verificação → confirmação, tudo ou nada) e exportação de cadastros em CSV ou Excel (.xlsx). */
export default function ImportacaoPage() {
  const notificar = useNotificar()
  const lista = useRecurso(() => api<{ data: Entidade[]; max_linhas: number }>('/data-transfer'), [])
  const importaveis = lista.dados?.data.filter((e) => e.pode_importar) ?? []
  const [entidade, setEntidade] = useState('')
  const [arquivo, setArquivo] = useState<File | null>(null)
  const [res, setRes] = useState<Resultado | null>(null)
  const [falha, setFalha] = useState<string | null>(null)
  const [enviando, setEnviando] = useState(false)
  const atual = importaveis.find((e) => e.entidade === (entidade || importaveis[0]?.entidade))

  const enviar = async (simulacao: boolean) => {
    if (!atual || !arquivo) return
    setEnviando(true); setFalha(null)
    const f = new FormData(); f.append('arquivo', arquivo); f.append('simulacao', simulacao ? '1' : '0')
    try {
      const r = await enviarArquivo<Resultado>(`/data-transfer/${atual.entidade}/import`, f)
      setRes(r)
      if (!simulacao && !r.erros.length) notificar('ok', `${r.gravadas} registro(s) importado(s) em ${atual.rotulo}.`)
    } catch (e) {
      setRes(null)
      setFalha(e instanceof ErroApi ? e.erro.message : 'Não foi possível enviar a planilha.')
    } finally { setEnviando(false) }
  }
  const trocar = (fn: () => void) => { fn(); setRes(null); setFalha(null) }

  return (
    <Pagina icone={FileSpreadsheet} titulo="Importar e exportar" trilha={[{ rotulo: 'Início', para: '/' }, { rotulo: 'Importar e exportar' }]}
      descricao="Cadastre em lote por planilha ou baixe os dados que você já pode ver. O cadastro manual pelas telas continua disponível.">
      {lista.carregando && <Carregando />}{lista.erro && <Erro erro={lista.erro} tentar={lista.recarregar} />}
      {lista.dados && !lista.dados.data.length && <Vazio titulo="Seu perfil não importa nem exporta cadastros." />}

      {importaveis.length > 0 && atual && <section aria-labelledby="imp-t" className="cartao">
        <h2 id="imp-t">Importar planilha</h2>
        <Aviso tipo="info">Preencha o modelo e envie o próprio arquivo <strong>.xlsx</strong> do Excel, ou salve como <strong>CSV UTF-8</strong>. Primeiro o sistema verifica a planilha inteira; só depois você confirma.
          Se qualquer linha tiver erro, nada é gravado. Máximo de {lista.dados?.max_linhas} linhas por arquivo.</Aviso>
        <div className="grade">
          <Campo rotulo="Cadastro" obrigatorio>{(p) => <select {...p} value={atual.entidade} onChange={(e) => trocar(() => setEntidade(e.target.value))}>
            {importaveis.map((e) => <option key={e.entidade} value={e.entidade}>{e.rotulo}</option>)}</select>}</Campo>
          <Campo rotulo="Planilha (.xlsx ou .csv)" obrigatorio dica="Só a primeira aba é lida. No CSV, separador ; ou ,. A primeira linha é o cabeçalho do modelo.">{(p) =>
            <input {...p} type="file" accept=".csv,text/csv,.xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" onChange={(e) => trocar(() => setArquivo(e.target.files?.[0] ?? null))} />}</Campo>
        </div>
        <p>Colunas aceitas (<span aria-hidden="true">*</span> obrigatória):</p>
        <ul className="lista-colunas">{atual.colunas.map((c) => <li key={c}><code>{c.replace('*', '')}</code>{c.endsWith('*') && <><span aria-hidden="true"> *</span><span className="sr-only"> (obrigatória)</span></>}</li>)}</ul>
        <div className="acoes">
          <a className="btn btn-sec" href={`${import.meta.env.BASE_URL}api/v1/data-transfer/${atual.entidade}/template?formato=xlsx`} download><Download size={18} aria-hidden="true" />Modelo Excel (.xlsx)<span className="sr-only"> de {atual.rotulo}</span></a>
          <a className="btn btn-sec" href={`${import.meta.env.BASE_URL}api/v1/data-transfer/${atual.entidade}/template`} download><Download size={18} aria-hidden="true" />Modelo CSV<span className="sr-only"> de {atual.rotulo}</span></a>
          <button type="button" className="btn btn-pri" disabled={!arquivo || enviando} onClick={() => enviar(true)}><Upload size={18} aria-hidden="true" />{enviando ? 'Verificando…' : 'Verificar planilha'}</button>
        </div>

        <div aria-live="polite">
          {falha && <Aviso tipo="perigo" titulo="Planilha recusada">{falha}</Aviso>}
          {res && res.erros.length > 0 && <>
            <Aviso tipo="perigo" titulo={`${res.erros.length} problema(s) em ${new Set(res.erros.map((e) => e.linha)).size} linha(s)`}>Nada foi gravado. Corrija a planilha e verifique de novo.</Aviso>
            <Tabela legenda="Problemas encontrados na planilha" linhas={res.erros} chave={(e) => `${e.linha}-${e.campo}-${e.mensagem}`} colunas={[
              { titulo: 'Linha', celula: (e) => e.linha, num: true },
              { titulo: 'Coluna', celula: (e) => e.campo ? <code>{e.campo}</code> : '—' },
              { titulo: 'Problema', celula: (e) => e.mensagem },
            ]} />
          </>}
          {res && !res.erros.length && res.simulacao && <Aviso tipo="ok" titulo={`${res.validas} linha(s) prontas para importar`}>
            Nenhum problema encontrado. Confirme para gravar.
            <div className="acoes"><button type="button" className="btn btn-pri" disabled={enviando} onClick={() => enviar(false)}>{enviando ? 'Importando…' : `Importar ${res.validas} registro(s)`}</button></div>
          </Aviso>}
          {res && !res.erros.length && !res.simulacao && <Aviso tipo="ok" titulo="Importação concluída">{res.gravadas} registro(s) gravado(s) e registrados na auditoria.</Aviso>}
        </div>
      </section>}

      {lista.dados && lista.dados.data.some((e) => e.pode_exportar) && <section aria-labelledby="exp-t">
        <h2 id="exp-t">Exportar</h2>
        <p className="dica">Planilha do Excel (.xlsx) ou CSV, no mesmo formato do modelo. Só sai o que você já pode ver; cada exportação fica na auditoria.</p>
        <ul className="grade-ops">{lista.dados.data.filter((e) => e.pode_exportar).map((e) => (
          <li key={e.entidade} className="op"><h3>{e.rotulo}</h3>
            <a className="btn btn-sec" href={`${import.meta.env.BASE_URL}api/v1/data-transfer/${e.entidade}/export?formato=xlsx`} download><Download size={18} aria-hidden="true" />Excel<span className="sr-only"> de {e.rotulo}</span></a>
            <a className="btn btn-sec" href={`${import.meta.env.BASE_URL}api/v1/data-transfer/${e.entidade}/export`} download><Download size={18} aria-hidden="true" />CSV<span className="sr-only"> de {e.rotulo}</span></a></li>
        ))}</ul>
      </section>}
    </Pagina>
  )
}
