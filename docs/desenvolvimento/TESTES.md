# Testes — como executar e evidência

| Suíte | Comando | Banco | Última execução (2026-11-05) |
|---|---|---|---|
| Backend (PHPUnit, feature + concorrência multiprocesso + adulteração) | `docker compose -f infra/compose.yaml run --rm --no-deps -w /app api php artisan test` | `sigarb_test` | **105 passed, 0 failed (759 assertions)** — CP-6, 2026-11-06 |
| Front-end unidade (Vitest) | `cd frontend && npx vitest run` | — (fetch/XHR simulados) | **24 passed (7 arquivos)** |
| Typecheck + build | `cd frontend && npx tsc -b && npm run build` | — | OK |
| E2E (Playwright, Laravel real, build de produção via `vite preview`) | `scripts/e2e.sh` | `sigarb_e2e` (recriado) | **16 passed, 3 skipped** (Chromium); multi-navegador: Chromium+Firefox 29/5 skipped, WebKit 16/3 skipped |
| Tudo | `scripts/test.sh` | — | — |
| Lint | `cd frontend && npx oxlint src e2e` | — | 0 erros, 8 warnings de estilo (only-export-components, set-state-in-effect) |

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

## Documentos (CP-5)
`DocumentoTest` (22): upload válido com checksum e sem expor caminho; PNG; formatos fora da lista (exe, html, svg, docx, sem extensão); conteúdo × extensão; PDF truncado; PDF ativo (JavaScript, OpenAction, Launch, EmbeddedFile, JS); nomes `#xx` e `/ObjStm`; erro do PCRE falha fechada; vazio e grande demais; path traversal e injeção de cabeçalho no nome; mesmo nome sem sobrescrita; upload sem permissão/fora do escopo (auditor, admin, aprovador, outra unidade, subunidade); outra unidade não consulta/baixa, código adivinhado, ID interno e traversal na URL → 404; hierarquia/admin/auditor; cabeçalhos de download e auditoria; conta desativada → 401; arquivo adulterado/ausente → 409; metadados (validação, campos proibidos, antes/depois); arquivar/reativar; triggers impedem trocar arquivo/apagar documento/alterar auditoria; vínculo por escopo e único; listagem/busca/paginação/escopo; operações institucionais seguem 409.
`ConcorrenciaTest`: 6 uploads simultâneos idênticos → 6 documentos, 6 arquivos distintos íntegros, cadeia de auditoria íntegra.
`AuditoriaAdulteracaoTest` (5): alteração, remoção intermediária, troca de ação, truncamento final (só via ponteiro), **reescrita total não detectada (limitação)**.
E2E `documentos.spec` (2): fluxo completo no navegador com download conferido byte a byte; isolamento entre unidades; revogação de sessão → 401 no download; ADMIN → 404.

## CP-6 (2026-11-06)
- Backend novos: `ReferenciaTest` (6), `DashboardTest` (5), `ProxyConfiavelTest` (4), regressão de deadlock em `ConcorrenciaTest`, regressão de rota em `CatalogoTest`.
- Vitest novos: `GraficoBarras.test.tsx` (2), `painel-referencias.test.tsx` (2).
- E2E novos: `csp.spec.ts` (2, com sonda negativa).
- `scripts/verificar-privilegios.sh`: 10/10 operações proibidas negadas ao runtime; E2E desktop com `sigarb_app`: 14 passed.
- `scripts/auditar-dependencias.sh`: 0 vulnerabilidades (composer e npm, produção e dev).
- Sondagem de arquivo privado real por URL direta (4 variações de caminho): 403/404, nunca 200.
- Pipeline `scripts/test.sh` em e3f2789: **exit 0**.
