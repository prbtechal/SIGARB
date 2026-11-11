<?php

namespace App\Support;

/**
 * Dicionário único código de ação de auditoria → rótulo e verbo em português (só apresentação; o banco guarda o código original).
 * Código desconhecido recebe rótulo automático legível, para que ações novas já apareçam razoáveis.
 */
final class AcoesAuditoria
{
    /** código => [rótulo, verbo usado na frase "<quem> <verbo>: <alvo>"] */
    private const ACOES = [
        'acautelamento.solicitado' => ['Acautelamento solicitado', 'solicitou o acautelamento'],
        'acautelamento.aprovado' => ['Acautelamento aprovado', 'aprovou o acautelamento'],
        'acautelamento.rejeitado' => ['Acautelamento rejeitado', 'rejeitou o acautelamento'],
        'acautelamento.cancelado' => ['Acautelamento cancelado', 'cancelou o acautelamento'],
        'acautelamento.entregue' => ['Acautelamento entregue', 'entregou o material do acautelamento'],
        'acautelamento.inspecionado' => ['Inspeção de acautelamento', 'registrou a inspeção do acautelamento'],
        'acautelamento.encerrado' => ['Acautelamento encerrado', 'encerrou o acautelamento'],
        'acautelamento.carteira_emitida' => ['Carteira emitida', 'emitiu a carteira do acautelamento'],
        'acautelamento.carteira_verificada' => ['Carteira verificada', 'verificou a carteira do acautelamento'],
        'acesso.negado' => ['Acesso negado', 'teve um acesso negado'],
        'acesso.papel.atribuido' => ['Papel atribuído', 'atribuiu um papel de acesso a'],
        'acesso.papel.removido' => ['Papel removido', 'removeu um papel de acesso de'],
        'auditoria.consulta' => ['Consulta à auditoria', 'consultou a trilha de auditoria'],
        'auth.login' => ['Entrada no sistema', 'entrou no sistema'],
        'auth.logout' => ['Saída do sistema', 'saiu do sistema'],
        'auth.senha_trocada' => ['Senha trocada', 'trocou a própria senha'],
        'auth.sessao.revogada' => ['Sessão encerrada', 'encerrou uma sessão própria'],
        'auth.sessao.revogada_admin' => ['Sessões encerradas pelo administrador', 'encerrou as sessões de'],
        'auth.sessao.revogada_limite' => ['Sessão encerrada por limite', 'teve uma sessão encerrada por limite de sessões'],
        'auth.conta_bloqueada' => ['Conta bloqueada', 'teve a conta bloqueada'],
        'auth.mfa' => ['Verificação em duas etapas', 'passou pela verificação em duas etapas'],
        'auth.mfa.solicitado' => ['Verificação em duas etapas solicitada', 'recebeu o pedido de verificação em duas etapas'],
        'auth.mfa.cadastro_iniciado' => ['Cadastro da verificação em duas etapas iniciado', 'iniciou o cadastro da verificação em duas etapas'],
        'auth.mfa.cadastrado' => ['Verificação em duas etapas cadastrada', 'cadastrou a verificação em duas etapas'],
        'auth.mfa.desativado' => ['Verificação em duas etapas desativada', 'desativou a verificação em duas etapas'],
        'catalogo.categoria_removida' => ['Categoria removida', 'removeu a categoria'],
        'configuracao.alterada' => ['Configuração alterada', 'alterou uma configuração do sistema'],
        'dados.exportados' => ['Dados exportados', 'exportou dados'],
        'documento.enviado' => ['Documento enviado', 'enviou o documento'],
        'documento.consulta' => ['Documento consultado', 'consultou o documento'],
        'documento.download' => ['Documento baixado', 'baixou o documento'],
        'documento.metadados' => ['Dados do documento alterados', 'alterou os dados do documento'],
        'documento.vinculado' => ['Documento vinculado', 'vinculou o documento'],
        'documento.vinculo_negado' => ['Vínculo de documento negado', 'teve negado o vínculo do documento'],
        'documento.acesso_negado' => ['Acesso a documento negado', 'teve negado o acesso ao documento'],
        'documento.integridade' => ['Integridade do documento verificada', 'verificou a integridade do documento'],
        'documento.arquivado' => ['Documento arquivado', 'arquivou o documento'],
        'documento.reativado' => ['Documento reativado', 'reativou o documento'],
        'estoque.entrada' => ['Entrada de estoque', 'registrou a entrada'],
        'estoque.estorno' => ['Estorno de entrada', 'estornou a entrada'],
        'estoque.saida' => ['Saída de estoque', 'registrou a saída'],
        'estoque.baixa' => ['Baixa de material', 'registrou a baixa'],
        'estoque.transferencia' => ['Transferência enviada', 'enviou a transferência'],
        'estoque.recebimento' => ['Transferência recebida', 'recebeu a transferência'],
        'estoque.reserva' => ['Reserva de material', 'reservou material'],
        'estoque.liberacao_reserva' => ['Reserva liberada', 'liberou a reserva'],
        'estoque.manutencao' => ['Envio para manutenção', 'enviou material para manutenção'],
        'estoque.retorno_manutencao' => ['Retorno de manutenção', 'registrou o retorno de manutenção'],
        'estoque.entrega' => ['Entrega a efetivo', 'entregou material'],
        'estoque.devolucao' => ['Devolução de material', 'registrou a devolução'],
        'importacao.simulada' => ['Importação simulada', 'simulou uma importação de planilha'],
        'importacao.executada' => ['Importação executada', 'importou uma planilha'],
        'inventario.carga_inicial' => ['Carga do inventário', 'carregou o inventário inicial'],
        'inventario.aberto' => ['Inventário aberto', 'abriu o inventário'],
        'inventario.contagem_salva' => ['Contagem salva', 'salvou a contagem do inventário'],
        'inventario.contagem_concluida' => ['Contagem concluída', 'concluiu a contagem do inventário'],
        'inventario.cancelado' => ['Inventário cancelado', 'cancelou o inventário'],
        'inventario.ajuste_aprovado' => ['Ajuste de inventário aprovado', 'aprovou o ajuste do inventário'],
        'inventario.ajuste_rejeitado' => ['Ajuste de inventário rejeitado', 'rejeitou o ajuste do inventário'],
        'item.conservacao_alterada' => ['Conservação alterada', 'alterou a conservação de'],
        'local.criado' => ['Local de estoque criado', 'criou o local de estoque'],
        'local.atualizado' => ['Local de estoque alterado', 'alterou o local de estoque'],
        'material.criado' => ['Material cadastrado', 'cadastrou o material'],
        'material.atualizado' => ['Material alterado', 'alterou o material'],
        'militar.criado' => ['Efetivo cadastrado', 'cadastrou o efetivo'],
        'militar.atualizado' => ['Efetivo alterado', 'alterou o efetivo'],
        'militar.categoria_alterada' => ['Tipo de efetivo alterado', 'alterou o tipo do efetivo'],
        'militar.consulta_detalhe' => ['Detalhe de efetivo consultado', 'consultou o detalhe do efetivo'],
        'militar.foto_alterada' => ['Foto de efetivo alterada', 'alterou a foto do efetivo'],
        'militar.foto_removida' => ['Foto de efetivo removida', 'removeu a foto do efetivo'],
        'militar.vinculo_secundario_criado' => ['Vínculo secundário criado', 'criou um vínculo secundário do efetivo'],
        'militar.vinculo_secundario_encerrado' => ['Vínculo secundário encerrado', 'encerrou um vínculo secundário do efetivo'],
        'operacao.bloqueada' => ['Operação bloqueada', 'tentou uma operação bloqueada'],
        'organizacao.criada' => ['Organização criada', 'criou a organização'],
        'permissao.alterada' => ['Permissão alterada', 'alterou as permissões do papel'],
        'relatorio.exportado' => ['Relatório exportado', 'exportou um relatório'],
        'suporte.chamado.aberto' => ['Chamado aberto', 'abriu o chamado'],
        'suporte.chamado.reaberto' => ['Chamado reaberto', 'reabriu o chamado'],
        'suporte.atendimento.alterado' => ['Atendimento alterado', 'alterou o atendimento do chamado'],
        'suporte.resposta' => ['Resposta em chamado', 'respondeu o chamado'],
        'suporte.nota_interna' => ['Nota interna em chamado', 'registrou nota interna no chamado'],
        'unidade.criada' => ['Unidade criada', 'criou a unidade'],
        'unidade.atualizada' => ['Unidade alterada', 'alterou a unidade'],
        'usuario.criado' => ['Usuário criado', 'criou o usuário'],
        'usuario.atualizado' => ['Usuário alterado', 'alterou o usuário'],
        'usuario.papel_atribuido' => ['Papel atribuído', 'atribuiu um papel a'],
        'usuario.papel_removido' => ['Papel removido', 'removeu um papel de'],
        'usuario.senha_redefinida' => ['Senha redefinida', 'redefiniu a senha de'],
        'usuario.senha_provisoria_trocada' => ['Senha provisória trocada', 'trocou a senha provisória'],
        'usuario.desbloqueado' => ['Usuário desbloqueado', 'desbloqueou o usuário'],
        'usuario.mfa_redefinido' => ['Verificação em duas etapas redefinida', 'redefiniu a verificação em duas etapas de'],
        'usuario.perfil_atualizado' => ['Perfil atualizado', 'atualizou o perfil de'],
    ];

    /** Listas de referência (ReferenciaService::LISTAS): referencia.<lista>.<evento>. */
    private const LISTAS = ['categorias' => 'categoria', 'fabricantes' => 'fabricante', 'calibres' => 'calibre',
        'tipos-documento' => 'tipo de documento', 'tipos-material' => 'tipo de material', 'niveis-protecao' => 'nível de proteção'];

    private const EVENTOS_LISTA = ['criada' => ['criado', 'cadastrou'], 'atualizada' => ['alterado', 'alterou'],
        'desativada' => ['desativado', 'desativou'], 'reativada' => ['reativado', 'reativou']];

    /** Termos usados no rótulo automático de códigos desconhecidos. */
    private const TERMOS = ['auth' => 'Autenticação', 'auditoria' => 'Auditoria', 'usuario' => 'Usuário', 'acesso' => 'Acesso', 'estoque' => 'Estoque',
        'mfa' => 'verificação em duas etapas', 'sessao' => 'sessão', 'papel' => 'papel', 'consulta' => 'consulta', 'senha' => 'senha',
        'documento' => 'Documento', 'militar' => 'Efetivo', 'inventario' => 'Inventário', 'acautelamento' => 'Acautelamento',
        'suporte' => 'Suporte', 'referencia' => 'Referência', 'catalogo' => 'Catálogo', 'organizacao' => 'Organização', 'unidade' => 'Unidade'];

    /** @return array<string, array{0: string, 1: string}> */
    public static function todas(): array
    {
        $todas = self::ACOES;
        foreach (self::LISTAS as $lista => $nome) {
            foreach (self::EVENTOS_LISTA as $evento => [$part, $verbo]) {
                $todas["referencia.$lista.$evento"] = [ucfirst($nome).' '.self::concordar($nome, $part), "$verbo $nome"];
            }
        }

        return $todas;
    }

    public static function conhecida(string $acao): bool
    {
        return isset(self::todas()[$acao]);
    }

    public static function rotulo(string $acao): string
    {
        return self::todas()[$acao][0] ?? self::automatico($acao);
    }

    public static function verbo(string $acao): string
    {
        return self::todas()[$acao][1] ?? 'realizou a ação “'.self::automatico($acao).'”';
    }

    public static function frase(string $acao, string $quem, ?string $alvo): string
    {
        return trim("$quem ".self::verbo($acao)).($alvo ? ": $alvo" : '');
    }

    /** Ex.: "auditoria.consulta" → "Auditoria – consulta"; "usuario.mfa_redefinido" → "Usuário – verificação em duas etapas redefinido". */
    public static function automatico(string $acao): string
    {
        $partes = array_map(fn ($p) => implode(' ', array_map(fn ($w) => self::TERMOS[$w] ?? $w, explode('_', $p))),
            array_filter(preg_split('/[.\-]/', strtolower($acao)) ?: []));

        return $partes === [] ? 'Ação' : ucfirst(implode(' – ', $partes));
    }

    private static function concordar(string $nome, string $participio): string
    {
        // "categoria criada", "nível de proteção criado": o gênero vem da primeira palavra.
        return str_ends_with(explode(' ', $nome)[0], 'a') ? substr($participio, 0, -1).'a' : $participio;
    }
}
