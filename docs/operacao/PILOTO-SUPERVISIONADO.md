# Piloto supervisionado e demonstração à gestão

> **Estado:** preparado, **não iniciado**. O piloto em .8/.9 depende do Portão A da Infra (preflight institucional
> 2026-11-06: NO-GO) e de aprovação de execução. A demonstração à gestão pode ser feita **no ambiente local
> production-like** (`scripts/prodlocal.sh subir`), com dados sintéticos, sem tocar as VMs.

## 1. Escopo do piloto
| Item | Definição |
|---|---|
| Usuário | **1** usuário da reserva de armamento, nominalmente autorizado, com conta individual (sem conta compartilhada). |
| Papel | `OPERADOR` no escopo de **uma** unidade (consulta, entrada administrativa, envio de documento). Supervisor com `AUDITOR` na mesma unidade. Administração (`ADMIN`) só pela equipe técnica. |
| Dados | **Fase 1: somente sintéticos** (`ReferenciaSeeder` + cadastro sintético manual). Dados reais apenas após regras validadas (P-02..P-06), papéis corretos, dados reconciliados, backup aprovado e rollback testado na VM. |
| Operações | Somente **ENTRADA** e **ESTORNO** (portão ADR-006). Entrega, devolução, transferência, baixa, reserva, manutenção, inventário e acautelamento permanecem **BLOQUEADOS** — não serão habilitados para "completar" o piloto. |
| Supervisão | Sessões acompanhadas; trilha de auditoria revisada ao fim de cada sessão (`sigarb:auditoria:verificar`). |
| Encerramento | A qualquer defeito de integridade, autorização ou auditoria: suspender o piloto, preservar evidências, reverter release. |

## 2. Roteiro de demonstração (≈ 20 min)
Legenda: **I** implementado · **T** testado automaticamente · **A** aceito institucionalmente (nenhum item tem A hoje).

| # | Demonstração | Como | Estado |
|---|---|---|---|
| 1 | Login individual e papéis | Entrar como gestor e como auditor; mostrar menus diferentes; tentativa de acesso indevido → 403 auditado | I · T |
| 2 | Escopo organizacional | Gestor da UN-ALFA não vê dados da UN-BRAVO (404, não 403) | I · T |
| 3 | Estoque e entrada autorizada | Saldos por lote/individual; nova entrada; replay idempotente não duplica; estorno vinculado | I · T |
| 4 | Documentos privados | Envio de PDF; download só no escopo; arquivo com conteúdo ativo recusado | I · T |
| 5 | Listas de referência | Tipos de documento, categorias, postos; desativar sem apagar | I · T |
| 6 | Painel | Indicadores por escopo | I · T |
| 7 | Trilha de auditoria | Eventos da demonstração; `sigarb:auditoria:verificar` → "Cadeia de auditoria íntegra"; tentativa de UPDATE no banco → bloqueada por trigger | I · T |
| 8 | Backup e recuperação | `scripts/testar-backup.sh` (19 casos): cifrado, adulteração detectada, restauração verificada | I · T (local) |
| 9 | Controles de segurança | CSP, sessão limitada, sondas de saúde, release reprodutível com checksum, rollback automático | I · T (local) |
| 10 | Pendências institucionais | Tela "Operações": cada fluxo bloqueado com a pendência que o bloqueia (P-02..P-07) | I |

Mensagem central: **nada está aceito institucionalmente**; o piloto existe para obter as decisões P-xx e o aceite.

## 3. Critérios para iniciar o piloto na VM
1. Portão A da Infra = GO (NTP, disco ≥ 30 GB, 3306 restrito, segredos 600/640, sudo do operador).
2. DNS `sigarb.prb.intra` + certificado; `SESSION_SECURE_COOKIE=true` funcionando.
3. `deploy/ops/preflight.sh` = `PREFLIGHT_OK` na VM .8.
4. Backup cifrado do schema `sigarb` com **restauração isolada registrada** na VM .9.
5. Release instalada pelo `RUNBOOK.md`, com SHA e checksum iguais aos publicados.
6. Linha de base do Sistema-de-escalas igual antes e depois (hash de configuração e `/escalacerta/up`).
7. Termo de autorização do piloto (usuário, unidade, período, supervisor).
