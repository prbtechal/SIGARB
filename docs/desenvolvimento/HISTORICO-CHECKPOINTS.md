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
