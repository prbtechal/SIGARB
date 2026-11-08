<?php

// Parâmetros PROVISÓRIOS de engenharia. Cada valor aponta para a pendência institucional que o definirá.
return [
    'ambiente_demonstracao' => env('SIGARB_DEMO', true),
    'saude' => [
        'cache_store' => env('SIGARB_SAUDE_CACHE', 'file'), // local ao nó, independente do banco (CP-8)
        'disco_minimo_bytes' => (int) env('SIGARB_DISCO_MINIMO_BYTES', 512 * 1024 * 1024), // provisório (P-10)
    ],
    'sessao' => [
        'max_simultaneas' => (int) env('SIGARB_MAX_SESSOES', 3), // P-11
    ],
    'login' => [
        'tentativas_por_minuto' => (int) env('SIGARB_LOGIN_TENTATIVAS', 5), // P-11 (conta + IP)
        'falhas_por_conta' => (int) env('SIGARB_LOGIN_FALHAS_CONTA', 20), // P-11 (15 min, qualquer IP)
        'falhas_por_ip' => (int) env('SIGARB_LOGIN_FALHAS_IP', 30), // P-11 (1 min, qualquer conta)
    ],
    'senha' => [
        'min' => 12, // P-11
    ],
    // RN-010 provisório (P-03): inspeção anual; alerta com antecedência.
    'acautelamento' => [
        'inspecao_dias' => (int) env('SIGARB_INSPECAO_DIAS', 365),
        'alerta_inspecao_dias' => (int) env('SIGARB_ALERTA_INSPECAO_DIAS', 30),
    ],
    // RF-022 provisório (RN-023/P-03): antecedência dos alertas de validade.
    'alertas' => [
        'validade_dias' => (int) env('SIGARB_ALERTA_VALIDADE_DIAS', 90),
        // CP-12: antecedência do alerta de validade de item individual (coletes). Vencer NÃO bloqueia o item (sem regra aprovada).
        'validade_item_dias' => (int) env('SIGARB_ALERTA_VALIDADE_ITEM_DIAS', 180),
    ],
    // Decisões do responsável do projeto (CP-13, docs/requisitos/DECISOES-RESPONSAVEL-P03-P05-P09.md).
    'politicas' => [
        'bloquear_vencido' => (bool) env('SIGARB_BLOQUEAR_VENCIDO', true),
    ],
    'movimentacao' => [
        'max_itens' => 200,
    ],
    // RF-014/RN-021 — lista de permissão CONSERVADORA do MVP (ADR-011). Tudo fora dela é rejeitado.
    'documentos' => [
        'max_bytes' => (int) env('SIGARB_DOC_MAX_BYTES', 10 * 1024 * 1024),
        'tipos' => [
            'pdf' => ['mime' => 'application/pdf', 'assinatura' => '%PDF-'],
            'png' => ['mime' => 'image/png', 'assinatura' => "\x89PNG\r\n\x1a\n"],
            'jpg' => ['mime' => 'image/jpeg', 'assinatura' => "\xFF\xD8\xFF"],
            'jpeg' => ['mime' => 'image/jpeg', 'assinatura' => "\xFF\xD8\xFF"],
        ],
        // Recursos ativos rejeitados em PDF (heurística; ver docs/seguranca/DOCUMENTOS.md).
        'pdf_marcadores_proibidos' => ['/JavaScript', '/JS', '/Launch', '/EmbeddedFile', '/OpenAction', '/AA', '/RichMedia', '/XFA'],
    ],
    // ADR-006 — portão de políticas. Só HABILITADA (ou BLOQUEADA com regra provisória LIGADA abaixo) altera estoque.
    // Regras provisórias: decisão do responsável do projeto (2026-11-06) para destravar o desenvolvimento.
    // NÃO são regras institucionais; desligadas por padrão; nenhum requisito vira ACCEPTED por elas.
    'operacoes_provisorias' => array_values(array_filter(array_map('trim', explode(',', (string) env('SIGARB_OPERACOES_PROVISORIAS', ''))))),
    'operacoes' => [
        'ENTRADA' => ['estado' => 'HABILITADA', 'pendencia' => 'PE-01', 'rotulo' => 'Entrada (registro administrativo)'],
        'ESTORNO' => ['estado' => 'HABILITADA', 'pendencia' => 'PE-02', 'rotulo' => 'Estorno de entrada'],
        'SAIDA' => ['estado' => 'HABILITADA', 'pendencia' => 'Decisão CP-13', 'rotulo' => 'Saída definitiva', 'regra_provisoria' => 'Exige motivo, destinação e documento. Só sai item disponível no local de origem.'],
        'ENTREGA' => ['estado' => 'HABILITADA', 'pendencia' => 'Decisão CP-13', 'rotulo' => 'Entrega', 'regra_provisoria' => 'Entrega a um efetivo ativo. Com data de retorno, o item fica pendente até a devolução; sem data, é consumo e sai do estoque.'],
        'DEVOLUCAO' => ['estado' => 'HABILITADA', 'pendencia' => 'Decisão CP-13', 'rotulo' => 'Devolução', 'regra_provisoria' => 'Devolve todo o restante ou só parte (itens e quantidades), ligada à entrega de origem. Só vale para entregas com data de retorno.'],
        'TRANSFERENCIA' => ['estado' => 'HABILITADA', 'pendencia' => 'Decisão CP-14', 'rotulo' => 'Transferência', 'regra_provisoria' => 'A origem envia e os itens ficam em trânsito; o destino registra o recebimento. Cada envio tem um único recebimento.'],
        'RESERVA' => ['estado' => 'HABILITADA', 'pendencia' => 'Decisão CP-13', 'rotulo' => 'Reserva', 'regra_provisoria' => 'Separa itens sem tirá-los do estoque físico. Cada reserva é liberada uma única vez.'],
        'BAIXA' => ['estado' => 'HABILITADA', 'pendencia' => 'Decisão CP-13', 'rotulo' => 'Baixa', 'regra_provisoria' => 'Exige motivo e documento. Vale para item disponível ou em manutenção.'],
        'MANUTENCAO' => ['estado' => 'HABILITADA', 'pendencia' => 'Decisão CP-13', 'rotulo' => 'Manutenção', 'regra_provisoria' => 'Só itens individuais disponíveis. O retorno devolve o item à disponibilidade.'],
        'INVENTARIO' => ['estado' => 'HABILITADA', 'pendencia' => 'Decisão CP-14', 'rotulo' => 'Inventário e ajuste', 'regra_provisoria' => 'O saldo do local fica registrado na abertura. O ajuste de lotes é aprovado por quem não abriu nem contou; diferença em item individual fica só registrada.'],
        'ACAUTELAMENTO' => ['estado' => 'HABILITADA', 'pendencia' => 'Decisão CP-14', 'rotulo' => 'Acautelamento (solicitação, decisão, entrega, carteira)', 'regra_provisoria' => 'Quem pede não aprova. A entrega só ocorre após a aprovação, e a carteira é conferida pelo QR com login.'],
        'INSPECAO' => ['estado' => 'HABILITADA', 'pendencia' => 'Decisão CP-13', 'rotulo' => 'Inspeção', 'regra_provisoria' => 'Inspeção anual, contada da entrega ou da última inspeção.'],
        'DESACAUTELAMENTO' => ['estado' => 'HABILITADA', 'pendencia' => 'Decisão CP-14', 'rotulo' => 'Desacautelamento', 'regra_provisoria' => 'Registra a condição do item e as divergências, devolve o item ao estoque e cancela a carteira.'],
    ],
];
