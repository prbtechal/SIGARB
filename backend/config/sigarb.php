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
        'SAIDA' => ['estado' => 'BLOQUEADA', 'pendencia' => 'P-05', 'rotulo' => 'Saída definitiva', 'regra_provisoria' => 'Exige motivo, destinação e documento; itens disponíveis no local de origem; executada por quem tem estoque.saida.'],
        'ENTREGA' => ['estado' => 'BLOQUEADA', 'pendencia' => 'P-05', 'rotulo' => 'Entrega', 'regra_provisoria' => 'Entrega a um efetivo ativo; com previsão de retorno o item fica ENTREGUE até a devolução, sem previsão sai do estoque (consumo).'],
        'DEVOLUCAO' => ['estado' => 'BLOQUEADA', 'pendencia' => 'P-05', 'rotulo' => 'Devolução', 'regra_provisoria' => 'Devolução integral vinculada à entrega de origem; só para entregas com previsão de retorno.'],
        'TRANSFERENCIA' => ['estado' => 'BLOQUEADA', 'pendencia' => 'P-02/P-03', 'rotulo' => 'Transferência', 'regra_provisoria' => 'Envio debita a origem e deixa itens EM_TRANSITO; recebimento pelo destino credita; um recebimento por envio.'],
        'RESERVA' => ['estado' => 'BLOQUEADA', 'pendencia' => 'P-03', 'rotulo' => 'Reserva', 'regra_provisoria' => 'Reduz disponibilidade sem reduzir quantidade física; liberação única por reserva.'],
        'BAIXA' => ['estado' => 'BLOQUEADA', 'pendencia' => 'P-03', 'rotulo' => 'Baixa', 'regra_provisoria' => 'Exige motivo e documento; item disponível ou em manutenção; executada por quem tem estoque.baixar.'],
        'MANUTENCAO' => ['estado' => 'BLOQUEADA', 'pendencia' => 'P-03', 'rotulo' => 'Manutenção', 'regra_provisoria' => 'Somente itens individuais disponíveis; retorno único devolve o item à disponibilidade.'],
        'INVENTARIO' => ['estado' => 'BLOQUEADA', 'pendencia' => 'P-03/P-06', 'rotulo' => 'Inventário e ajuste'],
        'ACAUTELAMENTO' => ['estado' => 'BLOQUEADA', 'pendencia' => 'P-06/P-07', 'rotulo' => 'Acautelamento (solicitação, decisão, entrega, carteira)', 'regra_provisoria' => 'Solicitação por quem tem acautelamento.solicitar; decisão por outra pessoa com acautelamento.aprovar; entrega após aprovação; carteira com token opaco verificável só com sessão.'],
        'INSPECAO' => ['estado' => 'BLOQUEADA', 'pendencia' => 'P-03', 'rotulo' => 'Inspeção', 'regra_provisoria' => 'Inspeção a cada SIGARB_INSPECAO_DIAS (padrão 365) a partir da entrega ou da última inspeção.'],
        'DESACAUTELAMENTO' => ['estado' => 'BLOQUEADA', 'pendencia' => 'P-05/P-06', 'rotulo' => 'Desacautelamento', 'regra_provisoria' => 'Registra condição e divergências, devolve o item à disponibilidade e revoga a carteira.'],
    ],
];
