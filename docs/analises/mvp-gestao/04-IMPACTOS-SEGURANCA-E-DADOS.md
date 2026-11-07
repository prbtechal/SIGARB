# 04 — Impactos em integridade, segurança e dados

Premissas preservadas: escrita de estoque só via `EstoqueService`/`OperacaoEstoqueService`; razão e auditoria append-only (triggers não são removidos); autorização no servidor; migrações existentes **não** são editadas (apenas novas, aditivas).

| Mudança | Migração / dados existentes | Concorrência / integridade | Auditoria | Segurança / LGPD | Rollback / backup |
|---|---|---|---|---|---|
| `tipos_material` + `tipo_id` | Nova tabela; `tipo_id` nullable, backfill "Geral" por categoria; depois NOT NULL em migração separada | FK restrict; sem efeito no razão | Cadastro auditado como hoje | Nenhum | `down()` simples antes do NOT NULL; restauração já cobre novas tabelas (`mysqldump` completo) |
| Colunas opcionais de catálogo/item | `ALTER ADD` nullable; zero dados afetados | CHECK de datas | Alterações cadastrais já auditadas | Nenhum | `dropColumn`; backup inalterado |
| Conservação lista fechada | Só validação → nenhuma; se enum: verificar valores existentes antes (`SELECT DISTINCT`) | — | — | — | Trivial |
| Militar: quadro, função, contatos, observações | Nullable | — | Auditar alteração (dados pessoais) | Contatos são dado pessoal: exibir só com `militares.ver` no escopo | Simples |
| `militar_unidades` | Nova tabela | Unicidade de vínculo ativo por coluna gerada | Abrir/encerrar auditado; **sem DELETE** (trigger) | **Não** usar para escopo de acesso | Simples |
| **CPF** | Nullable para legados; obrigatório só em novos (se aprovado) | UNIQUE em HMAC, não no texto | Nunca gravar CPF em claro no evento | Cifrado em repouso (`APP_KEY`/chave dedicada), mascarado na interface, permissão própria, finalidade e retenção definidas (P-09) | **Backup passa a conter dado pessoal cifrado**: perda da chave = perda do CPF; rotação de chave exige procedimento no runbook |
| Devolução parcial | Novo valor de enum em `movimentacoes.tipo` (ALTER MODIFY, como em `2026_11_08_000100`) | Pendente = entregue − devolvido calculado sob lock da entrega; CHECK quantidade > 0 já existe | Evento por devolução | Atrás do portão de operações provisórias | Down remove valor só se não usado |
| Adotar DER literal (rejeitado) | Migração de dados de `movimentacoes` para `entregas` e de saldos para `saldo_atual` | Perde idempotência e locks testados | Quebra continuidade (histórico em outro formato) | Documento polimórfico sem FK | Rollback inviável após dados novos |

Testes exigidos por mudança: Feature de validação por tipo; migração em banco com dados de demonstração (`DemonstracaoSeeder`); `scripts/testar-implantacao.sh` (migração com backup); suíte de backup/restauração; para CPF, testes de unicidade com pontuação diferente, mascaramento em auditoria/suporte e negação sem permissão.
