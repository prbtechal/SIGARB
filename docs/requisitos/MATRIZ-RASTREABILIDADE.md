# Matriz de rastreabilidade — SIGARB

> Gerada por `scripts/rastreabilidade.py` a partir de `SRS-EXTRAIDO.md` (definições) e do mapeamento mantido no script. **Não editar à mão.**
> Status: NOT_STARTED · IN_PROGRESS · IMPLEMENTED · TESTED · BLOCKED · ACCEPTED. Nenhum item é ACCEPTED: não houve aceite institucional.
> Caminhos de teste: `backend/tests/…` e `frontend/…`. Backend: `backend/app/Http/Controllers/Api/`, `backend/app/Services/`.

## Requisitos funcionais (SRS §3)

| ID | Área | Definição (SRS) | Prior. | RN relac. | UC relac. | Backend | Frontend | API (/api/v1) | Entidades | Testes | Status | Aprovação | Notas |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| RF-001 | Autenticação | Login com identificador e senha; registrar sucesso, falha e encerramento. | Alta | RN-020 | UC-01 | AuthController@login/logout | LoginPage | POST /auth/login, /auth/logout | usuarios, sessions, eventos_auditoria | Feature/AuthTest, e2e/autenticacao.spec | **TESTED** | PENDENTE (SRS v1.0 em rascunho) | Rate limit conta+IP, conta (anti-distribuído) e IP; dispositivo confiável no servidor. Política final: P-11. |
| RF-002 | Sessões | Aplicar política de sessão simultânea e revogação, com auditoria. | Alta | RN-020 | UC-01 | AuthController@sessoes/revogarSessao, UsuarioController@revogarSessoes | SessoesPage | GET/DELETE /me/sessions, POST /users/{id}/sessions/revoke | sessions, dispositivos_confiaveis | Feature/AuthTest | **TESTED** | PENDENTE (SRS v1.0 em rascunho) | Limite provisório 3 sessões (P-11). |
| RF-003 | Autorização | Permissões por ação e escopo organizacional, verificadas no servidor. | Alta | RN-019, RN-024 | todos | AutorizacaoService, Policies/* | Exige/menu por permissão (apenas UX) | todas | papeis, permissoes, atribuicoes_papel, unidades.caminho | Feature/OrganizacaoEPermissoesTest, Feature/MilitarTest, Feature/EstoqueTest, e2e/estoque.spec | **TESTED** | PENDENTE (SRS v1.0 em rascunho) | Escopo = subárvore da unidade (ADR-004); hierarquia real P-02. |
| RF-004 | Usuários | Criar, editar, desativar e reativar contas, preservando histórico. | Alta | RN-025 | UC-15 | UsuarioController | UsuariosPage | GET/POST /users, GET/PUT /users/{id}, POST/DELETE /users/{id}/roles | usuarios, atribuicoes_papel | Feature/AuthTest, Feature/OrganizacaoEPermissoesTest | **TESTED** | PENDENTE (SRS v1.0 em rascunho) | Contas nunca apagadas; desativação revoga sessões e dispositivos. |
| RF-005 | Militares | Cadastrar militares separadamente das contas de acesso. | Alta | — | UC-03 | MilitarController | MilitaresPage, MilitarFormPage | GET/POST /militares, GET/PUT /militares/{id} | militares, postos_graduacoes, usuarios.militar_id, militar_unidades | Feature/MilitarTest, Feature/Cp12EstruturalTest, e2e/estoque.spec | **TESTED** | PENDENTE (SRS v1.0 em rascunho) | Separado de usuário (§2.1); vínculo 0..1:1; dados mínimos (P-09, sem CPF). CP-12: quadro/função/contatos institucionais opcionais (contatos só com ver_detalhe, mascarados na auditoria) e vínculos secundários temporais que não ampliam escopo. |
| RF-006 | Organização | Representar organização, unidade, subunidade, reserva e depósito. | Alta | RN-024 | — | OrganizacaoController | OrganizacaoPage | /organizations, /units, /locations | organizacoes, unidades, locais_estoque | Feature/OrganizacaoEPermissoesTest | **TESTED** | PENDENTE (SRS v1.0 em rascunho) | Modelo genérico em árvore; hierarquia real P-02. |
| RF-007 | Armamentos | Manter cadastro técnico e identificação individual, incluindo série quando aplicável. | Alta | RN-003 | UC-03 | CatalogoController, EstoqueService | MateriaisPage, NovaEntradaPage | /items, POST /movements | materiais, tipos_material, itens_individuais, calibres, fabricantes | Feature/CatalogoTest, Feature/EstoqueTest, Feature/Cp12EstruturalTest | **IN_PROGRESS** | PENDENTE (SRS v1.0 em rascunho) | Identificação individual e série únicas testadas. CP-12: tipo de material por categoria com perfil de campos (arma, colete, acessório…) e atributos de colete (tamanho, nível, fabricação/validade). Lista oficial de tipos e níveis pendente (P-04). |
| RF-008 | Munições | Controlar tipo, lote, fabricante, validade e saldo quando aplicável. | Alta | RN-004 | UC-04 | EstoqueService@obterLote | NovaEntradaPage, SaldosPage | POST /movements, GET /stock/balances | lotes, saldos_estoque | Feature/EstoqueTest, Feature/ConcorrenciaTest | **TESTED** | PENDENTE (SRS v1.0 em rascunho) | Tipo via categoria/material; lote, fabricante, validade e saldo. |
| RF-009 | Equipamentos/produtos | Cadastrar itens unitários ou quantitativos, categoria, condição e localização. | Alta | RN-003 | UC-03 | CatalogoController | MateriaisPage | /items | materiais (modo_controle), itens_individuais.estado_conservacao | Feature/CatalogoTest, Feature/EstoqueTest, Feature/Cp12EstruturalTest | **TESTED** | PENDENTE (SRS v1.0 em rascunho) | Unitário x quantitativo por material (P-04). |
| RF-010 | Estoque | Exibir saldos por local, categoria, situação e unidade. | Alta | RN-012, RN-015 | UC-02 | EstoqueController@saldos | SaldosPage, InicioPage | GET /stock/balances | saldos_estoque, itens_individuais | Feature/EstoqueTest, e2e/estoque.spec | **TESTED** | PENDENTE (SRS v1.0 em rascunho) | Filtros por local, unidade, natureza, material, busca. |
| RF-011 | Movimentações | Registrar entrada, saída, entrega, devolução, acautelamento, desacautelamento, baixa e reserva. | Alta | RN-001, RN-005, RN-006, RN-007 | UC-04, UC-05 | EstoqueController@registrar, EstoqueService | NovaEntradaPage, MovimentacoesPage | POST /movements | movimentacoes, movimentacao_itens | Feature/EstoqueTest, Feature/ConcorrenciaTest, e2e/estoque.spec | **IN_PROGRESS** | PENDENTE (SRS v1.0 em rascunho) | ENTRADA e ESTORNO habilitados; saída/entrega/devolução/reserva/baixa/acautelamento BLOQUEADOS (P-03/P-05/P-06). |
| RF-012 | Transferência | Registrar origem, destino, itens, quantidades, documento e estados de envio/recebimento. | Alta | RN-013 | — | rota reservada | OperacoesPage | POST /transfers (409) | movimentacoes (tipo TRANSFERENCIA previsto) | Feature/OrganizacaoEPermissoesTest, Feature/EstoqueTest, e2e/estoque.spec | **BLOCKED** | PENDENTE (SRS v1.0 em rascunho) | Operação bloqueada (409 OPERACAO_BLOQUEADA) até P-02/P-03. Esquema preparado quando indicado. |
| RF-013 | Integridade de saldo | Impedir saldo negativo e garantir atualização transacional sob concorrência. | Alta | RN-002 | UC-04 | EstoqueService (FOR UPDATE, CHECK, UNIQUE, idempotência) | Idempotency-Key no cliente | POST /movements, /movements/{id}/reversal | saldos_estoque, movimentacoes | Feature/EstoqueTest, Feature/ConcorrenciaTest | **TESTED** | PENDENTE (SRS v1.0 em rascunho) | Concorrência multiprocesso provada (6 débitos de 10 aceitos sobre saldo 100). |
| RF-014 | Documentos | Guardar metadados/arquivos oficiais e vincular às operações pertinentes. | Alta | RN-019, RN-020, RN-021, RN-025 | — | DocumentoController, DocumentoService, disco privado "documentos" | DocumentosPage, DocumentoEnviarPage, DocumentoPage, seção na MovimentacaoPage | GET/POST /documents, GET/PUT /documents/{codigo}, GET …/download, POST …/archive\|reactivate\|links, GET /document-types | documentos, documento_vinculos, tipos_documento | Feature/DocumentoTest, Feature/ConcorrenciaTest, Feature/AuditoriaAdulteracaoTest, e2e/documentos.spec, vitest documentos/arquivos | **TESTED** | PENDENTE (SRS v1.0 em rascunho) | Guarda metadados/arquivos e vincula a movimentações. Emissão de documento oficial NÃO implementada. Classificação de sigilo (P-09) e retenção (P-08) pendentes; tipos de documento provisórios (PE-05). |
| RF-015 | Acautelamento | Gerir solicitação, análise, aprovação, entrega, acompanhamento e desacautelamento. | Alta | RN-009, RN-010 | UC-06, UC-08, UC-10 | rotas reservadas | OperacoesPage | POST /acautelamentos/* (409) | planejadas | Feature/OrganizacaoEPermissoesTest, Feature/EstoqueTest, e2e/estoque.spec | **BLOCKED** | PENDENTE (SRS v1.0 em rascunho) | Operação bloqueada (409 OPERACAO_BLOQUEADA) até P-06/P-07. Esquema preparado quando indicado. |
| RF-016 | Aprovação | Registrar decisão do aprovador autorizado, data e justificativa quando exigida. | Alta | RN-009 | UC-07 | rota reservada | OperacoesPage | POST /acautelamentos/{id}/decision (409) | aprovacoes (planejada) | Feature/OrganizacaoEPermissoesTest, Feature/EstoqueTest, e2e/estoque.spec | **BLOCKED** | PENDENTE (SRS v1.0 em rascunho) | Operação bloqueada (409 OPERACAO_BLOQUEADA) até P-06. Esquema preparado quando indicado. |
| RF-017 | Carteira | Gerar PDF e formato para impressão, com identificador verificável/QR Code. | Alta | RN-022 | UC-08 | — | — | — | carteiras (planejada) | Feature/OrganizacaoEPermissoesTest, Feature/EstoqueTest, e2e/estoque.spec | **BLOCKED** | PENDENTE (SRS v1.0 em rascunho) | Operação bloqueada (409 OPERACAO_BLOQUEADA) até P-07. Esquema preparado quando indicado. |
| RF-018 | Inspeções | Registrar inspeções periódicas e alertar sobre prazos próximos/atrasados. | Alta | RN-010 | UC-09 | rota reservada | OperacoesPage | POST /acautelamentos/{id}/inspections (409) | inspecoes (planejada) | Feature/OrganizacaoEPermissoesTest, Feature/EstoqueTest, e2e/estoque.spec | **BLOCKED** | PENDENTE (SRS v1.0 em rascunho) | Operação bloqueada (409 OPERACAO_BLOQUEADA) até P-03. Esquema preparado quando indicado. |
| RF-019 | Manutenção | Registrar encaminhamento, ocorrência, estado, documento e liberação/baixa proposta. | Alta | RN-014, RN-015 | UC-11 | — | OperacoesPage | — | itens_individuais.situacao MANUTENCAO | Feature/OrganizacaoEPermissoesTest, Feature/EstoqueTest, e2e/estoque.spec | **BLOCKED** | PENDENTE (SRS v1.0 em rascunho) | Operação bloqueada (409 OPERACAO_BLOQUEADA) até P-03. Esquema preparado quando indicado. |
| RF-020 | Inventário | Registrar contagens, divergências e ajustes sujeitos à política de aprovação. | Alta | RN-016 | UC-12 | — | OperacoesPage | — | planejadas | Feature/OrganizacaoEPermissoesTest, Feature/EstoqueTest, e2e/estoque.spec | **BLOCKED** | PENDENTE (SRS v1.0 em rascunho) | Operação bloqueada (409 OPERACAO_BLOQUEADA) até P-03/P-06. Esquema preparado quando indicado. |
| RF-021 | Reservas | Separar itens para finalidade definida e retirá-los da disponibilidade concorrente. | Alta | RN-012 | — | — | OperacoesPage | — | saldos_estoque.quantidade_reservada, situacao RESERVADO | Feature/OrganizacaoEPermissoesTest, Feature/EstoqueTest, e2e/estoque.spec | **BLOCKED** | PENDENTE (SRS v1.0 em rascunho) | Operação bloqueada (409 OPERACAO_BLOQUEADA) até P-03. Esquema preparado quando indicado. |
| RF-022 | Alertas | Exibir alertas internos de validade, estoque, inspeção, aprovações e exceções. | Alta | RN-023 | UC-09 | DashboardController (informativo) | InicioPage | GET /alerts (409) | alertas (planejada) | Feature/Cp12EstruturalTest | **IN_PROGRESS** | PENDENTE (SRS v1.0 em rascunho) | CP-12: alertas informativos ITEM_VENCIDO/ITEM_VENCENDO (janela config sigarb.alertas.validade_item_dias) no escopo, sem bloquear nem mudar situação. Regras configuradas e bloqueio por validade pendentes (P-03). |
| RF-023 | Relatórios | Oferecer filtros e exportação autorizada para PDF/Excel. | Alta | RN-019 | UC-13 | — | — | GET /reports/{key} (409) | — | Feature/OrganizacaoEPermissoesTest, Feature/EstoqueTest, e2e/estoque.spec | **BLOCKED** | PENDENTE (SRS v1.0 em rascunho) | Operação bloqueada (409 OPERACAO_BLOQUEADA) até P-12. Esquema preparado quando indicado. |
| RF-024 | Dashboard | Exibir indicadores e gráficos no escopo autorizado do usuário. | Média | RN-024 | — | DashboardController (blocos por permissão, escopo, cache da verificação da cadeia) | InicioPage, GraficoBarras | GET /dashboard | — | Feature/DashboardTest, vitest src/**, e2e/responsivo-acessibilidade.spec, scripts/e2e-navegadores.sh (Chromium/Firefox/WebKit) | **TESTED** | PENDENTE (SRS v1.0 em rascunho) | Indicadores e gráficos calculados no servidor por permissão/escopo; gráfico = tabela semântica (sem Chart.js, desvio reversível do §9.2); sem indicadores de operações bloqueadas. |
| RF-025 | Auditoria | Registrar ações críticas, consultas sensíveis, alterações, decisões e resultados. | Alta | RN-020 | UC-14 | AuditoriaService, AuditoriaController, bootstrap/app.php (negações) | AuditoriaPage | GET /audit-events, /audit-events/integrity | eventos_auditoria, auditoria_cadeia | Feature/AuditoriaTest, Feature/AuditoriaAdulteracaoTest, Feature/AuthTest, e2e/autenticacao.spec, e2e/estoque.spec, e2e/documentos.spec | **TESTED** | PENDENTE (SRS v1.0 em rascunho) | Append-only (triggers) e hash encadeado; matriz definitiva P-08. |
| RF-026 | Pesquisa | Oferecer pesquisa, filtros e paginação respeitando permissões. | Média | RN-024 | UC-02 | ApiController@paginar, filtros por recurso | filtros/paginação em todas as listas | ?q, ?page, ?per_page≤100, ?sort (lista branca) | — | Feature/MilitarTest, Feature/EstoqueTest, Feature/CatalogoTest | **TESTED** | PENDENTE (SRS v1.0 em rascunho) | Busca LIKE com curingas escapados; sem oráculo de matrícula. |
| RF-027 | Histórico | Exibir linha do tempo sem permitir alteração silenciosa do passado. | Alta | RN-017, RN-018 | — | EstoqueController@movimentacoes (item_individual_id/lote_id) | MovimentacaoPage | GET /movements?item_individual_id= | movimentacoes (imutável) | Feature/EstoqueTest, Feature/AuditoriaTest | **TESTED** | PENDENTE (SRS v1.0 em rascunho) | Linha do tempo por item/lote; triggers impedem alteração. |
| RF-028 | Acessibilidade | Oferecer teclado, rótulos, contraste e mensagens acessíveis. | Alta | — | — | — | todas as telas | — | — | e2e/responsivo-acessibilidade.spec, e2e/acessibilidade-estrutura.spec, vitest src/**, scripts/e2e-navegadores.sh (Chromium/Firefox/WebKit) | **IN_PROGRESS** | PENDENTE (SRS v1.0 em rascunho) | axe sem violações sérias/críticas em Chromium e WebKit (desktop/celular); teclado, foco de diálogo, zoom 200% nos 3 motores. Falta leitor de tela manual (roteiro em ACESSIBILIDADE.md). |
| RF-029 | Parâmetros | Administrar listas de referência e regras configuráveis com auditoria. | Média | RN-023, RN-025 | — | ReferenciaController, ReferenciaService | ReferenciasPage | GET /reference-lists, GET/POST /reference-lists/{tipo}, PUT …/{id}, POST …/{id}/deactivate\|reactivate | tipos_documento, categorias, fabricantes, calibres, tipos_material (ativo, desativado_em) | Feature/ReferenciaTest, Feature/CatalogoTest, Feature/Cp12EstruturalTest, vitest src/** | **TESTED** | PENDENTE (SRS v1.0 em rascunho) | Listas de referência administráveis (global, referencias.gerir), sem exclusão, desativação auditada, valor em uso não muda de significado. Regras configuráveis (alertas, inspeção) seguem em config/ e bloqueadas (P-03); listas oficiais pendentes (PE-05). |
| RF-030 | Backup | Executar backups automáticos e permitir testar restauração. | Alta | — | — | scripts/backup-local.sh, scripts/restaurar-local.sh, sigarb:documentos:verificar | — | — | todas (dump) + documentos referenciados | scripts/testar-backup.sh | **IN_PROGRESS** | PENDENTE (SRS v1.0 em rascunho) | Backup LOCAL cifrado (GnuPG AES-256) e restauração verificada em ambiente descartável, testados (11 casos). Agendamento automático, destino, retenção e RPO/RTO dependem de P-10/P-08. |

## Requisitos não funcionais (SRS §4)

| ID | Categoria | Definição (SRS) | Evidência / implementação | Testes | Status | Aprovação |
|---|---|---|---|---|---|---|
| RNF-01 | Segurança | HTTPS/TLS; segredos fora do código-fonte. | Segredos fora do código; CSP de produção sem unsafe-*; proxies confiáveis explícitos (bypass por Host corrigido); identidade MySQL de runtime sem DDL. HTTPS/HSTS só na implantação (P-01). | Feature/ProxyConfiavelTest, e2e/csp.spec, scripts/verificar-privilegios.sh | **IN_PROGRESS** | PENDENTE (SRS v1.0 em rascunho) |
| RNF-02 | Senhas | Hash seguro; nunca armazenar senha em texto simples. | Cast 'hashed' (bcrypt); senha nunca auditada. | Feature/AuthTest | **IMPLEMENTED** | PENDENTE (SRS v1.0 em rascunho) |
| RNF-03 | Autorização | Verificação no servidor em cada requisição e aplicação de escopo. | Gate/Policies em toda rota; listas filtradas na query. | Feature/OrganizacaoEPermissoesTest, Feature/MilitarTest, Feature/EstoqueTest, e2e/estoque.spec | **TESTED** | PENDENTE (SRS v1.0 em rascunho) |
| RNF-04 | Auditoria | Proteção contra alteração ou acesso indevido aos registros. | Triggers append-only + cadeia de hash + verificação; falha fechada sem ponteiro. Limitação: reescrita total por DBA privilegiado não é detectada localmente (proposta de âncora externa em AUDITORIA.md). | Feature/AuditoriaTest, Feature/AuditoriaAdulteracaoTest | **TESTED** | PENDENTE (SRS v1.0 em rascunho) |
| RNF-05 | Privacidade | Minimização de dados e retenção definida conforme política e legislação. | Minimização de campos de militar; auditoria de documentos sem conteúdo; retenção e classificação pendentes (P-08/P-09). | Feature/MilitarTest, Feature/DocumentoTest | **IN_PROGRESS** | PENDENTE (SRS v1.0 em rascunho) |
| RNF-06 | Integridade | Movimentações e saldos em transações atômicas, com controle de concorrência. | Transações, FOR UPDATE ordenado, CHECK, UNIQUE; deadlock em entradas concorrentes corrigido no CP-6 (linhas criadas fora da transação). | Feature/EstoqueTest, Feature/ConcorrenciaTest | **TESTED** | PENDENTE (SRS v1.0 em rascunho) |
| RNF-07 | Desempenho | Definir metas mensuráveis e validar por teste de carga representativo. | CP-8 production-like local (Apache+FPM+MySQL, 2 CPU no app): 50 VUs 0% erro, ~47 req/s, p95 1,6 s em 2 repetições, integridade íntegra; gargalos medidos (CPU do app; cadeia de auditoria nas escritas) em docs/operacao/CAPACIDADE.md. 100 VUs não executado; metas não definidas (P-10); 1.000 usuários NÃO verificado. | scripts/teste-carga.sh (k6) | **IN_PROGRESS** | PENDENTE (SRS v1.0 em rascunho) |
| RNF-08 | Escalabilidade | Permitir ampliar recursos de aplicação, banco, cache e filas. | API stateless exceto sessão em banco; filas/cache não configurados. | — | **NOT_STARTED** | PENDENTE (SRS v1.0 em rascunho) |
| RNF-09 | Disponibilidade | Monitoramento, backup e recuperação; SLA 24/7 fica para fase futura. | Liveness/readiness (banco, disco privado, espaço) independentes de sessão/cache em banco (CP-8: banco fora → live 200/ready 503; disco baixo real → 503); backup/restauração local testados (19 casos). Monitoramento contínuo e SLA pendentes (P-10). | Feature/ObservabilidadeTest, scripts/testar-backup.sh | **IN_PROGRESS** | PENDENTE (SRS v1.0 em rascunho) |
| RNF-10 | Usabilidade | Fluxos consistentes e utilizáveis em telas pequenas. | Gaveta no celular, tabelas em cartões, sem rolagem horizontal. | e2e/responsivo-acessibilidade.spec | **TESTED** | PENDENTE (SRS v1.0 em rascunho) |
| RNF-11 | Acessibilidade | Meta WCAG 2.2 AA com verificação automática e manual. | Verificação automática em 2 motores (axe) + teclado/foco/zoom em 3; manual com leitor de tela pendente. | e2e/responsivo-acessibilidade.spec, scripts/e2e-navegadores.sh (Chromium/Firefox/WebKit) | **IN_PROGRESS** | PENDENTE (SRS v1.0 em rascunho) |
| RNF-12 | Compatibilidade | Navegadores institucionais definidos na homologação. | Fluxos validados em Chromium, Firefox e WebKit (desktop e celular); navegadores institucionais ainda não definidos. | scripts/e2e-navegadores.sh (Chromium/Firefox/WebKit) | **IN_PROGRESS** | PENDENTE (SRS v1.0 em rascunho) |
| RNF-13 | Manutenibilidade | Padrões de código, revisão, testes e documentação. | Monorepo, ADRs, testes automatizados, AGENTS.md, auditoria de dependências em scripts/test.sh. | scripts/auditar-dependencias.sh | **IMPLEMENTED** | PENDENTE (SRS v1.0 em rascunho) |
| RNF-14 | Observabilidade | Logs/métricas sem segredos ou dados pessoais excessivos. | Logs JSON por requisição com request_id (correlacionado com resposta e auditoria), rota como modelo, sem corpo/query/cookies/senhas — testado. Retenção oficial de logs: P-08. | Feature/ObservabilidadeTest, Feature/AuditoriaTest | **TESTED** | PENDENTE (SRS v1.0 em rascunho) |
| RNF-15 | Recuperação | Aprovar RPO/RTO, retenção e testes de restauração antes da produção. | Restauração testada em ambiente isolado (automatizada); RPO/RTO e retenção não aprovados (P-10). | scripts/testar-backup.sh | **IN_PROGRESS** | PENDENTE (SRS v1.0 em rascunho) |

## Regras de negócio (SRS §5)

| ID | Regra (SRS) | Evidência / pendência | Status | Aprovação |
|---|---|---|---|---|
| RN-001 | Toda movimentação tem tipo, data/hora, responsável, itens/quantidades quando aplicável e origem/destino pertinente. | Feature/EstoqueTest | **TESTED** | PENDENTE (SRS v1.0 em rascunho) |
| RN-002 | Nenhuma operação pode gerar saldo negativo; validação e atualização são atômicas. | Feature/EstoqueTest, Feature/ConcorrenciaTest | **TESTED** | PENDENTE (SRS v1.0 em rascunho) |
| RN-003 | Itens individualizados devem ter identificador único; série única quando aplicável. | Feature/EstoqueTest | **TESTED** | PENDENTE (SRS v1.0 em rascunho) |
| RN-004 | Materiais por lote devem preservar lote e validade quando aplicável. | Feature/EstoqueTest | **TESTED** | PENDENTE (SRS v1.0 em rascunho) |
| RN-005 | Entrada deve registrar origem e documento de suporte conforme política institucional. | Feature/EstoqueTest (documento_referencia e origem obrigatórios; política PE-01) | **TESTED** | PENDENTE (SRS v1.0 em rascunho) |
| RN-006 | Saída definitiva exige motivo, destinação e autorização/documentação aplicável. | P-05 | **BLOCKED** | PENDENTE (SRS v1.0 em rascunho) |
| RN-007 | Entrega informa se há previsão de retorno; quando houver, vincula-se a devolução. | P-05 | **BLOCKED** | PENDENTE (SRS v1.0 em rascunho) |
| RN-008 | Devolução referencia a entrega de origem sempre que aplicável. | P-05 | **BLOCKED** | PENDENTE (SRS v1.0 em rascunho) |
| RN-009 | Acautelamento só é concluído após aprovação válida e requisitos institucionais atendidos. | P-06 — garantido pelo bloqueio do acautelamento | **BLOCKED** | PENDENTE (SRS v1.0 em rascunho) |
| RN-010 | Acautelamento permanece ativo até desacautelamento ou estado formalmente autorizado; inspeção anual é controlada separadamente da validade do registro. | P-03/P-06 | **BLOCKED** | PENDENTE (SRS v1.0 em rascunho) |
| RN-011 | Desacautelamento registra data, responsável, condição e divergências. | P-05/P-06 | **BLOCKED** | PENDENTE (SRS v1.0 em rascunho) |
| RN-012 | Reserva reduz disponibilidade, não necessariamente quantidade física. | débito considera reservado (EstoqueTest); operação de reserva bloqueada | **IN_PROGRESS** | PENDENTE (SRS v1.0 em rascunho) |
| RN-013 | Transferência registra saída e recebimento com vínculo comum e estados explícitos. | P-02/P-03 | **BLOCKED** | PENDENTE (SRS v1.0 em rascunho) |
| RN-014 | Baixa exige motivo, autorização e documento conforme política; não se confunde com manutenção. | P-03 | **BLOCKED** | PENDENTE (SRS v1.0 em rascunho) |
| RN-015 | Item em manutenção não deve ser apresentado como disponível sem liberação registrada. | saldo disponível conta só DISPONIVEL; estorno impedido para item em manutenção (EstoqueTest) | **IMPLEMENTED** | PENDENTE (SRS v1.0 em rascunho) |
| RN-016 | Ajuste de inventário preserva saldo anterior, contagem, diferença, justificativa e aprovação quando exigida. | P-03/P-06 | **BLOCKED** | PENDENTE (SRS v1.0 em rascunho) |
| RN-017 | Movimentações históricas não são apagadas fisicamente por operações comuns. | Feature/EstoqueTest, Feature/AuditoriaTest | **TESTED** | PENDENTE (SRS v1.0 em rascunho) |
| RN-018 | Correções são feitas por estorno/correção auditável, mantendo o evento original. | Feature/EstoqueTest, Feature/ConcorrenciaTest | **TESTED** | PENDENTE (SRS v1.0 em rascunho) |
| RN-019 | Autorização é verificada no back-end, inclusive em exportações e downloads. | Feature/OrganizacaoEPermissoesTest, Feature/EstoqueTest, Feature/DocumentoTest, e2e/documentos.spec; download de documento autorizado por escopo; exportações/relatórios bloqueados (P-12) | **TESTED** | PENDENTE (SRS v1.0 em rascunho) |
| RN-020 | Consultas sensíveis e operações críticas geram eventos de auditoria conforme matriz aprovada. | eventos implementados, inclusive consulta/download de documento; matriz aprovada pendente (P-08) | **IN_PROGRESS** | PENDENTE (SRS v1.0 em rascunho) |
| RN-021 | Arquivos são validados e guardados fora da área pública, com acesso autorizado. | Feature/DocumentoTest, e2e/documentos.spec — disco privado, lista de permissão PDF/PNG/JPG, assinatura+finfo, PDF ativo recusado, nome aleatório, download só autorizado | **TESTED** | PENDENTE (SRS v1.0 em rascunho) |
| RN-022 | QR Code não contém dados sensíveis em texto aberto; usa identificador opaco/validação controlada. | sem QR no MVP (P-07) | **NOT_STARTED** | PENDENTE (SRS v1.0 em rascunho) |
| RN-023 | Alertas usam regras configuradas e registram criação, estado e resolução. | P-03 | **NOT_STARTED** | PENDENTE (SRS v1.0 em rascunho) |
| RN-024 | Permissões granulares e escopo organizacional determinam o acesso efetivo. | Feature/OrganizacaoEPermissoesTest, Feature/MilitarTest, Feature/EstoqueTest | **TESTED** | PENDENTE (SRS v1.0 em rascunho) |
| RN-025 | Exclusão lógica não elimina a obrigação de retenção ou descarte seguro. | contas desativadas e documentos arquivados, nunca apagados (trigger); retenção/descarte seguro pendentes (P-08) | **IN_PROGRESS** | PENDENTE (SRS v1.0 em rascunho) |

## Casos de uso (SRS §6)

| ID | Caso de uso | Pré-condição | Resultado | Evidência / pendência | Status |
|---|---|---|---|---|---|
| UC-01 | Login | Conta ativa e credenciais válidas | Sessão criada; evento de acesso registrado. | Feature/AuthTest, e2e/autenticacao.spec | **TESTED** |
| UC-02 | Consultar estoque | Usuário autorizado | Saldos filtrados pelo escopo do usuário. | Feature/EstoqueTest, e2e/estoque.spec | **TESTED** |
| UC-03 | Cadastrar material | Permissão de cadastro | Registro validado, persistido e auditado. | Feature/MilitarTest, Feature/CatalogoTest, e2e/estoque.spec | **TESTED** |
| UC-04 | Registrar entrada | Operador autorizado | Documento/origem validados; saldo atualizado. | Feature/EstoqueTest, Feature/ConcorrenciaTest, e2e/estoque.spec | **TESTED** |
| UC-05 | Entrega e devolução | Operação autorizada | Entrega e retorno vinculados quando aplicável. | P-05 | **BLOCKED** |
| UC-06 | Solicitar acautelamento | Solicitante elegível | Pedido pendente criado. | P-06 | **BLOCKED** |
| UC-07 | Decidir solicitação | Aprovador autorizado | Aprovação/rejeição e justificativa registradas. | P-06 | **BLOCKED** |
| UC-08 | Concluir acautelamento | Aprovação válida e operador autorizado | Entrega, atualização de estado e carteira registrados. | P-06/P-07 | **BLOCKED** |
| UC-09 | Registrar inspeção | Perfil autorizado | Resultado salvo e alertas recalculados. | P-03 | **BLOCKED** |
| UC-10 | Desacautelar | Operador autorizado | Vínculo encerrado e condição registrada. | P-05/P-06 | **BLOCKED** |
| UC-11 | Manutenção | Perfil autorizado | Estado atualizado; item indisponível até liberação. | P-03 | **BLOCKED** |
| UC-12 | Inventário | Equipe autorizada | Contagens e divergências registradas. | P-03 | **BLOCKED** |
| UC-13 | Relatório | Permissão de relatório | Resultado/exportação limitada ao escopo. | P-12 | **BLOCKED** |
| UC-14 | Auditoria | Auditor autorizado | Eventos consultados por filtros. | Feature/AuditoriaTest, e2e/autenticacao.spec | **TESTED** |
| UC-15 | Gerir acessos | Administrador autorizado | Contas/permissões atualizadas e auditadas. | Feature/AuthTest, Feature/OrganizacaoEPermissoesTest | **TESTED** |

## Pendências (SRS §15)

| ID | Tema | Confirmar | Situação |
|---|---|---|---|
| P-01 | Nuvem | Provedor, região, residência de dados e serviços disponíveis. | **ABERTA** — ver PENDENCIAS-INSTITUCIONAIS.md |
| P-02 | Organização | Hierarquia real e escopo de acesso por unidade. | **ABERTA** — ver PENDENCIAS-INSTITUCIONAIS.md |
| P-03 | Normas | Regras institucionais para inspeção, baixa, autorização e retenção. | **ABERTA** — ver PENDENCIAS-INSTITUCIONAIS.md |
| P-04 | Estoque | Categorias serializadas versus controladas por lote/quantidade. | **ABERTA** — ver PENDENCIAS-INSTITUCIONAIS.md |
| P-05 | Entrega | Quando há retorno e como registrar consumo sem retorno. | **ABERTA** — ver PENDENCIAS-INSTITUCIONAIS.md |
| P-06 | Aprovação | Delegação, substituição, rejeição, cancelamento e segregação de funções. | **ABERTA** — ver PENDENCIAS-INSTITUCIONAIS.md |
| P-07 | Carteira | Campos, formato físico, validade e verificação do QR Code. | **ABERTA** — ver PENDENCIAS-INSTITUCIONAIS.md |
| P-08 | Auditoria | Consultas registradas individualmente, retenção e acesso aos logs. | **ABERTA** — ver PENDENCIAS-INSTITUCIONAIS.md |
| P-09 | Privacidade | Classificação, acesso remoto, incidentes e descarte. | **ABERTA** — ver PENDENCIAS-INSTITUCIONAIS.md |
| P-10 | Operação | Metas de desempenho, picos, RPO/RTO, retenção e disponibilidade. | **ABERTA** — ver PENDENCIAS-INSTITUCIONAIS.md |
| P-11 | Autenticação | Política de senha, recuperação, sessão e cronograma de MFA. | **ABERTA** — ver PENDENCIAS-INSTITUCIONAIS.md |
| P-12 | Relatórios | Relatórios prioritários, campos, assinaturas e modelos oficiais. | **ABERTA** — ver PENDENCIAS-INSTITUCIONAIS.md |

## Melhorias de engenharia (não constam do SRS)

| ID | Item | Motivo |
|---|---|---|
| EN-01 | Cadeia de hash na auditoria + verificação | Evidência de adulteração (RNF-04) além de append-only |
| EN-02 | Idempotency-Key obrigatório em escrita de estoque | SRS §11.1 cita idempotência "quando necessário" |
| EN-03 | Dispositivos confiáveis e limitadores por conta/IP | Mitigar força bruta distribuída sem permitir bloqueio da conta por terceiros |
| EN-04 | Portão de políticas (config/sigarb.php) | Torna explícito o que está bloqueado e por qual pendência |
| EN-05 | Mascaramento de matrícula sem `militares.ver_detalhe` | Minimização (P-09) |
| EN-06 | Verificação de checksum a cada download de documento | Detectar arquivo adulterado/corrompido no disco |
| EN-07 | Documento fora do escopo responde 404 (não 403) | Evita enumeração de documentos de outras unidades |
| EN-08 | CSP de produção validada no E2E (com sonda negativa) | Mitigar XSS e injeção de conteúdo |
| EN-09 | Identidades MySQL separadas (runtime só DML) | Reduzir impacto de comprometimento da aplicação; protege triggers |
| EN-10 | Proxies confiáveis explícitos | Impedir falsificação de IP/esquema e bypass de limites |

## Totais por status

| Status | Quantidade (RF+RNF+RN+UC) |
|---|---|
| NOT_STARTED | 3 |
| IN_PROGRESS | 15 |
| IMPLEMENTED | 3 |
| TESTED | 37 |
| BLOCKED | 27 |
| ACCEPTED | 0 |
