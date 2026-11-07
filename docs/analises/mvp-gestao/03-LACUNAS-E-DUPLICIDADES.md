# 03 — Lacunas, duplicidades e respostas Q1–Q15

## Lacunas reais (D)
1. **Tipo de material** abaixo de categoria (RF-MAT-01).
2. **Atributos por categoria**: marca; colete (tamanho, nível de proteção, fabricação, validade por unidade); arma (tipo, funcionamento, acabamento); acessório (compatibilidade, cor, tamanho); munição (tipo).
3. **Estado de conservação** sem lista única; faltam DANIFICADO e INSERVÍVEL.
4. **Colaborador**: quadro, função, contatos institucionais, observações; vínculos secundários de unidade.
5. **Devolução parcial** (só integral hoje) — depende de P-05.
6. **Consulta combinada** de vinculados por categoria/situação (RF-VIN-06).

## Duplicidades (proposta repete o que existe)
`colaboradores`=`militares`; `entregas`/`entrega_itens`/`devolucoes`/`devolucao_itens`=`movimentacoes`+`movimentacao_itens`(+`acautelamentos`); `lotes_estoque.saldo_atual`=`saldos_estoque` derivado do razão; `auditorias`/`historico_situacoes`=auditoria encadeada + razão; `perfis`/`usuario_unidades`=`papeis`/`atribuicoes_papel.unidade_id`; `movimentacoes` já existe.

## Q1–Q15
- **Q1** Manter `militares`, estender. Mesmo conceito (pessoa que recebe material, sem login obrigatório). Renomear tabela teria custo alto (FKs em `usuarios`, `movimentacoes`, `acautelamentos`, auditoria histórica com nome da entidade) e ganho nulo; a interface já diz "Efetivos" (CP-10). Se houver civis recebendo material, decidir à parte (07, D-1).
- **Q2** Não é necessário para operar: matrícula já é identificador único. CPF amplia risco LGPD e não está aprovado (P-09). Se exigido: só dígitos, DV validado, cifrado, unicidade por HMAC, acesso por permissão própria, registros legados permitidos sem CPF (obrigatório só para novos, decisão da gestão).
- **Q3** Não representados. Se a gestão confirmar uso (ex.: emprego em outra unidade para receber material), modelo temporal (`inicio/fim`) é necessário — vínculo encerrado não pode ser apagado. Atenção: vínculo secundário **não** deve ampliar escopo de autorização de usuários (escopo vem de `atribuicoes_papel`).
- **Q4** INDIVIDUAL e LOTE sim (por material). AMBOS no nível do tipo é atendido criando materiais distintos; AMBOS no mesmo material não é recomendado (P-04).
- **Q5** Parcialmente: calibre e fabricante normalizados; faltam tipo e atributos de colete/acessório.
- **Q6** Não. Colunas opcionais fixas + regra de obrigatoriedade por tipo + formulário condicional é mais barato e mantém RF-MAT-02. Ver `02` §5.
- **Q7** Campos independentes — já são. Situação operacional deve continuar **enum controlado pelo razão** (mudá-la à mão quebraria a reconciliação). Conservação: lista fechada no código (enum/validação) basta; tabela de referência só se a gestão quiser editar a lista sem release (as referências atuais usam `ativo`, ver `ReferenciaService`).
- **Q8** Sim, duplica. O saldo atual é `saldos_estoque.quantidade` atualizado só por `EstoqueService` dentro da transação do lançamento, com CHECK e reconciliável pelo razão. "Quantidade recebida" é derivável (soma de ENTRADAS do lote). Não adicionar.
- **Q9** Não. O DER não tem chave de idempotência, imutabilidade nem locks; `devolucoes` mutável permitiria editar o passado. O modelo atual preserva tudo isso (T:`ConcorrenciaTest.php`, `OperacoesProvisoriasTest.php`).
- **Q10** Já resolvido melhor que o doc propõe: transição de situação do item sob `lockForUpdate` (só DISPONIVEL → ENTREGUE/ACAUTELADO) + `uq_item_em_curso` no banco. O DER não tem restrição equivalente.
- **Q11** Não adequadamente: sem reserva, sem estorno; consumíveis e parcial só em texto. O SIGARB tem reserva (`quantidade_reservada` + CHECK), estorno (`estorno_de_id` unique), entrega sem retorno (RN-007); falta parcial.
- **Q12** O DER não trata escopo; documentos com "entidade relacionada" genérica enfraquecem integridade e filtragem. Manter Policies + `AutorizacaoService` e listas filtradas na query.
- **Q13** Ver `04` (impacto por mudança).
- **Q14** Já existem e não devem ser refeitos: cadastro/perfil de efetivo com foto (`MilitarFormPage`, `PerfilEfetivoPage`), catálogo e referências (`MateriaisPage`, `ReferenciasPage`), entrada (`NovaEntradaPage`), operações (`NovaOperacaoPage`), saldos, movimentações, documentos, acautelamento, inventário, organização, usuários, auditoria.
- **Q15** Ver `05`: formulário por tipo com só os campos aplicáveis, validade de colete com alerta, lista de conservação fechada, filtro de "material vinculado" por categoria/situação no perfil e na lista.
