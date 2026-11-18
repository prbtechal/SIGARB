# Matriz de permissões — PROPOSTA (não aprovada)

Fonte executável: `backend/config/sigarb_permissoes.php`. ARMEIRO (CP-13/CP-14): operação completa da reserva, inclusive baixa, saída, transferência e aprovações; não cadastra efetivos. Segregação: ninguém aprova o próprio pedido. Atribuições podem ter prazo (delegação, P-06). Escopo: a permissão vale na unidade atribuída **e descendentes**; atribuição sem unidade = global.

| Permissão | ADMIN | GESTOR | ARMEIRO | OPERADOR | AUDITOR | APROVADOR | EFETIVO |
|---|:-:|:-:|:-:|:-:|:-:|:-:|:-:|
| organizacao.ver | ✔ | ✔ | ✔ | ✔ | ✔ | ✔ | |
| organizacao.gerir (unidades/locais) | ✔ | | | | | | |
| usuarios.ver / usuarios.gerir (exige escopo global) | ✔ | | | | | | |
| sessoes.revogar (de outros) | ✔ | | | | | | |
| militares.ver | ✔ | ✔ | ✔ | ✔ | ✔ | ✔ | |
| militares.ver_detalhe (matrícula completa) | | ✔ | ✔ | | | | |
| militares.gerir | | ✔ | | | | | |
| catalogo.ver | ✔ | ✔ | ✔ | ✔ | ✔ | ✔ | |
| catalogo.gerir (global) | ✔ | ✔ | ✔ | | | | |
| estoque.ver | | ✔ | ✔ | ✔ | ✔ | ✔ | |
| estoque.entrada | | ✔ | ✔ | ✔ | | | |
| estoque.estornar | | ✔ | ✔ | | | | |
| estoque.saida / baixar / transferir | | ✔ | ✔ | | | | |
| estoque.reservar / manutencao / entregar (entrega e devolução, inclusive parcial) | | ✔ | ✔ | | | | |
| estoque.editar (corrigir dados de item/lote com motivo; ajustar quantidade de lote — AJUSTE sem aprovação) — decisão 2026-11-16 | ✔ | ✔ | ✔ | | | | |
| acautelamento.solicitar / acautelamento.operar (entrega, inspeção, desacautelamento, carteira) | | ✔ | ✔ | | | | |
| acautelamento.aprovar (nunca o próprio pedido) | | ✔ | ✔ | | | ✔ | |
| inventario.contar | | ✔ | ✔ | | | | |
| inventario.aprovar (nunca quem abriu ou contou) | | ✔ | ✔ | | | ✔ | |
| relatorios.exportar (CSV não oficial) | | ✔ | ✔ | | ✔ | | |
| dados.importar (planilhas; listas de referência só com escopo global) | ✔ | ✔ | ✔ | | | | |
| auditoria.ver | | | | | ✔ | | |
| referencias.gerir (listas de referência; exige escopo global) | ✔ | | | | | | |
| documentos.ver (consultar/baixar) | | ✔ | ✔ | ✔ | ✔ | | |
| documentos.enviar | | ✔ | ✔ | ✔ | | | |
| documentos.gerir (metadados, arquivar, vincular) | | ✔ | | | | | |
| suporte.abrir (abrir e acompanhar os PRÓPRIOS chamados) | ✔ | ✔ | ✔ | ✔ | ✔ | ✔ | |
| suporte.atender (fila, atribuição, status, notas internas — por escopo) | ✔ | | | | | | |
| portal.efetivo (fazer, acompanhar e cancelar os PRÓPRIOS pedidos; ver o próprio material) — decisão 2026-11-18 | | | | | | | ✔ |
| pedidos.gerir (painel de pedidos, aprovar/recusar, entregar com conferência — por escopo) — decisão 2026-11-18 | | ✔ | ✔ | | | ✔ | |

Regras adicionais aplicadas no servidor:
- Portal do efetivo (decisão 2026-11-18, `docs/operacao/PORTAL-EFETIVO.md`): EFETIVO é criado no primeiro acesso pelo CPF, no escopo da unidade do efetivo, e só tem `portal.efetivo` (não é privilegiado; sem MFA obrigatório). Pedido e material são filtrados pelo efetivo ligado à conta: pedido de outro = 404; telas de gestão = 403. Entregar um pedido exige `pedidos.gerir` na unidade do efetivo **e** `estoque.entregar` no local de origem; arma de fogo exige também `acautelamento.operar` (entrega) e `acautelamento.aprovar` (aprovação). APROVADOR aprova/recusa, mas não entrega (não tem `estoque.entregar`).
- Segregação: ninguém altera os próprios papéis, desativa a si mesmo ou revoga as próprias sessões pela rota administrativa.
- Unidade raiz só por escopo global; subunidade exige `organizacao.gerir` na superior.
- APROVADOR decide acautelamentos e ajustes de inventário (regra provisória), sem operar estoque.
- Segregação provisória: quem pede acautelamento não decide; quem abriu ou contou qualquer linha do inventário não aprova o ajuste (registro append-only de contadores).
- Entrega a efetivo exige `militares.ver` na unidade do efetivo; carteira mostra matrícula completa só com `militares.ver_detalhe`.
- ADMIN não opera estoque nem acessa documentos (administrar contas não implica ler documentos sensíveis). Exceção decidida pelo responsável em 2026-11-16: ADMIN recebe `estoque.editar`; como continua sem `estoque.ver`, na prática só a usa se também receber um papel que veja o estoque (as telas de saldo e itens exigem `estoque.ver`).
- `estoque.editar`: item/lote fora do escopo (sem `estoque.ver` nem `estoque.editar` na unidade) responde 404; no escopo sem a permissão, 403. Lote exige a permissão em **todas** as unidades onde tem saldo. OPERADOR mantém só a atualização de conservação (`estoque.entrada`).
- Documento fora do escopo responde 404; quem enxerga mas não gere recebe 403.
- Central de Suporte: o solicitante vê só os próprios chamados; o atendimento vê chamados das unidades do seu escopo de `suporte.atender` (chamado sem unidade = só escopo global). Fora disso: 404 uniforme (sem enumeração).
- `suporte.atender` **não** concede dados organizacionais: ADMIN atende chamados mas continua sem `estoque.ver`/`documentos.ver` (teste automatizado).
- Nota interna nunca é devolvida ao solicitante; solicitante não altera situação, prioridade nem responsável (403).

**Questões para a instituição:** catálogo é global ou por organização? Operador pode registrar entrada? Quem estorna e em que prazo (PE-02)? Auditor por unidade vê eventos de autenticação?
