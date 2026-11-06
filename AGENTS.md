# AGENTS.md — SIGARB

Sistema Integrado de Gestão de Armamentos e Reserva Bélica. Projeto **greenfield**; MVP local de demonstração com dados fictícios.

## Fronteiras (obrigatório)
- Trabalhe somente em `ronda-do-bairro/SIGARB/`. Não altere RondaSocial, Visita-Segura, Sistema-de-escalas nem seus containers, worktrees, portas ou bancos.
- Não acesse VMs institucionais (192.168.200.8/.9) nem faça deploy. Não crie repositório remoto sem autorização.
- Recursos próprios: Compose `sigarb-dev`, containers `sigarb-*`, portas 18406/18480/18481/18482/18483.
- Dados apenas sintéticos. Nunca use pessoas, matrículas ou inventários reais.
- Não invente política institucional: o que depende de P-01..P-12 fica **bloqueado** (`config/sigarb.php`), nunca simulado.

## Por onde começar (toda sessão)
1. Leia este arquivo, `docs/requisitos/original/PROVENIENCIA.md` e `docs/desenvolvimento/CHECKPOINT.md`.
2. Leia `docs/requisitos/MATRIZ-RASTREABILIDADE.md` e `PENDENCIAS-INSTITUCIONAIS.md`.
3. `git status` e `git log --oneline -10`; confirme que o checkpoint bate com o repositório.
4. Retome a "próxima tarefa" do checkpoint; não refaça trabalho concluído.

## Documentação
Requisitos: `docs/requisitos/` · Arquitetura/ADRs: `docs/arquitetura/` · API: `docs/api/openapi.yaml` · Segurança: `docs/seguranca/` · Processo: `docs/desenvolvimento/` · Operação: `docs/operacao/`.
A matriz é **gerada**: edite o mapeamento em `scripts/rastreabilidade.py` e rode `python3 -I scripts/rastreabilidade.py`.

## Testes (exigidos antes de commit)
`scripts/test.sh`, ou separadamente: backend `docker compose -f infra/compose.yaml run --rm --no-deps -w /app api php artisan test`; front-end `npx tsc -b && npx vitest run && npm run build`; E2E `scripts/e2e.sh`.
Nunca reclassifique falha como sucesso. Só faça commit com a suíte verde e confira o resultado **antes** do `git commit` (não encadeie com `grep … &&`).
RAM da estação é limitada: não rode E2E em paralelo com outras suítes pesadas.

## Commits
Conventional Commits em português. **Sem** `Co-Authored-By`, sem "Generated with", sem assinatura de IA. Use a identidade Git já configurada. Não reescreva histórico nem dê push --force.

## Segurança
Segredos só em `infra/.env` e `backend/.env` (ignorados). O DOCX do SRS não é versionado. Autorização sempre no servidor (Policies + `AutorizacaoService`), listas filtradas na query. Escrita de estoque só via `EstoqueService`. Auditoria append-only: nunca remova os triggers.

## Protocolo de checkpoint
Atualize `docs/desenvolvimento/CHECKPOINT.md` ao iniciar/encerrar fase, após testes, ao achar bloqueio e ao fim da sessão. Acrescente marcos em `HISTORICO-CHECKPOINTS.md`, sem reescrever entradas antigas. Status com evidência: NOT_STARTED, IN_PROGRESS, IMPLEMENTED, TESTED, BLOCKED, ACCEPTED (este só com aceite institucional).
