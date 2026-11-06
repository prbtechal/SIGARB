# Visão geral da arquitetura

```
Navegador ──HTTPS(futuro)──► React SPA (Vite) ──mesma origem /api/v1──► Laravel 13 (grupo "web": sessão+CSRF)
                                                                         │ Form Requests → Policies/AutorizacaoService
                                                                         │ Services (EstoqueService, AuditoriaService, DispositivoService)
                                                                         ▼
                                                                       MySQL 8.4 (CHECK, UNIQUE, FK, triggers append-only)
```

| Camada | Local | Responsabilidade |
|---|---|---|
| SPA | `frontend/src` | Telas, validação de UX, nunca autorização (RNF-03). Cliente único `api/client.ts`. |
| HTTP | `backend/routes/api.php`, `app/Http` | Rotas versionadas, validação (Form Requests), formato de erro único (`bootstrap/app.php`). |
| Autorização | `app/Services/AutorizacaoService.php`, `app/Policies` | Permissão + escopo por subárvore de unidade (ADR-004). |
| Domínio | `app/Services/EstoqueService.php` | Único escritor de saldo/razão; transações, locks, idempotência. |
| Auditoria | `app/Services/AuditoriaService.php` | Eventos encadeados por hash, sanitizados. |
| Políticas | `config/sigarb.php`, `config/sigarb_permissoes.php` | Portão de operações e matriz de papéis (propostas). |
| Infra local | `infra/compose.yaml` | Projeto Compose `sigarb-dev`; MySQL :18406, API :18480, API E2E :18482. |

Decisões: ver `DECISOES-ARQUITETURAIS.md`. Variância de hospedagem: ADR-008.
