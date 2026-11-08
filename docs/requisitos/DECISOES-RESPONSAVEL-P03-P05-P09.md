# Decisões do responsável do projeto — P-02, P-03, P-05, P-06, P-07 e P-09

- **Data:** 2026-11-08 (CP-13: P-03, P-05, P-09; CP-14: P-02, P-06, P-07)
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

## Papel do Armeiro (CP-14)
Pedido do responsável: o Armeiro faz a operação completa da reserva — inclusive **baixa, saída definitiva, transferência e aprovações**
(acautelamento e ajuste de inventário). Não cadastra efetivos. A segregação continua obrigatória no servidor: **ninguém aprova o próprio
pedido** e quem abriu ou contou um inventário não aprova o ajuste. Na prática, a unidade precisa de uma segunda pessoa com poder de
aprovar (outro Armeiro, o Gestor ou um Aprovador). O Gestor também passa a aprovar.

**CP-15 (substitui a segregação para o Armeiro):** a pedido do responsável, o **Armeiro pode pedir e aprovar o próprio acautelamento e o
próprio ajuste de inventário**, para não depender de uma segunda pessoa. Os demais papéis continuam com segregação. Em troca, cada evento
do acautelamento (pedido, aprovação, rejeição, entrega, inspeção, devolução, cancelamento) grava na trilha de auditoria encadeada:
quem operou, quem pediu, quem aprovou e quando, se houve **autoaprovação**, para qual efetivo (id, matrícula, posto, nome de guerra),
qual material (código, nome, categoria, tipo, calibre), qual item (identificação e série), a quantidade e o horário. A trilha é append-only.
Configurável: `SIGARB_AUTOAPROVACAO_PAPEIS` (padrão `ARMEIRO`; vazio = ninguém se autoaprova).

## P-02 — Organização e escopo

| Tema | Decisão |
|---|---|
| Hierarquia | Modelo genérico em árvore: organização → unidades → subunidades (sem limite de níveis) → locais de estoque (reserva ou depósito). A estrutura **real** é cadastrada pela instituição, pela tela ou por planilha (cadastros "Unidades" e "Locais de estoque"). |
| Escopo | Uma permissão vale na unidade atribuída **e em todas as suas subunidades**. Atribuição sem unidade = global (só Administrador e Auditor). |
| Transferência | Habilitada entre locais de qualquer unidade da organização. Envia quem tem `estoque.transferir` na origem; os itens ficam em trânsito até alguém com a mesma permissão **na unidade de destino** registrar o recebimento. |

## P-06 — Aprovação

| Tema | Decisão |
|---|---|
| Quem aprova | Aprovador, Gestor e Armeiro da unidade (ou de uma unidade superior). |
| Segregação | Quem pediu não decide; quem abriu ou contou o inventário não aprova o ajuste. |
| Rejeição | Exige justificativa. |
| Cancelamento | O próprio solicitante, ou quem opera acautelamentos na unidade, cancela antes da entrega, com justificativa. |
| Delegação e substituição | Atribuição do papel com **data de validade** (Usuários e acessos → "Válido até"). No dia seguinte à data o papel deixa de valer sozinho; pode ser renovado. Toda atribuição é auditada. |

## P-07 — Carteira de acautelamento

| Tema | Decisão |
|---|---|
| Campos | Efetivo (posto, nome de guerra, nome; matrícula completa só com `militares.ver_detalhe`), item (identificação, série, material, calibre), unidade, código, data de emissão e validade. |
| Formato | Página da própria aplicação para impressão, com QR que contém só um token aleatório, sem dado pessoal. |
| Validade | Enquanto o acautelamento estiver **ativo** e a **inspeção em dia**. A data impressa é a da próxima inspeção. |
| Verificação do QR | Só com login no sistema. Responde "válida", "vencida por inspeção atrasada" ou "inválida/encerrada". Cada verificação é auditada. |
