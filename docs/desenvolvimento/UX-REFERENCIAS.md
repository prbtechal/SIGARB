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
