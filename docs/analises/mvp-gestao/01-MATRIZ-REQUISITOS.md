# 01 — Matriz requisito a requisito

Legenda: **A** implementado e verificado · **B** implementado, incompleto · **C** implementado de outra forma, equivalente/melhor · **D** lacuna real · **E** proposta que regride/conflita · **F** depende de decisão da gestão.

Abreviações de evidência: `M:` migração em `backend/database/migrations/`, `S:` serviço em `backend/app/Services/`, `T:` teste em `backend/tests/Feature/`, `P:` página em `frontend/src/pages/`.

## 1. Colaboradores (Doc §1, §1.1 RF-COL)

| ID / campo | Classe | Evidência e observação |
|---|---|---|
| Entidade independente do login (Doc §1) | **A** | `militares` existe sem conta; `usuarios.militar_id` nullable **unique** (M:`2026_11_05_000200`). |
| RF-COL-01 CPF obrigatório e único | **F** (hoje: ausente por decisão) | P-09 registra minimização: "militar sem CPF/RG/endereço" (`docs/requisitos/PENDENCIAS-INSTITUCIONAIS.md`). Suporte mascara CPF em texto livre (`S:SuporteService.php:200`). Exige decisão LGPD. |
| Nome completo | **A** | `militares.nome` (160) + `nome_guerra` (não previsto no doc; útil). |
| Matrícula funcional | **A** | `militares.matricula` unique. T:`MilitarTest.php`. |
| Posto/graduação | **A** | FK `postos_graduacoes` (com `ordem`). |
| Quadro/categoria funcional | **D** | Não há campo. |
| Unidade principal (RF-COL-03) | **A** | `militares.unidade_id` NOT NULL, `restrictOnDelete`. |
| Subunidade | **C** | Unidades são árvore (`tipo` UNIDADE/SUBUNIDADE, `caminho`); a unidade do militar pode ser uma subunidade. |
| Vínculos secundários (RF-COL-04) | **D** | Não há tabela. |
| Função | **D** | Não há campo. |
| Situação funcional (Ativo/Inativo) | **A/C** | enum ATIVO/AFASTADO/INATIVO — mais rico que o doc. |
| Contatos institucionais (RF-COL-02) | **D** | Sem telefone/e-mail no militar (o usuário tem e-mail). |
| Foto (recomendado) | **A** | M:`2026_11_09_000100_foto_militar`, sanitização sem metadados (commit `50ff2d8`), T:`MilitarFotoTest.php`. |
| Dados de controle (cadastro, responsável, atualização) | **A/C** | `timestamps` + evento de auditoria encadeado com ator (S:`AuditoriaService`). |
| Observações administrativas | **D** | Não há campo (baixo custo). |
| RF-COL-05 consultar/editar/inativar sem apagar histórico | **A** | Inativação por `situacao`; FKs `restrictOnDelete`; auditoria append-only. |
| RF-COL-06 materiais atuais + histórico | **A/B** | P:`PerfilEfetivoPage.tsx` mostra armamento acautelado e entregas; `movimentacoes.militar_id`. Histórico de devolução depende de operações provisórias ligadas. |

## 2. Materiais (Doc §2, §1.2 RF-MAT)

| ID / campo | Classe | Evidência |
|---|---|---|
| RF-MAT-01 por categoria **e tipo** | **B** | `categorias` (com `natureza`) existe; **não há nível "tipo"**. |
| RF-MAT-02 só campos predefinidos | **A** | Esquema fixo; nenhum campo personalizado. |
| RF-MAT-03 individual (armas, coletes…) | **A** | `modo_controle=INDIVIDUAL`, `itens_individuais.identificador` unique, `(material_id, numero_serie)` unique. |
| RF-MAT-04 quantidade | **A** | `modo_controle=LOTE`, `lotes`, `saldos_estoque`. T:`EstoqueTest.php`, `ConcorrenciaTest.php`. |
| RF-MAT-05 individual **e** quantitativo conforme tipo ("AMBOS") | **F** | P-04: modo é definido por material e imutável após estoque. "AMBOS" no nível do *tipo* já é atendido: dois materiais do mesmo tipo com modos diferentes. Um mesmo material nos dois modos **não** deve existir (ambiguidade de saldo). |
| Descrição | **C** | `materiais.nome` (+ `codigo` unique). |
| Fabricante | **A** | FK `fabricantes` (nullable). |
| Marca | **D** | Não há campo. |
| Modelo | **A** | `materiais.modelo`. |
| Unidade de medida | **A** | `materiais.unidade_medida`. |
| Calibre | **A** | FK `calibres` — o doc o põe só em tabela especializada. |
| Localização atual | **A** | `itens_individuais.local_estoque_id`, `saldos_estoque(local, lote)`. |
| Unidade responsável (RF-MAT-07) | **C** | Derivada: `locais_estoque.unidade_id`. Campo próprio duplicaria e poderia divergir. |
| Estado de conservação | **B** | `itens_individuais.estado_conservacao` string; validação NOVO/BOM/REGULAR/RUIM (`EntradaRequest.php:25`). Faltam DANIFICADO/INSERVÍVEL e lista única. Lote não tem conservação. |
| Situação operacional | **A/C** | Enum de `itens_individuais.situacao` (DISPONIVEL, RESERVADO, ACAUTELADO, ENTREGUE, MANUTENCAO, EM_TRANSITO, FORA_DO_ESTOQUE, BAIXADO, ESTORNADO), alterada **somente** por movimentação. Equivalente às 9 do doc; "Aguardando avaliação" e "Bloqueado" faltam (F). |
| Data de aquisição / documento de origem | **C** | Movimentação de ENTRADA: `ocorrido_em`, `origem_descricao`, `documento_referencia`, documentos vinculados (`documento_vinculos`). |
| RF-MAT-08 sem foto de material | **A** | Não há. |
| RF-MAT-09 histórico de alterações e movimentações | **A** | Razão imutável (triggers `trg_movimentacoes_no_*`), auditoria com cadeia de hash (M:`000401`), T:`AuditoriaAdulteracaoTest.php`. |
| Campos patrimoniais excluídos (Doc §6) | **A** | Nenhum existe. |

## 3. Campos por categoria (Doc §3–§5)

| Categoria | Campos do doc ausentes hoje | Classe |
|---|---|---|
| Arma de fogo | tipo de arma, funcionamento, acabamento, país | **D** (calibre, série, modelo, fabricante: A) |
| Baixa letalidade | identificador quando sem série | **A** (`identificador` obrigatório sempre) |
| Colete | tamanho, nível de proteção, data de fabricação, **validade por unidade** | **D** — validade existe só em `lotes` |
| Munições (letal / BL distintas) | tipo de munição, data de fabricação | **B** — lote+validade+saldo: A; separação letal/BL via categorias distintas: possível hoje (F na classificação) |
| Acessórios | compatibilidade, cor, tamanho | **D** |
| Outras categorias (EPI, rádios…) | — | **A** — `categorias` é aberta; doc também desaconselha tabela por tipo |

## 4. Vínculo de materiais a colaboradores (Doc §1.3 RF-VIN)

| ID | Classe | Evidência |
|---|---|---|
| RF-VIN-01 vários materiais simultâneos | **A** | Sem limite por militar; `movimentacao.max_itens=200` por operação. |
| RF-VIN-02 cada entrega é uma operação | **A** | Movimentação `ENTREGA` com `chave_idempotencia` unique. |
| RF-VIN-03 data, responsável, recebedor, situação | **A** | `ocorrido_em`, `responsavel_id`, `militar_id`, `previsao_retorno`. |
| RF-VIN-04 devolução mantendo histórico | **A/B** | `DEVOLUCAO` referencia a entrega (`vinculo_id` unique, `OperacaoEstoqueService::CONCLUSOES`). **Devolução é integral** — parcial não existe (D, depende de P-05). |
| RF-VIN-05 item individual em um só colaborador | **A** (mais forte) | Situação única do item (ENTREGUE/ACAUTELADO) sob lock + `acautelamentos.uq_item_em_curso` (coluna gerada unique). T:`AcautelamentoTest.php`, `ConcorrenciaTest.php`. |
| RF-VIN-06 consulta por colaborador/unidade/categoria/situação | **B** | Perfil por militar e listas por escopo existem; falta filtro combinado "vinculados por categoria/situação". |
| Consumível sem devolução (Doc §2.4) | **B/F** | `ENTREGA` sem `previsao_retorno` sai do estoque (RN-007). Regra definitiva: P-05. |
| Estado das operações | **F** | Entrega/devolução/saída/baixa estão atrás de `SIGARB_OPERACOES_PROVISORIAS` (vazio por padrão, `config/sigarb.php:48`). O documento **não** é aprovação de P-05/P-06. |
