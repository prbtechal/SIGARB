# Fluxo do armeiro — entrega, devolução, baixa, edição e ajuste

Decisões do responsável do projeto em 2026-11-16. Não são aceite institucional.
O sistema gira em torno de três momentos: o efetivo **pega** o material (entrega), **devolve** (recebimento de volta) e, quando há problema, o material é **baixado**.
Todas as operações abaixo já estavam habilitadas no portão (`config/sigarb.php` → `operacoes`). Nenhuma foi criada ou religada.

## 1. Entrega a efetivo

Tela **Estoque › Material com efetivos › Registrar entrega a efetivo** (`/estoque/vinculados/nova-entrega`, permissão `estoque.entregar`).

1. **Quem recebe:** busca por nome, nome de guerra ou matrícula (só efetivos ativos do escopo). Cada resultado mostra o selo de aptidão.
2. **De onde sai e o que vai:** local de origem; itens individuais disponíveis (pelo nº de série) e lotes com o saldo disponível. Dá para buscar por material, série ou lote.
   - Arma de fogo e munição (naturezas ARMAMENTO e MUNICAO) aparecem **bloqueadas** quando o efetivo não pode recebê-las: R1, Vigilante ou efetivo sem tipo cadastrado. Só RR (PM/BM) e PM/BM da ativa podem (`Militar::APTAS_LETAL`). O servidor recusa do mesmo jeito (`EFETIVO_NAO_APTO_LETAL`).
   - Item vencido ou inservível não pode ser entregue (P-03).
3. **Retorno e registro:** com previsão de retorno, o material fica pendente com o efetivo; com "Sem retorno (consumo)", sai do estoque. Documento de referência é opcional; o motivo é obrigatório.

A gravação é a operação ENTREGA de sempre (`POST /api/v1/movements`): movimentação imutável, chave de idempotência e evento `estoque.entrega` na auditoria.
O material entregue **sai do saldo** do local na hora (o lote é debitado; o item fica ENTREGUE).

## 2. Material com efetivos

Tela `/estoque/vinculados` (API `GET /api/v1/stock/linked`). Mostra as entregas com retorno ainda pendentes e os acautelamentos ativos.

- **Colunas:** efetivo, material (com natureza), nº de série ou lote e quantidade pendente, data da entrega, previsão de retorno (selo **Atrasado** quando passou) e conservação.
- **Filtros:** busca livre (efetivo, material, série, lote), material, **só atrasados**, custódia, perfil e unidade.
- Devoluções parciais já são descontadas: o item que voltou sai da lista e o lote mostra só o que falta voltar.
- Acautelamento tem fluxo próprio (desacautelamento), aberto pelo botão **Ver acautelamento**.

## 3. Receber devolução

Botão **Receber devolução** em cada linha de entrega (também em **Operações e pendências**). Permissão `estoque.entregar` no local da entrega.

- **Data e hora:** já vem com "agora". **Registrar com outra data/hora** permite uma devolução retroativa, que exige justificativa. Não pode ser no futuro nem antes da entrega.
- **Quem está devolvendo:** o próprio efetivo (padrão), outro efetivo (busca) ou outra pessoa sem cadastro (nome).
- **Itens:** todos marcados. Desmarque o que ainda não voltou para registrar uma devolução **parcial**; o restante continua pendente. Para lotes, informe a quantidade que voltou.
- **Estado de cada item:** já vem com o estado atual; mude para o estado em que o item chegou (Novo, Bom, Regular, Ruim, Danificado, Inservível).
- **Observação:** livre; vira a justificativa obrigatória quando a devolução é retroativa.

O que fica gravado:
- movimentação DEVOLUCAO imutável com `ocorrido_em`, `devolvido_por_militar_id` ou `devolvido_por_nome` e a observação;
- em cada item individual da devolução, `movimentacao_itens.estado_conservacao` (como chegou) e `estado_anterior`;
- o item volta a DISPONIVEL no local de origem com o novo estado de conservação;
- auditoria: `estoque.devolucao` (com quem devolveu, parcial ou não, retroativa) e um `item.conservacao_alterada` por item cujo estado mudou.

## 4. Baixa — "devolveu com problema → baixa"

Se algum item volta **Danificado** ou **Inservível**, ao concluir a devolução o sistema abre **Registrar baixa** com esses itens já marcados. Também dá para fazer depois:
- na movimentação da devolução (aviso "Devolvido com problema" com o botão **Registrar baixa**);
- em **Itens e validades** ou no detalhe do material, botão **Baixa** no item disponível ou em manutenção.

A baixa segue a regra BAIXA existente: item disponível ou em manutenção no local, **motivo e documento obrigatórios**, permissão `estoque.baixar`. O item passa a BAIXADO e sai do estoque em definitivo (evento `estoque.baixa`).

## 5. Corrigir dados do item e do lote

Permissão nova `estoque.editar` (ARMEIRO, GESTOR, ADMIN). Motivo obrigatório (mínimo de 10 caracteres). Auditoria com o antes e o depois.

- **Item individual** (`PATCH /api/v1/stock/individual-items/{id}`, botão **Editar** em Itens e validades e no detalhe do material): número de série (único por material), validade, data de fabricação, tamanho, nível de proteção e estado de conservação. O perfil do tipo continua valendo (arma exige série; colete exige tamanho, nível e fabricação; validade não pode ser anterior à fabricação). O **identificador gerado pelo sistema nunca muda**. Item baixado ou estornado não é editado. Evento `item.editado`.
- **Lote** (`PATCH /api/v1/stock/lots/{id}`, botão **Editar lote** em Saldos e no detalhe do material): validade e código (sem repetir outro lote do mesmo material). Vale para o lote em todos os locais, por isso exige `estoque.editar` em todas as unidades onde ele tem saldo. Saldo e histórico não mudam. Evento `lote.editado`.
- O nome e o modelo do material continuam em **Editar catálogo** (detalhe do material, `PUT /api/v1/items/{id}`, `catalogo.gerir`).
- Quem só tem `estoque.entrada` (ex.: Operador) continua podendo atualizar apenas a conservação.

## 6. Ajustar quantidade de um lote

Botão **Ajustar quantidade** nas linhas de lote em Saldos e no detalhe do material (`POST /api/v1/stock/adjustments`, `estoque.editar`, portão "Inventário e ajuste").

- Informe a **quantidade correta** naquele local e o **motivo**; documento é opcional.
- Grava uma movimentação **AJUSTE** imutável e idempotente, **sem aprovação**: falta sai do local (`local_origem`), sobra entra (`local_destino`). Evento `estoque.ajuste` com antes, depois e diferença.
- Não deixa o saldo abaixo da quantidade **reservada** (`ABAIXO_DA_RESERVA`). Se o saldo mudou desde que a tela foi aberta, recusa (`SALDO_ALTERADO`) para a pessoa conferir.
- O que está com efetivos **já está fora do saldo** (foi debitado na entrega) e não é afetado; quando voltar na devolução, soma ao saldo ajustado.
- O inventário com aprovação continua existindo para contagens formais.

## 7. Natureza "EPI"

A natureza EQUIPAMENTO aparece como **EPI** na interface (painel "por natureza", filtros, listas, selos) e nas exportações (relatório de saldos ganhou a coluna Natureza; a planilha de categorias sai com "EPI" e a importação aceita "EPI").
O valor gravado no banco, os códigos e as categorias não mudaram (`App\Support\Natureza`; no front-end, `NATUREZAS` em `components/ui.tsx`).

## Banco de dados

Migração `2026_11_24_000100_armeiro_devolucao_edicao` (aditiva, colunas nulas; nenhum registro existente é reescrito):
- `movimentacoes.devolvido_por_militar_id` (FK militares) e `movimentacoes.devolvido_por_nome`;
- `movimentacao_itens.estado_conservacao` e `movimentacao_itens.estado_anterior`;
- permissão `estoque.editar` concedida a ARMEIRO, GESTOR e ADMIN.
`down()` remove as colunas e a permissão. Os triggers de imutabilidade do razão não são tocados.
