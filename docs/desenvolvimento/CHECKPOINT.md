# SIGARB — CHECKPOINT

- **Atualizado em:** 2026-11-05T21:09:55-03:00
- **Branch:** main (local; sem remoto)
- **HEAD no momento da validação:** f817a64 (este arquivo é commitado logo depois)
- **Fase atual:** Fim da sessão 1. Fases 0, 1, 2, 3, 5 e 7 concluídas no escopo do MVP; fase 4 bloqueada por pendências institucionais.
- **Objetivo atual:** MVP local demonstrável para a próxima sessão presencial.
- **Status geral:** MVP pronto para **demonstração interna** (critérios 1–12 da diretiva §17 atendidos com as ressalvas abaixo). **Não aprovado para produção.**

## Último trabalho concluído
Pipeline completo `scripts/test.sh` verde em f817a64.

## Em andamento
Nada em andamento.

## Requisitos (detalhe e evidência em MATRIZ-RASTREABILIDADE.md)
- **TESTED (32):** RF-001, RF-002, RF-003, RF-004, RF-005, RF-006, RF-008, RF-009, RF-010, RF-013, RF-025, RF-026, RF-027; RNF-03, RNF-04, RNF-06, RNF-10; RN-001…005, RN-017, RN-018, RN-019, RN-024; UC-01…04, UC-14, UC-15.
- **IMPLEMENTED, sem teste dedicado (3):** RNF-02, RNF-13, RN-015.
- **IN_PROGRESS / parcial (13):** RF-007, RF-011 (só ENTRADA/ESTORNO), RF-024, RF-028, RF-029; RNF-01, RNF-05, RNF-07, RNF-11, RNF-14; RN-012, RN-020, RN-025.
- **BLOCKED por pendência (27):** RF-012, RF-015…RF-021, RF-023; RN-006…011, RN-013, RN-014, RN-016; UC-05…UC-13.
- **NOT_STARTED (10):** RF-014, RF-022, RF-030; RNF-08, RNF-09, RNF-12, RNF-15; RN-021, RN-022, RN-023.
- **ACCEPTED:** nenhum (não houve aceite institucional).

## Testes executados (resultado exato, 2026-11-05T21:09:55-03:00)
| Suíte | Resultado |
|---|---|
| Backend PHPUnit (`sigarb_test`), incluindo concorrência multiprocesso | 60 passed, 0 failed, 391 assertions |
| Vitest | 13 passed (3 arquivos) |
| tsc -b + vite build | OK |
| Playwright E2E contra Laravel real (`sigarb_e2e`), desktop + Pixel 7 | 12 passed, 3 skipped (restritos por projeto), 0 failed |
| axe WCAG 2.x A/AA (11 telas × 2 viewports) | 0 violações sérias/críticas |
| Medição básica de desempenho | ver TESTES.md (não representativa; 1.000 usuários NÃO verificado) |

## Build
Front-end: `dist/` gerado (≈315 KB JS / 95 KB gzip). Back-end: sem etapa de build.

## Migrações
11 migrações aplicadas e testadas com `migrate:fresh` nos bancos `sigarb`, `sigarb_test` e `sigarb_e2e` (MySQL 8.4 local). Nenhum banco institucional tocado.

## Decisões arquiteturais relevantes
ADR-003 (sessão web+CSRF, sem Sanctum/JWT), ADR-004 (RBAC + escopo em árvore), ADR-005 (individual × lote; saldo × razão; idempotência), ADR-006 (portão de políticas), ADR-007 (auditoria com hash encadeado), ADR-008 (variância de hospedagem, **proposta**).

## Decisões institucionais pendentes
P-01…P-12 todas ABERTAS; PE-01…PE-04 (engenharia). Prioridade sugerida: P-04, P-02, P-06, P-05, P-11, P-08.

## Defeitos conhecidos / dívida técnica
- oxlint: 2 warnings (`ui.tsx` exporta helpers junto com componentes; `Layout.tsx` faz setState em effect para fechar a gaveta na troca de rota). Sem impacto funcional.
- `backend/.env` local usa `APP_DEBUG=true` (só o dev). A API de E2E roda com `APP_DEBUG=false`.
- Revisão manual com leitor de tela não realizada; testado só em Chromium.
- Commit `2e089f6` entrou com 1 teste falhando; corrigido em `f52b782` (registrado no histórico).

## Bloqueios ativos
Toda operação além de ENTRADA/ESTORNO depende de decisão institucional. Implantação depende de P-01 e de autorização explícita.

## Próxima tarefa exata
RF-014 (documentos): tabela `documentos` + `documento_vinculos`, upload privado (`storage/app/private`) com validação de MIME/tamanho, download autorizado por escopo e auditado (RN-021), vínculo opcional à movimentação. Testes de acesso horizontal e de upload malicioso. Não depende de pendência institucional.

## Arquivos para inspecionar ao retomar
`AGENTS.md`, `backend/app/Services/{EstoqueService,AutorizacaoService,AuditoriaService}.php`, `backend/config/sigarb*.php`, `backend/routes/api.php`, `frontend/src/api/client.ts`, `scripts/rastreabilidade.py`.

## Comandos seguros para revalidar
```bash
cd infra && docker compose up -d mysql && cd ..
scripts/test.sh                 # tudo
scripts/reset-demo.sh           # recria demo local
python3 -I scripts/rastreabilidade.py
git log --format='%B' | grep -ciE 'co-authored|generated with'   # deve ser 0
```

## Alterações não commitadas
Nenhuma (após o commit deste checkpoint). Ignorados por regra: `infra/.env`, `backend/.env`, DOCX original.

## Produção / implantação
**Nada implantado. Nenhum acesso às VMs .8/.9. Nenhum remoto Git criado.** Produção exige aprovação institucional, revisão de segurança, regras aprovadas, validação de capacidade, backup/restauração testados e implantação autorizada à parte.
