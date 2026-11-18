export type Operacao = { codigo: string; estado: 'HABILITADA' | 'PROVISORIA' | 'BLOQUEADA'; pendencia: string; rotulo: string; regra_provisoria?: string }
export type Perfil = {
  usuario: { id: number; nome: string; login: string; senha_provisoria?: boolean; senha_expirada?: boolean; mfa_ativo?: boolean; mfa_obrigatorio?: boolean; mfa_cadastro_pendente?: boolean; militar: { id: number; nome_guerra: string; posto: string } | null }
  papeis: { papel: string; papel_nome: string; unidade: { id: number; sigla: string } | null }[]
  permissoes: string[]
  operacoes: Operacao[]
  ambiente: string
}
export type Ref = { id: number; codigo?: string; sigla?: string; nome?: string }
export type Unidade = { id: number; sigla: string; nome: string; tipo: string; ativo: boolean; organizacao: { id: number; sigla: string }; unidade_superior_id: number | null; nivel: number }
export type Local = { id: number; codigo: string; nome: string; tipo: 'RESERVA' | 'DEPOSITO'; ativo: boolean; unidade: { id: number; sigla: string; nome: string } }
export type Militar = { id: number; nome: string; nome_guerra: string; matricula: string; matricula_completa: boolean; situacao: string; categoria_efetivo?: string | null; apto_arma_fogo?: boolean | null; posto_graduacao: { id: number; sigla: string }; sigla_exibicao?: string | null; rotulo_curto?: string; posto_a_atualizar?: boolean; unidade: { id: number; sigla: string }; pode_editar: boolean; usuario?: { id: number; login: string } | null; foto_versao?: number | null; cpf?: string | null; origem?: 'FUNCIONARIOS' | 'LOCAL'; tem_acesso?: boolean
  quadro?: string | null; funcao?: string | null; telefone_institucional?: string | null; email_institucional?: string | null; observacoes_administrativas?: string | null }
export type Material = { id: number; codigo: string; nome: string; modelo: string | null; modo_controle: 'INDIVIDUAL' | 'LOTE'; unidade_medida: string; ativo: boolean; categoria: { id: number; nome: string; natureza: string }; fabricante: Ref | null; calibre: Ref | null; possui_estoque?: boolean; ficha_tecnica?: Record<string, string | number> | null
  tipo?: { id: number; nome: string; perfil: PerfilTipo } | null; marca?: string | null; compatibilidade?: string | null; cor?: string | null; tamanho?: string | null; series?: string[]; total_itens?: number }
export type PerfilTipo = 'GERAL' | 'ARMA_FOGO' | 'BAIXA_LETALIDADE' | 'COLETE' | 'MUNICAO' | 'ACESSORIO'
export type RegraPerfil = { rotulo: string; modo: 'INDIVIDUAL' | 'LOTE' | null; material_obrig: string[]; material_perm: string[]; item_obrig: string[]; item_perm: string[] }
export type TipoMaterial = { id: number; categoria_id: number; nome: string; descricao: string | null; perfil: PerfilTipo }
export type CatalogoTipos = { data: TipoMaterial[]; perfis: Record<PerfilTipo, RegraPerfil>; niveis_protecao: string[] }
export type ItemIndividual = { id: number; identificador: string; numero_serie: string | null; situacao: string; estado_conservacao: string; fabricado_em: string | null; validade: string | null
  validade_estado: 'VENCIDO' | 'VENCENDO' | 'VALIDO' | null; tamanho: string | null; nivel_protecao: string | null
  material: { id: number; codigo: string; nome: string; tipo: { id: number; nome: string; perfil: PerfilTipo } | null; natureza?: string | null; natureza_rotulo?: string | null }
  local: { id: number; codigo: string; nome: string }; unidade: { id: number; sigla: string } | null; pode_atualizar_conservacao: boolean; pode_editar?: boolean; pode_baixar?: boolean }
export type Vinculado = { custodia: 'ACAUTELAMENTO' | 'ENTREGA'; origem: string; desde: string; militar: { id: number; posto: string; nome_guerra: string; categoria_efetivo?: string | null }; unidade: { id: number; sigla: string }
  local?: { id: number; codigo: string; nome: string } | null
  material: { id: number; codigo: string; nome: string; modo_controle: string; categoria: string; tipo: string | null; perfil: PerfilTipo | null; natureza?: string; natureza_rotulo?: string; unidade_medida?: string }
  item_individual_id: number | null; lote_id: number | null; referencia: string; quantidade: number; situacao: string | null; estado_conservacao: string | null; validade: string | null
  numero_serie?: string | null; lote_codigo?: string | null; entrega_id?: number | null; previsao_retorno?: string | null; atrasado?: boolean; pode_receber?: boolean }
/** Uma linha por entrega (ENTREGA) ou acautelamento, com todos os materiais ainda pendentes (decisão 2026-11-18). */
export type EntregaItem = { material: Vinculado['material']; item_individual_id: number | null; lote_id: number | null; referencia: string; numero_serie?: string | null
  lote_codigo?: string | null; quantidade: number; quantidade_entregue?: number; quantidade_devolvida?: number; situacao: string | null; situacao_na_entrega?: SituacaoNaEntrega | null; estado_conservacao: string | null; validade: string | null }
export type EntregaAgrupada = { custodia: 'ACAUTELAMENTO' | 'ENTREGA'; origem: string; desde: string; entrega_id: number | null; pedido: { codigo: string; comprovante: string } | null
  militar: { id: number; posto: string; nome_guerra: string; nome?: string; categoria_efetivo?: string | null }; unidade: { id: number; sigla: string }
  local: { id: number; codigo: string; nome: string } | null; previsao_retorno: string | null; atrasado: boolean; total_itens: number; quantidade_total: number
  conservacao: Record<string, number>; itens: EntregaItem[]; pode_receber: boolean }
export type OpcaoLista = { codigo: string; rotulo: string; descricao: string }
export type OpcoesEntrega = { documentos: OpcaoLista[]; motivos: OpcaoLista[]; local_origem_padrao_id: number | null }
export type ContagemClasse = { entregues: number; devolvidas: number }
export type RelatorioEntregas = { de: string; ate: string; agrupar: 'dia' | 'semana' | 'mes'; classes: Record<'armas' | 'municao' | 'demais', string>
  series: ({ rotulo: string; inicio: string; fim: string } & Record<'armas' | 'municao' | 'demais', ContagemClasse>)[]; totais: Record<'armas' | 'municao' | 'demais', ContagemClasse> }
export type VinculoUnidade = { id: number; unidade: { id: number; sigla: string; nome: string }; inicio: string; fim: string | null; ativo: boolean }
export type Saldo = { local: { id: number; codigo: string; nome: string }; unidade: { id: number; sigla: string }; material: { id: number; codigo: string; nome: string; modo_controle: string; categoria: string; tipo?: string | null; natureza: string; natureza_rotulo?: string; unidade_medida: string }; lote: { id: number; codigo: string; validade: string | null } | null; quantidade: number; reservada: number; disponivel: number; pode_editar?: boolean }
export type MovResumo = { id: number; tipo: string; ocorrido_em: string; responsavel: { id: number; nome: string }; local_origem: Ref | null; local_destino: Ref | null; documento_referencia: string | null; itens_count: number; estornada: boolean; estorno_id: number | null; provisoria?: boolean; vinculo_id?: number | null; conclusao_id?: number | null; militar?: { id: number; nome_guerra: string; posto?: string | null } | null; previsao_retorno?: string | null }
export type PendenteDevolucao = { itens: { item_individual_id: number; identificador: string; numero_serie?: string | null; estado_conservacao: string | null; material: { codigo: string; nome: string } }[]; lotes: { lote_id: number; codigo: string; quantidade: number; entregue?: number; devolvido?: number; situacao_na_entrega?: SituacaoNaEntrega | null; material: { codigo: string; nome: string } }[] }
export type MovDetalhe = MovResumo & { pendente_devolucao?: PendenteDevolucao | null; origem_descricao: string | null; justificativa: string | null; estorno_de_id: number | null; pode_estornar: boolean
  devolvido_por?: { militar: { id: number; nome_guerra: string; posto?: string | null } | null; nome: string | null } | null; entrega_id?: number | null
  itens: { tipo: 'INDIVIDUAL' | 'LOTE'; quantidade: number; material: Ref; estado_conservacao?: string | null; estado_anterior?: string | null
    item?: { id: number; identificador: string; numero_serie: string | null; situacao: string; estado_conservacao_atual?: string; local_id?: number }; lote?: { id: number; codigo: string; validade: string | null }; situacao_lote?: SituacaoLote | null; situacao_na_entrega?: SituacaoNaEntrega | null }[] }
/** Situação do material relativa à entrega, igual para item e lote (decisão 2026-11-18; calculada no servidor). */
export type SituacaoNaEntrega = { codigo: 'ENTREGUE' | 'DEVOLVIDO' | 'DEVOLVIDO_PARCIAL' | 'CONSUMIDO' | 'BAIXADO_APOS_DEVOLUCAO'; rotulo: string; entregue: number; devolvido: number; pendente: number; hoje?: string | null }
/** Lote não tem situação própria: entregue − devolvido da entrega (decisão 2026-11-18). */
export type SituacaoLote = { situacao: 'ENTREGUE' | 'DEVOLVIDO_PARCIAL' | 'DEVOLVIDO' | 'CONSUMIDO'; entregue: number; devolvido: number; pendente: number }
export type Posto = { id: number; sigla: string; nome: string }
/** Dados funcionais da conta (servem também para quem não é efetivo cadastrado). Posto nulo = civil. */
export type DadosFuncionais = { posto_graduacao: Posto | null; cargo: string | null; matricula: string | null; telefone: string | null
  militar: { id: number; nome_guerra: string; posto?: string | null; matricula?: string | null; telefone?: string | null } | null }
export type Usuario = DadosFuncionais & { id: number; nome: string; login: string; email: string | null; ativo: boolean; ultimo_login_em: string | null; bloqueado_em?: string | null; mfa_ativo?: boolean; militar: { id: number; nome_guerra: string } | null; atribuicoes: { id: number; papel: string; unidade: { id: number; sigla: string } | null; expira_em?: string | null; vencida?: boolean }[]; sessoes_ativas: number }
export type Evento = { id: number; ocorrido_em: string; acao: string; resultado: 'SUCESSO' | 'NEGADO' | 'FALHA'; usuario: { id: number; login: string; nome: string } | null; recurso_tipo: string | null; recurso_id: number | null; unidade_id: number | null; ip: string | null; request_id: string | null; dados: Record<string, unknown> | null; hash: string
  acao_rotulo?: string; frase?: string; recurso_rotulo?: string | null; recurso_link?: string | null; unidade_rotulo?: string | null }
export type DocResumo = { codigo: string; titulo: string; numero_referencia: string | null; tipo: { id: number; codigo: string; nome: string }; unidade: { id: number; sigla: string }; nome_original: string; mime: string; tamanho_bytes: number; situacao: 'ATIVO' | 'ARQUIVADO'; enviado_por: string; enviado_em: string }
export type DocDetalhe = DocResumo & { descricao: string | null; sha256: string; extensao: string; arquivado_em: string | null; vinculos: { movimentacao_id: number; tipo: string }[]; vinculos_ocultos: number; pode_gerir: boolean }
export type StatusChamado = 'ABERTO' | 'EM_ATENDIMENTO' | 'AGUARDANDO_SOLICITANTE' | 'RESOLVIDO' | 'CANCELADO'
export type ChamadoResumo = { codigo: string; protocolo: string; assunto: string; categoria: string; prioridade: string; status: StatusChamado; versao: number; criado_em: string; atualizado_em: string;
  solicitante?: { id: number; nome: string; login: string }; atribuido_a?: { id: number; nome: string }; unidade?: { id: number; sigla: string } }
export type MensagemChamado = { id: number; tipo: 'PUBLICA' | 'INTERNA' | 'SISTEMA'; corpo: string; redigido: boolean; autor: { id: number; nome: string } | null; do_solicitante: boolean; criada_em: string }
export type ChamadoDetalhe = ChamadoResumo & { atendimento: boolean; resolvido_em: string | null; reaberturas: number; pode_reabrir: boolean; transicoes: StatusChamado[];
  diagnostico: { rota?: string; navegador?: string; request_id?: string; versao?: string } | null; mensagens: MensagemChamado[] }
export type EstadoAcautelamento = 'SOLICITADO' | 'APROVADO' | 'REJEITADO' | 'CANCELADO' | 'ATIVO' | 'ENCERRADO'
export type AcautResumo = { codigo: string; estado: EstadoAcautelamento; versao: number; efetivo: { id: number; nome_guerra: string; posto: string | null }; item: { id: number; identificador: string; numero_serie?: string | null; material: string | null }; unidade: { id: number; sigla: string }; proxima_inspecao: string | null; inspecao_atrasada: boolean; criado_em: string }
export type AcautDetalhe = AcautResumo & { justificativa: string; solicitante: { id: number; nome: string }; aprovador: { id: number; nome: string } | null; decidido_em: string | null; decisao_justificativa: string | null; entrega_id: number | null; devolucao_id: number | null
  inspecoes: { id: number; realizada_em: string; resultado: 'CONFORME' | 'NAO_CONFORME'; observacao: string | null; inspetor: string }[]
  acoes: { decidir: boolean; cancelar: boolean; entregar: boolean; inspecionar: boolean; desacautelar: boolean; carteira: boolean } }

/** Tipos de efetivo (iguais ao Sistema de Escalas). Só RR e PM/BM da ativa recebem armamento e munição. */
export const CATEGORIAS_EFETIVO: Record<string, string> = { RR: 'RR (PM/BM)', PM_ATIVA: 'PM ativo', BM_ATIVA: 'BM ativo', R1: 'R1', VIGILANTE: 'Vigilante' }

// Portal do efetivo (decisão do responsável, 2026-11-18): pedido prévio de material.
export type StatusPedido = 'PENDENTE' | 'APROVADO' | 'RECUSADO' | 'CANCELADO' | 'ENTREGUE'
export type PedidoResumo = { codigo: string; rotulo: string; status: StatusPedido; versao: number
  efetivo: { id: number; nome_guerra: string; nome?: string; posto: string | null; categoria_efetivo: string | null; apto_arma_fogo: boolean }; unidade: { id: number; sigla: string }
  retirada_prevista: string | null; local_retirada: { id: number; codigo: string; nome: string } | null; itens_resumo: string; tem_letal: boolean
  criado_em: string; decidido_em: string | null; entregue_em: string | null; pode_cancelar?: boolean; acoes?: { decidir: boolean; entregar: boolean } }
export type PedidoItem = { id: number; quantidade: number; quantidade_aprovada: number | null; quantidade_entregue: number | null
  material: { id: number; codigo: string; nome: string; modo_controle: 'INDIVIDUAL' | 'LOTE'; unidade_medida: string; categoria: string | null; natureza: string | null; natureza_rotulo: string | null; perfil: string | null; acautelamento: boolean } }
export type PedidoDetalhe = PedidoResumo & { observacao: string | null; motivo_recusa: string | null; observacao_armeiro: string | null; decidido_por: string | null; entregue_por: string | null
  cancelado_em: string | null; entrega_movimentacao_id: number | null; acautelamentos: { codigo: string; estado: string; item: string | null }[]; itens: PedidoItem[]
  efetivo_conferencia?: { id: number; nome: string; nome_guerra: string; posto: string | null; unidade: string | null; matricula: string; cpf_mascarado: string | null; situacao: string; categoria_efetivo: string | null; apto_arma_fogo: boolean; foto_versao: number | null } }
export type EfetivoPortal = { nome: string; nome_guerra: string; posto: string | null; unidade: string | null; categoria_efetivo: string | null; apto_arma_fogo: boolean; situacao: string }
export type ItemCatalogoPortal = { id: number; codigo: string; nome: string; modo_controle: 'INDIVIDUAL' | 'LOTE'; unidade_medida: string; categoria: string | null; natureza: string | null; natureza_rotulo: string | null; tipo: string | null; perfil: string | null; bloqueio: string | null }
export type MaterialComigo = { custodia: 'ACAUTELAMENTO' | 'ENTREGA'; origem: string | null; desde: string; material: { nome: string; categoria: string; natureza: string; natureza_rotulo: string; unidade_medida: string }
  referencia: string; numero_serie: string | null; lote_codigo: string | null; quantidade: number; situacao?: string | null; situacao_na_entrega?: SituacaoNaEntrega | null; quantidade_entregue?: number; quantidade_devolvida?: number; unidade: string; local: string | null; previsao_retorno: string | null; atrasado: boolean }
/** Permissões do papel EFETIVO: quem só tem estas vê o menu enxuto do portal. */
export const PERMISSOES_PORTAL = ['portal.efetivo', 'suporte.abrir']
/** Só o portal: nenhuma permissão de gestão. */
export const soPortal = (permissoes: string[] | undefined) => !!permissoes?.includes('portal.efetivo') && permissoes.every((p) => PERMISSOES_PORTAL.includes(p))
/** Painel "Início" por tipo de material (GET /dashboard/materials, 2026-11-18). */
export type GrupoMaterial = 'ARMA_FOGO' | 'MUNICAO' | 'COLETE' | 'TASER' | 'CARTUCHO_TASER' | 'RADIO' | 'ALGEMA' | 'OUTROS'
export type CartaoGrupo = { chave: GrupoMaterial; rotulo: string; disponiveis: number; vencidos: number; vencendo: number; tem_validade: boolean
  com_efetivos: number; efetivos: number; entradas_hoje: number; saidas_hoje: number; entradas_7_dias: number; saidas_7_dias: number }
export type PainelMaterial = { gerado_em: string; validade_dias: number; grupos: CartaoGrupo[]
  operacao: { entregas_atrasadas: number; pedidos_pendentes?: number; pedidos_aprovados?: number; pendente_mais_antigo?: string | null } }
/** Devolução já aberta: efetivos com material pendente (GET /stock/linked/recipients). */
export type EfetivoPendente = { militar: { id: number; posto: string | null; nome_guerra: string; nome: string; categoria_efetivo: string | null; apto_arma_fogo: boolean | null }
  entregas: number; itens: number; lotes: number; quantidade_lotes: number; mais_antiga: string; proxima_previsao: string | null; atrasadas: number; atrasado: boolean }
export type ResumoPedidos = { pendentes: number; aprovados: number; pendente_mais_antigo: string | null; por_status: Record<StatusPedido, number> }
/** Rótulos dos grupos (espelho de App\Support\GrupoMaterial::ROTULOS) — usados no filtro vindo do card do Início. */
export const GRUPOS_MATERIAL: Record<GrupoMaterial, string> = { ARMA_FOGO: 'Armas de fogo', MUNICAO: 'Munição', COLETE: 'Coletes', TASER: 'Tasers',
  CARTUCHO_TASER: 'Cartuchos de Taser', RADIO: 'Rádios', ALGEMA: 'Algemas', OUTROS: 'Outros materiais' }
