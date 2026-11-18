# Portal do efetivo — pedido prévio de material

Decisão do responsável do projeto em 2026-11-18. Não é aceite institucional.
Objetivo: reduzir pedidos presenciais. Qualquer efetivo (RR PM/BM, PM ativo, BM ativo, R1, Vigilante) entra no Arsenal, **pede o material antes**; o armeiro **aprova antecipadamente**; na retirada, o armeiro **confere a identidade e entrega**.

## 1. Acesso do efetivo — primeiro acesso pelo CPF

- Login = CPF. No **primeiro acesso**, a pessoa digita o CPF como login **e** como senha.
- A conta é criada só nesta combinação: não existe usuário com aquele login, existe militar **ATIVO** com aquele CPF, ele ainda **não tem conta** e a senha digitada é **igual ao CPF**. Qualquer outra combinação recebe a resposta genérica de sempre ("CPF ou senha inválidos…"), sem revelar se o CPF é de um efetivo.
- A conta nasce com: login = CPF, nome do militar, `militar_id`, **senha provisória** (troca obrigatória antes de usar o sistema, fluxo já existente) e o papel **EFETIVO** no escopo da unidade do militar.
- Os limites de tentativa valem igual (3 erros no mesmo CPF + IP = espera de 5 min; limites por conta e por IP; bloqueio após 10 falhas). A criação só acontece depois de passar por eles.
- Auditoria: `usuario.criado` (com `origem: primeiro_acesso`, login mascarado e militar) e `acesso.papel.atribuido` (EFETIVO, `origem: primeiro_acesso`).
- Corrida: dois primeiros acessos simultâneos não criam duas contas (unicidade de `usuarios.login` e `usuarios.militar_id` no banco; a segunda requisição usa a conta que ficou).
- Implementação: `AuthController::primeiroAcesso()`.

## 2. Papéis e permissões

| Permissão | Quem tem | Para quê |
|---|---|---|
| `portal.efetivo` | EFETIVO | Fazer, acompanhar e cancelar os **próprios** pedidos; ver o **próprio** material e perfil |
| `pedidos.gerir` | ARMEIRO, GESTOR, APROVADOR (no escopo) | Painel de pedidos, aprovar/recusar, entregar |

- EFETIVO **não é privilegiado** (`Usuario::PERMISSOES_PRIVILEGIADAS` não inclui nada do portal) e não está em `sigarb.mfa.papeis_obrigatorios`: sem MFA obrigatório.
- A matriz continua editável pela tela **Permissões**; o portal decide o menu pelas permissões, não pelo código do papel.
- O escopo do pedido é a **unidade do efetivo** no momento do pedido (`pedidos_material.unidade_id`). Armeiro de unidade superior vê os pedidos das subunidades.
- Entregar exige `pedidos.gerir` na unidade do pedido **e** `estoque.entregar` no local de origem. Arma de fogo exige ainda `acautelamento.aprovar` (para aprovar) e `acautelamento.operar` (para entregar). APROVADOR aprova/recusa, mas não entrega.
- EFETIVO recebe 403 em todas as rotas de gestão (estoque, movimentações, efetivos, usuários, acautelamentos, auditoria, documentos, painel de pedidos…). Pedido de outro efetivo responde 404.

## 3. Pedido de material

Tabelas (migração aditiva `2026_11_25_000100_portal_efetivo_pedidos`): `pedidos_material`, `pedido_material_itens`, `pedido_material_acautelamentos` e a coluna `notificacoes.pedido_material_id`.

- **Pedido:** código público (ULID; comprovante curto `PED-` + 6 últimos caracteres), efetivo, conta que pediu, situação, retirada prevista (opcional, até 60 dias), local de retirada (opcional: locais ativos da unidade do efetivo e das superiores), observação, decisão (quem, quando, motivo da recusa, observação do armeiro), entrega (quem, quando, movimentação ENTREGA, acautelamentos), versão.
- **Linhas:** material do catálogo (ativo), quantidade pedida (item individual = 1 por linha; lote = 1…1000), quantidade **aprovada** (o armeiro pode reduzir) e quantidade **entregue**.
- **Situações:** `PENDENTE → APROVADO | RECUSADO | CANCELADO`; `APROVADO → ENTREGUE | CANCELADO`. Toda transição trava o pedido e confere a versão (409 se mudou).

Regras (todas no servidor):
- Arma de fogo e munição (naturezas `ARMAMENTO`/`MUNICAO`, `Militar::NATUREZAS_LETAIS`) só para `Militar::APTAS_LETAL` (RR, PM ativo, BM ativo). Validado na **criação**, na **aprovação** e na **entrega** (o tipo do efetivo pode mudar no meio). Recusa: 422 `EFETIVO_NAO_APTO_LETAL`.
- Efetivo sem tipo cadastrado não pede letal; o catálogo mostra o motivo "Procure a T.I.".
- Só o próprio efetivo cria e cancela o seu pedido; cancelar só enquanto `PENDENTE` ou `APROVADO`.
- No máximo **3 pedidos em aberto** (pendente ou aprovado) por efetivo (`SIGARB_PEDIDOS_LIMITE_ABERTOS`); o limite é conferido com o efetivo travado (sem corrida). Criação limitada a 10/min, cancelamento a 20/min.
- Quem fez o pedido não decide (salvo os papéis de autoaprovação já existentes, com auditoria).
- Recusa exige motivo (≥ 5 caracteres). Aprovação precisa de pelo menos uma linha com quantidade.

## 4. Entrega — decisão sobre arma de fogo

O sistema já tem dois caminhos de saída para um efetivo:
1. **ENTREGA** (`POST /movements`): material com o efetivo, com ou sem previsão de retorno; devolução pela tela do armeiro.
2. **ACAUTELAMENTO**: cautela individual de **um item**, com aprovação, **carteira** (QR) e **inspeção** periódica; encerra por desacautelamento.

Decisão (deste trabalho): **arma de fogo (tipo de material com perfil `ARMA_FOGO`) sai por ACAUTELAMENTO**, porque é assim que o sistema controla arma com uma pessoa (carteira e inspeção). Para cada arma escolhida, `PedidoMaterialService` chama o `AcautelamentoService` existente:
- solicitação em nome do **efetivo** (dono do pedido),
- decisão pela pessoa que **aprovou o pedido** (a aprovação antecipada é a decisão do acautelamento; segregação mantida),
- entrega por quem está **entregando** agora (movimentação ENTREGA do acautelamento, item ACAUTELADO, carteira emitida).

Todo o resto (EPI, rádio, colete, munição, acessórios…) sai numa **única movimentação ENTREGA** (`OperacaoEstoqueService::abrir`), com `documento_referencia = PEDIDO-<código>` e chave de idempotência derivada do código (uma ENTREGA por pedido). Com previsão de retorno fica pendente com o efetivo; sem data é consumo. Munição é letal mas é lote: vai pela ENTREGA (com a mesma validação de aptidão).

Entrega **única**: o armeiro escolhe itens (série) e lotes do local que atendem cada linha, até a quantidade aprovada. O que não for entregue fica registrado (`quantidade_entregue < quantidade_aprovada`, `parcial: true` na auditoria) e o pedido vira `ENTREGUE`; o restante exige novo pedido. Não há "aprovado com entrega parcial" pendente — evita pedido aberto indefinidamente e mantém o limite de pedidos simples.

Idempotência: `Idempotency-Key` obrigatório. Mesma chave repetida devolve o mesmo resultado (200, `Idempotent-Replayed: true`); outra chave num pedido já entregue: 409 `JA_ENTREGUE`. Tudo numa transação: se a arma falhar (ex.: efetivo deixou de ser apto), nada é gravado.

Conferência de identidade: a tela de entrega mostra foto, nome, posto, matrícula (completa só com `militares.ver_detalhe`), CPF mascarado (`•••.123.456-••`) e o selo de aptidão.
Desde 2026-11-18 o armeiro não tem `militares.ver` (tela Efetivos): a foto da conferência é liberada com `pedidos.gerir`/`estoque.entregar` na unidade do efetivo, e ele mantém `militares.ver_detalhe` para a matrícula completa. Pedido entregue aparece em **Entrega de material** com o comprovante, e a tela **Devolução de material** acha a entrega pelo `PED-XXXXXX`.

## 5. Notificações e alertas

- Pedido novo: notificação para quem tem `pedidos.gerir` na unidade (ou acima/global); link para `/pedidos/<código>`.
- Aprovação, recusa e entrega: notificação para o efetivo; link para `/portal/pedidos/<código>`.
- Alertas: `PEDIDO_PENDENTE` e `PEDIDO_A_RETIRAR` para o armeiro; `MEU_PEDIDO_APROVADO` e `MINHA_DEVOLUCAO_ATRASADA` para o efetivo. Contador de pendentes no item de menu **Pedidos de material**.

## 6. Auditoria

`pedido.criado`, `pedido.cancelado`, `pedido.aprovado` (com as reduções), `pedido.recusado` (motivo), `pedido.entregue` (local, movimentação, acautelamentos, linhas, parcial). A entrega também gera os eventos de sempre: `estoque.entrega`, `acautelamento.solicitado/aprovado/entregue`.

## 7. Telas

Efetivo (menu enxuto quando a conta só tem `portal.efetivo`):
- **Início** (`/`): pedidos em aberto, material com ele, devoluções atrasadas, aviso de tipo de efetivo não cadastrado.
- **Novo pedido** (`/portal/novo`): catálogo por categoria, bloqueados com o motivo, quantidade, retirada prevista, local, observação.
- **Meus pedidos** (`/portal/pedidos`) e detalhe com andamento, linhas, motivo da recusa, cancelar e **comprovante** do pedido aprovado (código `PED-XXXXXX` para mostrar ao armeiro).
- **Material comigo** (`/portal/material`): entregas pendentes de devolução (previsão, atrasado) e armas acauteladas.
- **Meu perfil**, **Segurança da conta**, **Minhas sessões** e **Sobre** (já existentes).

Quem gere e também é efetivo (conta com `militar_id` e os dois papéis) vê o grupo **Meu portal** no menu.

Armeiro: **Pedidos de material** (`/pedidos`, filtros por situação, efetivo/código e data) e detalhe (`/pedidos/<código>`) com conferência, Aprovar (ajuste das quantidades e observação), Recusar (motivo) e **Entregar pedido**. A busca aceita o código do comprovante.

## 8. API

Ver `docs/api/openapi.yaml`: `/portal/*` (efetivo) e `/material-requests*` (armeiro).
