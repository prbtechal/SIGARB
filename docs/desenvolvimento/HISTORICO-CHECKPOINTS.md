# Histórico de checkpoints (somente acréscimos)

## CP-0 — Inicialização (2026-11-05)
SRS localizado (1 arquivo, SHA-256 00d37d32…4c5c), copiado sem alteração; DOCX mantido fora do Git (PE-03). Extrato, pendências e ADRs criados. SIGARB não existia; nenhum trabalho alheio foi tocado.

## CP-1 — Fundação + cadastros + estoque (2026-11-05, 2990cfd)
Laravel 13/PHP 8.5/MySQL 8.4 em Docker isolado. 53 testes verdes. Bug real achado pelos testes: o MySQL reordena as chaves JSON e o cast datetime descartava microssegundos; ambos quebravam a cadeia de hash da auditoria e foram corrigidos antes do commit.

## CP-2 — Endurecimento de login (2026-11-05, 14940ff → cc071ce)
A revisão automática de segurança apontou: oráculo de matrícula na busca, força bruta distribuída, bloqueio de conta por terceiros, divergência de normalização e cookie de dispositivo não revogável. Todos corrigidos em iterações com testes de regressão.
**Falha de processo:** o commit 2e089f6 entrou com 1 teste falhando (cadeia `&&` com grep); corrigido em f52b782. Regra adicionada ao AGENTS.md.

## CP-3 — Front-end + E2E (2026-11-05, c37341e)
SPA React com os fluxos do MVP; referências de UX no Mobbin registradas. O E2E revelou que `php artisan serve` não repassava DB_* do container; substituído por `php -S` + server.php. Cliente HTTP passou a rejeitar respostas não-JSON.

## CP-4 — Documentação e MVP pronto para demonstração interna (2026-11-05T21:09:55-03:00, f817a64)
Matriz gerada (32 TESTED, 13 IN_PROGRESS, 3 IMPLEMENTED, 27 BLOCKED, 10 NOT_STARTED, 0 ACCEPTED). Pipeline completo verde: 60 backend, 13 Vitest, 12 E2E. Próximo passo: RF-014 documentos.

## CP-5 — Gestão segura de documentos RF-014 (2026-11-06T00:01:16-03:00, 662dc1f, branch feat/documentos-rf014)
Estado inicial conferido: HEAD f0ec846 = CP-4, árvore limpa, sem remoto. Módulo de documentos com disco privado, lista de permissão com verificação de conteúdo, download só como anexo com checksum, 404 fora do escopo, triggers de imutabilidade, telas e E2E.
A revisão automática de segurança achou 3 fragilidades na validação de PDF (escape #xx, /ObjStm, regex falhando aberta), todas corrigidas com teste. Os testes revelaram um defeito latente: a cadeia de auditoria recomeçava em silêncio sem o ponteiro; agora falha fechada.
Limitação registrada: reescrita total da cadeia por DBA não é detectada localmente (proposta PE-07).
Incidente de ambiente: queda e remontagem do disco FUSE e baixa memória causaram falhas transitórias de Vitest/E2E; reexecutado em ambiente estável. O E2E passou a servir o build de produção.
Resultado: 89 backend, 20 Vitest, 14 E2E, todos verdes. RF-014 TESTED (não ACCEPTED).

## CP-5 integrado ao main (2026-11-06T00:20:56-03:00)
- Pré-merge: HEAD dae20ae conferido, árvore limpa, SRS SHA-256 inalterado, sem segredos versionados, main sem divergência.
- Merge `--no-ff` → `ea2b0bc`. **A suíte sobre o main falhou** (1/89): deadlock em 8 entradas simultâneas no mesmo lote esgotou 3 tentativas e recusou uma entrada legítima (sem perda de integridade). Defeito pré-existente e intermitente, não introduzido pelo CP-5.
- Correção em `fix/deadlock-entrada-concorrente` (linhas de lote/saldo criadas antes da transação; 5 tentativas; regressão 3×10 entradas). Concorrência verde em 3 execuções seguidas.
- Merge da correção → **main `d81745d`**: 90 backend (651 asserções), 20 Vitest, build, 14 E2E + 3 skips. Branches `feat/documentos-rf014` e `fix/deadlock-entrada-concorrente` preservados.
