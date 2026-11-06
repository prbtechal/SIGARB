# Roadmap

| Fase | Conteúdo | Situação (2026-11-05) |
|---|---|---|
| 0 Descoberta | SRS preservado, matriz, pendências, ADRs, domínio, DER, permissões, OpenAPI | Concluída (aguarda validação institucional) |
| 1 Fundação | Monorepo, Docker isolado, Laravel/React, sessão, RBAC+escopo, auditoria | Concluída e testada |
| 2 Cadastros | Organização, unidades, locais, usuários/papéis, militares, catálogo | Concluída e testada |
| 3 Estoque | Individual × lote, saldo × razão, entrada, estorno, idempotência, concorrência | Concluída e testada (só ENTRADA/ESTORNO) |
| 4 Workflows | Requisições, aprovações, acautelamento, inspeção, manutenção, inventário, alertas | **Bloqueada** por P-03/P-05/P-06/P-07; rotas retornam 409 |
| 4a Documentos (CP-5) | Upload privado, metadados, consulta, download, ciclo de vida, vínculos | Concluída e testada (RF-014 TESTED; classificação/retenção pendentes) |
| 5 UX/UI | Shell responsivo, telas do MVP, axe, teclado, zoom | Concluída para o escopo; falta revisão manual com leitor de tela |
| 6 Qualidade | Testes de segurança/concorrência/E2E | Base pronta; falta carga representativa, revisão de dependências, pentest |
| 7 Entrega local | Scripts dev/test/reset/e2e | Concluída |
| 8 Homologação/Implantação | — | Não autorizada (ADR-008, P-01) |

## Próximas tarefas seguras (sem decisão institucional)
1. ~~RF-014 documentos~~ — concluído no CP-5.
2. RF-024 gráficos (Chart.js) no dashboard, dentro do escopo.
3. RF-029 parâmetros editáveis com auditoria (hoje em `config/`).
4. Revisão manual de acessibilidade (NVDA/Orca) e navegadores institucionais (RNF-12).
5. CSP, `TrustProxies`, revisão `composer audit`/`npm audit` no pipeline.
6. Teste de carga representativo em ambiente isolado (RNF-07) — depende de metas de P-10.

## Após decisões
P-04 → classificação do catálogo; P-05 → entrega/devolução/saída; P-06 → acautelamento/aprovação; P-07 → carteira/QR opaco; P-03 → inspeção, manutenção, baixa, inventário; P-12 → relatórios.

## Proposta CHECKPOINT 6 — Endurecimento operacional e observabilidade (sem decisão institucional)
1. RF-029: parâmetros e tipos de documento editáveis na UI com auditoria (hoje via API/config).
2. RF-024: gráficos no painel (Chart.js) dentro do escopo, com alternativa textual acessível.
3. Segurança: CSP para a SPA, `TrustProxies` configurável, `composer audit`/`npm audit` em `scripts/test.sh`, usuário MySQL de aplicação sem DDL (separado do de migração).
4. Documentos: protótipo isolado de sanitização de PDF (qpdf/Ghostscript em container sem rede) para avaliação — sem ativar sem aprovação (PE-06).
5. Auditoria: protótipo de exportação de âncora (`sigarb:auditoria:ancorar` gerando arquivo assinado local) para avaliação (PE-07).
6. RNF-12/RF-028: E2E em Firefox/WebKit e roteiro de verificação manual com leitor de tela.
