# Fluxo do armeiro — entrega, devolução, baixa, edição e ajuste

Decisões do responsável do projeto em 2026-11-16 e 2026-11-18. Não são aceite institucional.
O sistema gira em torno de três momentos: o efetivo **pega** o material (entrega), **devolve** (recebimento de volta) e, quando há problema, o material é **baixado**.
Todas as operações abaixo já estavam habilitadas no portão (`config/sigarb.php` → `operacoes`). Nenhuma foi criada ou religada.

## 0. Armeiro na própria base (decisão de 2026-11-18)

- O papel **ARMEIRO** só pode ser atribuído com escopo numa **base** (unidade abaixo da raiz PRB, ex.: Poço). Escopo global ou a raiz PRB é recusado no servidor (`422 ESCOPO_BASE_OBRIGATORIO`) e a tela **Usuários e acessos** só oferece bases.
- Tudo o que o armeiro vê é filtrado na query pela base e suas seções: pedidos, entregas, devoluções, saldos, itens, relatórios, alertas, busca de efetivo, locais e unidades. O filtro **Unidade** só lista o escopo; com uma base só, a opção vazia é "Toda a base POCO" (nunca "Todas").
- **Entrada é da gestão (decisão de 2026-11-18):** o ARMEIRO não tem `estoque.entrada` (migração `2026_11_29_000100`). `POST /movements` tipo ENTRADA e a importação de estoque respondem 403; o botão **Registrar entrada** some do Início, de Saldos e de Movimentações. Atualizar a conservação do item continua liberado ao armeiro por `estoque.editar`.
- **Depósito só para a gestão (decisão de 2026-11-18):** locais do tipo **DEPÓSITO** (em produção, DEP-SMB "Estoque SMB - Seção de Materiais Bélicos") são da gestão — retomada de estoque, conferência e distribuição entre unidades. Sem a permissão `estoque.depositos` (concedida a ADMIN, GESTOR, AUDITOR e APROVADOR; migração `2026_11_29_000200`), o depósito some de Saldos, Itens, detalhe do material, Início (cards e gráficos contam só a reserva), Entrega (local de origem e materiais), Devolução, Pedidos, Relatório de entregas, `/locations`, alertas e exportações; operar a partir dele ou para ele responde 403 (`DEPOSITO_SO_GESTAO`). O armeiro trabalha só com a **reserva da base** (ex.: RES-POCO). A tela Saldos avisa: "Você vê apenas a reserva do seu escopo organizacional. Depósitos (retomada, conferência e distribuição) são da gestão."
- Menu do armeiro: Início, Pedidos de material, **Entrega de material**, **Devolução de material**, Saldos de estoque, Itens e validades, Acautelamentos, Relatórios, Alertas, Central de suporte, conta e Sobre. As telas de gestão (Movimentações, Operações e pendências, Efetivos, Documentos, Inventários, Catálogo, Importar e exportar, Unidades e locais) não aparecem e as rotas respondem 403 (ver `docs/seguranca/MATRIZ-PERMISSOES.md`).

## 1. Registrar entrega

Tela **Entrega de material › Registrar entrega** (`/entregas/nova`; o endereço antigo `/estoque/vinculados/nova-entrega` redireciona; permissão `estoque.entregar`).

1. **Quem recebe:** busca por nome, nome de guerra, matrícula ou CPF (`GET /stock/recipients`, só efetivos ativos da base). Cada resultado mostra o selo de aptidão.
2. **De onde sai e o que vai:** o local de origem já vem com o **local padrão da base** — `SIGARB_ENTREGA_LOCAL_PADRAO` (código do local), ou o primeiro local **RESERVA** ativo da base (em produção, Reserva Poço) — e pode ser trocado. Itens individuais disponíveis (pelo nº de série) e lotes com o saldo disponível.
   - Arma de fogo e munição (naturezas ARMAMENTO e MUNICAO) aparecem **bloqueadas** quando o efetivo não pode recebê-las: R1, Vigilante ou efetivo sem tipo cadastrado. Só RR (PM/BM) e PM/BM da ativa podem (`Militar::APTAS_LETAL`). O servidor recusa do mesmo jeito (`EFETIVO_NAO_APTO_LETAL`).
   - Item vencido ou inservível não pode ser entregue (P-03).
   - **Lista paginada (2026-11-18):** dois blocos — **Itens por nº de série** (marque **Entregar**) e **Lotes** (marque **Entregar** e informe a quantidade; marcar sugere 1, desmarcar zera). Lote marcado com quantidade é entregue, igual ao item marcado. Cada bloco tem 15 linhas por página, paginadas no servidor (`GET /stock/individual-items?situacao=DISPONIVEL&per_page=15&page=N` e `GET /stock/balances?lotes_disponiveis=1&per_page=15&page=N`). A busca (material, código, nº de série, identificador, lote) vale para o conjunto todo e volta à página 1. O que já foi marcado continua marcado ao trocar de página ou de busca, e o resumo **"N selecionado(s) · X item(ns) por série · Y lote(s): Z unidade(s)"** fica sempre visível no topo da lista.
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
- **Filtros:** busca, material, só atrasados, custódia, perfil e unidade. Um filtro que casa um material traz a entrega inteira. Paginação por entrega (20 por página).
- **Busca (corrigida em 2026-11-18):** nome e código do material (e tipo), efetivo (nome, nome de guerra; matrícula/CPF exatos onde a matrícula completa é visível), nº de série, identificador, lote, nº da entrega e comprovante do pedido. É **parcial, sem diferença de maiúsculas e acentos, palavra por palavra** (todas as palavras precisam casar) e tolera plural simples: "PT" acha "PT 100 Cal. .40 Taurus", "colete" acha "Colete balístico - tamanho P", "munições" acha "Munição .40". A regra é única (`App\Support\Busca`) e vale também na **Devolução de material** e no seletor do **Registrar entrega**.
- Devoluções parciais já são descontadas: o que voltou sai da linha; o lote mostra só o que falta voltar.
- **Situação do lote (2026-11-18):** lote (munição etc.) não tem situação própria; ela é calculada por entregue − devoluções ligadas à entrega e aparece junto dos itens: **"Entregue · 10 pendentes"**, **"Parcial: 4 de 10 devolvidos"**, **"Devolvido"** ou **"Consumo (sem retorno)"**. Vale no detalhe "Movimentação #N" (coluna *Situação atual*), na lista agrupada, na busca da devolução, no diálogo de devolução e em "Material comigo" do portal. Na API: `GET /movements/{id}` → `itens[].situacao_lote` {situacao, entregue, devolvido, pendente}; `GET /stock/linked` e `GET /portal/material` → `situacao = ENTREGUE`, `quantidade` (pendente), `quantidade_entregue`, `quantidade_devolvida`. O filtro `situacao=ENTREGUE` também traz os lotes.
- Acautelamento tem fluxo próprio (desacautelamento), aberto por **Ver acautelamento**.

## 2.1 Devolução de material (tela de balcão)

Tela `/devolucoes` (permissão `estoque.entregar`; a lista exige `estoque.ver`).

- **Abre já listando (decisão de 2026-11-18):** sem digitar nada, a tela mostra **um cartão por efetivo** que está com material da base (`GET /stock/linked/recipients`, escopo do armeiro): sigla de exibição + nome de guerra, nome, resumo **"2 entregas · 3 itens · 1 lote (30 unid.)"**, entrega mais antiga ("Desde …"), **previsão de retorno mais próxima** e selo **Atrasado** (com o número de entregas atrasadas) ou **No prazo**. Ordem: **atrasados primeiro**, depois a previsão mais próxima; acautelamento sem previsão vai ao fim. 20 por página.
- **Busca:** o mesmo campo de sempre — nome, nome de guerra, **matrícula**, **CPF**, **nº da entrega** (`1183` ou `#1183`) ou **comprovante do pedido** (`PED-7K2M9Q`) — agora **filtra a mesma lista** enquanto se digita (350 ms depois da última tecla; Enter aplica na hora). Matrícula e CPF só casam onde a matrícula completa é visível (`militares.ver_detalhe`).
- **Abrir o efetivo** (botão com `aria-expanded`) mostra as entregas dele (`GET /stock/linked?militar_id=`), com materiais, previsão, atraso e conservação. **Receber devolução** abre o mesmo diálogo da seção 3; arma acautelada abre o acautelamento para o desacautelamento. Depois de receber, a lista e as entregas se atualizam.
- **Vazio:** "Nenhum material com efetivos na base." (tudo voltou); com busca: "Nada pendente encontrado para …" e o botão **Ver todos os efetivos**.

## 3. Receber devolução

Botão **Receber devolução** em cada entrega (telas **Entrega de material** e **Devolução de material**; para o gestor, também em **Operações e pendências**). Permissão `estoque.entregar` no local da entrega.

- **Data e hora:** já vem com "agora". **Registrar com outra data/hora** permite uma devolução retroativa, que exige justificativa. Não pode ser no futuro nem antes da entrega.
- **Quem está devolvendo (regra de 2026-11-18):** **só o próprio efetivo que recebeu** o material. O diálogo mostra "Quem está devolvendo: RR CICERO LIMA" como confirmação; o armeiro confere a identidade. Não há mais as opções "Outro efetivo" e "Outra pessoa (sem cadastro)". O servidor recusa `devolvido_por_nome` e `devolvido_por_militar_id` diferente do efetivo da entrega com **422 `DEVOLUCAO_SO_PELO_EFETIVO`** e grava sempre o próprio efetivo. As colunas continuam (histórico antigo intacto). O desacautelamento de arma já não aceitava terceiro.
- **Itens:** todos marcados. Desmarque o que ainda não voltou para registrar uma devolução **parcial**; o restante continua pendente. Para lotes, informe a quantidade que voltou.
- **Estado de cada item:** já vem com o estado atual; mude para o estado em que o item chegou (Novo, Bom, Regular, Ruim, Danificado, Inservível).
- **Observação:** livre; vira a justificativa obrigatória quando a devolução é retroativa.

O que fica gravado:
- movimentação DEVOLUCAO imutável com `ocorrido_em`, `devolvido_por_militar_id` (sempre o efetivo da entrega; `devolvido_por_nome` só em registros antigos) e a observação;
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
- Quem só tem `estoque.entrada` (ex.: Operador) continua podendo atualizar apenas a conservação; o armeiro atualiza a conservação por `estoque.editar` (desde 2026-11-18 ele não tem `estoque.entrada`).

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

## 9. Sigla do efetivo e posto a atualizar (decisão de 2026-11-18)

Quase todo o efetivo de produção vem do banco `funcionarios` com posto **CIV** ("Civil / sem posto"), porque a origem não traz posto, mas com categoria RR, PM_ATIVA, BM_ATIVA, R1 ou VIGILANTE.
- **Exibição em todo o sistema** (listas, seleções, detalhe, pedidos, entregas, devoluções, acautelamentos, carteira, relatórios/exportações de movimentações e acautelamentos): com posto CIV, a sigla é a da categoria — **RR, PM, BM, VIG, R1**; sem categoria, só o nome (sem "CIV"); posto real (SD, 3SGT…) aparece como sempre. Função única: `Militar::siglaExibicao()` / `rotulo()` e os atributos `sigla_exibicao` / `rotulo_curto` no servidor; `components/efetivo.ts` (`siglaEfetivo`, `rotuloEfetivo`) no front-end. Os DTOs compactos (`posto` em entregas, pedidos, acautelamentos, portal, conta) já trazem a sigla de exibição; `posto_graduacao.sigla` continua com o posto real (cadastro e modelo de importação de efetivos).
- **Alerta agregado para a T.I.:** quem tem `militares.gerir` ou `usuarios.gerir` vê em Alertas "N — Efetivos RR/PM/BM com posto/graduação a atualizar" (no escopo; inativos fora), com link para **Efetivos** filtrada (`/militares?posto_a_atualizar=1`; caixa **Posto a atualizar** na tela; API `GET /militares?posto_a_atualizar=1`). O perfil e a lista mostram o selo "Posto a atualizar".
- **Notificação ao mudar a categoria:** quando a categoria passa a RR/PM_ATIVA/BM_ATIVA e o posto continua CIV — em `PUT /militares/{id}/categoria` e na sincronização com funcionários (atualização que muda a categoria; efetivo novo entra só no alerta agregado) — quem tem `militares.gerir`/`usuarios.gerir` na unidade (ou acima/global) recebe "Atualize o posto/graduação de <nome> (agora <categoria>)", com link para o perfil. Sem spam: quem já tem aviso **não lido** do mesmo efetivo não recebe outro. Auditoria `militar.aviso_posto` (categoria antes/depois e nº de destinatários), além do `militar.categoria_alterada` / `militar.sincronizado` de sempre.

## 10. Início do armeiro — cards por tipo de material (decisão de 2026-11-18)

Tela **Início** (`/`), dados de `GET /dashboard/materials` (exige `estoque.ver`; tudo no escopo — armeiro = sua base).

- **Operação do dia** (primeiro, porque pede ação): **Pedidos pendentes de aprovação** (com "Mais antigo há N dias"; link `/pedidos?status=PENDENTE`), **Aprovados aguardando retirada** (`/pedidos?status=APROVADO`) — só com `pedidos.gerir` — e **Entregas com devolução atrasada** (`/entregas?atrasados=1`). Número maior que zero ganha o selo em texto **"Requer ação"** e a borda de atenção.
- **Disponível na base:** um card por grupo do catálogo oficial, sempre na mesma ordem: **Armas de fogo, Munição, Coletes, Tasers, Cartuchos de Taser, Rádios, Algemas e Outros materiais**. Cada card: número grande de **disponíveis** (munição e cartuchos em unidades), linha secundária objetiva — "N com M efetivos" ou, para munição e cartuchos, **"Hoje: +entradas · −saídas · 7 dias: +/−"** —, selos **"N vencidos"** / **"N vencem em até 30 dias"** (ou "Validade em dia") quando o material tem validade, e links **Ver saldos** (`/estoque?grupo=…`) e **Com efetivos** (`/entregas?grupo=…`). As listas abertas pelo link mostram "Tipo de material: … · Ver todos".
- **Grupos sem nome fixo de material** (`App\Support\GrupoMaterial`): arma de fogo = tipo com perfil `ARMA_FOGO`; Taser = categoria 009 + perfil `BAIXA_LETALIDADE`; cartucho de Taser = 011 + perfil `ACESSORIO`; munição = demais da 011 (ou natureza MUNICAO); colete = perfil `COLETE` ou 001 + tipo oficial "Colete balístico"; rádio = 002 + tipo oficial "Rádio"; algemas = categoria 003; o resto, Outros.
- **Definições:** disponível = item individual DISPONÍVEL + saldo de lote menos o reservado; com efetivos = entregue/acautelado e ainda não devolvido (mesma fonte da Entrega de material); entradas = ENTRADA e DEVOLUÇÃO com destino na base; saídas = ENTREGA, SAÍDA e BAIXA com origem na base; "hoje" e "7 dias" no horário de Maceió; vencendo = até `sigarb.alertas.validade_item_dias` dias.
- **Responsivo e acessível:** 2 colunas no celular (1 abaixo de 360 px), 3 no tablet, 4 no desktop largo; cada card é um `article` com título; estado de carregamento (esqueleto com `role=status`) e vazio ("Nenhum material na base ainda.").
- **Gestores** (quem tem `movimentacoes.ver`) continuam vendo também **Indicadores gerais**, os gráficos e as últimas movimentações. O armeiro vê, no lugar, o gráfico **"Com efetivos agora, por tipo de material"** (`GraficoBarras`, com tabela acessível).
- **Ações do topo:** **Registrar entrega** é a ação principal de quem tem `estoque.entregar`. **Registrar entrada** aparece só para quem tem `estoque.entrada` (gestão); desde 2026-11-18 o ARMEIRO não tem mais essa permissão e não vê o botão.
- **Desempenho:** uma consulta agregada por bloco (disponível/validade, com efetivos, entradas/saídas, pedidos, entregas atrasadas), sem consulta por grupo ou material.

## 11. Pedidos de material — painel do armeiro (decisão de 2026-11-18)

Tela `/pedidos` (`pedidos.gerir`), redesenhada:
- **Abas de situação com contador:** Pendentes (padrão), Aguardando retirada, Entregues, Recusados, Cancelados e Todos. Os contadores vêm de `GET /material-requests/summary` (`por_status`) e seguem a busca e o período.
- **Filtros em uma linha:** busca por efetivo (nome, nome de guerra, matrícula) ou código (`PED-…`), **De** e **Até**. Filtra sem botão (350 ms depois da última tecla; Enter aplica na hora); **Limpar filtros** aparece quando há filtro. Tudo fica na URL.
- **Lista densa:** código PED- (link), efetivo (sigla de exibição + nome de guerra, nome e selo de aptidão), material resumido (selo "Letal" quando houver), feito em ("há N dias"), retirada prevista (e local), situação (selo) e **ações rápidas** conforme a situação e a permissão: **Aprovar** (confirma as quantidades pedidas; para reduzir, abre o pedido), **Recusar** (motivo obrigatório, mín. 5 caracteres), **Entregar** (abre o detalhe já com o diálogo de entrega e a conferência de identidade) ou **Ver**.
- **Pendentes e aguardando retirada vêm dos mais antigos para os mais novos.** Pedido pendente há **2 dias ou mais** ganha o selo **"Parado há N dias"** e uma faixa lateral; no detalhe, o aviso "Pedido parado".
- **Vazio:** Pendentes sem filtro = "Nenhum pedido aguardando aprovação. Fila em dia."; as demais abas explicam o fluxo em 3 passos (o efetivo pede no portal → o armeiro aprova/recusa → na retirada confere a identidade e entrega). Com filtro: "Nenhum pedido com estes filtros" + **Limpar filtros**.
- 15 por página; abaixo de 720 px a tabela vira cartões (padrão do sistema) e as abas rolam na horizontal.
- O resumo do pedido (`GET /material-requests`) passou a trazer `efetivo.nome`.

## 12. Situação do material na entrega (decisão de 2026-11-18)

- Em todas as telas que mostram material de uma entrega (detalhe da movimentação, Entrega de material, Devolução de material, diálogo de devolução e "Material comigo" do portal), a coluna mostra a situação **relativa à entrega**, igual para item individual e lote: **Entregue** (ainda com o efetivo), **Devolvido** (voltou por devolução ligada a esta entrega, mesmo que hoje esteja disponível ou já reentregue a outro), **Devolvido parcialmente: X de Y** (lote), **Consumido** (entrega sem retorno) e **Baixado após devolução** (voltou e foi baixado antes de sair de novo).
- "Disponível" não aparece nesse contexto. A situação atual do item no estoque, quando útil, vem como texto secundário discreto (ex.: "hoje: disponível na RES-POCO").
- Calculada no servidor pelo vínculo devolução ↔ entrega (`vinculo_id` / `vinculo_parcial_id`) — campo único `situacao_na_entrega` (`App\Support\SituacaoNaEntrega`).

## 13. Registrar saída, origem com "Outro…" e documentos (pedido de 2026-11-19)

- **Registrar saída** (`/estoque/saida`, botão em Saldos de estoque e "Saída" em cada linha com disponível): escolha **Saída definitiva** (exige destinação) ou **Baixa** (inservível/extraviado; aceita item em manutenção), o local, marque os itens ou digite a quantidade do lote (nunca acima do disponível), documento de referência e motivo (obrigatórios). Revisar → confirmar. Usa as operações SAIDA/BAIXA já existentes: registro imutável, idempotente, saldo nunca negativo, auditado. Vindo da linha de Saldos, local e lote já chegam escolhidos.
- **Origem da entrada** e **Destinação da saída**: digite para buscar entre as já usadas no seu escopo (mais usadas primeiro); ↑/↓ e Enter escolhem. Se não existir, **Outro…** libera o campo livre já com o texto digitado (Enter sem nenhuma opção, ou sair do campo, também leva ao "Outro…"). "Escolher da lista" volta.
- **Anexo opcional** na entrada e na saída (PDF, PNG, JPG até 10 MB) para quem tem `documentos.enviar`. O registro é gravado primeiro; o arquivo vai em seguida. Se o envio do arquivo falhar, o registro continua válido e aparece em **Documentos › Registros sem documento**.
- **Documentos**: busca única por título, arquivo, número, tipo, quem enviou e pelo registro ligado (origem/destinação, material, série, lote, "#123"); filtros por tipo, registro, período, situação, "enviados por mim" e versões anteriores. A aba **Registros sem documento** lista entradas/saídas/baixas sem anexo, com **Anexar** (quem registrou ou a gestão de documentos). No detalhe do documento, quem enviou pode **Enviar nova versão** (a anterior continua guardada).
- O ARMEIRO (sem `documentos.enviar`) registra saída e baixa na própria base, mas não vê o campo de anexo nem a tela Documentos.

## Banco de dados

Nenhuma migração nos itens 10, 11 e na Devolução já aberta (2.1): só consultas e telas.


Migração `2026_11_28_000100_notificacao_posto_a_atualizar` (aditiva): coluna nula `notificacoes.militar_id` (FK militares) para ligar o aviso ao efetivo e não duplicá-lo. `down()` remove a coluna.


Migração `2026_11_24_000100_armeiro_devolucao_edicao` (aditiva, colunas nulas; nenhum registro existente é reescrito):
- `movimentacoes.devolvido_por_militar_id` (FK militares) e `movimentacoes.devolvido_por_nome`;
- `movimentacao_itens.estado_conservacao` e `movimentacao_itens.estado_anterior`;
- permissão `estoque.editar` concedida a ARMEIRO, GESTOR e ADMIN.
`down()` remove as colunas e a permissão. Os triggers de imutabilidade do razão não são tocados.

Migração `2026_11_26_000100_armeiro_base_entrega_devolucao` (só matriz papel × permissão; nenhuma tabela nem movimentação tocada):
- permissões novas `movimentacoes.ver` e `inventario.ver` (concedidas a todo papel que já tinha `estoque.ver`, menos ARMEIRO) e `relatorios.entregas` (ARMEIRO e GESTOR);
- ARMEIRO perde `militares.ver`, `organizacao.ver`, `catalogo.ver`, `catalogo.gerir`, `documentos.ver`, `documentos.enviar`, `inventario.contar`, `inventario.aprovar`, `dados.importar` e `relatorios.exportar`.
`down()` devolve essas permissões ao ARMEIRO e apaga as três novas.
