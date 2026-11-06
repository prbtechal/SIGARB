# SIGARB — CHECKPOINT

- **Atualizado em:** 2026-11-06T00:01:16-03:00
- **Branch:** `feat/documentos-rf014` (criado a partir de `main` @ `f0ec846`, que coincidia com o CP-4; sem divergência). Não mesclado em `main`.
- **HEAD validado:** 662dc1f (este arquivo é commitado logo depois)
- **Fase atual:** CHECKPOINT 5 — Gestão segura de documentos (RF-014) — **concluído no escopo técnico**.
- **Status geral:** MVP local de demonstração com módulo de documentos. **Não aprovado para produção.** Nada implantado.

## Último trabalho concluído
RF-014 de ponta a ponta (backend, frontend, E2E) e revisão do modelo de ameaças da auditoria. Pipeline `scripts/test.sh` verde em 662dc1f.

## Em andamento
Nada.

## Requisitos (ver MATRIZ-RASTREABILIDADE.md)
- Totais: **34 TESTED · 13 IN_PROGRESS · 3 IMPLEMENTED · 27 BLOCKED · 8 NOT_STARTED · 0 ACCEPTED**.
- Mudanças no CP-5: RF-014 NOT_STARTED → **TESTED**; RN-021 NOT_STARTED → **TESTED**; RN-019, RN-020, RN-025, RNF-04, RNF-05 com nova evidência (status mantido onde a política segue pendente).
- RF-014 **não** está ACCEPTED (sem aceite institucional). Classificação de sigilo (P-09) e retenção (P-08) não modeladas.

## Testes (resultado exato, 2026-11-06T00:01:16-03:00)
| Suíte | Resultado |
|---|---|
| Backend PHPUnit (`sigarb_test`) | **89 passed, 0 failed, 616 assertions** (inclui 22 de documentos, 5 de adulteração da cadeia, 5 de concorrência) |
| Vitest | **20 passed** (5 arquivos) |
| tsc -b + build de produção | OK |
| Playwright E2E (`sigarb_e2e`, build servido por `vite preview`) | **14 passed, 3 skipped, 0 failed** |
| axe (12 telas × 2 viewports, inclui Documentos) | 0 violações sérias/críticas |
| oxlint | 0 erros, 8 warnings de estilo |

## Migrações
Nova: `2026_11_06_000100_create_documentos_tables` (tipos_documento, documentos, documento_vinculos + 4 triggers). Aplicada com sucesso em `sigarb` (demo, 10 migrações "Ran"), `sigarb_test` e `sigarb_e2e` (via migrate:fresh). Cadeia de auditoria do demo verificada íntegra após a migração.

## Correções de segurança no CP-5 (cada uma com teste de regressão)
1. Auditoria: ponteiro da cadeia ausente fazia a cadeia recomeçar **em silêncio** → agora falha fechada.
2. PDF: escapes `#xx` e `/ObjStm` contornavam a heurística → recusados (CONTEUDO_NAO_VERIFICAVEL).
3. PDF: erro do PCRE era tratado como "não encontrado" → recusa o arquivo.

## Decisões arquiteturais
ADR-011 (documentos privados). Limitação da cadeia de hash documentada em `docs/seguranca/AUDITORIA.md`, com proposta de âncora externa/HMAC (**não aplicada**: decisão de segurança institucional, PE-07).

## Decisões institucionais pendentes
P-01…P-12 abertas. Relevantes para documentos: **P-08** (retenção/descarte), **P-09** (classificação de sigilo), **P-07/P-12** (documentos oficiais), **PE-05** (tipos oficiais), **PE-06** (sanitização/antivírus/cifragem), **PE-07** (âncora da auditoria).

## Defeitos conhecidos / dívida técnica
- oxlint: 8 warnings de estilo (sem impacto funcional).
- Inspeção de PDF heurística (ver DOCUMENTOS.md); sem antivírus; sem cifragem em repouso.
- Testado só em Chromium; leitor de tela manual não realizado.
- Ambiente: o disco `/media/joseph/Workspace` (FUSE) caiu e foi remontado durante a sessão (falsos negativos transitórios de Vitest/E2E sob baixa memória; resolvidos e reexecutados).

## Bloqueios ativos
Operações além de ENTRADA/ESTORNO e emissão de documentos oficiais dependem de decisões institucionais. Implantação depende de P-01 e autorização explícita.

## Próxima tarefa exata
CHECKPOINT 6, item 1: tela de administração de listas de referência (tipos de documento, categorias, fabricantes, calibres) com auditoria — RF-029, sem dependência institucional. Antes: decidir com o usuário se `feat/documentos-rf014` será mesclado em `main` (merge local, sem remoto).

## Arquivos para inspecionar ao retomar
`backend/app/Services/DocumentoService.php`, `backend/app/Http/Controllers/Api/DocumentoController.php`, `backend/config/sigarb.php` (lista de permissão), `docs/seguranca/DOCUMENTOS.md`, `docs/seguranca/AUDITORIA.md`, `frontend/src/pages/Documento*.tsx`.

## Comandos seguros para revalidar
```bash
scripts/test.sh
python3 -I scripts/rastreabilidade.py
git log --format='%B' | grep -ciE 'co-authored|generated with'   # deve ser 0
```

## Alterações não commitadas
Nenhuma após o commit deste checkpoint.

## Produção / implantação
**Nada implantado. Nenhum acesso às VMs .3/.4/.8/.9. Nenhum remoto Git.** RondaSocial, Visita-Segura e Sistema-de-escalas não foram tocados.
