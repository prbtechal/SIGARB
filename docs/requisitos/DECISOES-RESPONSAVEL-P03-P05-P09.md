# Decisões do responsável do projeto — P-03, P-05 e P-09

- **Data:** 2026-11-08 (CP-13)
- **Origem:** o responsável do projeto delegou à engenharia a definição de P-03, P-05 e P-09 ("quero que você mesmo resolva").
- **Natureza:** decisões **de projeto**, adotadas para o MVP. Valem até que a instituição publique norma própria; nenhum requisito passa a ACCEPTED por causa delas.
  Cada regra numérica fica em `config/sigarb.php` para poder ser ajustada sem mudar código.
- **P-04 continua aberta:** a lista oficial de tipos de material e de níveis de proteção será cadastrada pela própria instituição, na interface
  (Referências) ou por importação de planilha (`docs/operacao/IMPORTACAO-EXPORTACAO.md`).

## P-03 — Inspeção, baixa, autorização e retenção

| Tema | Decisão |
|---|---|
| Validade vencida | Item individual ou lote com validade **anterior a hoje** não pode ser **entregue** nem **reservado**. Continua podendo ir para manutenção, baixa ou saída. Configurável: `sigarb.politicas.bloquear_vencido`. |
| Conservação | Item com estado **INSERVIVEL** não pode ser entregue nem reservado. DANIFICADO e RUIM geram aviso, sem bloqueio. |
| Situação operacional | Continua mudando **só por operação** (entrega, devolução, manutenção, baixa…). Não existe edição direta da situação. |
| Baixa | Exige motivo e documento de referência; vale para item disponível ou em manutenção (regra que já existia, agora definitiva no MVP). |
| Manutenção | Só item individual disponível; o retorno devolve o item à disponibilidade. |
| Inspeção | Periodicidade anual a partir da entrega ou da última inspeção (mantida). |
| Autorização | Quem registra é o usuário com a permissão no escopo da unidade do local (Armeiro, Gestor). Não há aprovação prévia para entrega, devolução, baixa ou manutenção; aprovação continua só no acautelamento (P-06). |
| Retenção | Nada é apagado: movimentações, auditoria, vínculos e documentos são permanentes (triggers append-only). Cadastros são **desativados**, nunca excluídos. |

## P-05 — Entrega com e sem retorno; devolução

| Tema | Decisão |
|---|---|
| Com retorno | Entrega **com data prevista de retorno**: o item fica ENTREGUE e a entrega fica pendente até tudo ser devolvido. |
| Sem retorno (consumo) | Entrega **sem data de retorno** é consumo: o material sai do estoque e não admite devolução. Indicada para munição de treino, EPI descartável e similares. |
| Devolução parcial | **Permitida.** Cada devolução informa quais itens individuais e quantas unidades de cada lote voltam. O restante continua pendente. A entrega só é concluída quando tudo voltou. |
| Devolução total | Sem lista de itens, a devolução devolve todo o restante (comportamento anterior). |
| Estado na devolução | O armeiro pode registrar o estado de conservação de cada item individual devolvido; a mudança é auditada junto com a devolução. |
| Excesso | Não é possível devolver mais do que foi entregue nem um item que não estava na entrega. |
| Extravio ou perda | Não é devolução: o item permanece pendente e o caso segue por **baixa** com motivo e documento, depois da apuração. |

## P-09 — Dados pessoais, acesso, incidentes e descarte

| Tema | Decisão |
|---|---|
| Dados coletados | Mínimos: nome, nome de guerra, matrícula, posto/graduação, unidade, situação, quadro, função, telefone e e-mail **institucionais**, observações administrativas e foto. **CPF, RG, endereço e dados de saúde não são coletados.** |
| Classificação | Matrícula completa, contatos, observações e foto são **dado pessoal restrito**: só com `militares.ver_detalhe`. Os demais campos são de uso interno. |
| Exportação | A exportação de efetivos respeita o mesmo corte: sem `militares.ver_detalhe`, a matrícula sai mascarada e contatos/observações saem vazios. Toda exportação é auditada. |
| Importação | A planilha de efetivos rejeita colunas desconhecidas (por exemplo, CPF): dado não previsto não entra no sistema. |
| Auditoria | Contatos são mascarados na trilha de auditoria. |
| Acesso remoto | Só pela aplicação, com sessão autenticada; nenhum acesso direto ao banco fora da operação. |
| Incidente | Suspeita de vazamento: revogar sessões do usuário, exportar a trilha de auditoria do período e comunicar o encarregado de dados da instituição. Roteiro em `docs/seguranca/MODELO-AMeACAS.md`. |
| Descarte | Efetivo que deixa a instituição é **inativado**, não excluído (rastreabilidade das cautelas). Foto pode ser removida a qualquer momento. |
