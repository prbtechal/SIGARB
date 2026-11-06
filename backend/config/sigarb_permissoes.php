<?php

// Matriz PROPOSTA de papéis × permissões (docs/seguranca/MATRIZ-PERMISSOES.md). Não aprovada institucionalmente.
return [
    'permissoes' => [
        'organizacao.ver' => 'Consultar organizações, unidades e locais no escopo',
        'organizacao.gerir' => 'Criar/editar unidades e locais de estoque no escopo',
        'usuarios.ver' => 'Consultar contas de acesso',
        'usuarios.gerir' => 'Criar/editar/desativar contas e atribuir papéis (escopo global)',
        'sessoes.revogar' => 'Revogar sessões de outros usuários',
        'militares.ver' => 'Consultar militares no escopo (dados resumidos)',
        'militares.ver_detalhe' => 'Consultar matrícula completa e detalhe do militar',
        'militares.gerir' => 'Cadastrar/editar militares no escopo',
        'catalogo.ver' => 'Consultar catálogo de materiais e referências',
        'catalogo.gerir' => 'Manter catálogo de materiais e referências',
        'estoque.ver' => 'Consultar saldos, itens e movimentações no escopo',
        'estoque.entrada' => 'Registrar entrada administrativa no escopo',
        'estoque.estornar' => 'Estornar entrada no escopo',
        'auditoria.ver' => 'Consultar trilha de auditoria no escopo',
        'documentos.ver' => 'Consultar e baixar documentos no escopo',
        'documentos.enviar' => 'Enviar documentos no escopo',
        'documentos.gerir' => 'Editar metadados, arquivar/reativar e vincular documentos no escopo',
    ],
    'papeis' => [
        'ADMIN' => ['nome' => 'Administrador', 'descricao' => 'Gere contas, permissões, unidades e locais.',
            'permissoes' => ['organizacao.ver', 'organizacao.gerir', 'usuarios.ver', 'usuarios.gerir', 'sessoes.revogar', 'militares.ver', 'catalogo.ver']],
        'GESTOR' => ['nome' => 'Gestor', 'descricao' => 'Mantém cadastros e executa operações permitidas no escopo.',
            'permissoes' => ['organizacao.ver', 'militares.ver', 'militares.ver_detalhe', 'militares.gerir', 'catalogo.ver', 'catalogo.gerir', 'estoque.ver', 'estoque.entrada', 'estoque.estornar', 'documentos.ver', 'documentos.enviar', 'documentos.gerir']],
        'OPERADOR' => ['nome' => 'Operador', 'descricao' => 'Consulta e executa operações explicitamente autorizadas.',
            'permissoes' => ['organizacao.ver', 'militares.ver', 'catalogo.ver', 'estoque.ver', 'estoque.entrada', 'documentos.ver', 'documentos.enviar']],
        'AUDITOR' => ['nome' => 'Auditor', 'descricao' => 'Consulta trilhas e evidências; não altera registros.',
            'permissoes' => ['organizacao.ver', 'militares.ver', 'catalogo.ver', 'estoque.ver', 'auditoria.ver', 'documentos.ver']],
        'APROVADOR' => ['nome' => 'Comandante/aprovador', 'descricao' => 'Decisão de acautelamento BLOQUEADA até P-06.',
            'permissoes' => ['organizacao.ver', 'militares.ver', 'catalogo.ver', 'estoque.ver']],
    ],
];
