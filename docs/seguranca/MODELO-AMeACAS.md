# Modelo de ameaças (STRIDE, MVP)

| Ameaça | Vetor | Mitigação implementada | Evidência | Resíduo |
|---|---|---|---|---|
| Spoofing | Força bruta de senha | Limites conta+IP (5/min), conta (20/15min), IP (30/min); resposta genérica; hash dummy contra enumeração por tempo | AuthTest | MFA fora do escopo (P-11) |
| Spoofing | Bloqueio da conta por terceiros | Dispositivo confiável no servidor dispensa só o limite por conta; limite próprio por dispositivo; revogação em troca de senha/desativação | AuthTest (4 casos) | — |
| Spoofing | Sequestro de sessão | Cookie HttpOnly/SameSite=Lax, sessão regenerada no login, criptografada, ociosidade 30 min, limite 3 sessões, revogação | AuthTest | HTTPS/`Secure` só na implantação |
| Tampering | CSRF | Grupo web: token XSRF obrigatório em escrita | e2e autenticacao (419) | — |
| Tampering | Alterar razão/auditoria | Triggers MySQL + traits Imutavel + hash encadeado | EstoqueTest, AuditoriaTest | DBA com privilégio pode dropar trigger → verificação de cadeia detecta |
| Tampering | Saldo negativo / corrida | FOR UPDATE ordenado, CHECK/UNSIGNED, UNIQUE, idempotência | ConcorrenciaTest | — |
| Tampering | SQL injection / LIKE | Query builder parametrizado; curingas escapados; ordenação por lista branca | MilitarTest | — |
| Repudiation | Negar ação | Auditoria com ator, IP, request_id, resultado (inclui negações) | AuditoriaTest | Retenção P-08 |
| Info disclosure | IDOR horizontal | Policies por escopo em cada recurso; listas filtradas na query | Org/Militar/Estoque tests, e2e escopo | — |
| Info disclosure | Oráculo de matrícula mascarada | Busca por matrícula só onde há `ver_detalhe` | MilitarTest | — |
| Info disclosure | Vazamento em erro | `APP_DEBUG=false` fora do dev; erro padronizado com request_id | AuditoriaTest | `.env` de dev usa debug=true |
| Info disclosure | Dados em storage do navegador | Nada sensível em localStorage; `Cache-Control: no-store` na API | revisão de código | — |
| DoS | Abuso de listagens | per_page ≤ 100, máx. 200 itens por movimentação | testes de validação | Sem rate limit global da API |
| Elevation | Escalada de papel | Gestão de papéis só global; sem auto-alteração | OrganizacaoEPermissoesTest | — |
| Elevation | Simular aprovação | Rotas de acautelamento retornam 409 sem persistir | e2e estoque | — |

Pendências de segurança antes de produção: HTTPS/HSTS, `TrustProxies` correto (os limitadores dependem do IP real), cabeçalho CSP, revisão de dependências, pentest, backup criptografado.

## Revisão CP-6 (2026-11-06)
| Achado | Gravidade | Disposição |
|---|---|---|
| `TrustProxies` sem configuração confiava em `X-Forwarded-For` quando o Host terminava em `.on-forge.com/.on-vapor.com` → bypass dos limites de login por IP | Alta | **Corrigido** (ADR-014) com teste vermelho→verde |
| Rota `POST /catalog/{tipo}` permitia a gestor de uma unidade criar valor global, contornando `referencias.gerir` | Média | **Corrigido** (rota removida) com regressão |
| Deadlock em entradas concorrentes recusava entrada legítima (disponibilidade) | Média | **Corrigido** com regressão 3×10 |
| Ausência de CSP na SPA e na API | Média | **Corrigido** (ADR-012) |
| Runtime com privilégios de DDL | Média | **Mitigado** no modelo (ADR-013); aplicação real depende da implantação |
| Ghostscript não sanitiza PDF (preserva JS) | Informativo | Registrado em PE-06; não adotado |
| Reescrita total da cadeia por DBA | Alta (residual) | **Aberto**; protótipo de âncora PE-07 aguarda decisão |
| Dependências | — | 0 advisories (composer/npm, produção e dev) em 2026-11-06 |

## Roteiro de incidente com dados pessoais (P-09, decisão do responsável — CP-13)
1. **Conter:** revogar as sessões do usuário suspeito (`POST /users/{id}/sessions/revoke`) e desativar a conta se necessário.
2. **Preservar:** exportar a trilha de auditoria do período (Auditoria → filtros por usuário/data) e conferir a integridade da cadeia (`GET /audit-events/integrity`).
3. **Avaliar:** listar exportações (`dados.exportados`, `relatorio.exportado`) e consultas a efetivos no período; identificar quais titulares foram expostos.
4. **Comunicar:** encarregado de dados da instituição, com o relatório dos passos 2–3. A comunicação à ANPD e aos titulares segue a norma institucional.
5. **Corrigir:** registrar a causa e a correção no checkpoint do projeto.
