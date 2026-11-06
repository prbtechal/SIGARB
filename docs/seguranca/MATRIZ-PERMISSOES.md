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
| auditoria.ver | | | | ✔ | |
| referencias.gerir (listas de referência; exige escopo global) | ✔ | | | | |
| documentos.ver (consultar/baixar) | | ✔ | ✔ | ✔ | |
| documentos.enviar | | ✔ | ✔ | | |
| documentos.gerir (metadados, arquivar, vincular) | | ✔ | | | |

Regras adicionais aplicadas no servidor:
- Segregação: ninguém altera os próprios papéis, desativa a si mesmo ou revoga as próprias sessões pela rota administrativa.
- Unidade raiz só por escopo global; subunidade exige `organizacao.gerir` na superior.
- APROVADOR não tem nenhuma ação operacional até P-06.
- ADMIN não opera estoque nem acessa documentos (administrar contas não implica ler documentos sensíveis).
- Documento fora do escopo responde 404; quem enxerga mas não gere recebe 403.

**Questões para a instituição:** catálogo é global ou por organização? Operador pode registrar entrada? Quem estorna e em que prazo (PE-02)? Auditor por unidade vê eventos de autenticação?
