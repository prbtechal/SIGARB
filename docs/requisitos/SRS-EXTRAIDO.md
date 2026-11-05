# SIGARB — SRS v1.0 — Extrato fiel em Markdown

> **Fonte:** `docs/requisitos/original/SIGARB_SRS_v1_0_Especificacao_Requisitos (2).docx`
> **SHA-256:** `00d37d3282fa20f7eaf9e8cea42924b9a700c6cbc4e84e15670a7efe56b44c5c`
> **Título:** SIGARB — Sistema Integrado de Gestão de Armamentos e Reserva Bélica — Especificação de Requisitos do Sistema (SRS)
> **Versão / data do documento:** 1.0 / 2026-10-27
> **Estado:** Rascunho estruturado; requer validação institucional (NÃO aprovado)
> **Origem da cópia:** `$HOME/Downloads/` (único arquivo correspondente ao padrão; 49.895 bytes; mtime 2026-11-05 11:54:16 -0300)
> **Data da cópia:** 2026-11-05
> **Método de extração:** conversão automática do `word/document.xml` (parágrafos e tabelas, em ordem). Conteúdo não editado; apenas a formatação foi convertida.
> **Metadados internos do DOCX:** gerado por `python-docx`; título/autor em branco; datas internas 2013-12-23 (padrão da ferramenta, não confiáveis como data do documento).
>
> Este arquivo é **extrato**, não interpretação. Interpretações de engenharia ficam em `MATRIZ-RASTREABILIDADE.md`, `PENDENCIAS-INSTITUCIONAIS.md` e `docs/arquitetura/`.

---

SIGARBSistema Integrado de Gestão de Armamentos e Reserva BélicaEspecificação de Requisitos do Sistema (SRS)Regras de negócio • Casos de uso • Dados • Arquitetura • API • Testes • ImplantaçãoVersão 1.0 — Documento-base para validação27 de outubro de 2026

## Controle do documento

| Campo | Valor |
|---|---|
| Projeto | SIGARB — Sistema Integrado de Gestão de Armamentos e Reserva Bélica |
| Versão/data | 1.0 / 27-10-2026 |
| Estado | Rascunho estruturado; requer validação institucional |
| Tecnologias | React, Laravel/PHP, MySQL, API REST/JSON |
| Uso | Base para análise, desenvolvimento, testes e implantação |

### Como usar este documento

Requisitos definidos refletem as decisões fornecidas para o projeto. Itens ainda incertos são tratados como pendências e devem ser confirmados antes da implementação. O documento não substitui validação jurídica, normativa, de segurança da informação ou dos procedimentos institucionais.

## Sumário

- 1. Introdução e escopo

- 2. Partes interessadas

- 3. Requisitos funcionais

- 4. Requisitos não funcionais

- 5. Regras de negócio

- 6. Casos de uso

- 7. Entidades e modelo de domínio

- 8. DER lógico preliminar

- 9. Arquitetura técnica

- 10. Mapa de telas

- 11. API inicial

- 12. Plano de implementação

- 13. Testes e aceite

- 14. Implantação e operação

- 15. Pendências e próximos passos

## 1. Introdução e escopo

### 1.1 Objetivo

O SIGARB será uma aplicação web institucional para gerir materiais sob responsabilidade de reservas e depósitos, abrangendo cadastro patrimonial, estoque, movimentações, entregas, devoluções, acautelamentos, inspeções, manutenção, reservas, documentos oficiais, relatórios e auditoria.

### 1.2 Objetivos

- Manter informação consistente sobre o que existe, onde está, em que estado se encontra e quem responde pelo material.

- Registrar o histórico de operações com responsável, data, justificativa e documentos relacionados.

- Formalizar solicitações e aprovações de acautelamento conforme autoridade institucional.

- Emitir carteira/registro em PDF e formato de impressão.

- Apresentar alertas internos, relatórios e dashboards.

- Aplicar controle de acesso e auditoria, com interface responsiva e acessível.

### 1.3 Escopo incluído

- Usuários, militares e estrutura organizacional.

- Armamentos, munições/lotes, equipamentos, produtos e materiais de treinamento.

- Estoques, locais, níveis estratégico/regional/operacional e saldos.

- Movimentações, reservas, transferências, entregas, devoluções, baixas, manutenção e inventário.

- Solicitações, aprovações, acautelamentos, inspeções e desacautelamentos.

- Documentos, relatórios, dashboards, alertas, auditoria e administração.

### 1.4 Fora do escopo inicial

- Integração com sistemas externos, LDAP/Active Directory ou APIs de terceiros.

- Alertas por SMS, WhatsApp ou e-mail; inicialmente, serão internos ao sistema.

- Alta disponibilidade 24/7 como requisito obrigatório da primeira versão.

- MFA/certificado digital na primeira entrega, embora a arquitetura deva permitir evolução.

### 1.5 Premissas e restrições

- Front-end React; back-end Laravel/PHP; banco MySQL; hospedagem em nuvem com HTTPS.

- Alvo inicial de cerca de 1.000 usuários simultâneos, com evolução pretendida para 5.000, a comprovar por testes de carga.

- Interface responsiva e meta de acessibilidade WCAG 2.2 AA, sujeita a validação.

- Backup automático obrigatório; frequência, retenção, RPO e RTO pendentes de aprovação.

- Regras normativas de inspeção, baixa, autorização e retenção devem ser confirmadas pela instituição.

## 2. Partes interessadas e papéis

| Papel | Responsabilidade |
|---|---|
| Administrador | Gerir contas, permissões e parâmetros autorizados. |
| Gestor | Manter cadastros e executar operações permitidas no seu escopo. |
| Operador | Consultar dados e executar operações explicitamente autorizadas. |
| Auditor | Consultar trilhas e evidências; não apagar nem alterar registros auditáveis. |
| Comandante/aprovador | Decidir solicitações de acautelamento conforme delegação formal. |
| Militar | Pessoa cadastrada que pode receber material, com ou sem login. |
| Equipe técnica/segurança | Desenvolver, operar, proteger e manter a aplicação. |

### 2.1 Usuário versus militar

Usuário é uma identidade de autenticação. Militar é um registro institucional de pessoa elegível a receber material. Um militar pode existir sem conta de acesso; a associação entre as entidades é opcional e controlada.

## 3. Requisitos funcionais

| ID | Área | Requisito | Prioridade |
|---|---|---|---|
| RF-001 | Autenticação | Login com identificador e senha; registrar sucesso, falha e encerramento. | Alta |
| RF-002 | Sessões | Aplicar política de sessão simultânea e revogação, com auditoria. | Alta |
| RF-003 | Autorização | Permissões por ação e escopo organizacional, verificadas no servidor. | Alta |
| RF-004 | Usuários | Criar, editar, desativar e reativar contas, preservando histórico. | Alta |
| RF-005 | Militares | Cadastrar militares separadamente das contas de acesso. | Alta |
| RF-006 | Organização | Representar organização, unidade, subunidade, reserva e depósito. | Alta |
| RF-007 | Armamentos | Manter cadastro técnico e identificação individual, incluindo série quando aplicável. | Alta |
| RF-008 | Munições | Controlar tipo, lote, fabricante, validade e saldo quando aplicável. | Alta |
| RF-009 | Equipamentos/produtos | Cadastrar itens unitários ou quantitativos, categoria, condição e localização. | Alta |
| RF-010 | Estoque | Exibir saldos por local, categoria, situação e unidade. | Alta |
| RF-011 | Movimentações | Registrar entrada, saída, entrega, devolução, acautelamento, desacautelamento, baixa e reserva. | Alta |
| RF-012 | Transferência | Registrar origem, destino, itens, quantidades, documento e estados de envio/recebimento. | Alta |
| RF-013 | Integridade de saldo | Impedir saldo negativo e garantir atualização transacional sob concorrência. | Alta |
| RF-014 | Documentos | Guardar metadados/arquivos oficiais e vincular às operações pertinentes. | Alta |
| RF-015 | Acautelamento | Gerir solicitação, análise, aprovação, entrega, acompanhamento e desacautelamento. | Alta |
| RF-016 | Aprovação | Registrar decisão do aprovador autorizado, data e justificativa quando exigida. | Alta |
| RF-017 | Carteira | Gerar PDF e formato para impressão, com identificador verificável/QR Code. | Alta |
| RF-018 | Inspeções | Registrar inspeções periódicas e alertar sobre prazos próximos/atrasados. | Alta |
| RF-019 | Manutenção | Registrar encaminhamento, ocorrência, estado, documento e liberação/baixa proposta. | Alta |
| RF-020 | Inventário | Registrar contagens, divergências e ajustes sujeitos à política de aprovação. | Alta |
| RF-021 | Reservas | Separar itens para finalidade definida e retirá-los da disponibilidade concorrente. | Alta |
| RF-022 | Alertas | Exibir alertas internos de validade, estoque, inspeção, aprovações e exceções. | Alta |
| RF-023 | Relatórios | Oferecer filtros e exportação autorizada para PDF/Excel. | Alta |
| RF-024 | Dashboard | Exibir indicadores e gráficos no escopo autorizado do usuário. | Média |
| RF-025 | Auditoria | Registrar ações críticas, consultas sensíveis, alterações, decisões e resultados. | Alta |
| RF-026 | Pesquisa | Oferecer pesquisa, filtros e paginação respeitando permissões. | Média |
| RF-027 | Histórico | Exibir linha do tempo sem permitir alteração silenciosa do passado. | Alta |
| RF-028 | Acessibilidade | Oferecer teclado, rótulos, contraste e mensagens acessíveis. | Alta |
| RF-029 | Parâmetros | Administrar listas de referência e regras configuráveis com auditoria. | Média |
| RF-030 | Backup | Executar backups automáticos e permitir testar restauração. | Alta |

## 4. Requisitos não funcionais

| ID | Categoria | Requisito |
|---|---|---|
| RNF-01 | Segurança | HTTPS/TLS; segredos fora do código-fonte. |
| RNF-02 | Senhas | Hash seguro; nunca armazenar senha em texto simples. |
| RNF-03 | Autorização | Verificação no servidor em cada requisição e aplicação de escopo. |
| RNF-04 | Auditoria | Proteção contra alteração ou acesso indevido aos registros. |
| RNF-05 | Privacidade | Minimização de dados e retenção definida conforme política e legislação. |
| RNF-06 | Integridade | Movimentações e saldos em transações atômicas, com controle de concorrência. |
| RNF-07 | Desempenho | Definir metas mensuráveis e validar por teste de carga representativo. |
| RNF-08 | Escalabilidade | Permitir ampliar recursos de aplicação, banco, cache e filas. |
| RNF-09 | Disponibilidade | Monitoramento, backup e recuperação; SLA 24/7 fica para fase futura. |
| RNF-10 | Usabilidade | Fluxos consistentes e utilizáveis em telas pequenas. |
| RNF-11 | Acessibilidade | Meta WCAG 2.2 AA com verificação automática e manual. |
| RNF-12 | Compatibilidade | Navegadores institucionais definidos na homologação. |
| RNF-13 | Manutenibilidade | Padrões de código, revisão, testes e documentação. |
| RNF-14 | Observabilidade | Logs/métricas sem segredos ou dados pessoais excessivos. |
| RNF-15 | Recuperação | Aprovar RPO/RTO, retenção e testes de restauração antes da produção. |

## 5. Regras de negócio

| ID | Regra de negócio |
|---|---|
| RN-001 | Toda movimentação tem tipo, data/hora, responsável, itens/quantidades quando aplicável e origem/destino pertinente. |
| RN-002 | Nenhuma operação pode gerar saldo negativo; validação e atualização são atômicas. |
| RN-003 | Itens individualizados devem ter identificador único; série única quando aplicável. |
| RN-004 | Materiais por lote devem preservar lote e validade quando aplicável. |
| RN-005 | Entrada deve registrar origem e documento de suporte conforme política institucional. |
| RN-006 | Saída definitiva exige motivo, destinação e autorização/documentação aplicável. |
| RN-007 | Entrega informa se há previsão de retorno; quando houver, vincula-se a devolução. |
| RN-008 | Devolução referencia a entrega de origem sempre que aplicável. |
| RN-009 | Acautelamento só é concluído após aprovação válida e requisitos institucionais atendidos. |
| RN-010 | Acautelamento permanece ativo até desacautelamento ou estado formalmente autorizado; inspeção anual é controlada separadamente da validade do registro. |
| RN-011 | Desacautelamento registra data, responsável, condição e divergências. |
| RN-012 | Reserva reduz disponibilidade, não necessariamente quantidade física. |
| RN-013 | Transferência registra saída e recebimento com vínculo comum e estados explícitos. |
| RN-014 | Baixa exige motivo, autorização e documento conforme política; não se confunde com manutenção. |
| RN-015 | Item em manutenção não deve ser apresentado como disponível sem liberação registrada. |
| RN-016 | Ajuste de inventário preserva saldo anterior, contagem, diferença, justificativa e aprovação quando exigida. |
| RN-017 | Movimentações históricas não são apagadas fisicamente por operações comuns. |
| RN-018 | Correções são feitas por estorno/correção auditável, mantendo o evento original. |
| RN-019 | Autorização é verificada no back-end, inclusive em exportações e downloads. |
| RN-020 | Consultas sensíveis e operações críticas geram eventos de auditoria conforme matriz aprovada. |
| RN-021 | Arquivos são validados e guardados fora da área pública, com acesso autorizado. |
| RN-022 | QR Code não contém dados sensíveis em texto aberto; usa identificador opaco/validação controlada. |
| RN-023 | Alertas usam regras configuradas e registram criação, estado e resolução. |
| RN-024 | Permissões granulares e escopo organizacional determinam o acesso efetivo. |
| RN-025 | Exclusão lógica não elimina a obrigação de retenção ou descarte seguro. |

## 6. Casos de uso

| ID | Caso de uso | Pré-condição | Resultado |
|---|---|---|---|
| UC-01 | Login | Conta ativa e credenciais válidas | Sessão criada; evento de acesso registrado. |
| UC-02 | Consultar estoque | Usuário autorizado | Saldos filtrados pelo escopo do usuário. |
| UC-03 | Cadastrar material | Permissão de cadastro | Registro validado, persistido e auditado. |
| UC-04 | Registrar entrada | Operador autorizado | Documento/origem validados; saldo atualizado. |
| UC-05 | Entrega e devolução | Operação autorizada | Entrega e retorno vinculados quando aplicável. |
| UC-06 | Solicitar acautelamento | Solicitante elegível | Pedido pendente criado. |
| UC-07 | Decidir solicitação | Aprovador autorizado | Aprovação/rejeição e justificativa registradas. |
| UC-08 | Concluir acautelamento | Aprovação válida e operador autorizado | Entrega, atualização de estado e carteira registrados. |
| UC-09 | Registrar inspeção | Perfil autorizado | Resultado salvo e alertas recalculados. |
| UC-10 | Desacautelar | Operador autorizado | Vínculo encerrado e condição registrada. |
| UC-11 | Manutenção | Perfil autorizado | Estado atualizado; item indisponível até liberação. |
| UC-12 | Inventário | Equipe autorizada | Contagens e divergências registradas. |
| UC-13 | Relatório | Permissão de relatório | Resultado/exportação limitada ao escopo. |
| UC-14 | Auditoria | Auditor autorizado | Eventos consultados por filtros. |
| UC-15 | Gerir acessos | Administrador autorizado | Contas/permissões atualizadas e auditadas. |

### 6.1 Fluxo principal de acautelamento

1. O solicitante cria pedido com os campos exigidos pela política institucional.

2. O sistema valida elegibilidade, dados obrigatórios e impedimentos configurados.

3. O pedido é encaminhado à autoridade competente.

4. A autoridade aprova ou rejeita; decisão e justificativa ficam registradas.

5. Após aprovação, operador autorizado registra entrega efetiva e condição dos itens.

6. O sistema grava a movimentação, atualiza disponibilidade em transação e gera a carteira.

7. O sistema agenda alertas de inspeção conforme regra institucional aprovada.

8. O desacautelamento encerra o vínculo após conferência, registrando condição, documentos e divergências.

Exceções a detalhar: cancelamento, aprovação expirada, item indisponível entre aprovação e entrega, devolução parcial, dano e indisponibilidade do sistema.

## 7. Entidades e modelo de domínio

| Domínio | Entidades candidatas |
|---|---|
| Acesso | usuarios, sessoes, tentativas_login, perfis/permissoes |
| Pessoas | militares, postos_graduacoes, situacoes_funcionais |
| Organização | organizacoes, unidades, subunidades, locais_estoque |
| Catálogos | categorias, tipos_item, fabricantes, modelos, calibres, estados_conservacao, tipos_movimentacao, tipos_documento |
| Materiais | itens_patrimoniais, armamentos, equipamentos, produtos, lotes_municao |
| Estoque | estoques, saldos_estoque, reservas_estoque, inventarios, contagens_inventario, ajustes_estoque |
| Operações | movimentacoes, movimentacao_itens, transferencias, entregas, devolucoes, manutencoes, baixas |
| Acautelamento | solicitacoes_acautelamento, aprovacoes, acautelamentos, acautelamento_itens, inspecoes, carteiras |
| Documentos | documentos, documento_vinculos |
| Governança | eventos_auditoria, alertas, configuracoes |

### 7.1 Decisões de modelagem

- Separar itens individualizados e serializados de materiais controlados por lote/quantidade.

- Evitar referência polimórfica genérica sem integridade referencial para todos os materiais.

- Separar saldo atual do histórico/razão de movimentações.

- Usar tabelas de referência para tipos configuráveis que precisem evoluir.

- Definir chaves estrangeiras, índices, unicidade, exclusão lógica e retenção no modelo físico.

- Não registrar senhas, tokens ou segredos nos logs.

## 8. DER lógico preliminar

O DER abaixo é lógico e preliminar. Cardinalidades e entidades intermediárias devem ser confirmadas na modelagem física.

- ORGANIZACOES 1:N UNIDADES

- UNIDADES 1:N LOCAIS_ESTOQUE

- UNIDADES 1:N MILITARES

- USUARIOS 0..1:1 MILITARES — associação opcional, conforme política

- LOCAIS_ESTOQUE 1:N SALDOS_ESTOQUE

- MOVIMENTACOES 1:N MOVIMENTACAO_ITENS

- ITENS_PATRIMONIAIS 1:N MOVIMENTACAO_ITENS, para itens individualizados

- LOTES_MUNICAO 1:N MOVIMENTACAO_ITENS, para material por lote

- DOCUMENTOS N:N MOVIMENTACOES por DOCUMENTO_VINCULOS

- MILITARES 1:N SOLICITACOES_ACAUTELAMENTO

- SOLICITACOES_ACAUTELAMENTO 1:N APROVACOES

- SOLICITACOES_ACAUTELAMENTO 0..1:1 ACAUTELAMENTOS

- ACAUTELAMENTOS 1:N ACAUTELAMENTO_ITENS e 1:N INSPECOES

- USUARIOS 1:N EVENTOS_AUDITORIA

O DER gráfico definitivo e o DDL versionado só devem ser fechados depois de decidir a estratégia de itens individuais versus lotes, a hierarquia organizacional e os fluxos de estoque.

## 9. Arquitetura técnica

### 9.1 Visão lógica

Navegador → React SPA → HTTPS/JSON → Laravel API → Form Requests/Policies → Services ou Actions → Eloquent/Repository quando justificável → MySQL.

### 9.2 Front-end

- React com Vite; TypeScript recomendado.

- React Router para navegação e camada de API para comunicação uniforme.

- Formulários com validação, componentes reutilizáveis e estados de carregamento/erro/vazio/sucesso.

- Chart.js para gráficos; documentos oficiais gerados no servidor quando adequado.

- A interface nunca substitui autorização no servidor.

### 9.3 Back-end

- Selecionar versão estável e suportada do Laravel no início da implementação, verificando compatibilidade das dependências.

- API versionada, por exemplo /api/v1, com validação, Policies, Services/Actions e transações.

- Eloquent como ORM; Repository apenas quando trouxer benefício real.

- Filas e agendador para tarefas demoradas/recorrentes.

- Definir Sanctum, cookies, CSRF, CORS e domínios conforme o deployment real.

### 9.4 Banco e segurança

- Migrations versionadas, chaves estrangeiras e índices adequados.

- Transações e controle de concorrência em operações de saldo.

- Backups criptografados e rede do banco restrita.

- Proteção contra CSRF, XSS, SQL injection, brute force e uploads maliciosos.

- Arquivos em armazenamento privado; downloads sempre autorizados.

- Segredos em variáveis protegidas ou gerenciador de segredos.

## 10. Mapa inicial de telas

| Área | Telas/recursos |
|---|---|
| Acesso | Login, logout, sessão expirada, acesso negado |
| Dashboard | Indicadores, alertas, pendências, gráficos, atalhos |
| Administração | Usuários, permissões, parâmetros, unidades e locais |
| Pessoas | Lista/detalhe de militares e vínculo opcional com conta |
| Catálogo | Categorias, fabricantes, modelos, calibres e tipos |
| Materiais | Armamentos, munições/lotes, equipamentos e produtos |
| Estoque | Saldos, disponibilidade, localização, reservas e histórico |
| Movimentações | Entrada, saída, entrega, devolução, transferência, reserva, baixa |
| Acautelamento | Solicitação, aprovação, entrega, carteira, inspeção, desacautelamento |
| Manutenção/inventário | Ordens, estados, contagem, divergências e ajustes |
| Documentos | Cadastro, upload, pesquisa, vínculos e download |
| Relatórios | Filtros, prévia, PDF e Excel |
| Auditoria | Busca por ator, período, ação, objeto e resultado |

A quantidade definitiva de telas será definida após prototipação; não é necessário fixar agora uma contagem rígida.

## 11. API inicial

| Método | Endpoint proposto | Finalidade |
|---|---|---|
| POST | /api/v1/auth/login | Autenticar |
| POST | /api/v1/auth/logout | Encerrar sessão |
| GET | /api/v1/me | Identidade e permissões efetivas |
| GET/POST | /api/v1/users | Listar/criar usuários |
| GET/PUT | /api/v1/users/{id} | Consultar/atualizar usuário |
| GET/POST | /api/v1/militares | Listar/cadastrar militares |
| GET/POST | /api/v1/items | Consultar/cadastrar materiais, conforme modelo final |
| GET | /api/v1/stock/balances | Consultar saldos autorizados |
| POST | /api/v1/movements | Registrar movimentação validada |
| POST | /api/v1/transfers | Iniciar transferência |
| POST | /api/v1/acautelamentos/requests | Criar solicitação |
| POST | /api/v1/acautelamentos/{id}/decision | Aprovar/rejeitar |
| POST | /api/v1/acautelamentos/{id}/delivery | Registrar entrega |
| POST | /api/v1/acautelamentos/{id}/inspections | Registrar inspeção |
| POST | /api/v1/acautelamentos/{id}/return | Registrar desacautelamento |
| GET | /api/v1/documents/{id}/download | Download autorizado |
| GET | /api/v1/reports/{reportKey} | Gerar/consultar relatório |
| GET | /api/v1/audit-events | Consultar auditoria |
| GET | /api/v1/alerts | Listar alertas |

### 11.1 Padrões da API

- Paginação, ordenação e filtros validados no servidor.

- Formato JSON consistente, códigos HTTP apropriados e erros sem detalhes internos.

- Idempotência ou proteção contra repetição em operações críticas quando necessário.

- Contrato documentado em OpenAPI antes da integração completa.

- Exportações e downloads verificam permissões e podem gerar evento de auditoria.

## 12. Plano de implementação

| Etapa | Entregáveis |
|---|---|
| 0 — Decisões | Validar políticas, organização, modelo de estoque, ambiente, versões e critérios de aceite. |
| 1 — Fundação | Repositórios Git, Laravel, React, MySQL, ambientes, autenticação, autorização e auditoria-base. |
| 2 — Cadastros | Organização, locais, militares, usuários, categorias e catálogos. |
| 3 — Materiais/estoque | Itens individuais, lotes, saldos, razão de movimentações e integridade transacional. |
| 4 — Operações | Entrada, saída, entrega, devolução, transferência, reserva, manutenção, baixa e inventário. |
| 5 — Acautelamento | Solicitação, aprovação, entrega, carteira, inspeção e desacautelamento. |
| 6 — Documentos/relatórios | Upload seguro, vínculos, PDF/Excel, dashboard e filtros. |
| 7 — Qualidade | Segurança, auditoria, acessibilidade, carga e revisão de dependências. |
| 8 — Homologação | Migração controlada, restauração testada, treinamento, documentação e rollback. |

### 12.1 Organização de código sugerida

Laravel: Controllers API versionados, Requests, Policies, Services/Actions, Models, Events/Listeners, Jobs, Notifications, migrations, factories, seeders e testes. React: app, routes, features, components, api, hooks, schemas, layouts, pages e tests. A estrutura final deve ser padronizada antes do crescimento do código.

## 13. Testes e critérios de aceite

| Tipo | Cobertura |
|---|---|
| Unitários | Regras, transições de estado, cálculos e permissões. |
| Integração | Transações, relacionamentos, arquivos, documentos e auditoria. |
| API | Autenticação, autorização, validação, paginação e erros. |
| Ponta a ponta | Login, cadastro, movimentação, acautelamento, aprovação, inspeção e desacautelamento. |
| Segurança | Acesso horizontal/vertical, CSRF, XSS, injection, brute force e uploads. |
| Concorrência | Operações simultâneas sem saldo negativo ou duplicação. |
| Desempenho | Teste representativo para 1.000 usuários simultâneos; metas definidas antes da homologação. |
| Acessibilidade | Teclado, foco, leitores de tela, contraste e formulários. |
| Recuperação | Restaurar backup em ambiente isolado e medir RPO/RTO. |
| Aceitação | Representantes autorizados validam fluxos, relatórios e permissões. |

### 13.1 Critérios mínimos

- Nenhuma ação sem autorização efetiva no servidor.

- Movimentações críticas transacionais, sem saldo negativo.

- Histórico rastreável para operações relevantes.

- Acautelamento exige aprovação válida antes da entrega.

- Carteiras, relatórios e downloads respeitam permissões.

- Alertas seguem regras configuradas e verificadas.

- Backup executado e restauração testada.

- Vulnerabilidades críticas/altas corrigidas ou formalmente tratadas antes da produção.

## 14. Implantação e operação

### 14.1 Ambientes

- Desenvolvimento com dados fictícios.

- Teste/integração com configuração próxima à produção.

- Homologação institucional com acessos controlados.

- Produção isolada, com segredos, rede, arquivos e banco protegidos.

### 14.2 Pipeline

Commit → revisão → análise estática e de dependências → testes → build React → migrações revisadas → homologação → aceite → backup/checagem pré-deploy → implantação controlada → verificação pós-deploy.

### 14.3 Backup e recuperação

- Definir frequência, retenção, criptografia, cópias separadas e responsáveis.

- Aprovar RPO e RTO.

- Incluir banco, documentos e configurações necessárias; proteger segredos.

- Testar restauração periodicamente e registrar evidências.

- Definir rollback da aplicação e política para migrações de banco.

### 14.4 Monitoramento

- Monitorar disponibilidade, erros, latência, recursos, banco, filas e tarefas agendadas.

- Alertar equipe técnica sobre falhas de backup e erros críticos.

- Não registrar senhas, tokens ou conteúdo confidencial desnecessário.

## 15. Pendências e próximos passos

| ID | Decisão pendente | Confirmar |
|---|---|---|
| P-01 | Nuvem | Provedor, região, residência de dados e serviços disponíveis. |
| P-02 | Organização | Hierarquia real e escopo de acesso por unidade. |
| P-03 | Normas | Regras institucionais para inspeção, baixa, autorização e retenção. |
| P-04 | Estoque | Categorias serializadas versus controladas por lote/quantidade. |
| P-05 | Entrega | Quando há retorno e como registrar consumo sem retorno. |
| P-06 | Aprovação | Delegação, substituição, rejeição, cancelamento e segregação de funções. |
| P-07 | Carteira | Campos, formato físico, validade e verificação do QR Code. |
| P-08 | Auditoria | Consultas registradas individualmente, retenção e acesso aos logs. |
| P-09 | Privacidade | Classificação, acesso remoto, incidentes e descarte. |
| P-10 | Operação | Metas de desempenho, picos, RPO/RTO, retenção e disponibilidade. |
| P-11 | Autenticação | Política de senha, recuperação, sessão e cronograma de MFA. |
| P-12 | Relatórios | Relatórios prioritários, campos, assinaturas e modelos oficiais. |

### 15.1 Riscos principais

- Modelagem incorreta entre item individual e lote pode comprometer estoque e rastreabilidade.

- Regras não aprovadas podem gerar retrabalho e divergência com os procedimentos institucionais.

- Auditar toda leitura sem política pode gerar volume excessivo de logs; definir eventos e retenção.

- 1.000 usuários simultâneos é uma meta de capacidade a comprovar, não uma garantia decorrente do framework.

- QR Code não deve expor dados sensíveis; deve usar identificador opaco e validação controlada.

- Documentos e logs precisam de proteção, retenção e recuperação testada.

### 15.2 Próximas entregas

- Validar este SRS com o responsável institucional e registrar aprovação/alterações.

- Resolver pendências, priorizando modelo de estoque, hierarquia e acautelamento.

- Criar matriz de rastreabilidade requisito → regra → caso de uso → tela/API → teste.

- Finalizar modelo de domínio, DER gráfico e dicionário de dados.

- Produzir migrations revisadas e dados de teste fictícios.

- Prototipar e validar os fluxos críticos.

- Implementar uma fatia vertical inicial: autenticação, permissões, auditoria e primeiro fluxo de cadastro/consulta.

- Expandir por módulos, com testes e revisão a cada entrega.

## Anexo A — Glossário

| Termo | Definição |
|---|---|
| Acautelamento | Registro formal de atribuição de material a militar, sujeito às regras institucionais. |
| Desacautelamento | Encerramento formal do vínculo e registro do retorno. |
| Movimentação | Evento que altera localização, disponibilidade, responsável ou quantidade. |
| Saldo | Quantidade existente/disponível num local, conciliada com movimentações. |
| Item individualizado | Bem rastreado individualmente, normalmente por identificador ou série. |
| Lote | Conjunto controlado por lote, como munições ou produtos consumíveis. |
| RPO | Perda máxima de dados tolerável, expressa em tempo. |
| RTO | Tempo-alvo de recuperação após interrupção. |
| SRS | Especificação de Requisitos do Sistema. |

## Anexo B — Versionamento

Esta versão é uma base de engenharia e não deve ser considerada especificação final aprovada até que as pendências sejam resolvidas e os requisitos validados. Mudanças futuras devem registrar versão, data, responsável, motivo e impacto.

