# Fluxo do armeiro — entrega, devolução, baixa, edição e ajuste

Decisões do responsável do projeto em 2026-11-16 e 2026-11-18. Não são aceite institucional.
O sistema gira em torno de três momentos: o efetivo **pega** o material (entrega), **devolve** (recebimento de volta) e, quando há problema, o material é **baixado**.
Todas as operações abaixo já estavam habilitadas no portão (`config/sigarb.php` → `operacoes`). Nenhuma foi criada ou religada.

## 0. Armeiro na própria base (decisão de 2026-11-18)

- O papel **ARMEIRO** só pode ser atribuído com escopo numa **base** (unidade abaixo da raiz PRB, ex.: Poço). Escopo global ou a raiz PRB é recusado no servidor (`422 ESCOPO_BASE_OBRIGATORIO`) e a tela **Usuários e acessos** só oferece bases.
- Tudo o que o armeiro vê é filtrado na query pela base e suas seções: pedidos, entregas, devoluções, saldos, itens, relatórios, alertas, busca de efetivo, locais e unidades. O filtro **Unidade** só lista o escopo; com uma base só, a opção vazia é "Toda a base POCO" (nunca "Todas").
- Menu do armeiro: Início, Pedidos de material, **Entrega de material**, **Devolução de material**, Saldos de estoque, Itens e validades, Acautelamentos, Relatórios, Alertas, Central de suporte, conta e Sobre. As telas de gestão (Movimentações, Operações e pendências, Efetivos, Documentos, Inventários, Catálogo, Importar e exportar, Unidades e locais) não aparecem e as rotas respondem 403 (ver `docs/seguranca/MATRIZ-PERMISSOES.md`).

## 1. Registrar entrega

Tela **Entrega de material › Registrar entrega** (`/entregas/nova`; o endereço antigo `/estoque/vinculados/nova-entrega` redireciona; permissão `estoque.entregar`).

1. **Quem recebe:** busca por nome, nome de guerra, matrícula ou CPF (`GET /stock/recipients`, só efetivos ativos da base). Cada resultado mostra o selo de aptidão.
2. **De onde sai e o que vai:** o local de origem já vem com o **local padrão da base** — `SIGARB_ENTREGA_LOCAL_PADRAO` (código do local), ou o primeiro local **RESERVA** ativo da base (em produção, Reserva Poço) — e pode ser trocado. Itens individuais disponíveis (pelo nº de série) e lotes com o saldo disponível.
   - Arma de fogo e munição (naturezas ARMAMENTO e MUNICAO) aparecem **bloqueadas** quando o efetivo não pode recebê-las: R1, Vigilante ou efetivo sem tipo cadastrado. Só RR (PM/BM) e PM/BM da ativa podem (`Militar::APTAS_LETAL`). O servidor recusa do mesmo jeito (`EFETIVO_NAO_APTO_LETAL`).
   - Item vencido ou inservível não pode ser entregue (P-03).
3. **Retorno e registro** (três colunas alinhadas; uma coluna no celular):
   - **Previsão de retorno**, com a opção **Sem retorno (consumo)** logo abaixo do campo: com data, o material fica pendente com o efetivo; sem retorno, sai do estoque.
   - **Documento de referência** (lista com descrição): Cautela, Escala de serviço, Ordem de serviço, Ofício/Memorando, Pedido de material, Sem documento; número/identificação opcional.
   - **Motivo** (lista com descrição): Serviço de patrulha (escala), Evento/operação, Treinamento/instrução, Substituição de material com defeito, Reposição/consumo, Outro — "Outro" abre o campo de descrição obrigatório (mín. 5 caracteres); nos demais, observação opcional.
   - As listas ficam em `config/sigarb.php` (`entrega.documentos` / `entrega.motivos`), saem por `GET /stock/delivery-options` e são **validadas no servidor**. O que se grava continua nos campos de sempre: `documento_referencia` = "Cautela nº 77" (ou nulo para "Sem documento") e `justificativa` = "Serviço de patrulha (escala)" (com " — observação") ou "Outro — <texto>".

A gravação é a operação ENTREGA de sempre (`POST /api/v1/movements` com `motivo_codigo`, `documento_codigo`, `documento_numero`): movimentação imutável, chave de idempotência e evento `estoque.entrega` na auditoria.
O material entregue **sai do saldo** do local na hora (o lote é debitado; o item fica ENTREGUE). A ENTREGA saiu da tela genérica **Nova operação**: só pelo formulário acima (ou pela entrega de pedido).

## 2. Entrega de material (antes "Material com efetivos")

Tela `/entregas` (o endereço antigo `/estoque/vinculados` redireciona mantendo os filtros; API `GET /api/v1/stock/linked`).

- **Uma linha por entrega** (movimentação ENTREGA) ou por acautelamento — antes cada material virava uma linha. Colunas: efetivo; **materiais** ("3 materiais" com os nomes resumidos, expansível para ver séries, lotes e quantidades); entregue em; previsão de retorno (selo **Atrasado**); **conservação** resumida ("Bom" ou "2 Bom · 1 Regular"); ações **Receber devolução** (abre com todos os itens daquela entrega) e **Entrega #N** (detalhe).
- **Filtros:** busca (efetivo, material, série, lote, nº da entrega), material, só atrasados, custódia, perfil e unidade. Um filtro que casa um material traz a entrega inteira. Paginação por entrega.
- Devoluções parciais já são descontadas: o que voltou sai da linha; o lote mostra só o que falta voltar.
- Acautelamento tem fluxo próprio (desacautelamento), aberto por **Ver acautelamento**.

## 2.1 Devolução de material (tela de balcão)

Tela `/devolucoes` (permissão `estoque.entregar`). Um campo só, já com o foco: nome, nome de guerra, **matrícula**, **CPF**, **nº da entrega** (`1183` ou `#1183`) ou **comprovante do pedido** (`PED-7K2M9Q` ou o código completo). O resultado vem **agrupado por efetivo e por entrega**, com materiais, previsão, atraso e conservação; **Ver tudo com este efetivo** mostra todas as entregas pendentes dele. **Receber devolução** abre o mesmo diálogo da seção 3; arma acautelada abre o acautelamento para o desacautelamento. Matrícula e CPF só casam onde a matrícula completa é visível (`militares.ver_detalhe`).

## 3. Receber devolução

Botão **Receber devolução** em cada entrega (telas **Entrega de material** e **Devolução de material**; para o gestor, também em **Operações e pendências**). Permissão `estoque.entregar` no local da entrega.

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

## 8. Relatório do armeiro (decisão de 2026-11-18)

Tela **Relatórios** com a permissão `relatorios.entregas` (ARMEIRO e GESTOR): **Entregas e devoluções na base**.
- Quantidade **entregue** (movimentações ENTREGA a efetivo, inclusive a entrega de acautelamento) e **devolvida** (DEVOLUCAO, inclusive desacautelamento), por **dia, semana ou mês** e período (padrão: 14 dias / 8 semanas / 12 meses; máximo 400 dias). Item individual conta 1; lote conta a quantidade. Datas no horário de Maceió.
- Separado em **Armas** (tipo de material com perfil `ARMA_FOGO`), **Munição** (natureza MUNICAO) e **Demais materiais**: totais do período, gráfico de barras (entregues × devolvidas; Chart.js, `components/GraficoSeries.tsx`) e tabela acessível ("Ver dados em tabela").
- Exportar em **Excel (XLSX)** e **CSV** (`GET /reports/deliveries-returns/export?formato=xlsx|csv`), com o rótulo de informação restrita e o evento `relatorio.exportado` na auditoria.
- O armeiro não tem mais `relatorios.exportar`: as exportações de gestão (saldos, movimentações, acautelamentos, cadastros) ficam com gestor e auditor.

## Banco de dados

Migração `2026_11_24_000100_armeiro_devolucao_edicao` (aditiva, colunas nulas; nenhum registro existente é reescrito):
- `movimentacoes.devolvido_por_militar_id` (FK militares) e `movimentacoes.devolvido_por_nome`;
- `movimentacao_itens.estado_conservacao` e `movimentacao_itens.estado_anterior`;
- permissão `estoque.editar` concedida a ARMEIRO, GESTOR e ADMIN.
`down()` remove as colunas e a permissão. Os triggers de imutabilidade do razão não são tocados.

Migração `2026_11_26_000100_armeiro_base_entrega_devolucao` (só matriz papel × permissão; nenhuma tabela nem movimentação tocada):
- permissões novas `movimentacoes.ver` e `inventario.ver` (concedidas a todo papel que já tinha `estoque.ver`, menos ARMEIRO) e `relatorios.entregas` (ARMEIRO e GESTOR);
- ARMEIRO perde `militares.ver`, `organizacao.ver`, `catalogo.ver`, `catalogo.gerir`, `documentos.ver`, `documentos.enviar`, `inventario.contar`, `inventario.aprovar`, `dados.importar` e `relatorios.exportar`.
`down()` devolve essas permissões ao ARMEIRO e apaga as três novas.
