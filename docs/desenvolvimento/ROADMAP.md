# Roadmap

| Fase | Conteúdo | Situação (2026-11-05) |
|---|---|---|
| 0 Descoberta | SRS preservado, matriz, pendências, ADRs, domínio, DER, permissões, OpenAPI | Concluída (aguarda validação institucional) |
| 1 Fundação | Monorepo, Docker isolado, Laravel/React, sessão, RBAC+escopo, auditoria | Concluída e testada |
| 2 Cadastros | Organização, unidades, locais, usuários/papéis, militares, catálogo | Concluída e testada |
| 3 Estoque | Individual × lote, saldo × razão, entrada, estorno, idempotência, concorrência | Concluída e testada (só ENTRADA/ESTORNO) |
| 4 Workflows | Requisições, aprovações, acautelamento, inspeção, manutenção, inventário, documentos, alertas | **Bloqueada** por P-03/P-05/P-06/P-07; rotas retornam 409 |
| 5 UX/UI | Shell responsivo, telas do MVP, axe, teclado, zoom | Concluída para o escopo; falta revisão manual com leitor de tela |
| 6 Qualidade | Testes de segurança/concorrência/E2E | Base pronta; falta carga representativa, revisão de dependências, pentest |
| 7 Entrega local | Scripts dev/test/reset/e2e | Concluída |
| 8 Homologação/Implantação | — | Não autorizada (ADR-008, P-01) |

## Próximas tarefas seguras (sem decisão institucional)
1. RF-014 documentos: upload privado com validação MIME/tamanho, download autorizado e auditado (RN-021).
2. RF-024 gráficos (Chart.js) no dashboard, dentro do escopo.
3. RF-029 parâmetros editáveis com auditoria (hoje em `config/`).
4. Revisão manual de acessibilidade (NVDA/Orca) e navegadores institucionais (RNF-12).
5. CSP, `TrustProxies`, revisão `composer audit`/`npm audit` no pipeline.
6. Teste de carga representativo em ambiente isolado (RNF-07) — depende de metas de P-10.

## Após decisões
P-04 → classificação do catálogo; P-05 → entrega/devolução/saída; P-06 → acautelamento/aprovação; P-07 → carteira/QR opaco; P-03 → inspeção, manutenção, baixa, inventário; P-12 → relatórios.
