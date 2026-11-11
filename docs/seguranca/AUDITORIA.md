# Política PROVISÓRIA de auditoria (até P-08)

| Evento (`acao`) | Quando | Resultado |
|---|---|---|
| auth.login | toda tentativa (falha com login mascarado, ex. `529******25`, e IP) | SUCESSO / FALHA (motivo: identificador_inexistente, senha_incorreta, conta_inativa, conta_bloqueada) / NEGADO (limite) |
| auth.conta_bloqueada, usuario.desbloqueado | 10 falhas seguidas (PSI-PRB 10.3) / desbloqueio com motivo | SUCESSO |
| auth.senha_trocada | troca da própria senha (motivo: provisoria, expirada, voluntaria) | SUCESSO / FALHA |
| auth.mfa.solicitado, auth.mfa | 2ª etapa do login (PSI-PRB cap. 11) | SUCESSO / FALHA |
| auth.mfa.cadastro_iniciado, auth.mfa.cadastrado, auth.mfa.desativado, usuario.mfa_redefinido | verificação em duas etapas (redefinição com motivo) | SUCESSO / FALHA |
| usuario.desativado_inatividade | comando `sigarb:contas:desativar-inativas` (PSI-PRB 9.3) | SUCESSO |
| auth.logout, auth.sessao.revogada, auth.sessao.revogada_limite, auth.sessao.revogada_admin | revogação/encerramento | SUCESSO |
| acesso.negado | qualquer 403 de usuário autenticado | NEGADO |
| operacao.bloqueada | tentativa de operação sem política | NEGADO |
| usuario.criado/atualizado, acesso.papel.atribuido/removido | administração | SUCESSO |
| unidade.*, local.*, organizacao.criada, material.*, catalogo.*.criado, militar.criado/atualizado | cadastros (antes/depois) | SUCESSO |
| militar.consulta_detalhe | leitura de detalhe com matrícula completa (consulta sensível) | SUCESSO |
| estoque.entrada, estoque.estorno | movimentações | SUCESSO |
| auditoria.consulta | toda consulta à trilha | SUCESSO |
| documento.* | ver `DOCUMENTOS.md` | SUCESSO / NEGADO / FALHA |

Nunca registrados: senha, token, cookie, corpo bruto. Chaves sensíveis são substituídas por `[REMOVIDO]`.
Integridade: `php artisan sigarb:auditoria:verificar` ou `GET /api/v1/audit-events/integrity` (auditor global).
Retenção: `eventos_auditoria` nunca é apagado (append-only, triggers). Logs de arquivo: 180 dias (PSI-PRB 31.3: mínimo de 6 meses) — `SIGARB_LOG_DIAS`, `LOG_DAILY_DAYS` e `deploy/modelos/logrotate-sigarb`. Ver `PSI-PRB-CONFORMIDADE.md`; demais pontos de P-08 seguem abertos. Leituras comuns de listas **não** são auditadas individualmente (risco de volume, SRS §15.1).

## Modelo de ameaças da cadeia de hash (revisão CP-5)

Cada evento grava `hash = sha256(hash_anterior | conteúdo canônico)`; o ponteiro `auditoria_cadeia.ultimo_hash` é atualizado sob lock na mesma transação.
O conteúdo canônico ordena chaves JSON recursivamente (o MySQL reordena chaves) e preserva microssegundos.

| Ator / ação | Detectado? | Evidência |
|---|---|---|
| Aplicação ou usuário tentando UPDATE/DELETE | **Bloqueado** (triggers + trait Imutavel) | AuditoriaTest |
| DBA remove triggers e altera conteúdo, ação ou ator de um evento | **Sim** (verificação aponta o evento) | AuditoriaAdulteracaoTest |
| DBA apaga um evento intermediário | **Sim** | AuditoriaAdulteracaoTest |
| DBA insere evento forjado | **Sim** | AuditoriaTest |
| DBA apaga os últimos eventos | Só pela divergência com `auditoria_cadeia` | AuditoriaAdulteracaoTest |
| Ponteiro `auditoria_cadeia` removido | Escrita auditada **falha fechada** (antes recomeçava em silêncio — corrigido no CP-5) | AuditoriaTest |
| DBA que altera e **recalcula toda a cadeia e o ponteiro** | **NÃO** — limitação demonstrada em teste | AuditoriaAdulteracaoTest |

**A cadeia não é à prova de adulteração** contra quem controla simultaneamente os registros e os hashes no mesmo banco.

### Proposta (não implementada; requer decisão de segurança institucional)
1. **Âncora externa periódica:** a cada N minutos, publicar `(id, ultimo_hash, timestamp)` num destino fora do controle do DBA da aplicação (log WORM/append-only de outro servidor, syslog central, ou registro assinado enviado à equipe de segurança). A verificação compara a cadeia com as âncoras.
2. **HMAC com chave fora do banco** (KMS/HSM ou segredo do servidor de aplicação), para que reescrever a cadeia exija também a chave.
3. **Separação de privilégios:** usuário MySQL da aplicação sem DDL/TRIGGER; migrações com usuário distinto.
Nenhuma dessas medidas foi aplicada: alteram a arquitetura de segurança e dependem de P-01/P-08.
