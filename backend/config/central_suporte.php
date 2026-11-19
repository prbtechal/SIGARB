<?php

// Central de Suporte PRB (Central-Suporte/docs/INTEGRACAO.md, contrato v1).
// Leitura DESLIGADA por padrão: sem SUPORTE_DB_DATABASE o Arsenal não abre conexão com o banco `suporte` e
// GET /api/v1/central-suporte responde vazio (200). SUPORTE_URL só monta os links ("Abrir chamado", "Ver na Central").
return [
    // Endereço base da Central, sem barra no fim. Os links são montados AQUI (nunca copiados da view); só http(s).
    'url' => rtrim((string) env('SUPORTE_URL', 'http://192.168.200.8/suporte'), '/'),

    // Banco das views (conexão `suporte` em config/database.php). Vazio = leitura desligada.
    'banco' => (string) env('SUPORTE_DB_DATABASE', ''),

    // Código do sistema no formulário da Central (?sistema=).
    'sistema' => 'ARSENAL',

    // Segundos de cache por usuário (contrato: 60 s). A falha também fica em cache, para não repetir o timeout de 3 s.
    'cache' => 60,
];
