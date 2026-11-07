# Avaliação do "SIGARB — Cadastro do MVP" (gestão) frente ao SIGARB implementado

- **Data:** 2026-11-07 · **Base avaliada:** `main` = `origin/main` = `50ff2d8` (CP-11)
- **Documento:** `SIGARB — Cadastro do MVP.docx` (fora do repositório; não versionado, não alterado). 535 linhas de texto, 1 imagem (DER, `image1.png`, inspecionada visualmente).
- **Natureza:** análise e decisão. Nenhuma migração, tabela, regra ou fluxo foi alterado.

> Referências de página: o DOCX não tem numeração de página no XML; usamos **seções do documento** (ex.: "Doc §2.4"). O DER é a figura da seção "3. DER lógico preliminar" (a "página 11" citada no pedido).

## Pergunta: dado o que o SIGARB já faz, o que mudar com base no documento, e por quê?

**Resposta curta: manter a arquitetura atual e estender seletivamente.** O DER proposto é um modelo genérico de *almoxarifado com empréstimo* (cadastro → item/lote → entrega → devolução). O SIGARB já tem esse núcleo, e de forma mais forte: razão de movimentações imutável (triggers), saldo com `CHECK`, idempotência, travas de concorrência, acautelamento com aprovação segregada, inventário e auditoria encadeada por hash. Adotar o DER como está **trocaria garantias testadas por tabelas mutáveis** (`saldo_atual`, `entregas`/`devolucoes` sem imutabilidade, `auditorias` simples).

O documento, porém, traz **lacunas reais de cadastro** que valem a pena:

| Adotar agora (sem mudar fluxos bloqueados) | Adotar depois (exige decisão/migração) | Não adotar |
|---|---|---|
| Lista fechada de **estado de conservação** (Novo…Inservível) e validação única | **Tipos de material** abaixo de categoria + atributos por tipo (calibre, tamanho, nível de proteção, validade do colete, compatibilidade) | `colaboradores` como tabela nova (duplica `militares`) |
| **Formulário por categoria** no front (mostrar só campos aplicáveis) | **Vínculos secundários** de unidade com início/fim | `saldo_atual` em lote (duplica `saldos_estoque` + razão) |
| Exibir **marca** e **validade** onde já existem | **Quadro/categoria funcional, função, contatos institucionais** | `entregas`/`devolucoes` separadas (duplica `movimentacoes` + `acautelamentos`) |
| Terminologia: "situação operacional" ≠ "estado de conservação" na interface | **CPF** — só após decisão LGPD (P-09) | Tabelas especializadas por categoria (`materiais_armas_fogo`, …) |
| | **Devolução parcial** e consumo sem retorno (P-05) | `auditorias`/`historico_situacoes` mutáveis no lugar da cadeia de hash |
| | Modo de controle **AMBOS** (P-04) | `marcas` e `modelos` como tabelas no MVP |

## Sobre "ficou muito parecido com o do aéreo"
Não há no repositório nem na estação nenhum documento/modelo do sistema "aéreo"; **a semelhança direta não pôde ser verificada**. O que se verifica é que o DER é **genérico de controle patrimonial/empréstimo**: não representa aprovação, acautelamento, inspeção, inventário, reserva, transferência em dois tempos, estorno, idempotência nem escopo por unidade — todos específicos de reserva bélica e já presentes no SIGARB. Ver `02-COMPARATIVO-DER.md` §4.

## Recomendação final
**KEEP CURRENT ARCHITECTURE + EXTEND SELECTIVELY.** Detalhes e custos em `06-RECOMENDACAO-E-ROADMAP.md`; decisões que só a gestão pode tomar em `07-DECISOES-PARA-GESTAO.md`.

## Índice
1. `01-MATRIZ-REQUISITOS.md` — RF-COL/RF-MAT/RF-VIN e campos, classificados A–F com evidência.
2. `02-COMPARATIVO-DER.md` — AS-IS (real), DER proposto, TO-BE, tabela de diferenças.
3. `03-LACUNAS-E-DUPLICIDADES.md`
4. `04-IMPACTOS-SEGURANCA-E-DADOS.md`
5. `05-MELHORIAS-UX.md`
6. `06-RECOMENDACAO-E-ROADMAP.md`
7. `07-DECISOES-PARA-GESTAO.md`
