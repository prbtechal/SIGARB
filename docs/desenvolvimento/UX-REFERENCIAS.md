# Referências de UX (Mobbin) — 2026-11-05

Pesquisa feita via MCP do Mobbin (plataforma web), conforme a regra do usuário para trabalho de UI.

## O que foi pesquisado
1. "inventory management dashboard with sidebar navigation and stock summary cards"
2. "audit log table with filters for actor, date range and action"

## Referências usadas
| Produto | Tela | Padrão aproveitado |
|---|---|---|
| [Shopify — Inventory](https://mobbin.com/screens/d9deb551-88bf-4c93-91b3-43ef6659ccef) | Tabela de estoque | Colunas numéricas alinhadas à direita separando *disponível* de *em mãos* → saldo × reservado × disponível (RN-012). |
| [Deel — Assets](https://mobbin.com/screens/966dd7a5-28c6-4144-8a9d-a32e7e1f24fd) | Ativos | Cartões-resumo acima da tabela + filtros em linha + ação primária no canto superior direito. |
| [Square — Item library](https://mobbin.com/screens/36ae5076-f9b4-488d-a3b9-ace1d89c8aba) | Catálogo | Busca + filtros de categoria/status na mesma barra; badges de status. |
| [Vanta — Event log](https://mobbin.com/screens/41659ab7-5710-4abf-91ca-c6bf482c9b0b) | Log de eventos | Descrição curta no topo; filtros por tipo/ação/período; colunas data, ator, ação, alvo. |
| [Employment Hero — Audit Trail](https://mobbin.com/screens/3bc5bf9c-e40a-4da9-9257-dac36438f933) | Trilha de auditoria | Faixa informativa sobre o escopo dos dados exibidos; breadcrumb. |
| [1Password — Activity Log](https://mobbin.com/screens/84dbef44-916b-4322-956f-154845c224a7) | Activity log | Aviso "você só vê eventos das áreas a que tem acesso" → aviso de escopo organizacional. |

## Decisões aproveitadas
- Shell com sidebar fixa no desktop, que vira gaveta no celular; título + ação primária no topo da página.
- Tabelas com filtros acima e, abaixo de 720px, viram cartões empilhados.
- Badges de situação com texto (nunca só cor).
- Aviso de escopo nas listagens filtradas por unidade.
- Operações bloqueadas aparecem como cartões desabilitados com o motivo (pendência P-xx) em vez de serem escondidas, para que a pendência fique visível.

## Descartado e por quê
- Miniaturas de produto (Shopify/Wix/Square): material controlado não precisa de foto na listagem e foto aumentaria a exposição.
- Edição inline de quantidade (Uvodo/Wix): contraria RN-017/018, porque saldo só muda por movimentação registrada.
- Exportação CSV no log (Vanta): exportação depende de P-12/RN-019 e não entra no MVP.
- Paleta e marca dos produtos: identidade neutra SIGARB (PE-04).

---

# Documentos (RF-014) — 2026-11-05

Pesquisado no Mobbin (web): "document upload form with file drop zone, progress bar and validation error" e "documents list table with file type, size, uploader and download action".

| Produto | Padrão aproveitado |
|---|---|
| [Mercury — Company documents](https://mobbin.com/screens/57ae12ab-0e8b-4cc1-b97e-2542adeeb8c5) | Tipo de documento selecionado antes do arquivo; envio como página própria, não modal. |
| [Chatbase — Files](https://mobbin.com/screens/5a2dc98d-5c16-4eea-985f-bf094ad6fa1d) | Formatos aceitos e limite exibidos junto ao campo de arquivo. |
| [Faire — upload](https://mobbin.com/screens/13ca04f0-37af-452b-90eb-ff9021ef2b7d) | Barra de progresso com percentual e confirmação explícita ao concluir. |
| [Gusto — Documents](https://mobbin.com/screens/bed504c1-25c4-4978-be9b-41d91d2dcfde) | Lista enxuta: título como link, situação com selo e data, ação primária "Adicionar". |
| [Grok — Files](https://mobbin.com/screens/b5736569-ff1d-46ed-8f68-8f93c7f91ca5) / [HubSpot — Documents](https://mobbin.com/screens/eb236772-00f9-4d23-89e2-1027ef0dc6eb) | Colunas tipo, tamanho, autor e data; busca no topo. |

Descartado:
- Miniaturas e pré-visualização inline (Podia, Kajabi): o servidor só entrega como anexo, sem renderização inline (segurança).
- Seleção em massa com exclusão (Podia, StackAI): documentos não são apagados (RN-025); só se arquivam, com justificativa.
- Zona de arrastar e soltar como único meio de envio: mantive o campo de arquivo nativo com rótulo, mais confiável para teclado e leitor de tela.

---

# Painel (RF-024) e listas de referência (RF-029) — 2026-11-06

Pesquisa no Mobbin: "admin dashboard with KPI cards and bar chart breakdown by category" e "settings page managing a list of custom field options with archive action".

| Produto | Padrão aproveitado |
|---|---|
| [Gorgias — Ticket Fields](https://mobbin.com/screens/a880201b-c954-4cd3-bd03-ff00b63d6176) | Abas Ativos/Arquivados, busca e "Criar" na lista de valores. |
| [Wix — arquivar campo](https://mobbin.com/screens/4691074c-3ff9-4d4d-bf99-d4f7b0d5e230) | Diálogo de confirmação explicando a consequência (aqui com justificativa obrigatória). |
| [Cofounder — uso detalhado](https://mobbin.com/screens/c60ae2a8-05c4-425a-b74b-157cc9238fb5) | Barras horizontais com valor e percentual escritos ao lado. |
| [Gorgias — Statistics](https://mobbin.com/screens/b16206fc-c8f4-49a7-8a17-5181fe95ae48) / [Toggl — Admin Overview](https://mobbin.com/screens/68e22456-a90c-42b3-9f52-0afbd85ff0ea) | Cartões de indicador agrupados por tema, com título de seção. |

Descartado: séries temporais/linhas (não há série histórica que as justifique), rosquinhas (difícil de tornar acessível), cartões promocionais/onboarding (Shopify).
Decisão: o gráfico é uma `<table>` semântica com barras decorativas (`aria-hidden`); dispensa Chart.js (desvio de engenharia reversível do SRS §9.2).

## CP-9 — Interface institucional e Central de Suporte (2026-11-06)
**Referência principal:** Sistema-de-escalas (código-fonte, ver `DESIGN-SYSTEM.md`).
**Mobbin (web), pesquisado antes de implementar:**
- Fila de atendimento: [Zendesk](https://mobbin.com/screens/3156ae05-1afc-4c44-8335-bf21078eebf7), [Klaviyo](https://mobbin.com/screens/91b3f8b8-d5ec-4597-8ac1-8e7af35f123b), [Gorgias](https://mobbin.com/screens/8d45af19-088f-40a4-9b54-72e5cf109dc6).
- Detalhe com conversa e nota interna: [Plain](https://mobbin.com/screens/89c2075c-dc3c-4d43-abca-3bdb70ad0e0b), [Outseta](https://mobbin.com/screens/90fca916-605d-4890-98ea-448d0a6b2c96), [Zendesk](https://mobbin.com/screens/a5855ce7-b82b-4d19-b206-4842759e7d75).
- Abertura de chamado: [Supabase](https://mobbin.com/screens/88416c16-475b-4148-9fed-6bb1e0a3ca5d), [Krea](https://mobbin.com/screens/616d36c9-cb48-4259-8965-780e47731066).

**Aproveitado:** colunas de situação/prioridade/responsável com selos e filtros (Zendesk/Klaviyo); nota interna com fundo amarelo e aviso "não visível ao solicitante" e eventos de sistema discretos na linha do tempo (Plain); alternância Responder/Nota no editor (Outseta); categoria + severidade lado a lado e limite de caracteres visível (Supabase); painel lateral com situação, responsável e prioridade (Plain/Zendesk).
**Descartado:** inbox de três painéis (densa e ruim no celular), resumos por IA, tags, ações em massa, multicanal (e-mail/chat) e anexos (nova superfície de upload sem necessidade comprovada).
