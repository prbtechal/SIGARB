# Decisões arquiteturais (ADR)

Status possíveis: PROPOSTA · ACEITA-ENGENHARIA (reversível, sem aval institucional) · APROVADA (institucional).

## ADR-001 — Monorepo
**ACEITA-ENGENHARIA.** `backend/` (Laravel), `frontend/` (React), `docs/`, `infra/` (Docker Compose dev), `scripts/`. Um único histórico facilita rastreabilidade requisito → código → teste.

## ADR-002 — Versões
**ACEITA-ENGENHARIA.** PHP 8.5 (imagem `php:8.5-cli`), Laravel 13 (suportado, compatível com PHP ≥ 8.3), MySQL 8.4 LTS, Node 24 / React 19 / Vite / TypeScript. Versões exatas fixadas em `composer.lock` e `package-lock.json`. Não há PHP no host: todo comando PHP roda em container.

## ADR-003 — Autenticação: sessão por cookie (Sanctum modo SPA), sem JWT
**ACEITA-ENGENHARIA.** A SPA é servida na mesma origem da API (proxy do Vite em dev; Apache/Nginx em implantação futura). A API usa o guard de sessão do Laravel via `statefulApi()` do Sanctum: cookie `HttpOnly`, `SameSite=Lax`, proteção CSRF por `XSRF-TOKEN`. **Não** são emitidos tokens pessoais nem JWT; nada sensível vai para `localStorage`.
- Sessões em tabela `sessions` (driver `database`) → revogação real (RF-002): o usuário vê/revoga suas sessões; o admin revoga todas as de uma conta; desativação de conta revoga tudo.
- Limite provisório de sessões simultâneas (P-11): 3; ao exceder, a mais antiga é revogada e auditada.
- Login: rate limit por identificador+IP; mensagem genérica para falha; eventos `auth.login.sucesso|falha`, `auth.logout`.
- Evolução para MFA (fora do escopo inicial) não exige trocar o mecanismo.

## ADR-004 — Autorização: RBAC próprio + escopo organizacional
**ACEITA-ENGENHARIA (regra de escopo depende de P-02).** Tabelas `papeis`, `permissoes`, `papel_permissao`, `atribuicoes_papel(usuario, papel, unidade_escopo)`. Permissão efetiva = permissão do papel **e** recurso dentro da subárvore da unidade da atribuição. A árvore usa caminho materializado (`unidades.caminho = /1/4/9/`) para consultas `LIKE '/1/4/%'` indexadas. Verificação sempre no servidor (Policies + `ScopeService`); listas são filtradas por escopo na query, nunca no front. Sem pacote de terceiros (menos dependências; regras explícitas).
Papel `ADMIN` com atribuição sem unidade = escopo global (somente administração; não implica operar estoque).

## ADR-005 — Modelo de estoque: material individual × lote, saldo × razão
**ACEITA-ENGENHARIA (classificação depende de P-04).**
- `materiais` (catálogo) com `modo_controle` ∈ {INDIVIDUAL, LOTE}.
- INDIVIDUAL → `itens_individuais` (identificador patrimonial único global; nº de série único por material quando informado; local atual; situação).
- LOTE → `lotes` (código único por material, fabricante, validade) + `saldos_estoque(local, lote)` com `quantidade` e `quantidade_reservada`, `CHECK (quantidade >= 0 AND quantidade_reservada >= 0 AND quantidade_reservada <= quantidade)`.
- `movimentacoes` + `movimentacao_itens` = razão imutável (sem UPDATE/DELETE por triggers). Cada item referencia exatamente um entre `item_individual_id` e `lote_id` (CHECK) — sem polimorfismo genérico (SRS §7.1).
- Escrita de saldo apenas via `EstoqueService` em transação com `SELECT … FOR UPDATE` nas linhas de saldo, em ordem determinística (evita deadlock).
- Idempotência: `movimentacoes.chave_idempotencia` UNIQUE, enviada pelo cliente no cabeçalho `Idempotency-Key`; repetição devolve o resultado original (200) sem duplicar.
- Correção = estorno (RN-018): nova movimentação `ESTORNO` vinculada (`estorno_de_id` UNIQUE), nunca edição.

## ADR-006 — Portão de políticas para operações
**ACEITA-ENGENHARIA.** `config/sigarb_operacoes.php` lista cada tipo de operação com estado `HABILITADA` ou `BLOQUEADA` + pendência. MVP habilita apenas **ENTRADA** (registro administrativo, RN-001/002/003/004/005) e **ESTORNO** (RN-018). Todos os demais (saída, entrega, devolução, transferência, reserva, baixa, manutenção, inventário/ajuste, acautelamento, aprovação, inspeção, carteira) respondem `409` com código `OPERACAO_BLOQUEADA` e a pendência correspondente. O front exibe a operação como indisponível com o motivo.

## ADR-007 — Auditoria append-only com encadeamento de hash
**ACEITA-ENGENHARIA (política de retenção depende de P-08).** `eventos_auditoria` com triggers MySQL que rejeitam UPDATE/DELETE; cada evento grava `hash = sha256(hash_anterior ‖ conteúdo canônico)` sob lock, permitindo detectar remoção/alteração (comando `sigarb:auditoria:verificar`). Nunca grava senha, token, cookie ou corpo bruto de requisição.

## ADR-008 — Hospedagem: variância em relação ao SRS
**PROPOSTA — requer confirmação institucional.** O SRS (§1.5) prevê nuvem com HTTPS. Há proposta de hospedar em VMs de intranet (app 192.168.200.8 / banco 192.168.200.9), que já hospedam outros sistemas em produção. A aprovação existente **não** cobre o SIGARB. Nenhum acesso a essas VMs foi feito. Ver `docs/operacao/IMPLANTACAO-FUTURA.md`.

## ADR-009 — Dados pessoais mínimos
**ACEITA-ENGENHARIA (P-09).** Militar sem CPF, RG, endereço, telefone. Listagens não exibem matrícula completa para perfis sem `militares.ver_detalhe`; URLs usam IDs internos, nunca nome/matrícula.

## ADR-010 — Front-end sem biblioteca de UI/estado global
**ACEITA-ENGENHARIA.** React 19 + React Router + TypeScript; estado de sessão em Context; cliente HTTP único (`fetch`) com CSRF, tratamento de 401/403/409/422 e sem cache em storage. CSS com tokens próprios (tema neutro, contraste AA). Testes: Vitest + Testing Library; E2E: Playwright contra o backend Laravel real.
