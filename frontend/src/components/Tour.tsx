import { useCallback, useEffect, useLayoutEffect, useRef, useState } from 'react'
import { createPortal } from 'react-dom'
import { useLocation } from 'react-router'
import { HelpCircle, X } from 'lucide-react'
import { useAuth } from '../auth/AuthContext'

/** Um passo: `alvo` é um seletor CSS; sem alvo (ou alvo ausente/oculto), o balão aparece centralizado. */
export type Passo = { alvo?: string; titulo: string; texto: string }
/**
 * Tour de tela (`rota`) ou de contexto (`quando`: seletor de um diálogo/formulário que aparece depois, ex.: o modal de criar conta).
 * Cada um aparece uma única vez por usuário. Regra para não cansar: no máximo 4 passos, frases curtas, só o que não é óbvio.
 */
export type Roteiro = { id: string; rota?: RegExp; quando?: string; passos: Passo[] }

const TOPO = '.pagina-topo .acoes'
const PRI = '.pagina-topo .acoes .btn-pri'
const FILTROS = '.filtros'
const LISTA = '.tabela-wrap'
const BARRA = '.barra-acoes .btn-pri'
/** Diálogo aberto pelo `data-tour` (ver `ancora` em ui.tsx) e campo dentro dele pelo rótulo. */
const dlg = (t: string) => `dialog[open][data-tour="${t}"]`
const campo = (t: string, d?: string) => `${d ? dlg(d) + ' ' : ''}[data-tour="${t}"]`
const CONFIRMA = (d: string) => `${dlg(d)} .dialogo-rodape .btn-pri`

export const ROTEIROS: Roteiro[] = [
  // ——— Telas ———
  { id: 'inicio', rota: /^\/$/, passos: [
    { titulo: 'Bem-vindo(a) ao SIGARB', texto: 'Dicas rápidas aparecem na primeira vez que você abre cada tela ou formulário. Para rever, use o botão "Tour" no topo.' },
    { alvo: '.lateral', titulo: 'Menu', texto: 'Só aparece o que o seu perfil pode usar. Os números indicam pendências.' },
    { alvo: '.topo-papeis', titulo: 'Seus perfis', texto: 'Seus papéis e a unidade em que valem. Eles definem o que você vê e pode fazer.' },
    { alvo: '.cartoes', titulo: 'Painel', texto: 'Indicadores só do seu escopo. Abaixo ficam as últimas movimentações e alertas.' },
  ] },
  { id: 'estoque', rota: /^\/estoque$/, passos: [
    { alvo: FILTROS, titulo: 'Busca', texto: 'Procure por material, código ou lote.' },
    { alvo: LISTA, titulo: 'Saldos', texto: '"Reservada" está separada para uma operação; só o "Disponível" pode sair.' },
    { alvo: PRI, titulo: 'Registrar entrada', texto: 'Material que chega à reserva entra por aqui, sempre com documento de referência.' },
  ] },
  { id: 'itens', rota: /^\/estoque\/itens$/, passos: [
    { alvo: FILTROS, titulo: 'Itens individuais', texto: 'Cada arma ou equipamento controlado por identificador ou número de série.' },
    { alvo: LISTA, titulo: 'Situação e conservação', texto: 'A situação muda pelas operações. A conservação (estado físico) pode ser atualizada na coluna Ações.' },
  ] },
  { id: 'vinculados', rota: /^\/estoque\/vinculados$/, passos: [
    { alvo: FILTROS, titulo: 'Material com militares', texto: 'Cautelas ativas e entregas com retorno ainda não devolvidas. Filtre por custódia, perfil ou tipo.' },
  ] },
  { id: 'movimentacoes', rota: /^\/movimentacoes$/, passos: [
    { alvo: LISTA, titulo: 'Histórico', texto: 'Toda entrada, saída, transferência e estorno. Abra uma linha para ver os itens e documentos.' },
    { alvo: PRI, titulo: 'Registrar entrada', texto: 'Para entradas. As demais operações ficam em "Operações".' },
  ] },
  { id: 'movimentacao', rota: /^\/movimentacoes\/\d+$/, passos: [
    { alvo: '.pagina-topo .acoes .btn-perigo', titulo: 'Estornar', texto: 'Desfaz a movimentação gerando uma movimentação inversa. Nada é apagado.' },
    { alvo: '#docs-mov', titulo: 'Documentos', texto: 'Comprovantes ligados a esta movimentação.' },
  ] },
  { id: 'nova-entrada', rota: /^\/movimentacoes\/nova-entrada$/, passos: [
    { alvo: campo('local-de-destino'), titulo: 'Para onde vai', texto: 'O local de estoque que recebe o material.' },
    { alvo: campo('documento-de-referencia'), titulo: 'Documento de referência', texto: 'Obrigatório: o número da nota, guia ou ofício que comprova a entrada.' },
    { alvo: '.linha-item[aria-label="Item 1"]', titulo: 'Itens', texto: 'Material individual pede identificador patrimonial; material por lote pede código do lote e quantidade.' },
    { alvo: BARRA, titulo: 'Revisar', texto: 'Você confere tudo antes de gravar. Só depois de confirmar a entrada é registrada.' },
  ] },
  { id: 'operacoes', rota: /^\/operacoes$/, passos: [
    { alvo: '.grade-ops', titulo: 'Situação das operações', texto: 'Habilitada funciona normalmente; provisória funciona com regra temporária; bloqueada aguarda decisão institucional.' },
    { alvo: '#pend-t', titulo: 'Pendências', texto: 'Entregas com retorno e operações provisórias que ainda precisam ser concluídas.' },
    { alvo: PRI, titulo: 'Nova operação', texto: 'Saída, transferência, reserva, entrega, baixa, manutenção.' },
  ] },
  { id: 'nova-operacao', rota: /^\/operacoes\/nova$/, passos: [
    { alvo: campo('operacao'), titulo: 'Tipo de operação', texto: 'Só aparecem as permitidas ao seu perfil. Os campos abaixo mudam conforme o tipo.' },
    { alvo: campo('local-de-origem'), titulo: 'Origem', texto: 'Escolha o local; a lista de itens disponíveis aparece em seguida.' },
    { alvo: '.bloco .tabela-wrap', titulo: 'Itens', texto: 'Marque os itens individuais ou informe a quantidade dos lotes (até o disponível).' },
    { alvo: BARRA, titulo: 'Revisar', texto: 'Confira o resumo antes de confirmar.' },
  ] },
  { id: 'acautelamentos', rota: /^\/acautelamentos$/, passos: [
    { alvo: PRI, titulo: 'Nova solicitação', texto: 'A cautela passa por: solicitação → aprovação → entrega → devolução.' },
    { alvo: LISTA, titulo: 'Acompanhar', texto: 'Abra uma cautela para aprovar, entregar, inspecionar ou receber de volta.' },
  ] },
  { id: 'novo-acautelamento', rota: /^\/acautelamentos\/novo$/, passos: [
    { alvo: campo('efetivo'), titulo: 'Militar', texto: 'Quem vai receber a arma. Se não estiver na lista, cadastre pelo atalho ao lado.' },
    { alvo: campo('item'), titulo: 'Item', texto: 'Só aparecem itens individuais disponíveis no seu escopo.' },
    { alvo: '.bloco .btn-pri', titulo: 'Solicitar', texto: 'A solicitação ainda precisa ser aprovada antes da entrega.' },
  ] },
  { id: 'acautelamento', rota: /^\/acautelamentos\/[^/]+$/, passos: [
    { alvo: 'ol.fluxo', titulo: 'Etapas', texto: 'Onde a cautela está agora. As etapas concluídas ficam marcadas.' },
    { alvo: '.barra-acoes', titulo: 'Próximo passo', texto: 'Só aparecem as ações que você pode fazer nesta etapa.' },
  ] },
  { id: 'inventarios', rota: /^\/inventarios$/, passos: [
    { alvo: PRI, titulo: 'Abrir inventário', texto: 'Conferência física de um local. O saldo do momento vira a quantidade esperada.' },
    { alvo: LISTA, titulo: 'Inventários', texto: 'Abra um para contar, enviar ou decidir o ajuste.' },
  ] },
  { id: 'inventario', rota: /^\/inventarios\/[^/]+$/, passos: [
    { alvo: 'input.qtd', titulo: 'Contagem', texto: 'Digite o que foi contado fisicamente. A diferença é calculada na hora.' },
    { alvo: '.barra-acoes', titulo: 'Salvar e enviar', texto: 'Salve a contagem; envie quando todas as linhas estiverem contadas. O ajuste de estoque precisa de aprovação.' },
  ] },
  { id: 'alertas', rota: /^\/alertas$/, passos: [
    { alvo: '.lista-alertas', titulo: 'Alertas', texto: 'Validades vencendo, inspeções atrasadas e outras situações que pedem ação.' },
  ] },
  { id: 'relatorios', rota: /^\/relatorios$/, passos: [
    { alvo: FILTROS, titulo: 'Período', texto: 'Vale para o relatório de movimentações.' },
    { alvo: '.grade-ops', titulo: 'Relatórios', texto: 'Cada exportação fica registrada na auditoria.' },
  ] },
  { id: 'dados', rota: /^\/dados$/, passos: [
    { alvo: '#imp-t', titulo: 'Importar', texto: '1) escolha o cadastro, 2) baixe o modelo, 3) preencha e envie.' },
    { alvo: 'section[aria-labelledby=imp-t] .btn-pri', titulo: 'Verificar primeiro', texto: 'O sistema confere a planilha inteira sem gravar. Se uma linha tiver erro, nada é gravado.' },
    { alvo: '#exp-t', titulo: 'Exportar', texto: 'Excel ou CSV, só com o que você pode ver.' },
  ] },
  { id: 'documentos', rota: /^\/documentos$/, passos: [
    { alvo: FILTROS, titulo: 'Documentos', texto: 'Notas, termos e ofícios que comprovam movimentações e cautelas.' },
    { alvo: PRI, titulo: 'Enviar', texto: 'Envie um PDF ou imagem e vincule ao que ele comprova.' },
  ] },
  { id: 'documento-enviar', rota: /^\/documentos\/enviar$/, passos: [
    { alvo: campo('unidade-responsavel'), titulo: 'Unidade responsável', texto: 'Define quem poderá ver o documento.' },
    { alvo: campo('arquivo'), titulo: 'Arquivo', texto: 'PDF, PNG ou JPG até 10 MB. PDF com scripts ou anexos é recusado.' },
  ] },
  { id: 'militares', rota: /^\/militares$/, passos: [
    { alvo: FILTROS, titulo: 'Busca', texto: 'Por nome, nome de guerra ou matrícula.' },
    { alvo: PRI, titulo: 'Cadastrar efetivo', texto: 'Cadastre o militar antes de cautelar ou entregar material a ele.' },
  ] },
  { id: 'militar-form', rota: /^\/militares\/(novo|\d+\/editar)$/, passos: [
    { alvo: campo('matricula'), titulo: 'Matrícula', texto: 'Letras maiúsculas, números e hífen (4 a 30).' },
    { alvo: campo('observacoes-administrativas'), titulo: 'Observações', texto: 'Só informação administrativa. Não registre CPF, saúde ou endereço.' },
  ] },
  { id: 'militar', rota: /^\/militares\/\d+$/, passos: [
    { alvo: '#pf-acaut', titulo: 'Armamento acautelado', texto: 'O que está com o militar agora.' },
    { alvo: '#pf-unidades', titulo: 'Unidades secundárias', texto: 'Para quem também atua em outra unidade.' },
  ] },
  { id: 'materiais', rota: /^\/materiais$/, passos: [
    { alvo: FILTROS, titulo: 'Catálogo', texto: 'O que pode existir no estoque. Cadastrar aqui não cria saldo.' },
    { alvo: PRI, titulo: 'Cadastrar material', texto: 'Depois do cadastro, o material entra no estoque por "Registrar entrada".' },
  ] },
  { id: 'organizacao', rota: /^\/organizacao$/, passos: [
    { alvo: TOPO, titulo: 'Estrutura', texto: 'Unidades e seus locais de estoque (reserva ou depósito). O escopo dos perfis segue essa árvore.' },
  ] },
  { id: 'usuarios', rota: /^\/usuarios$/, passos: [
    { alvo: PRI, titulo: 'Criar conta', texto: 'Crie a conta de acesso; depois atribua os papéis.' },
    { alvo: LISTA, titulo: 'Contas', texto: 'Em cada linha: papéis (o que a pessoa pode fazer e onde) e desativar.' },
  ] },
  { id: 'configuracoes', rota: /^\/configuracoes$/, passos: [
    { alvo: '.config-item', titulo: 'Ajustes', texto: 'Ligue/desligue operações e ajuste prazos. Desligar só bloqueia; não libera o que está bloqueado.' },
    { alvo: '.config-grupo', titulo: 'Motivo obrigatório', texto: 'Toda mudança pede um motivo e fica na auditoria. "Voltar ao padrão" desfaz.' },
  ] },
  { id: 'referencias', rota: /^\/referencias$/, passos: [
    { alvo: '.abas', titulo: 'Listas', texto: 'Categorias, tipos, fabricantes, calibres e outras opções usadas nos cadastros.' },
    { alvo: PRI, titulo: 'Criar valor', texto: 'Valores em uso não são apagados, só desativados.' },
  ] },
  { id: 'auditoria', rota: /^\/auditoria$/, passos: [
    { alvo: FILTROS, titulo: 'Auditoria', texto: 'Registro inalterável de tudo o que foi feito. Filtre por ação, entidade ou período; clique num evento para os detalhes.' },
  ] },
  { id: 'suporte', rota: /^\/suporte$/, passos: [
    { alvo: PRI, titulo: 'Novo chamado', texto: 'Algo não funcionou ou ficou com dúvida? Abra um chamado e acompanhe as respostas aqui.' },
  ] },
  { id: 'chamado-novo', rota: /^\/suporte\/novo$/, passos: [
    { alvo: campo('prioridade'), titulo: 'Prioridade', texto: 'Use "Crítica" só quando o trabalho da unidade inteira estiver parado.' },
    { alvo: campo('assunto'), titulo: 'Assunto', texto: 'Uma frase que resume o problema.' },
  ] },
  { id: 'chamado', rota: /^\/suporte\/chamados\/[^/]+$/, passos: [
    { alvo: 'form.compositor', titulo: 'Responder', texto: 'Escreva aqui para continuar a conversa.' },
  ] },
  { id: 'atendimento', rota: /^\/suporte\/atendimento$/, passos: [
    { alvo: '.cartoes', titulo: 'Fila', texto: 'Resumo dos chamados abertos.' },
    { alvo: FILTROS, titulo: 'Filtros', texto: 'Por situação, prioridade, categoria ou responsável.' },
  ] },
  { id: 'sessoes', rota: /^\/sessoes$/, passos: [
    { alvo: LISTA, titulo: 'Sessões', texto: 'Onde sua conta está conectada. Encerre a que você não reconhecer.' },
  ] },

  // ——— Diálogos e formulários (aparecem quando abertos) ———
  { id: 'd-criar-conta', quando: dlg('criar-conta-de-acesso'), passos: [
    { alvo: campo('identificador-login', 'criar-conta-de-acesso'), titulo: 'Login', texto: 'Letras minúsculas, números e ponto (ex.: joao.silva). É o que a pessoa digita para entrar.' },
    { alvo: campo('senha-inicial', 'criar-conta-de-acesso'), titulo: 'Senha inicial', texto: 'Mínimo de 12 caracteres, com letras e números. Entregue à pessoa de forma reservada.' },
    { alvo: CONFIRMA('criar-conta-de-acesso'), titulo: 'Depois de criar', texto: 'A conta nasce sem acesso: atribua os papéis pelo botão "Papéis" na lista.' },
  ] },
  { id: 'd-editar-conta', quando: dlg('editar-conta'), passos: [
    { alvo: campo('identificador-login', 'editar-conta'), titulo: 'Login', texto: 'Pode ser corrigido; a pessoa passa a entrar com o novo.' },
    { alvo: campo('nova-senha', 'editar-conta'), titulo: 'Nova senha', texto: 'Só preencha para redefinir. A pessoa é desconectada de todos os aparelhos.' },
  ] },
  { id: 'd-papeis', quando: dlg('papeis'), passos: [
    { alvo: campo('papel', 'papeis'), titulo: 'Papel', texto: 'Define o que a pessoa pode fazer.' },
    { alvo: campo('escopo-unidade', 'papeis'), titulo: 'Escopo', texto: 'Em qual unidade o papel vale. Vazio = todas (global).' },
    { alvo: campo('valido-ate', 'papeis'), titulo: 'Válido até', texto: 'Para substituição temporária. Vazio = sem prazo.' },
  ] },
  { id: 'd-material', quando: dlg('cadastrar-material'), passos: [
    { alvo: campo('tipo-de-material', 'cadastrar-material'), titulo: 'Tipo de material', texto: 'O tipo define quais campos aparecem e são obrigatórios (ex.: ficha técnica para armas).' },
    { alvo: `${dlg('cadastrar-material')} input[name=modo]`, titulo: 'Modo de controle', texto: 'Individual: cada unidade tem identificador/número de série. Lote: controle por quantidade.' },
    { alvo: CONFIRMA('cadastrar-material'), titulo: 'Salvar', texto: 'Isso só cadastra no catálogo; o saldo entra por "Registrar entrada".' },
  ] },
  { id: 'd-local', quando: dlg('cadastrar-local-de-estoque'), passos: [
    { alvo: campo('tipo', 'cadastrar-local-de-estoque'), titulo: 'Tipo', texto: 'Reserva ou depósito. O local fica ligado à unidade escolhida e entra no escopo de quem tem papel nela.' },
  ] },
  { id: 'd-unidade', quando: dlg('cadastrar-unidade'), passos: [
    { alvo: campo('subordinada-a', 'cadastrar-unidade'), titulo: 'Subordinada a', texto: 'A unidade acima desta. Vazio = unidade de topo (exige perfil global).' },
  ] },
  { id: 'd-inventario', quando: dlg('abrir-inventario'), passos: [
    { alvo: campo('local-de-estoque', 'abrir-inventario'), titulo: 'Local', texto: 'O saldo atual do local vira a quantidade esperada da contagem.' },
  ] },
  { id: 'd-confirmar-entrada', quando: dlg('confirmar-entrada'), passos: [
    { alvo: `${dlg('confirmar-entrada')} .dialogo-corpo`, titulo: 'Revise', texto: 'Confira local, documento e itens. Depois de gravada, a entrada só é desfeita por estorno.' },
  ] },
  { id: 'd-confirmar-operacao', quando: dlg('confirmar-operacao'), passos: [
    { alvo: `${dlg('confirmar-operacao')} .dialogo-corpo`, titulo: 'Revise', texto: 'Confira antes de confirmar. Depois de gravada, a operação só é desfeita por estorno.' },
  ] },
  { id: 'd-estorno', quando: dlg('estornar-movimentacao'), passos: [
    { alvo: campo('justificativa', 'estornar-movimentacao'), titulo: 'Justificativa', texto: 'Mínimo de 10 caracteres. Fica na auditoria.' },
  ] },
  { id: 'd-conservacao', quando: dlg('conservacao'), passos: [
    { alvo: campo('estado-de-conservacao', 'conservacao'), titulo: 'Conservação', texto: 'Estado físico do item. A situação (disponível, cautelado…) não muda aqui.' },
    { alvo: campo('justificativa', 'conservacao'), titulo: 'Justificativa', texto: 'Mínimo de 10 caracteres. Fica na auditoria.' },
  ] },
  { id: 'd-devolucao', quando: dlg('devolucao'), passos: [
    { alvo: `${dlg('devolucao')} .dialogo-corpo`, titulo: 'Devolução', texto: 'Marque o que voltou. Se faltar algo, a devolução fica parcial e a pendência continua.' },
  ] },
  { id: 'd-acautelamento', quando: dlg('acao-acautelamento'), passos: [
    { alvo: `${dlg('acao-acautelamento')} .dialogo-corpo`, titulo: 'Confirmar etapa', texto: 'Quando houver campo de texto, ele é registrado na auditoria junto com a etapa.' },
  ] },
  { id: 'd-ajuste', quando: dlg('ajuste'), passos: [
    { alvo: `${dlg('ajuste')} .dialogo-corpo`, titulo: 'Motivo', texto: 'Explique a mudança (mínimo 5 caracteres). Fica na auditoria com o valor anterior e o novo.' },
  ] },
  { id: 'd-referencia', quando: dlg('valor-referencia'), passos: [
    { alvo: campo('codigo', 'valor-referencia'), titulo: 'Código', texto: 'Maiúsculas, "_" e "-". Não pode mudar depois que o valor estiver em uso.' },
  ] },
  { id: 'd-vincular', quando: dlg('vincular-a-movimentacao'), passos: [
    { alvo: `${dlg('vincular-a-movimentacao')} .dialogo-corpo`, titulo: 'Atenção', texto: 'O vínculo entre documento e movimentação não pode ser desfeito.' },
  ] },
]

const chave = (uid: number, id: string) => `sigarb.tour.${uid}.${id}`
// shortcut: "já visto" fica no navegador (localStorage); em outro computador as dicas reaparecem uma vez. Se incomodar, gravar no servidor.
const visto = (k: string) => { try { return localStorage.getItem(k) === '1' } catch { return true } }
const marcar = (k: string) => { try { localStorage.setItem(k, '1') } catch { /* sem armazenamento: só não lembra */ } }

/** Elemento visível do seletor, ou null se não existe/está oculto. */
function caixa(sel?: string) {
  const el = sel ? document.querySelector(sel) : null
  if (!el) return null
  const r = el.getBoundingClientRect()
  return r.width && r.height ? el : null
}
/** Diálogo modal aberto (o resto da página fica inerte: o balão precisa ser renderizado dentro dele). */
const dialogoAberto = () => document.querySelector<HTMLDialogElement>('dialog[open]')

/** Mostra cada roteiro uma vez por usuário: o da tela ao entrar, o de um diálogo/formulário quando ele abre. */
export function Tour() {
  const { pathname } = useLocation()
  const { perfil } = useAuth()
  const uid = perfil?.usuario.id
  const [ativo, setAtivo] = useState<{ r: Roteiro; passos: Passo[] } | null>(null)
  const [i, setI] = useState(0)
  const [dialogo, setDialogo] = useState<HTMLDialogElement | null>(null)
  const daTela = ROTEIROS.find((r) => r.rota?.test(pathname) && !r.quando)
  const doDialogo = dialogo && ROTEIROS.find((r) => r.quando && dialogo.matches(r.quando))

  const abrir = useCallback((r: Roteiro) => {
    const passos = r.passos.filter((p) => !p.alvo || caixa(p.alvo))
    if (passos.length) { setI(0); setAtivo({ r, passos }) }
    return passos.length > 0
  }, [])
  const fechar = useCallback(() => { setAtivo((a) => { if (a && uid) marcar(chave(uid, a.r.id)); return null }) }, [uid])

  const atual = useRef(ativo); atual.current = ativo
  // Observa a tela: diálogos abrindo/fechando e conteúdo carregando.
  useEffect(() => {
    if (!uid) return
    let t: ReturnType<typeof setTimeout>
    const avaliar = () => {
      const d = dialogoAberto()
      setDialogo(d)
      const a = atual.current
      // Balão de outro contexto (ex.: tour da tela quando um diálogo abriu) ficaria inerte: encerra.
      if (a && (a.r.quando ? !d?.matches(a.r.quando) : !!d)) { marcar(chave(uid, a.r.id)); atual.current = null; setAtivo(null) }
      if (atual.current || visto(chave(uid, 'desligado'))) return
      const r = ROTEIROS.find((x) => d ? x.quando && d.matches(x.quando) : !x.quando && x.rota?.test(pathname))
      if (r && !visto(chave(uid, r.id))) abrir(r)
    }
    const agendar = () => { clearTimeout(t); t = setTimeout(avaliar, 600) } // espera a tela/diálogo terminar de renderizar
    const obs = new MutationObserver(agendar)
    obs.observe(document.body, { childList: true, subtree: true, attributes: true, attributeFilter: ['open'] })
    agendar()
    return () => { clearTimeout(t); obs.disconnect() }
  }, [uid, pathname, abrir])

  const desligar = () => { if (uid) marcar(chave(uid, 'desligado')); fechar() }

  return <>
    {daTela && <button type="button" className="btn btn-sec tour-rever" onClick={() => abrir(daTela)}><HelpCircle size={18} aria-hidden="true" /><span className="rotulo-largo">Tour</span><span className="sr-only"> desta página</span></button>}
    {dialogo && doDialogo && !ativo && createPortal(<button type="button" className="btn btn-sec btn-icone tour-dica" onClick={() => abrir(doDialogo)} aria-label="Dicas deste formulário" title="Dicas deste formulário"><HelpCircle size={18} aria-hidden="true" /></button>, dialogo)}
    {ativo && createPortal(<Balao passo={ativo.passos[i]} n={i} total={ativo.passos.length} voltar={() => setI(i - 1)} avancar={() => i + 1 < ativo.passos.length ? setI(i + 1) : fechar()} fechar={fechar} desligar={desligar} />, dialogo ?? document.body)}
  </>
}

function Balao({ passo, n, total, voltar, avancar, fechar, desligar }: { passo: Passo; n: number; total: number; voltar: () => void; avancar: () => void; fechar: () => void; desligar: () => void }) {
  const [r, setR] = useState<DOMRect | null>(null)
  const prox = useRef<HTMLButtonElement>(null)

  useLayoutEffect(() => {
    const el = caixa(passo.alvo)
    if (!el) { setR(null); return }
    el.scrollIntoView({ block: 'center', behavior: 'instant' as ScrollBehavior })
    const medir = () => setR(el.getBoundingClientRect())
    medir()
    window.addEventListener('resize', medir); window.addEventListener('scroll', medir, true)
    return () => { window.removeEventListener('resize', medir); window.removeEventListener('scroll', medir, true) }
  }, [passo])

  useEffect(() => { prox.current?.focus() }, [passo])
  useEffect(() => {
    // Captura: Esc fecha só o balão, sem fechar o diálogo por baixo.
    const tecla = (e: KeyboardEvent) => { if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); fechar() } }
    window.addEventListener('keydown', tecla, true); return () => window.removeEventListener('keydown', tecla, true)
  }, [fechar])

  // Balão abaixo do alvo se couber, senão acima, senão ao lado (alvo alto, ex.: menu); centralizado sem alvo.
  const vw = window.innerWidth, vh = window.innerHeight, L = Math.min(360, vw - 32), H = 240
  const preso = (v: number, max: number) => Math.max(16, Math.min(v, max))
  const estilo: React.CSSProperties = !r ? { width: L, left: '50%', top: '50%', transform: 'translate(-50%, -50%)' }
    : r.bottom + 12 + H < vh ? { width: L, left: preso(r.left, vw - L - 16), top: r.bottom + 12 }
    : r.top - 12 - H > 0 ? { width: L, left: preso(r.left, vw - L - 16), bottom: vh - r.top + 12 }
    : { width: L, left: preso(r.right + 12, vw - L - 16), top: preso(r.top, vh - H - 16) }

  return <>
    {r ? <div className="tour-foco" aria-hidden="true" style={{ top: r.top - 6, left: r.left - 6, width: r.width + 12, height: r.height + 12 }} />
      : <div className="tour-fundo" aria-hidden="true" />}
    <div className="tour-balao" role="dialog" aria-modal="false" aria-labelledby="tour-t" aria-describedby="tour-d" style={estilo}>
      <button type="button" className="btn btn-sec btn-icone tour-x" aria-label="Pular dicas" onClick={fechar}><X size={18} aria-hidden="true" /></button>
      {total > 1 && <p className="tour-passo">{n + 1} de {total}</p>}
      <h2 id="tour-t">{passo.titulo}</h2>
      <p id="tour-d">{passo.texto}</p>
      <div className="acoes">
        {n > 0 ? <button type="button" className="btn btn-sec" onClick={voltar}>Voltar</button> : total > 1 && <button type="button" className="btn btn-sec" onClick={fechar}>Pular</button>}
        <button ref={prox} type="button" className="btn btn-pri" onClick={avancar}>{n + 1 < total ? 'Próximo' : 'Entendi'}</button>
      </div>
      {n === 0 && <button type="button" className="link tour-desligar" onClick={desligar}>Não mostrar dicas automáticas</button>}
    </div>
  </>
}
