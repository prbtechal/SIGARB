# Catálogo oficial de categorias e tipos de material

Fonte: documento do gestor "Categorias e materiais" e decisões do responsável de 2026-11-11.
No código: `backend/app/Support/CatalogoOficial.php`. Aplicação: migração `2026_11_20_000100_catalogo_oficial_categorias`.

## Categorias do documento

| Código | Categoria | Natureza no SIGARB | Prefixo do item | Exemplos (documento) |
|---|---|---|---|---|
| 001 | EPI | EQUIPAMENTO | EPI | capacetes, escudos, óculos, máscaras |
| 002 | Comunicação | EQUIPAMENTO | COM | rádios, microfones, fones |
| 003 | Contenção | EQUIPAMENTO | CON | algemas e acessórios |
| 004 | Treinamento | TREINAMENTO | TRE | alvos e materiais de instrução (já existia, cadastro do gestor; nome e natureza mantidos, só ganhou o prefixo) |
| 005 | Sinalização | EQUIPAMENTO | SIN | lanternas e sinalizadores (já existia, cadastro do gestor; nome e natureza mantidos, só ganhou o prefixo) |
| 006 | Observação | EQUIPAMENTO | OBS | binóculos, equipamentos ópticos, drone |
| 007 | Acessórios operacionais | EQUIPAMENTO | ACO | bolsas, mochilas, suportes |
| 008 | Produtos | PRODUTO | PRO | sprays e outros produtos controlados |
| 009 | Armas | ARMAMENTO | ARM | Letal, Não letal |
| 010 | Equipamento tático | EQUIPAMENTO | TAT | coldre, cinto tático, porta-algema, porta-carregador, fiel retrátil |

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

## Identificador do item individual

Desde a migração `2026_11_20_000200_identificador_automatico_itens`, o sistema gera o identificador ao registrar o item (entrada, carga, importação CSV). O formato é o prefixo da categoria, um hífen e uma sequência de 6 dígitos, por exemplo `ARM-000001`.

- A sequência é por prefixo e vale para o sistema todo. Não depende de local nem de unidade, então o item muda de lugar sem trocar de código.
- O código não muda depois de gerado. O número de série continua em `numero_serie`, informado pelo usuário ou pela planilha.
- O prefixo fica em `categorias.prefixo_codigo`. Categoria sem prefixo usa `ITM`.
- O último número de cada prefixo fica em `sequencias_identificador`. A linha é travada (`FOR UPDATE`) dentro da transação da entrada, em ordem fixa de prefixo. Se a entrada falhar, o número volta, então a sequência não tem buracos nem repetições.
- Não usamos o id do item como número: o id é compartilhado por todas as categorias e não daria uma sequência por prefixo.
- `POST /movements`, entrada: enviar `itens.*.identificador` é recusado com 422, para o cliente não achar que o valor foi gravado. Na importação CSV, a coluna `identificador` saiu do modelo, e um arquivo que ainda a tenha é recusado com `COLUNA_DESCONHECIDA`.
- Itens que já existiam (identificador = prefixo antigo + série, por exemplo `ARM-SXH-15937`) receberam o código novo na ordem do id. O número de série não mudou.
- Movimentações e acautelamentos apontam para o item pelo id. Eventos antigos de auditoria podem citar o código antigo; cada troca gerou o evento `item.identificador_regenerado` (antigo, novo, série), que serve de mapa.
- Carga do inventário: um item com série já cadastrado para o mesmo material conta como "já carregado". Um item sem série usa a chave da posição no documento, que é a mesma de antes. Por isso recarregar a planilha depois da migração não duplica nada.
