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
