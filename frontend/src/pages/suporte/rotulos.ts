export const CATEGORIAS: Record<string, string> = { ERRO: 'Erro ou falha', DUVIDA: 'Dúvida de uso', ACESSO: 'Acesso e permissões', DADOS: 'Dados incorretos', SUGESTAO: 'Sugestão' }
export const PRIORIDADES: Record<string, string> = { BAIXA: 'Baixa', MEDIA: 'Média', ALTA: 'Alta', CRITICA: 'Crítica' }
export const STATUS: Record<string, string> = { ABERTO: 'Aberto', EM_ATENDIMENTO: 'Em atendimento', AGUARDANDO_SOLICITANTE: 'Aguardando solicitante', RESOLVIDO: 'Resolvido', CANCELADO: 'Cancelado' }
/** Avisa o menu e o sino para recarregar contadores após uma ação de suporte. */
export const avisarAlteracao = () => window.dispatchEvent(new Event('sigarb:suporte-alterado'))
