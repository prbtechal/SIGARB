# Registro de pendências institucionais (P-01 a P-12)

Fonte: SRS v1.0 §15. Nenhuma pendência foi resolvida institucionalmente até 2026-11-05.
Coluna "Tratamento no MVP" descreve **apenas** o que a engenharia fez de forma segura e reversível; não substitui a decisão.

| ID | Tema (SRS) | Confirmar (SRS) | Situação | Bloqueia | Tratamento provisório no MVP |
|---|---|---|---|---|---|
| P-01 | Nuvem | Provedor, região, residência de dados e serviços disponíveis. | ABERTA | Implantação (qualquer ambiente não local) | Somente ambiente local Docker com dados sintéticos. **Variância registrada (ADR-008):** a hospedagem proposta em VMs de intranet (.8/.9) diverge do SRS (nuvem com HTTPS) e da aprovação existente para outros sistemas — exige confirmação explícita para o SIGARB. |
| P-02 | Organização | Hierarquia real e escopo de acesso por unidade. | ABERTA | Carga de dados reais; regra definitiva de escopo | Modelo genérico: organização → unidade (árvore com subunidades, profundidade livre) → locais de estoque (reserva/depósito). Escopo = unidade atribuída + descendentes (ADR-004). Hierarquia apenas sintética. |
| P-03 | Normas | Regras de inspeção, baixa, autorização e retenção. | ABERTA | RF-018, RF-019, RN-014, RN-025 operacionais | Inspeção, baixa e manutenção **desabilitadas** (resposta `409 OPERACAO_BLOQUEADA`). |
| P-04 | Estoque | Categorias serializadas vs. lote/quantidade. | ABERTA | Classificação definitiva do catálogo | `modo_controle` (INDIVIDUAL/LOTE) configurado **por material**, imutável após existir estoque. Não se presume nenhuma categoria real. |
| P-05 | Entrega | Quando há retorno; consumo sem retorno. | ABERTA | RF-011 (entrega/devolução/saída), RN-006/007/008, UC-05 | Entrega, devolução e saída **desabilitadas**. |
| P-06 | Aprovação | Delegação, substituição, rejeição, cancelamento, segregação de funções. | ABERTA | RF-015, RF-016, RN-009, UC-06/07/08/10 | Papel "Comandante/aprovador" existe sem nenhuma permissão operacional. Endpoints de acautelamento respondem `409 OPERACAO_BLOQUEADA`. Nenhuma aprovação simulada é persistida. |
| P-07 | Carteira | Campos, formato, validade, verificação do QR. | ABERTA | RF-017, RN-022 | Nenhuma carteira/PDF é gerado. |
| P-08 | Auditoria | Consultas registradas individualmente, retenção, acesso aos logs. | ABERTA | Matriz de auditoria definitiva | Política provisória em `docs/seguranca/AUDITORIA.md`: escrita, autenticação, negações e consultas a auditoria/militares detalhados são auditadas; retenção indefinida no dev. |
| P-09 | Privacidade | Classificação, acesso remoto, incidentes, descarte. | ABERTA | Campos pessoais definitivos | Minimização: militar sem CPF/RG/endereço; somente nome, nome de guerra, matrícula fictícia, posto/graduação, unidade, situação. |
| P-10 | Operação | Desempenho, picos, RPO/RTO, retenção, disponibilidade. | ABERTA | RNF-07, RNF-09, RNF-15, RF-030 | Medição básica local apenas; 1.000 usuários simultâneos **não verificado**. Backup não implementado. |
| P-11 | Autenticação | Senha, recuperação, sessão, MFA. | ABERTA | Política definitiva | Provisório configurável: senha ≥ 12 caracteres, sessão ociosa 30 min, até 3 sessões simultâneas (a mais antiga é revogada), bloqueio por rate limit 5 tentativas/min. Sem recuperação self-service (admin redefine). |
| P-12 | Relatórios | Relatórios prioritários, campos, assinaturas, modelos. | ABERTA | RF-023, UC-13 | Nenhum relatório oficial; apenas consultas em tela. |

## Pendências adicionais identificadas pela engenharia (não constam do SRS)

| ID | Questão | Motivo |
|---|---|---|
| PE-01 | Entrada de material exige documento de suporte obrigatório? Qual tipo? (RN-005 "conforme política") | MVP exige `documento_referencia` textual e origem; anexo de arquivo ainda não exigido. |
| PE-02 | Quem pode estornar uma entrada e em que prazo? (RN-018) | MVP: permissão `estoque.estornar` (Gestor), justificativa obrigatória, um estorno por movimentação. |
| PE-03 | Autorização para distribuir o DOCX do SRS em repositório (mesmo privado). | DOCX mantido apenas local. |
| PE-04 | Identidade visual institucional autorizada. | Identidade neutra "SIGARB"; sem brasões/selos. |
| PE-05 | Tabela oficial de tipos de documento e quais são obrigatórios por operação. | MVP usa 6 tipos genéricos editáveis (catálogo). |
| PE-06 | Sanitização de PDF/antivírus nos uploads e cifragem em repouso. | Heurística + entrega como anexo; proposta em DOCUMENTOS.md. |
| PE-07 | Âncora externa/HMAC para a cadeia de auditoria. | Limitação documentada; proposta em AUDITORIA.md. |

Relação com P-08/P-09 (CP-5): documentos **não** têm classificação de sigilo nem prazo de retenção; nada é apagado.

## Regras provisórias (2026-11-06)
Por decisão do responsável do projeto, saída, baixa, transferência, reserva, manutenção, entrega/devolução, acautelamento
(com decisão, carteira, inspeção e desacautelamento) e inventário foram implementados com regras
PROVISÓRIAS (`config/sigarb.php`, campo `regra_provisoria`), **desligadas por padrão**. Ligar exige listar as operações em
`SIGARB_OPERACOES_PROVISORIAS` (lista completa: `SAIDA,BAIXA,TRANSFERENCIA,RESERVA,MANUTENCAO,ENTREGA,DEVOLUCAO,ACAUTELAMENTO,INSPECAO,DESACAUTELAMENTO,INVENTARIO`).
Alertas (prazos em `SIGARB_ALERTA_VALIDADE_DIAS`, `SIGARB_INSPECAO_DIAS`, `SIGARB_ALERTA_INSPECAO_DIAS`), exportação CSV e ficha técnica
não alteram estoque e ficam sempre disponíveis, marcados como provisórios/não oficiais. Os registros saem com `provisoria = true`
e auditoria `regra: PROVISORIA`. Nenhuma pendência acima é considerada resolvida e nenhum requisito vira ACCEPTED por isso.
