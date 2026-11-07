# 06 — Recomendação, custo/benefício e roteiro

**Recomendação: MANTER A ARQUITETURA ATUAL E ESTENDER SELETIVAMENTE.** Não redesenhar.

Esforço: P (≤1 dia), M (2–4 dias), G (≥1 semana), incluindo testes. Nenhum item bloqueia implantação do piloto.

## Categoria 1 — Adotar agora
| # | Item | Benefício | Esforço | Migração | Risco | Testes |
|---|---|---|---|---|---|---|
| 1.1 | Conservação: lista única NOVO/BOM/REGULAR/RUIM/DANIFICADO/INSERVÍVEL, constante compartilhada em `EntradaRequest` e `AcautelamentoController` | Consistência | P | Não | Baixo | Feature de validação |
| 1.2 | Rótulos situação × conservação; situação só leitura | Clareza | P | Não | Baixo | Vitest |
| 1.3 | Formulário de material condicional por natureza/modo | Menos erro | P | Não | Baixo | Vitest + E2E |
| 1.4 | Filtro de vinculados por categoria/situação | RF-VIN-06 | P–M | Não | Baixo | Feature de escopo |

## Categoria 2 — Adotar depois (com aprovação)
| # | Item | Dependência | Esforço | Migração | Prioridade |
|---|---|---|---|---|---|
| 2.1 | `tipos_material` + atributos opcionais fixos + obrigatoriedade por tipo | Gestão validar lista de tipos/campos (D-3) | M | Aditiva | Alta |
| 2.2 | Validade/fabricação/tamanho/nível em item (colete) + alerta | 2.1 | M | Aditiva | Alta |
| 2.3 | Militar: quadro, função, contatos, observações | D-2 | P | Aditiva | Média |
| 2.4 | Vínculos secundários temporais | D-4 | M | Aditiva | Média |
| 2.5 | CPF cifrado com unicidade por HMAC | **P-09** (D-5) | M | Aditiva + chave | Condicional |
| 2.6 | Devolução parcial | **P-05** (D-6) | M | Enum | Condicional |
| 2.7 | Situações "Aguardando avaliação" e "Bloqueado" | P-03 (D-7) | M | Enum + operação | Baixa |

## Categoria 3 — Não adotar
| Proposta | Motivo |
|---|---|
| Tabela `colaboradores` | Duplica `militares`; cria duas fontes de verdade sobre pessoa |
| `lotes_estoque.saldo_atual` / `quantidade_recebida` | Duplica saldo transacional e razão; risco de divergência |
| `entregas`/`devolucoes`/itens | Duplica movimentações imutáveis e idempotentes |
| Tabelas especializadas por categoria | Joins e migrações sem ganho; Doc §5 concorda |
| `auditorias`, `historico_situacoes` mutáveis | Mais fracas que a cadeia de hash existente |
| `marcas`, `modelos` como tabelas | Colunas bastam no MVP |
| Documento com "entidade relacionada" genérica | Perde FK; o modelo atual de vínculo tipado é melhor |
| Lote preso a um local | Impede saldo do mesmo lote em vários locais (já suportado) |
| Mesmo material em modo AMBOS | Saldo ambíguo |

O documento **não** habilita entrega, devolução, baixa ou aprovação: continuam atrás de `SIGARB_OPERACOES_PROVISORIAS` até P-03/P-05/P-06.
