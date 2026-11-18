# Matriz de permissões — PROPOSTA (não aprovada)

Fonte executável: `backend/config/sigarb_permissoes.php` (mudanças em bancos existentes por migração; última: `2026_11_29_000200_depositos_so_gestao`). ARMEIRO (CP-13/CP-14, revisto em 2026-11-18): opera a reserva **da sua base** (não registra entrada — é da gestão — e não vê depósitos) — pedidos, entrega e devolução, baixa, saída, transferência, ajuste, acautelamento e relatório de entregas/devoluções; **não** acessa as telas de gestão (Movimentações, Operações e pendências, Efetivos, Documentos, Inventários, Catálogo, Importar e exportar, Unidades e locais). Segregação: ninguém aprova o próprio pedido. Atribuições podem ter prazo (delegação, P-06). Escopo: a permissão vale na unidade atribuída **e descendentes**; atribuição sem unidade = global.

| Permissão | ADMIN | GESTOR | ARMEIRO | OPERADOR | AUDITOR | APROVADOR | EFETIVO |
|---|:-:|:-:|:-:|:-:|:-:|:-:|:-:|
| organizacao.ver (tela Unidades e locais) | ✔ | ✔ | | ✔ | ✔ | ✔ | |
| organizacao.gerir (unidades/locais) | ✔ | | | | | | |
| usuarios.ver / usuarios.gerir (exige escopo global) | ✔ | | | | | | |
| sessoes.revogar (de outros) | ✔ | | | | | | |
| militares.ver (tela Efetivos) | ✔ | ✔ | | ✔ | ✔ | ✔ | |
| militares.ver_detalhe (matrícula completa) | | ✔ | ✔ | | | | |
| militares.gerir | | ✔ | | | | | |
| catalogo.ver (tela Catálogo) | ✔ | ✔ | | ✔ | ✔ | ✔ | |
| catalogo.gerir (global) | ✔ | ✔ | | | | | |
| estoque.ver | | ✔ | ✔ | ✔ | ✔ | ✔ | |
| estoque.itens (tela Itens e validades; decisão de 2026-11-18: gestores para cima) | | ✔ | | | ✔ | ✔ | |
| estoque.entrada (registrar entrada; decisão de 2026-11-18: é da gestão, sai do ARMEIRO — migração `2026_11_29_000100`) | | ✔ | | ✔ | | | |
| estoque.depositos (ver e operar locais do tipo DEPÓSITO; decisão de 2026-11-18: gestores para cima — migração `2026_11_29_000200`) | ✔ | ✔ | | | ✔ | ✔ | |
| estoque.estornar | | ✔ | ✔ | | | | |
| estoque.saida / baixar / transferir | | ✔ | ✔ | | | | |
| estoque.reservar / manutencao / entregar (entrega e devolução, inclusive parcial) | | ✔ | ✔ | | | | |
| estoque.editar (corrigir dados de item/lote com motivo; ajustar quantidade de lote — AJUSTE sem aprovação) — decisão 2026-11-16 | ✔ | ✔ | ✔ | | | | |
| acautelamento.solicitar / acautelamento.operar (entrega, inspeção, desacautelamento, carteira) | | ✔ | ✔ | | | | |
| acautelamento.aprovar (nunca o próprio pedido) | | ✔ | ✔ | | | ✔ | |
| inventario.ver (tela Inventários) — 2026-11-18 | | ✔ | | ✔ | ✔ | ✔ | |
| inventario.contar | | ✔ | | | | | |
| inventario.aprovar (nunca quem abriu ou contou) | | ✔ | | | | ✔ | |
| relatorios.exportar (CSV não oficial; também exportação de cadastros) | | ✔ | | | ✔ | | |
| relatorios.entregas (relatório de entregas e devoluções por período, CSV/XLSX) — 2026-11-18 | | ✔ | ✔ | | | | |
| movimentacoes.ver (telas Movimentações e Operações e pendências) — 2026-11-18 | | ✔ | | ✔ | ✔ | ✔ | |
| dados.importar (planilhas; listas de referência só com escopo global) | ✔ | ✔ | | | | | |
| auditoria.ver | | | | | ✔ | | |
| referencias.gerir (listas de referência; exige escopo global) | ✔ | | | | | | |
| documentos.ver (consultar/baixar) | | ✔ | | ✔ | ✔ | | |
| documentos.enviar | | ✔ | | ✔ | | | |
| documentos.gerir (metadados, arquivar, vincular) | | ✔ | | | | | |
| suporte.abrir (abrir e acompanhar os PRÓPRIOS chamados) | ✔ | ✔ | ✔ | ✔ | ✔ | ✔ | |
| suporte.atender (fila, atribuição, status, notas internas — por escopo) | ✔ | | | | | | |
| portal.efetivo (fazer, acompanhar e cancelar os PRÓPRIOS pedidos; ver o próprio material) — decisão 2026-11-18 | | | | | | | ✔ |
| pedidos.gerir (painel de pedidos, aprovar/recusar, entregar com conferência — por escopo) — decisão 2026-11-18 | | ✔ | ✔ | | | ✔ | |

Regras adicionais aplicadas no servidor:
- **Armeiro na própria base (decisão 2026-11-18):** atribuir ARMEIRO exige `unidade_id` de uma unidade **abaixo da raiz** (ex.: Poço); global ou a raiz PRB = 422 `ESCOPO_BASE_OBRIGATORIO` (`sigarb.papeis_por_base`). Todas as listas do armeiro são filtradas na query pelo escopo da atribuição (base + seções).
- **Fluxos do armeiro sem as telas de gestão (2026-11-18):** listas de seleção usam a permissão de operação no escopo — `GET /units` e `GET /locations` aceitam `organizacao.ver` **ou** `estoque.ver`; `GET /items`, `/items/{id}` e `/catalog/*` aceitam `catalogo.ver` **ou** `estoque.ver` (escrita continua `catalogo.gerir`); busca de efetivo `GET /stock/recipients` com `estoque.entregar`/`acautelamento.solicitar`/`pedidos.gerir` (só ativos do escopo, sem contatos); foto do efetivo com `militares.ver`, `estoque.entregar`, `pedidos.gerir` ou `acautelamento.operar` na unidade dele; efetivo da ENTREGA e quem devolve com `militares.ver` **ou** `estoque.entregar` na unidade do efetivo; solicitar acautelamento com `militares.ver` **ou** `acautelamento.solicitar`. `GET /movements` (lista) exige `movimentacoes.ver`; o detalhe `GET /movements/{id}` ("Entrega #N") continua com `estoque.ver`. `GET /inventories` exige `inventario.ver`; `GET /data-transfer` responde 403 sem `dados.importar` nem `relatorios.exportar`.
- O armeiro mantém `militares.ver_detalhe` (matrícula completa na conferência do pedido e busca por matrícula/CPF); sozinha ela não abre a tela Efetivos (que exige `militares.ver`).
- Consequência registrada: sem `movimentacoes.ver`, o armeiro não usa a tela Operações e pendências (recebimento de transferência, liberação de reserva, retorno de manutenção); o alerta "Transferências a receber" só aparece para quem tem a tela. A tela **Nova operação** (saída, transferência, reserva, manutenção, baixa) continua com `estoque.ver` + a permissão de cada operação.
- Portal do efetivo (decisão 2026-11-18, `docs/operacao/PORTAL-EFETIVO.md`): EFETIVO é criado no primeiro acesso pelo CPF, no escopo da unidade do efetivo, e só tem `portal.efetivo` (não é privilegiado; sem MFA obrigatório). Pedido e material são filtrados pelo efetivo ligado à conta: pedido de outro = 404; telas de gestão = 403. Entregar um pedido exige `pedidos.gerir` na unidade do efetivo **e** `estoque.entregar` no local de origem; arma de fogo exige também `acautelamento.operar` (entrega) e `acautelamento.aprovar` (aprovação). APROVADOR aprova/recusa, mas não entrega (não tem `estoque.entregar`).
- Segregação: ninguém altera os próprios papéis, desativa a si mesmo ou revoga as próprias sessões pela rota administrativa.
- Unidade raiz só por escopo global; subunidade exige `organizacao.gerir` na superior.
- APROVADOR decide acautelamentos e ajustes de inventário (regra provisória), sem operar estoque.
- Segregação provisória: quem pede acautelamento não decide; quem abriu ou contou qualquer linha do inventário não aprova o ajuste (registro append-only de contadores).
- Entrega a efetivo exige `militares.ver` **ou** `estoque.entregar` na unidade do efetivo (2026-11-18); carteira mostra matrícula completa só com `militares.ver_detalhe`.
- ADMIN não opera estoque nem acessa documentos (administrar contas não implica ler documentos sensíveis). Exceção decidida pelo responsável em 2026-11-16: ADMIN recebe `estoque.editar`; como continua sem `estoque.ver`, na prática só a usa se também receber um papel que veja o estoque (as telas de saldo e itens exigem `estoque.ver`).
- `estoque.editar`: item/lote fora do escopo (sem `estoque.ver` nem `estoque.editar` na unidade) responde 404; no escopo sem a permissão, 403. Lote exige a permissão em **todas** as unidades onde tem saldo. OPERADOR mantém só a atualização de conservação (`estoque.entrada`).
- Documento fora do escopo responde 404; quem enxerga mas não gere recebe 403.
- Central de Suporte: o solicitante vê só os próprios chamados; o atendimento vê chamados das unidades do seu escopo de `suporte.atender` (chamado sem unidade = só escopo global). Fora disso: 404 uniforme (sem enumeração).
- `suporte.atender` **não** concede dados organizacionais: ADMIN atende chamados mas continua sem `estoque.ver`/`documentos.ver` (teste automatizado).
- Nota interna nunca é devolvida ao solicitante; solicitante não altera situação, prioridade nem responsável (403).

**Questões para a instituição:** catálogo é global ou por organização? Operador pode registrar entrada? Quem estorna e em que prazo (PE-02)? Auditor por unidade vê eventos de autenticação?

## Depósitos só para a gestão (decisão de 2026-11-18)
- Locais `tipo = DEPOSITO` (ex.: DEP-SMB) servem à retomada de estoque, à conferência (documento de auditoria) e à distribuição entre unidades; o armeiro trabalha só com a reserva da base.
- Sem `estoque.depositos` na unidade do depósito, o local some de **todas** as listas e contagens (saldos, itens, detalhe do material, `/locations`, Início/painel, alertas, entrega/devolução, pedidos, relatório de entregas, exportações, inventários) e qualquer operação a partir dele ou para ele responde **403** (`DEPOSITO_SO_GESTAO`).
- Filtro centralizado em `AutorizacaoService` (`depositosOcultos`, `ocultarDepositos`, `locaisVisiveis`, `exigirLocalVisivel`), aplicado nas consultas (nunca no front-end).
- OPERADOR não recebe a permissão (padrão "gestores para cima"); a matriz continua editável em Papéis e permissões.

