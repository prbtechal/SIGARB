# Matriz de permissões — PROPOSTA (não aprovada)

Fonte executável: `backend/config/sigarb_permissoes.php`. Escopo: a permissão vale na unidade atribuída **e descendentes**; atribuição sem unidade = global.

| Permissão | ADMIN | GESTOR | OPERADOR | AUDITOR | APROVADOR |
|---|:-:|:-:|:-:|:-:|:-:|
| organizacao.ver | ✔ | ✔ | ✔ | ✔ | ✔ |
| organizacao.gerir (unidades/locais) | ✔ | | | | |
| usuarios.ver / usuarios.gerir (exige escopo global) | ✔ | | | | |
| sessoes.revogar (de outros) | ✔ | | | | |
| militares.ver | ✔ | ✔ | ✔ | ✔ | ✔ |
| militares.ver_detalhe (matrícula completa) | | ✔ | | | |
| militares.gerir | | ✔ | | | |
| catalogo.ver | ✔ | ✔ | ✔ | ✔ | ✔ |
| catalogo.gerir (global) | | ✔ | | | |
| estoque.ver | | ✔ | ✔ | ✔ | ✔ |
| estoque.entrada | | ✔ | ✔ | | |
| estoque.estornar | | ✔ | | | |
| estoque.saida / baixar / transferir / reservar / manutencao / entregar (regra provisória) | | ✔ | | | |
| acautelamento.solicitar / acautelamento.operar (entrega, inspeção, desacautelamento, carteira) | | ✔ | | | |
| acautelamento.aprovar (nunca o próprio pedido) | | | | | ✔ |
| inventario.contar | | ✔ | | | |
| inventario.aprovar (nunca quem abriu ou contou) | | | | | ✔ |
| relatorios.exportar (CSV não oficial) | | ✔ | | ✔ | |
| auditoria.ver | | | | ✔ | |
| referencias.gerir (listas de referência; exige escopo global) | ✔ | | | | |
| documentos.ver (consultar/baixar) | | ✔ | ✔ | ✔ | |
| documentos.enviar | | ✔ | ✔ | | |
| documentos.gerir (metadados, arquivar, vincular) | | ✔ | | | |
| suporte.abrir (abrir e acompanhar os PRÓPRIOS chamados) | ✔ | ✔ | ✔ | ✔ | ✔ |
| suporte.atender (fila, atribuição, status, notas internas — por escopo) | ✔ | | | | |

Regras adicionais aplicadas no servidor:
- Segregação: ninguém altera os próprios papéis, desativa a si mesmo ou revoga as próprias sessões pela rota administrativa.
- Unidade raiz só por escopo global; subunidade exige `organizacao.gerir` na superior.
- APROVADOR decide acautelamentos e ajustes de inventário (regra provisória), sem operar estoque.
- Segregação provisória: quem pede acautelamento não decide; quem abriu ou contou qualquer linha do inventário não aprova o ajuste (registro append-only de contadores).
- Entrega a efetivo exige `militares.ver` na unidade do efetivo; carteira mostra matrícula completa só com `militares.ver_detalhe`.
- ADMIN não opera estoque nem acessa documentos (administrar contas não implica ler documentos sensíveis).
- Documento fora do escopo responde 404; quem enxerga mas não gere recebe 403.
- Central de Suporte: o solicitante vê só os próprios chamados; o atendimento vê chamados das unidades do seu escopo de `suporte.atender` (chamado sem unidade = só escopo global). Fora disso: 404 uniforme (sem enumeração).
- `suporte.atender` **não** concede dados organizacionais: ADMIN atende chamados mas continua sem `estoque.ver`/`documentos.ver` (teste automatizado).
- Nota interna nunca é devolvida ao solicitante; solicitante não altera situação, prioridade nem responsável (403).

**Questões para a instituição:** catálogo é global ou por organização? Operador pode registrar entrada? Quem estorna e em que prazo (PE-02)? Auditor por unidade vê eventos de autenticação?
