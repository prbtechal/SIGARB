# Testes — como executar e evidência

| Suíte | Comando | Banco | Última execução (2026-11-05) |
|---|---|---|---|
| Backend (PHPUnit, feature + concorrência multiprocesso) | `docker compose -f infra/compose.yaml run --rm --no-deps -w /app api php artisan test` | `sigarb_test` | **60 passed, 0 failed (391 assertions)** |
| Front-end unidade (Vitest) | `cd frontend && npx vitest run` | — (fetch simulado) | **13 passed** |
| Typecheck + build | `cd frontend && npx tsc -b && npm run build` | — | OK |
| E2E (Playwright, Laravel real) | `scripts/e2e.sh` | `sigarb_e2e` (recriado) | **12 passed, 3 skipped** (skips = testes só desktop/só celular) |
| Tudo | `scripts/test.sh` | — | — |
| Lint | `cd frontend && npx oxlint src e2e` | — | 0 erros, 2 warnings (ver Defeitos conhecidos no CHECKPOINT) |

Concorrência (`ConcorrenciaTest`): processos PHP separados disparados no mesmo instante:
6 requisições com a mesma chave → 1 movimentação; 8 entradas no mesmo lote → soma exata;
6 estornos simultâneos → 1 vence (demais `JA_ESTORNADA`); 10 débitos de 15 sobre saldo 100 → exatamente 6 aceitos, saldo final 10.

Acessibilidade automatizada: axe (wcag2a/aa, 21aa, 22aa) em login + 10 telas, desktop e Pixel 7: 0 violações sérias/críticas.
Também testados: navegação por teclado no login, foco em diálogo e retorno ao gatilho, zoom equivalente a 200%, ausência de rolagem horizontal.
**Não feito:** leitor de tela manual, contraste em modo escuro verificado manualmente, navegadores além de Chromium.

## Desempenho (medição básica, NÃO é teste de carga)
`scripts/medir-desempenho.sh` (200 requisições, concorrência 10, servidor embutido do PHP com 4 workers, sem OPcache, notebook com RAM no limite):

| Rota | p50 | p95 | máx |
|---|---|---|---|
| /me | 284 ms | 652 ms | 813 ms |
| /stock/balances | 254 ms | 438 ms | 595 ms |
| /movements | 288 ms | 509 ms | 766 ms |
| /militares | 349 ms | 557 ms | 820 ms |

Conclusão: só linha de base de desenvolvimento. A meta de 1.000 usuários simultâneos (SRS §1.5) permanece **NÃO VERIFICADA**.
