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
