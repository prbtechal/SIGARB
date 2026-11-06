export type Operacao = { codigo: string; estado: 'HABILITADA' | 'PROVISORIA' | 'BLOQUEADA'; pendencia: string; rotulo: string; regra_provisoria?: string }
export type Perfil = {
  usuario: { id: number; nome: string; login: string; militar: { id: number; nome_guerra: string; posto: string } | null }
  papeis: { papel: string; papel_nome: string; unidade: { id: number; sigla: string } | null }[]
  permissoes: string[]
  operacoes: Operacao[]
  ambiente: string
}
export type Ref = { id: number; codigo?: string; sigla?: string; nome?: string }
export type Unidade = { id: number; sigla: string; nome: string; tipo: string; ativo: boolean; organizacao: { id: number; sigla: string }; unidade_superior_id: number | null; nivel: number }
export type Local = { id: number; codigo: string; nome: string; tipo: 'RESERVA' | 'DEPOSITO'; ativo: boolean; unidade: { id: number; sigla: string; nome: string } }
export type Militar = { id: number; nome: string; nome_guerra: string; matricula: string; matricula_completa: boolean; situacao: string; posto_graduacao: { id: number; sigla: string }; unidade: { id: number; sigla: string }; pode_editar: boolean; usuario?: { id: number; login: string } | null }
export type Material = { id: number; codigo: string; nome: string; modelo: string | null; modo_controle: 'INDIVIDUAL' | 'LOTE'; unidade_medida: string; ativo: boolean; categoria: { id: number; nome: string; natureza: string }; fabricante: Ref | null; calibre: Ref | null; possui_estoque?: boolean }
export type Saldo = { local: { id: number; codigo: string; nome: string }; unidade: { id: number; sigla: string }; material: { id: number; codigo: string; nome: string; modo_controle: string; categoria: string; natureza: string; unidade_medida: string }; lote: { id: number; codigo: string; validade: string | null } | null; quantidade: number; reservada: number; disponivel: number }
export type MovResumo = { id: number; tipo: string; ocorrido_em: string; responsavel: { id: number; nome: string }; local_origem: Ref | null; local_destino: Ref | null; documento_referencia: string | null; itens_count: number; estornada: boolean; estorno_id: number | null; provisoria?: boolean; vinculo_id?: number | null; conclusao_id?: number | null; militar?: { id: number; nome_guerra: string } | null; previsao_retorno?: string | null }
export type MovDetalhe = MovResumo & { origem_descricao: string | null; justificativa: string | null; estorno_de_id: number | null; pode_estornar: boolean; itens: { tipo: 'INDIVIDUAL' | 'LOTE'; quantidade: number; material: Ref; item?: { id: number; identificador: string; numero_serie: string | null; situacao: string }; lote?: { id: number; codigo: string; validade: string | null } }[] }
export type Usuario = { id: number; nome: string; login: string; email: string | null; ativo: boolean; ultimo_login_em: string | null; militar: { id: number; nome_guerra: string } | null; atribuicoes: { id: number; papel: string; unidade: { id: number; sigla: string } | null }[]; sessoes_ativas: number }
export type Evento = { id: number; ocorrido_em: string; acao: string; resultado: 'SUCESSO' | 'NEGADO' | 'FALHA'; usuario: { id: number; login: string; nome: string } | null; recurso_tipo: string | null; recurso_id: number | null; unidade_id: number | null; ip: string | null; request_id: string | null; dados: Record<string, unknown> | null; hash: string }
export type DocResumo = { codigo: string; titulo: string; numero_referencia: string | null; tipo: { id: number; codigo: string; nome: string }; unidade: { id: number; sigla: string }; nome_original: string; mime: string; tamanho_bytes: number; situacao: 'ATIVO' | 'ARQUIVADO'; enviado_por: string; enviado_em: string }
export type DocDetalhe = DocResumo & { descricao: string | null; sha256: string; extensao: string; arquivado_em: string | null; vinculos: { movimentacao_id: number; tipo: string }[]; vinculos_ocultos: number; pode_gerir: boolean }
export type StatusChamado = 'ABERTO' | 'EM_ATENDIMENTO' | 'AGUARDANDO_SOLICITANTE' | 'RESOLVIDO' | 'CANCELADO'
export type ChamadoResumo = { codigo: string; protocolo: string; assunto: string; categoria: string; prioridade: string; status: StatusChamado; versao: number; criado_em: string; atualizado_em: string;
  solicitante?: { id: number; nome: string; login: string }; atribuido_a?: { id: number; nome: string }; unidade?: { id: number; sigla: string } }
export type MensagemChamado = { id: number; tipo: 'PUBLICA' | 'INTERNA' | 'SISTEMA'; corpo: string; redigido: boolean; autor: { id: number; nome: string } | null; do_solicitante: boolean; criada_em: string }
export type ChamadoDetalhe = ChamadoResumo & { atendimento: boolean; resolvido_em: string | null; reaberturas: number; pode_reabrir: boolean; transicoes: StatusChamado[];
  diagnostico: { rota?: string; navegador?: string; request_id?: string; versao?: string } | null; mensagens: MensagemChamado[] }
export type EstadoAcautelamento = 'SOLICITADO' | 'APROVADO' | 'REJEITADO' | 'CANCELADO' | 'ATIVO' | 'ENCERRADO'
export type AcautResumo = { codigo: string; estado: EstadoAcautelamento; versao: number; efetivo: { id: number; nome_guerra: string; posto: string | null }; item: { id: number; identificador: string; material: string | null }; unidade: { id: number; sigla: string }; proxima_inspecao: string | null; inspecao_atrasada: boolean; criado_em: string }
export type AcautDetalhe = AcautResumo & { justificativa: string; solicitante: { id: number; nome: string }; aprovador: { id: number; nome: string } | null; decidido_em: string | null; decisao_justificativa: string | null; entrega_id: number | null; devolucao_id: number | null
  inspecoes: { id: number; realizada_em: string; resultado: 'CONFORME' | 'NAO_CONFORME'; observacao: string | null; inspetor: string }[]
  acoes: { decidir: boolean; cancelar: boolean; entregar: boolean; inspecionar: boolean; desacautelar: boolean; carteira: boolean } }
