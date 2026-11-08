# Importação e exportação de cadastros (planilha)

Tela: **Cadastros → Importar e exportar** (`/dados`). API: `GET /data-transfer`, `GET /data-transfer/{cadastro}/template`,
`POST /data-transfer/{cadastro}/import` (multipart `arquivo`, `simulacao` 1/0), `GET /data-transfer/{cadastro}/export`.

Quem prefere pode continuar cadastrando tudo pelas telas. A planilha é só outro caminho para os **mesmos** cadastros, com as mesmas regras.

## Como preencher

1. Na tela, escolha o cadastro e clique em **Baixar modelo**. O arquivo vem com o cabeçalho e uma linha de exemplo.
2. Abra no Excel, apague a linha de exemplo e preencha uma linha por registro. **Não mude os nomes das colunas.** Colunas opcionais podem ficar vazias ou ser removidas.
3. Salve com **Arquivo → Salvar como → CSV UTF-8 (delimitado por vírgulas)**. O Excel em português usa `;` como separador; o sistema aceita `;` e `,`.
4. Envie em **Verificar planilha**. O sistema confere o arquivo inteiro e lista os problemas por linha, sem gravar nada.
5. Sem problemas, clique em **Importar**. Se qualquer linha falhar, **nada** é gravado (tudo ou nada). Corrija e envie de novo.

Limites: 2.000 linhas e 2 MB por arquivo. Datas no formato `AAAA-MM-DD`. Maiúsculas/minúsculas não importam em códigos de situação, perfil, modo e conservação.

## Regras que valem para todos

- Cada linha passa pelas **mesmas validações e permissões** da tela (escopo da unidade, campos obrigatórios por perfil do tipo, unicidade).
- Códigos de outra tabela são procurados pelo **código ou nome** indicado na coluna. Um local ou unidade fora do seu escopo responde como "não encontrado".
- **Coluna fora do modelo é recusada** (por exemplo `cpf`): dado não previsto não entra no sistema (P-09).
- Toda importação, simulação e exportação fica na trilha de auditoria. Cada registro criado também gera o evento normal de criação.
- Não há atualização por planilha: código já existente é erro. Para corrigir um registro, use a tela.

## Ordem recomendada para uma carga inicial

1. Listas de referência (Administrador): categorias → fabricantes → calibres → níveis de proteção → tipos de material.
2. Catálogo de materiais (Gestor ou Armeiro).
3. Efetivos (Gestor).
4. Estoque: entrada de itens e lotes (Gestor ou Armeiro).

## Colunas por cadastro (`*` = obrigatória)

### Categorias — `categorias` (Administrador)
| Coluna | Valores |
|---|---|
| codigo* | 2–30 caracteres: `A-Z`, `0-9`, `_`, `-` |
| nome* | texto |
| natureza* | `ARMAMENTO`, `MUNICAO`, `EQUIPAMENTO`, `PRODUTO` ou `TREINAMENTO` |

### Fabricantes — `fabricantes` · Calibres — `calibres` (Administrador)
| Coluna | Valores |
|---|---|
| nome* | texto único (calibre: até 40 caracteres) |

### Níveis de proteção — `niveis-protecao` (Administrador) — **lista oficial da P-04**
| Coluna | Valores |
|---|---|
| nome* | até 20 caracteres, único (ex.: `IIIA`). Não muda depois que algum item usar o nível. |
| descricao | texto livre |

### Tipos de material — `tipos-material` (Administrador) — **lista oficial da P-04**
| Coluna | Valores |
|---|---|
| categoria_codigo* | código de uma categoria ativa |
| nome* | único dentro da categoria |
| perfil* | `GERAL`, `ARMA_FOGO`, `BAIXA_LETALIDADE`, `COLETE`, `MUNICAO` ou `ACESSORIO` (define os campos obrigatórios do material e do item) |
| descricao | texto livre |

### Materiais — `materiais` (Gestor, Armeiro)
| Coluna | Valores |
|---|---|
| codigo* | 2–40 caracteres: `A-Z`, `0-9`, `.`, `_`, `-` |
| nome* | texto |
| categoria_codigo* | código da categoria |
| tipo | nome do tipo dentro da categoria (vazio = tipo "Geral") |
| modo_controle* | `INDIVIDUAL` (com número de identificação) ou `LOTE` (por quantidade) |
| fabricante, calibre | nome cadastrado (obrigatórios conforme o perfil do tipo) |
| modelo, marca, compatibilidade, cor, tamanho, unidade_medida | texto (aplicáveis conforme o perfil) |

### Efetivos — `efetivos` (Gestor)
| Coluna | Valores |
|---|---|
| matricula* | 4–30 caracteres: `A-Z`, `0-9`, `-` |
| nome*, nome_guerra* | texto |
| posto_sigla* | sigla do posto/graduação (ex.: `SD`, `CB`, `3SGT`) |
| unidade_sigla* | sigla de uma unidade do seu escopo |
| situacao | `ATIVO` (padrão), `AFASTADO` ou `INATIVO` |
| quadro, funcao | texto |
| telefone_institucional | 8–20 caracteres: dígitos, espaço, `( ) + -` |
| email_institucional | e-mail institucional |

Não há coluna para CPF, RG, endereço ou dados de saúde (P-09).

### Estoque (entrada de itens e lotes) — `estoque` (Gestor, Armeiro)
Linhas com o mesmo `local_codigo` + `documento_referencia` + `origem` viram **uma** entrada (até 200 itens por entrada).

| Coluna | Valores |
|---|---|
| local_codigo* | código de um local de estoque do seu escopo |
| documento_referencia* | nota, termo ou ofício que comprova a entrada |
| origem* | de onde veio o material |
| material_codigo* | código do material |
| identificador | **material individual:** número de identificação único |
| numero_serie | material individual |
| estado_conservacao | `NOVO`, `BOM`, `REGULAR`, `RUIM`, `DANIFICADO` ou `INSERVIVEL` |
| tamanho, nivel_protecao, fabricado_em | conforme o perfil (colete exige tamanho, nível e fabricação) |
| validade | `AAAA-MM-DD`; não pode ser anterior à fabricação |
| lote_codigo, quantidade | **material por lote** |
| fabricante | nome do fabricante do lote |

## Exportação

Cada cadastro pode ser baixado em CSV no mesmo formato do modelo. Sai só o que você já pode ver:
- **Efetivos:** sem a permissão de detalhe na unidade, a matrícula sai mascarada (`•••123`) e contatos saem vazios.
- **Estoque:** fotografia atual (itens no estoque e saldos de lote) do seu escopo; documento e origem vêm vazios. Não reimporte
  a exportação de estoque: isso duplicaria a entrada.
- Valores que começam com `=`, `+`, `-` ou `@` saem com `'` na frente (proteção contra fórmulas). A importação remove esse `'`.
