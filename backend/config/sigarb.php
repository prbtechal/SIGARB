<?php

// Parâmetros PROVISÓRIOS de engenharia. Cada valor aponta para a pendência institucional que o definirá.
return [
    'ambiente_demonstracao' => env('SIGARB_DEMO', true),
    'sessao' => [
        'max_simultaneas' => (int) env('SIGARB_MAX_SESSOES', 3), // P-11
    ],
    'login' => [
        'tentativas_por_minuto' => (int) env('SIGARB_LOGIN_TENTATIVAS', 5), // P-11
    ],
    'senha' => [
        'min' => 12, // P-11
    ],
    'movimentacao' => [
        'max_itens' => 200,
    ],
    // ADR-006 — portão de políticas. Só HABILITADA pode alterar estoque.
    'operacoes' => [
        'ENTRADA' => ['estado' => 'HABILITADA', 'pendencia' => 'PE-01', 'rotulo' => 'Entrada (registro administrativo)'],
        'ESTORNO' => ['estado' => 'HABILITADA', 'pendencia' => 'PE-02', 'rotulo' => 'Estorno de entrada'],
        'SAIDA' => ['estado' => 'BLOQUEADA', 'pendencia' => 'P-05', 'rotulo' => 'Saída definitiva'],
        'ENTREGA' => ['estado' => 'BLOQUEADA', 'pendencia' => 'P-05', 'rotulo' => 'Entrega'],
        'DEVOLUCAO' => ['estado' => 'BLOQUEADA', 'pendencia' => 'P-05', 'rotulo' => 'Devolução'],
        'TRANSFERENCIA' => ['estado' => 'BLOQUEADA', 'pendencia' => 'P-02/P-03', 'rotulo' => 'Transferência'],
        'RESERVA' => ['estado' => 'BLOQUEADA', 'pendencia' => 'P-03', 'rotulo' => 'Reserva'],
        'BAIXA' => ['estado' => 'BLOQUEADA', 'pendencia' => 'P-03', 'rotulo' => 'Baixa'],
        'MANUTENCAO' => ['estado' => 'BLOQUEADA', 'pendencia' => 'P-03', 'rotulo' => 'Manutenção'],
        'INVENTARIO' => ['estado' => 'BLOQUEADA', 'pendencia' => 'P-03/P-06', 'rotulo' => 'Inventário e ajuste'],
        'ACAUTELAMENTO' => ['estado' => 'BLOQUEADA', 'pendencia' => 'P-06/P-07', 'rotulo' => 'Acautelamento (solicitação, decisão, entrega, carteira)'],
        'INSPECAO' => ['estado' => 'BLOQUEADA', 'pendencia' => 'P-03', 'rotulo' => 'Inspeção'],
        'DESACAUTELAMENTO' => ['estado' => 'BLOQUEADA', 'pendencia' => 'P-05/P-06', 'rotulo' => 'Desacautelamento'],
    ],
];
