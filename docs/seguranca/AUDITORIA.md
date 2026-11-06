# Política PROVISÓRIA de auditoria (até P-08)

| Evento (`acao`) | Quando | Resultado |
|---|---|---|
| auth.login | toda tentativa | SUCESSO / FALHA (motivo) / NEGADO (limite) |
| auth.logout, auth.sessao.revogada, auth.sessao.revogada_limite, auth.sessao.revogada_admin | revogação/encerramento | SUCESSO |
| acesso.negado | qualquer 403 de usuário autenticado | NEGADO |
| operacao.bloqueada | tentativa de operação sem política | NEGADO |
| usuario.criado/atualizado, acesso.papel.atribuido/removido | administração | SUCESSO |
| unidade.*, local.*, organizacao.criada, material.*, catalogo.*.criado, militar.criado/atualizado | cadastros (antes/depois) | SUCESSO |
| militar.consulta_detalhe | leitura de detalhe com matrícula completa (consulta sensível) | SUCESSO |
| estoque.entrada, estoque.estorno | movimentações | SUCESSO |
| auditoria.consulta | toda consulta à trilha | SUCESSO |

Nunca registrados: senha, token, cookie, corpo bruto. Chaves sensíveis são substituídas por `[REMOVIDO]`.
Integridade: `php artisan sigarb:auditoria:verificar` ou `GET /api/v1/audit-events/integrity` (auditor global).
Retenção: indefinida no ambiente local; definir com a instituição (P-08). Leituras comuns de listas **não** são auditadas individualmente (risco de volume, SRS §15.1).
