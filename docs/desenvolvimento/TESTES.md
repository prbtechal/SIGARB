# Testes — como executar e evidência

| Suíte | Comando | Banco | Última execução (2026-11-05) |
|---|---|---|---|
| Backend (PHPUnit, feature + concorrência multiprocesso + adulteração) | `docker compose -f infra/compose.yaml run --rm --no-deps -w /app api php artisan test` | `sigarb_test` | **111 passed, 0 failed (794 assertions)** — CP-7, 2026-11-06 |
| Front-end unidade (Vitest) | `cd frontend && npx vitest run` | — (fetch/XHR simulados) | **24 passed (7 arquivos)** |
| Typecheck + build | `cd frontend && npx tsc -b && npm run build` | — | OK |
| E2E (Playwright, Laravel real, build de produção via `vite preview`) | `scripts/e2e.sh` | `sigarb_e2e` (recriado) | **19 passed, 6 skipped** (Chromium); multi-navegador: Chromium+Firefox 35/8 skipped, WebKit 19/6 skipped |
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

## CP-7 (2026-11-06)
- `ObservabilidadeTest` (6): linha JSON correlacionada (request_id = cabeçalho = auditoria); log sem senha, corpo, query, IDs de URL e cookies; liveness; readiness ok/503 sem detalhes; cache e limite de taxa.
- `scripts/testar-backup.sh` (11 casos, incluído em `scripts/test.sh`): backup cifrado, ilegível sem chave, permissão 600, banco não permitido recusado, restauração verificada (contagens, triggers, cadeia, checksums), controle de diretório, 1 bit adulterado rejeitado (cifra e SHA-256), chave errada rejeitada, documento ausente aborta, sem resíduos.
- `scripts/teste-carga.sh` (k6 1.3.0, API E2E com servidor embutido do PHP, estação de 4 núcleos compartilhada, load average ~4):

| Métrica | Valor |
|---|---|
| Requisições / taxa | 1481 / 23,8 req/s (60 s; 15 VUs leitura, 3 login, 5 entradas concorrentes) |
| Erros | 0,00% |
| Latência geral | média 769 ms, mediana 703 ms, p90 1,25 s, p95 1,44 s, máx 3,18 s |
| Login (bcrypt + sessão + auditoria) | média 1,55 s, p95 2,84 s |
| Entradas concorrentes no mesmo lote | 137 aceitas = saldo 137; 30 repetições idempotentes sem duplicar |
| Integridade pós-carga | cadeia de auditoria íntegra |
| Recursos | API PHP média 131% CPU (pico 181% de 400%), 49 MiB; MySQL média 28% CPU, pico 740 MiB (limite do container de dev: 768 MiB) |

Interpretação: gargalo no PHP (servidor embutido, sem OPcache); **não é medida de capacidade de produção**; 1.000 usuários **não verificado**.
- `e2e/acessibilidade-estrutura.spec.ts` (3 × 3 motores) — ver ACESSIBILIDADE.md.
- Pipeline `scripts/test.sh` em c41d091: **exit 0**.

## CP-8 (2026-11-06)
- Backend 112 (810 asserções), Vitest 24, build, E2E 19/6 skipped, backup/restauração **19 casos** (triggers ativos após restauração; restauração interrompida).
- Novo: sondas de saúde com banco inacessível e drivers de produção; concorrência em lote novo com verificação de linha única de saldo.
- Carga production-like: `scripts/carga-escalonada.sh` (cenários `completo|leitura|login|escrita_mesmo_lote|escrita_lotes`); resultados em `docs/operacao/CAPACIDADE.md`.
- `auditar-dependencias.sh`: exit 1 = vulnerabilidade em produção; **exit 2 = auditoria incompleta** (nunca conta como aprovado).
- Pint não faz parte do pipeline; ~60 arquivos legados divergem.

## Release e implantação (2026-11-06)
- `deploy/construir-release.sh` (reprodutibilidade: dois builds → mesmo SHA-256).
- `scripts/testar-implantacao.sh <R1> <R2>`: 26 casos em contêiner descartável + schema isolado; nenhum acesso às VMs.
