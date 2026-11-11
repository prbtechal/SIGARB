# Registro de pendências institucionais (P-01 a P-12)

Fonte: SRS v1.0 §15. Nenhuma pendência foi resolvida institucionalmente até 2026-11-05. Em 2026-11-08 o responsável do projeto delegou à engenharia P-03, P-05 e P-09 e, no CP-14, P-02, P-06 e P-07 (decisões de projeto, não institucionais).
Coluna "Tratamento no MVP" descreve **apenas** o que a engenharia fez de forma segura e reversível; não substitui a decisão.

| ID | Tema (SRS) | Confirmar (SRS) | Situação | Bloqueia | Tratamento provisório no MVP |
|---|---|---|---|---|---|
| P-01 | Nuvem | Provedor, região, residência de dados e serviços disponíveis. | ABERTA | Implantação (qualquer ambiente não local) | Somente ambiente local Docker com dados sintéticos. **Variância registrada (ADR-008):** a hospedagem proposta em VMs de intranet (.8/.9) diverge do SRS (nuvem com HTTPS) e da aprovação existente para outros sistemas — exige confirmação explícita para o SIGARB. |
| P-02 | Organização | Hierarquia real e escopo de acesso por unidade. | DECIDIDA PELO RESPONSÁVEL (sem aceite institucional) | Carga de dados reais; regra definitiva de escopo | **Decisão do responsável (CP-14):** árvore genérica com escopo por subárvore; estrutura real cadastrada pela instituição (tela ou planilha de unidades/locais); transferência habilitada, recebimento por quem tem permissão no destino. Ver `docs/requisitos/DECISOES-RESPONSAVEL-P03-P05-P09.md`. |
| P-03 | Normas | Regras de inspeção, baixa, autorização e retenção. | DECIDIDA PELO RESPONSÁVEL (sem aceite institucional) | RF-018, RF-019, RN-014, RN-025 operacionais | **Decisão do responsável (CP-13):** baixa, manutenção, reserva e inspeção habilitadas; vencido e inservível não são entregues nem reservados; nada é apagado. Ver `docs/requisitos/DECISOES-RESPONSAVEL-P03-P05-P09.md`. |
| P-04 | Estoque | Categorias serializadas vs. lote/quantidade. | ABERTA | Classificação definitiva do catálogo | `modo_controle` (INDIVIDUAL/LOTE) configurado **por material**, imutável após existir estoque. Não se presume nenhuma categoria real. **CP-13:** níveis de proteção e tipos de material administráveis em Referências e importáveis por planilha (`docs/operacao/IMPORTACAO-EXPORTACAO.md`); a lista oficial será cadastrada pela instituição. |
| P-05 | Entrega | Quando há retorno; consumo sem retorno. | DECIDIDA PELO RESPONSÁVEL (sem aceite institucional) | RF-011 (entrega/devolução/saída), RN-006/007/008, UC-05 | **Decisão do responsável (CP-13):** com data de retorno a entrega fica pendente; sem data é consumo; devolução parcial por item/quantidade; extravio segue por baixa. Ver `docs/requisitos/DECISOES-RESPONSAVEL-P03-P05-P09.md`. |
| P-06 | Aprovação | Delegação, substituição, rejeição, cancelamento, segregação de funções. | DECIDIDA PELO RESPONSÁVEL (sem aceite institucional) | RF-015, RF-016, RN-009, UC-06/07/08/10 | **Decisão do responsável (CP-14):** Aprovador, Gestor e Armeiro aprovam; ninguém aprova o próprio pedido; rejeição com justificativa; cancelamento antes da entrega; delegação/substituição por papel com prazo. Ver `docs/requisitos/DECISOES-RESPONSAVEL-P03-P05-P09.md`. |
| P-07 | Carteira | Campos, formato, validade, verificação do QR. | DECIDIDA PELO RESPONSÁVEL (sem aceite institucional) | RF-017, RN-022 | **Decisão do responsável (CP-14):** carteira com QR de token opaco; válida enquanto o acautelamento estiver ativo e a inspeção em dia; verificação só com login, auditada. Ver `docs/requisitos/DECISOES-RESPONSAVEL-P03-P05-P09.md`. |
| P-08 | Auditoria | Consultas registradas individualmente, retenção, acesso aos logs. | ABERTA | Matriz de auditoria definitiva | Política provisória em `docs/seguranca/AUDITORIA.md`: escrita, autenticação, negações e consultas a auditoria/militares detalhados são auditadas; retenção indefinida no dev. |
| P-09 | Privacidade | Classificação, acesso remoto, incidentes, descarte. | DECIDIDA PELO RESPONSÁVEL (sem aceite institucional) | Campos pessoais definitivos | **Decisão do responsável (CP-13):** dados mínimos sem CPF/RG/endereço; contatos e matrícula completa só com `militares.ver_detalhe` (tela e exportação); importação recusa colunas fora do modelo; inativação em vez de exclusão. Ver `docs/requisitos/DECISOES-RESPONSAVEL-P03-P05-P09.md`. |
| P-10 | Operação | Desempenho, picos, RPO/RTO, retenção, disponibilidade. | ABERTA | RNF-07, RNF-09, RNF-15, RF-030 | Medição básica local apenas; 1.000 usuários simultâneos **não verificado**. Backup não implementado. |
| P-11 | Autenticação | Senha, recuperação, sessão, MFA. | DECIDIDA PELO RESPONSÁVEL (sem aceite institucional) — alinhada à PSI-PRB v1.0 | Política definitiva | **2026-11-11 (PSI-PRB, `docs/seguranca/PSI-PRB-CONFORMIDADE.md`):** senha ≥ 8 (exceção E-01; PSI pede 12) com maiúscula, minúscula, número e especial, sem nome/CPF, diferente das 5 últimas, troca a cada 90 dias; bloqueio após 10 falhas seguidas com desbloqueio pelo gestor; MFA TOTP opcional (exceção E-02; obrigatoriedade por config); senha inicial = CPF para conta não privilegiada (exceção E-03), senha padrão de config para privilegiada; contas sem acesso há 60 dias desativadas. Login pelo CPF. Sessão ociosa 30 min, até 3 sessões simultâneas. Sem recuperação self-service (admin redefine). Pendências P-PSI-01..06 no documento de conformidade. |
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
| P-PSI-01 | Senha padrão única de contas privilegiadas (`SIGARB_SENHA_PADRAO`, 9 caracteres) não atende à PSI 10.1; só vale como provisória. | Ver `docs/seguranca/PSI-PRB-CONFORMIDADE.md`. |
| P-PSI-02 | Termo de Responsabilidade (Anexo I da PSI) e ciência do monitoramento: aceite eletrônico vale como assinatura? | Não implementado. |
| P-PSI-03 | Formalizar as exceções E-01 (senha 8), E-02 (MFA opcional) e E-03 (senha inicial = CPF) conforme cap. 34. | Decididas pelo responsável em 2026-11-11. |
| P-PSI-04 | Produção: agendador `schedule:run` (desativação por inatividade) e logrotate de 180 dias nas VMs. | `deploy/RUNBOOK.md`. |
| P-PSI-05 | Rótulo de classificação em documentos impressos pelo navegador (carteira) e futuros relatórios oficiais (P-12). | Exportações CSV/XLSX já rotuladas. |
| P-PSI-06 | RIPD (PSI 29.3) dos dados de efetivo e contas. | Encarregado de Dados. |
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
