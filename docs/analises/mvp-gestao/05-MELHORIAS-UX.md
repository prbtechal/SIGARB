# 05 — Produto e UX

Base: telas atuais em `frontend/src/pages/` (design system em `docs/desenvolvimento/DESIGN-SYSTEM.md`, E2E em Chromium/Firefox/celular).

O documento propõe 18 campos comuns + específicos. Na prática, o operador cadastra **o material de referência uma vez** e depois lança **entradas** de itens/lotes muitas vezes. A maior parte dos campos do doc deve ficar no catálogo, não na entrada, para evitar digitação repetida.

| Melhoria | Muda banco? | Benefício ao operador |
|---|---|---|
| Lista fechada de conservação (select, com DANIFICADO/INSERVÍVEL) em entrada e desacautelamento | Não (validação) | Consistência; filtros confiáveis |
| Rótulos "Situação operacional" (somente leitura, vem das operações) × "Estado de conservação" (editável) | Não | Evita a confusão apontada no Doc §6 |
| Formulário de material por categoria: mostrar calibre só para armamento/munição, unidade de medida só para LOTE | Não (campos já existem) | Menos campos irrelevantes |
| Filtro "materiais vinculados" por categoria/situação no perfil e lista de efetivos | Não (consulta) | RF-VIN-06 |
| Campos por tipo (tamanho, nível de proteção, validade do colete, compatibilidade) com seções condicionais | Sim (TO-BE) | Cadastro completo de colete/acessório |
| Alerta de validade de colete por unidade (reusar `AlertasRelatoriosPage`) | Sim (validade no item) | Segurança operacional |

Regras: manter navegação, componentes e tema atuais; seções condicionais com `aria-expanded`/foco correto; mensagens de validação do servidor exibidas por campo; nenhuma tela nova se uma existente comporta o campo.
