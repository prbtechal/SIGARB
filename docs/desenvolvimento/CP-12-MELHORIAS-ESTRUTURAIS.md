# CP-12 — Melhorias estruturais do MVP (base: documento da gestão + `docs/analises/mvp-gestao/`)

Extensão incremental da arquitetura existente. **Nada foi substituído**: razão (`movimentacoes`/`movimentacao_itens`), `saldos_estoque`, `acautelamentos`, inventário e cadeia de auditoria seguem iguais. Nenhuma tabela `colaboradores`, `entregas`, `devolucoes`, `auditorias`, `historico_situacoes` ou `saldo_atual` foi criada. Operações bloqueadas continuam atrás de `SIGARB_OPERACOES_PROVISORIAS`.

## Banco de dados (4 migrações novas, todas aditivas)

| Migração | Mudança | Dados existentes |
|---|---|---|
| `2026_11_10_000100_tipos_material` | Tabela `tipos_material` (categoria_id FK restrict, nome, descricao, `perfil` enum, ativo, desativado_em; UNIQUE categoria+nome). `materiais.tipo_id` (FK **nullable**), `marca`, `compatibilidade`, `cor`, `tamanho` | Backfill determinístico: cada categoria recebe o tipo "Geral" (perfil GERAL, sem campos obrigatórios novos); materiais sem tipo passam a apontar para ele. IDs preservados. `tipo_id` **não** foi tornado NOT NULL. |
| `2026_11_10_000200_atributos_item_individual` | `itens_individuais.fabricado_em`, `validade` (indexada), `tamanho`, `nivel_protecao`; CHECK `chk_item_validade` (validade ≥ fabricação) | Colunas nulas; nenhum item alterado. |
| `2026_11_10_000300_militares_dados_funcionais` | `militares.quadro`, `funcao`, `telefone_institucional`, `email_institucional`, `observacoes_administrativas` (todos nulos) | Registros existentes continuam válidos. **Sem CPF.** |
| `2026_11_10_000400_militar_unidades` | Vínculos secundários temporais (`inicio`, `fim`, quem registrou/encerrou); coluna gerada `vinculo_ativo` UNIQUE (um ativo por militar+unidade); CHECK fim ≥ início; trigger sem DELETE; trigger que só permite encerrar (fim NULL→data) | Tabela nova. |

### Dono de cada fato (sem duplicação)
- **Material (modelo de referência):** marca, compatibilidade, cor, tamanho; espécie/funcionamento/acabamento da arma continuam em `ficha_tecnica` (CP-10) — não foram criadas colunas `tipo_arma`/`funcionamento`/`acabamento` repetidas.
- **Item físico:** fabricação, validade, tamanho, nível de proteção (cada colete tem a própria etiqueta).
- **Lote:** validade do lote (inalterada).
- **Situação operacional:** só muda por operação transacional (inalterado). **Conservação:** condição física, alterável por endpoint próprio auditado. **Validade:** apenas informativa — vencer não bloqueia nem muda a situação (não há regra aprovada).

### Reversão
- `down()` existe em todas, mas **descarta** tipos, atributos e vínculos cadastrados depois. Reverter **código** não reverte o banco. Em base com dados reais, a reversão é por **restauração do backup** feito pelo `implantar.sh` antes de migrar (fluxo já exigido pelo runbook: `--migrar` + backup verificado).
- O código anterior (CP-11) **funciona** com o esquema novo (colunas novas nulas e tabelas extras são ignoradas) — a única incompatibilidade é o `migrate:status` "banco à frente do código", que o `implantar.sh`/`reverter.sh` já detectam e bloqueiam por design.

## Regras por perfil de tipo (`App\Support\PerfilMaterial`, campos fixos — sem campos personalizados)

| Perfil | Controle | Obrigatório no material | Aplicável no material | Obrigatório no item | Aplicável no item |
|---|---|---|---|---|---|
| GERAL | qualquer | — | marca, cor, tamanho | — | — |
| ARMA_FOGO | INDIVIDUAL | fabricante, modelo, calibre | marca (+ ficha técnica) | número de série | — |
| BAIXA_LETALIDADE | INDIVIDUAL | fabricante, modelo | marca | — (identificador já é obrigatório) | — |
| COLETE | INDIVIDUAL | fabricante | marca | tamanho, nível de proteção, fabricação | validade |
| MUNICAO | LOTE | fabricante | marca | — (lote/validade/quantidade como antes) | — |
| ACESSORIO | qualquer | — | marca, compatibilidade, cor, tamanho | — | — |

- "AMBOS" = o tipo admite materiais individuais **e** materiais por lote (registros distintos). O mesmo material nunca tem dois modos.
- Munição letal e de baixa letalidade: categorias distintas com tipos de perfil MUNICAO (controles de estoque não se misturam).
- Material criado sem `tipo_id` recebe o tipo "Geral" da categoria (compatibilidade de API). Material legado sem tipo segue editável; depois de ter tipo, não pode ficar sem.
- Níveis de proteção aceitos (provisório, NIJ): IIA, II, IIIA, III, IV.
- Conservação (lista única, `App\Support\Conservacao`): NOVO, BOM, REGULAR, RUIM, DANIFICADO, INSERVIVEL — usada na entrada, no desacautelamento e na atualização autorizada.

## API nova/alterada

| Método e rota | Permissão | Observação |
|---|---|---|
| `GET /catalog/tipos-material[?categoria_id=]` | `catalogo.ver` | Tipos ativos + regras de perfis + níveis de proteção (para o formulário). |
| `GET/POST/PUT /reference-lists/tipos-material…` (+ deactivate/reactivate) | `referencias.gerir` (global) | Perfil e categoria não mudam com o tipo em uso (409 `VALOR_EM_USO`). |
| `POST/PUT /items` | inalterada | Aceita `tipo_id`, `marca`, `compatibilidade`, `cor`, `tamanho`; valida o perfil. |
| `POST /movements` (ENTRADA) | inalterada | Itens aceitam `fabricado_em`, `validade`, `tamanho`, `nivel_protecao`, conservação ampliada. |
| `GET /stock/individual-items` | `estoque.ver` | Novos filtros: `unidade_id`, `categoria_id`, `tipo_id`, `perfil`, `validade=VENCIDO|VENCENDO|VALIDO|COM_VALIDADE`. |
| `PATCH /stock/individual-items/{id}/conservation` | `estoque.entrada` na unidade do item | Justificativa ≥ 10; auditado; não toca a situação; fora do escopo = 404. |
| `GET /stock/linked` | `estoque.ver` (escopo na query) | RF-VIN-06: acautelamentos ativos + entregas com retorno pendentes; filtros `militar_id`, `unidade_id`, `categoria_id`, `tipo_id`, `perfil`, `modo_controle`, `custodia`, `situacao`. Não expõe matrícula. |
| `GET/POST /militares/{id}/secondary-units`, `POST …/{vinculo}/end` | ver / `militares.gerir` | Unidade de destino precisa estar no escopo de consulta do usuário. **Não concede acesso.** |
| `GET /alerts` | — | Novos `ITEM_VENCIDO` / `ITEM_VENCENDO` (antecedência `SIGARB_ALERTA_VALIDADE_ITEM_DIAS`, padrão 180). |

## Privacidade
- Contatos institucionais e observações só aparecem com `militares.ver_detalhe` (mesma regra da matrícula completa); o formulário não os envia quando o usuário não os vê (não sobrescreve às cegas).
- Na auditoria, telefone/e-mail/observações são gravados como `[informado]`, nunca em claro.
- CPF não é coletado (P-09 aberta).

## Telas
- **Catálogo:** tipo filtrado pela categoria; campos obrigatórios/aplicáveis pelo perfil; modo de controle travado quando o perfil exige; calibre; aviso explícito antes de descartar campos preenchidos que deixam de se aplicar.
- **Nova entrada:** conservação (lista única) e, para colete, tamanho/nível/fabricação/validade com obrigatoriedade marcada.
- **Itens e validades** (`/estoque/itens`, nova): filtros por validade/perfil/tipo/unidade/situação, selo de validade, atualização de conservação com justificativa.
- **Material com efetivos** (`/estoque/vinculados`, nova): consulta combinada RF-VIN-06; atalho no perfil do efetivo.
- **Efetivo:** quadro, função, contatos (restritos), observações; perfil mostra e gerencia unidades secundárias (ativas e histórico).
- **Listas de referência:** aba "Tipos de material" (categoria, perfil, descrição).

## Pendências que continuam bloqueadas (sem simulação)
- **CPF** (P-09): finalidade, base legal, acesso e retenção.
- **Devolução parcial e consumo sem retorno** (P-05): devolução segue integral.
- **Situações "Aguardando avaliação" e "Bloqueado"** e bloqueio por validade vencida (P-03).
- **Lista oficial de tipos e de níveis de proteção** (P-04): perfis e níveis são provisórios.
