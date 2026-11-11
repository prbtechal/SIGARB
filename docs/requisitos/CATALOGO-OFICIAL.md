# Catálogo oficial de categorias e tipos de material

Fonte: documento do gestor "Categorias e materiais" e decisões do responsável de 2026-11-11.
No código: `backend/app/Support/CatalogoOficial.php`. Aplicação: migração `2026_11_20_000100_catalogo_oficial_categorias`.

## Categorias do documento

| Código | Categoria | Natureza no SIGARB | Exemplos (documento) |
|---|---|---|---|
| 001 | EPI | EQUIPAMENTO | capacetes, escudos, óculos, máscaras |
| 002 | Comunicação | EQUIPAMENTO | rádios, microfones, fones |
| 003 | Contenção | EQUIPAMENTO | algemas e acessórios |
| 004 | Treinamento | TREINAMENTO | alvos e materiais de instrução (já existia, cadastro do gestor, não alterada) |
| 005 | Sinalização | EQUIPAMENTO | lanternas e sinalizadores (já existia, cadastro do gestor, não alterada) |
| 006 | Observação | EQUIPAMENTO | binóculos, equipamentos ópticos, drone |
| 007 | Acessórios operacionais | EQUIPAMENTO | bolsas, mochilas, suportes |
| 008 | Produtos | PRODUTO | sprays e outros produtos controlados |
| 009 | Armas | ARMAMENTO | Letal, Não letal |
| 010 | Equipamento tático | EQUIPAMENTO | coldre, cinto tático, porta-algema, porta-carregador, fiel retrátil |

001, 002 e 003 haviam sido apagadas por engano e foram recriadas com os mesmos códigos.

## Tipos de material e controle

O SIGARB modela categoria → tipo. A divisão Letal/Não letal de Armas é rótulo no nome do tipo, sem nova hierarquia.
O modo de controle (individual ou lote) é do material (P-04). O perfil do tipo só restringe o modo quando o documento fixa um único modo.
Toda categoria tem também o tipo "Geral".

| Tipo (documento) | Categoria | Tipo no SIGARB | Perfil | Controle |
|---|---|---|---|---|
| Arma de fogo | 009 Armas | Arma de fogo (letal) | ARMA_FOGO | individual |
| Taser ou equipamento semelhante | 009 Armas | Taser ou equipamento semelhante (não letal) | BAIXA_LETALIDADE | individual |
| Acessórios de Taser (cartuchos, baterias, coldres) | 009 Armas | Acessório de Taser (não letal) | ACESSORIO | individual ou lote |
| Munição letal | 009 Armas | Munição letal | GERAL (ver nota) | por lote (definido no material) |
| Munição de baixa letalidade | 009 Armas | Munição de baixa letalidade | MUNICAO | por lote |
| Colete balístico | 001 EPI | Colete balístico | GERAL (ver nota) | individual (definido no material) |
| Rádio | 002 Comunicação | Rádio | GERAL | definido no material |
| Algemas e acessórios | 003 Contenção | Algemas e acessórios | GERAL | definido no material |
| Coldre, Cinto tático, Porta-algema, Porta-carregador, Fiel retrátil, Porta-objetos | 010 Equipamento tático | um tipo para cada | ACESSORIO | individual ou lote ("ambos, conforme o tipo") |

Nota: "Munição letal" e "Colete balístico" usam o perfil GERAL. Os perfis MUNICAO e COLETE exigem fabricante, nível de proteção e data de fabricação, e os materiais da carga de 2026 não têm esses dados. Um tipo em uso não muda de perfil depois (`ReferenciaService`). Para passar a exigir esses campos, é preciso uma decisão e uma migração própria, depois que os materiais tiverem os dados.

Natureza: a munição passou da natureza MUNICAO para ARMAMENTO, porque a categoria Armas é ARMAMENTO. As duas naturezas são letais (`Militar::NATUREZAS_LETAIS`), então as regras de entrega não mudam. O gráfico "por natureza" do painel passa a somar a munição em Armamento.

## Mapeamento aplicado aos materiais da carga de 2026

| Categoria / tipo na carga | Categoria / tipo oficial |
|---|---|
| ARMAMENTO / Pistola | 009 Armas / Arma de fogo (letal) |
| TASER / Dispositivo de energia | 009 Armas / Taser ou equipamento semelhante (não letal) |
| TASER / Acessório de Taser | 009 Armas / Acessório de Taser (não letal) |
| MUNICAO / Geral | 009 Armas / Munição letal |
| COLETE / Geral | 001 EPI / Colete balístico |
| ALGEMA / Geral | 003 Contenção / Algemas e acessórios |
| COMUNICACAO / Geral | 002 Comunicação / Rádio |

A migração altera só `materiais.categoria_id` e `materiais.tipo_id`. Itens, lotes, saldos e movimentações apontam para o material pelo id e não mudam.
Cada material movido gera o evento `material.atualizado` (antes e depois, com o motivo `catalogo-oficial`). Categorias e tipos criados ou removidos também geram eventos.
Depois disso, as categorias e os tipos da carga sem material são apagados. Um tipo da carga sem mapeamento seria levado com o mesmo nome e perfil para a categoria de destino.
A migração é idempotente. `down()` não desfaz nada; para reverter, restaure o backup.

A carga (`sigarb:inventario:importar`) passou a usar o mesmo mapeamento (`CatalogoOficial::CARGA`).
