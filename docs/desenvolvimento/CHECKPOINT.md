# SIGARB — CHECKPOINT

- **Atualizado em:** 2026-11-06T01:41:36-03:00
- **Branch:** `feat/checkpoint-6-admin-dashboard-hardening` (criado de main `d81745d`). **Não mesclado** em main.
- **HEAD validado:** e3f2789 (este arquivo é commitado logo depois)
- **main local:** `d81745d` (CP-5 integrado + correção de deadlock)
- **Fase atual:** CHECKPOINT 6 — **concluído no escopo técnico**.
- **Status geral:** MVP local de demonstração. **Não aprovado para produção. Nada implantado.**

## Integração do CP-5 (Fase 1)
Merge `--no-ff` em main `ea2b0bc`; a suíte sobre main falhou (deadlock intermitente, pré-existente). Corrigido em `fix/deadlock-entrada-concorrente` e mesclado: **main `d81745d`** com 90 backend, 20 Vitest, build, 14 E2E. Branches `feat/documentos-rf014` e `fix/deadlock-entrada-concorrente` preservados.

## Entregas do CP-6
- **RF-029** listas de referência (TESTED): `/reference-lists`, tela "Listas de referência", desativação auditada, sem exclusão.
- **RF-024** painel (TESTED): blocos por permissão/escopo, gráficos acessíveis (tabela semântica), alertas de integridade.
- **Segurança:** CSP (ADR-012), identidades MySQL (ADR-013), proxies confiáveis (ADR-014), auditoria de dependências, cadeia de auditoria preservada (falha fechada + testes de adulteração).
- **Protótipos isolados:** PE-06 (qpdf eficaz; Ghostscript NÃO sanitiza), PE-07 (âncora Ed25519 detecta reescrita total).
- **Navegadores:** Chromium, Firefox e WebKit (desktop e celular).

## Achados de segurança e disposição
1. Bypass do limite de login por IP via Host falsificado (`.on-forge.com`) — **alta, corrigido** (`dad6d50`).
2. Rota irmã `POST /catalog/{tipo}` contornando `referencias.gerir` — **média, corrigido** (`e1dff3c`).
3. Deadlock recusando entrada legítima — **média, corrigido** (`d81745d` em main).
4. Reescrita total da cadeia por DBA — **residual aberto** (PE-07 aguarda decisão).

## Requisitos (ver MATRIZ-RASTREABILIDADE.md)
- Totais: **36 TESTED · 12 IN_PROGRESS · 3 IMPLEMENTED · 27 BLOCKED · 7 NOT_STARTED · 0 ACCEPTED**.
- CP-6: RF-024 IN_PROGRESS → TESTED; RF-029 IN_PROGRESS → TESTED; RNF-12 NOT_STARTED → IN_PROGRESS; nova evidência em RNF-01, RNF-06, RNF-11, RNF-13, RF-028.

## Testes (resultado exato, 2026-11-06T01:41:36-03:00)
| Suíte | Resultado |
|---|---|
| Backend PHPUnit | **105 passed, 0 failed, 759 assertions** |
| Vitest | **24 passed** (7 arquivos) |
| tsc + build | OK |
| E2E Chromium (scripts/test.sh) | **16 passed, 3 skipped** |
| E2E multi-navegador | Chromium+Firefox **29 passed / 5 skipped**; WebKit **16 passed / 3 skipped**; 0 failed |
| Privilégio mínimo | 10/10 negações; E2E com `sigarb_app` 14 passed |
| Dependências | 0 vulnerabilidades (produção e dev) |
| Pipeline `scripts/test.sh` | **exit 0** |

## Migrações
Nova: `2026_11_06_000200_add_ativo_to_referencias`. Aplicada via migrate:fresh em `sigarb_test` e `sigarb_e2e` (também com `sigarb_migr`). Banco de demonstração `sigarb` também migrado (cadeia de auditoria verificada íntegra após a migração).

## Limitações conhecidas
- Firefox 159: varredura axe desativada (o processo de conteúdo cai na injeção do axe-core; app estável sem axe). Ver ACESSIBILIDADE.md.
- Leitor de tela manual não executado; sem declaração de conformidade WCAG.
- Inspeção de PDF heurística; sem antivírus; sem cifragem em repouso.
- Desempenho não validado para 1.000 usuários.
- oxlint: 0 erros, avisos de estilo.

## Decisões institucionais pendentes
P-01…P-12 abertas. Engenharia: PE-05 (tipos/listas oficiais), PE-06 (qpdf/antivírus/cifragem), PE-07 (âncora: chave, destino, periodicidade). Navegadores institucionais (RNF-12).

## Próxima tarefa exata
1. Decidir com o usuário o merge de `feat/checkpoint-6-admin-dashboard-hardening` em main (local).
2. CP-7 item 1: logs estruturados com request_id e health check com dependências (RNF-09/RNF-14).

## Comandos seguros para revalidar
```bash
scripts/test.sh                  # backend + Vitest + build + E2E + auditoria de dependências
scripts/e2e-navegadores.sh       # Chromium, Firefox, WebKit
scripts/verificar-privilegios.sh # privilégio mínimo (banco E2E)
python3 -I scripts/rastreabilidade.py
```

## Árvore de trabalho
Limpa após o commit deste checkpoint. `infra/.env` ganhou `SIGARB_DB_APP_PASSWORD`/`SIGARB_DB_MIGR_PASSWORD` (local, não versionado).

## Produção / implantação
**Nada implantado. Nenhum remoto. Nenhum acesso às VMs .3/.4/.8/.9.** RondaSocial, Visita-Segura e Sistema-de-escalas não foram tocados. Imagens Docker adicionais apenas locais: `sigarb-proto-pe06:local`, `mcr.microsoft.com/playwright:v1.65.0-noble`.
