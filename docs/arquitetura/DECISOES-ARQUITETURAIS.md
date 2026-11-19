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

## ADR-011 — Documentos privados com validação de conteúdo
**ACEITA-ENGENHARIA (classificação e retenção dependem de P-09/P-08).** Disco `documentos` privado, nome aleatório, lista de permissão PDF/PNG/JPG com verificação de assinatura e `finfo`, PDF com conteúdo ativo ou não verificável recusado, download sempre como anexo com checksum verificado. Vínculos por coluna FK por entidade (`documento_vinculos.movimentacao_id`), sem polimorfismo. Documentos não são apagados (arquivamento com justificativa). Detalhes: `docs/seguranca/DOCUMENTOS.md`.

## ADR-012 — Content Security Policy
**ACEITA-ENGENHARIA.** SPA em produção: `default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; font-src 'self'; connect-src 'self'; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'` (fonte: `frontend/csp.ts`), sem `unsafe-inline`/`unsafe-eval`; validada no E2E sobre o build (zero violações + sonda negativa). API: `default-src 'none'; frame-ancestors 'none'`. O servidor web de produção deve emitir os mesmos cabeçalhos e acrescentar `upgrade-insecure-requests` + HSTS sob HTTPS. Dev (Vite HMR) sem CSP.

## ADR-013 — Identidades MySQL separadas
**ACEITA-ENGENHARIA (implantação depende de P-01).** `sigarb_app` (runtime): SELECT/INSERT/UPDATE no banco; DELETE apenas em `sessions, cache, cache_locks, jobs, job_batches, failed_jobs, atribuicoes_papel`; sem DDL, TRIGGER, GRANT. `sigarb_migr`: DDL, usado somente em migração autorizada. Modelo: `infra/mysql/privilegios.sql.tpl`; prova: `scripts/verificar-privilegios.sh` (10 negações + E2E completo com `sigarb_app`).

## ADR-014 — Proxies confiáveis explícitos
**ACEITA-ENGENHARIA.** `config/trustedproxy.php` é sempre um array (vazio = nenhum proxy), lido de `SIGARB_TRUSTED_PROXIES`; `*`/`**` recusados. Fecha o atalho do framework que confiava em qualquer origem quando o Host terminava em `.on-forge.com`/`.on-vapor.com` (bypass de limite de login por IP, corrigido no CP-6).

## ADR-015 — Fuso America/Maceio no app, no banco e nos logs; conversão única do histórico UTC
**ACEITA-ENGENHARIA (pedido do responsável, 2026-11-19).** O Arsenal gravava e exportava em UTC (`config/app.php` fixo, sessão MySQL sem fuso) e aparecia 3 h adiantado nas telas com data crua, planilhas, banco, Sobre e logs. Passa a operar como a VM (.8/.9) e o Escala: `APP_TIMEZONE` (padrão `America/Maceio`) e `DB_TIMEZONE` (padrão `-03:00`, sessões `mysql`, `ecossistema` e `funcionarios`). Maceió é UTC-3 fixo (sem horário de verão desde 2019); se voltar a ter, `DB_TIMEZONE` fixo deixa de servir e esta decisão precisa ser revista. A API continua devolvendo ISO 8601 **com offset** (`…-03:00`); datas cruas de agregações (`MIN/MAX`) são lidas no fuso do app; o front-end formata sempre em `America/Maceio` (`components/quando.ts`, `dataHora`). `health/ready` ganhou a verificação `fuso` (offset da sessão do banco = offset do app).

**Histórico (migração `2026_12_01_000100_fuso_america_maceio`).** Alternativas consideradas: (a) não converter e interpretar UTC até um marco — mantém o banco 3 h adiantado para quem consulta direto e espalha a regra por toda leitura; (b) recalcular os hashes da auditoria — destrói a prova de integridade; (c) **escolhida**: converter todas as colunas `DATETIME`/`TIMESTAMP` uma única vez e manter os hashes originais, com o corte ancorado na própria cadeia.
- `DATETIME` guarda texto: −3 h. `TIMESTAMP` é guardado em UTC e convertido pela sessão: aplica-se o deslocamento do fuso padrão do servidor (`@@global.time_zone`, com que o app antigo gravava; −3 h na VM .9, 0 num MySQL em UTC), com a sessão em `+00:00`.
- Cada tabela converte numa transação que grava a marca `conversao_fuso` (EM_ANDAMENTO → CONVERTIDA; global CONCLUIDA). Rodar de novo não converte de novo; queda no meio é retomada pela marca.
- Tabelas imutáveis (razão, auditoria, inspeções, vínculos de documento, mensagens de chamado, vínculos de unidade etc.): os gatilhos `BEFORE UPDATE` são **guardados na marca antes** de serem removidos (com HMAC da APP_KEY: a retomada recusa definição adulterada pela credencial de runtime), recriados idênticos logo depois (também em falha) e conferidos ao fim; os nomes vão para o evento de auditoria. Os gatilhos `BEFORE DELETE` não são tocados. Remoção temporária só dentro desta migração, com o usuário `sigarb_migr` na janela autorizada (ADR-013).
- `eventos_auditoria`: sob o lock de `auditoria_cadeia`, guarda o último id (`corte_evento_id`), converte até ele e grava o evento encadeado `sistema.fuso.convertido` (fusos, deslocamentos, corte, linhas por tabela, gatilhos recriados). `AuditoriaService::verificar()` reconstrói o `ocorrido_em` original (+3 h) só até o corte; corte adulterado quebra o hash do próprio evento (falha fechada).
- Banco novo (a migração inicial da auditoria rodou no mesmo `migrate`: testes, E2E, instalação nova) só recebe a marca `BANCO_NOVO`. `down()` recusa se houve conversão: **rollback = restaurar o backup pré-migração**.
- Risco aceito: o `ops/implantar.sh` migra com a release antiga no ar; registros gravados pela release antiga entre a conversão de uma tabela e a troca (segundos) ficam 3 h adiantados. Mitigação: janela sem operação (RUNBOOK, seção 8).
