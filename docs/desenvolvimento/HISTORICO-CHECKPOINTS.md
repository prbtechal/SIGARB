# Histórico de checkpoints (somente acréscimos)

## CP-0 — Inicialização (2026-11-05)
SRS localizado (1 arquivo, SHA-256 00d37d32…4c5c), copiado sem alteração; DOCX mantido fora do Git (PE-03). Extrato, pendências e ADRs criados. SIGARB não existia; nenhum trabalho alheio foi tocado.

## CP-1 — Fundação + cadastros + estoque (2026-11-05, 2990cfd)
Laravel 13/PHP 8.5/MySQL 8.4 em Docker isolado. 53 testes verdes. Bug real achado pelos testes: o MySQL reordena as chaves JSON e o cast datetime descartava microssegundos; ambos quebravam a cadeia de hash da auditoria e foram corrigidos antes do commit.

## CP-2 — Endurecimento de login (2026-11-05, 14940ff → cc071ce)
A revisão automática de segurança apontou: oráculo de matrícula na busca, força bruta distribuída, bloqueio de conta por terceiros, divergência de normalização e cookie de dispositivo não revogável. Todos corrigidos em iterações com testes de regressão.
**Falha de processo:** o commit 2e089f6 entrou com 1 teste falhando (cadeia `&&` com grep); corrigido em f52b782. Regra adicionada ao AGENTS.md.

## CP-3 — Front-end + E2E (2026-11-05, c37341e)
SPA React com os fluxos do MVP; referências de UX no Mobbin registradas. O E2E revelou que `php artisan serve` não repassava DB_* do container; substituído por `php -S` + server.php. Cliente HTTP passou a rejeitar respostas não-JSON.

## CP-4 — Documentação e MVP pronto para demonstração interna (2026-11-05T21:09:55-03:00, f817a64)
Matriz gerada (32 TESTED, 13 IN_PROGRESS, 3 IMPLEMENTED, 27 BLOCKED, 10 NOT_STARTED, 0 ACCEPTED). Pipeline completo verde: 60 backend, 13 Vitest, 12 E2E. Próximo passo: RF-014 documentos.

## CP-5 — Gestão segura de documentos RF-014 (2026-11-06T00:01:16-03:00, 662dc1f, branch feat/documentos-rf014)
Estado inicial conferido: HEAD f0ec846 = CP-4, árvore limpa, sem remoto. Módulo de documentos com disco privado, lista de permissão com verificação de conteúdo, download só como anexo com checksum, 404 fora do escopo, triggers de imutabilidade, telas e E2E.
A revisão automática de segurança achou 3 fragilidades na validação de PDF (escape #xx, /ObjStm, regex falhando aberta), todas corrigidas com teste. Os testes revelaram um defeito latente: a cadeia de auditoria recomeçava em silêncio sem o ponteiro; agora falha fechada.
Limitação registrada: reescrita total da cadeia por DBA não é detectada localmente (proposta PE-07).
Incidente de ambiente: queda e remontagem do disco FUSE e baixa memória causaram falhas transitórias de Vitest/E2E; reexecutado em ambiente estável. O E2E passou a servir o build de produção.
Resultado: 89 backend, 20 Vitest, 14 E2E, todos verdes. RF-014 TESTED (não ACCEPTED).

## CP-5 integrado ao main (2026-11-06T00:20:56-03:00)
- Pré-merge: HEAD dae20ae conferido, árvore limpa, SRS SHA-256 inalterado, sem segredos versionados, main sem divergência.
- Merge `--no-ff` → `ea2b0bc`. **A suíte sobre o main falhou** (1/89): deadlock em 8 entradas simultâneas no mesmo lote esgotou 3 tentativas e recusou uma entrada legítima (sem perda de integridade). Defeito pré-existente e intermitente, não introduzido pelo CP-5.
- Correção em `fix/deadlock-entrada-concorrente` (linhas de lote/saldo criadas antes da transação; 5 tentativas; regressão 3×10 entradas). Concorrência verde em 3 execuções seguidas.
- Merge da correção → **main `d81745d`**: 90 backend (651 asserções), 20 Vitest, build, 14 E2E + 3 skips. Branches `feat/documentos-rf014` e `fix/deadlock-entrada-concorrente` preservados.

## CP-6 — Administração, painel e endurecimento (2026-11-06T01:41:36-03:00, e3f2789, branch feat/checkpoint-6-admin-dashboard-hardening)
RF-029 e RF-024 TESTED. Três defeitos de segurança/disponibilidade encontrados e corrigidos com teste: bypass de limite por IP via Host falsificado (provado vermelho antes da correção), rota irmã de catálogo sem a permissão global, deadlock em entradas concorrentes. CSP estrita validada com sonda negativa; runtime MySQL sem DDL validado com E2E completo; 0 vulnerabilidades de dependências.
Protótipos: Ghostscript se mostrou **ineficaz** como sanitizador (preserva JS); qpdf + varredura eficaz; âncora Ed25519 detecta reescrita total da cadeia.
Navegadores: Chromium, Firefox, WebKit. Axe desativado só no Firefox 159 após investigação (crash da ferramenta, não da aplicação).
Falha de processo registrada: `scripts/test.sh` terminou com exit 127 numa execução (caminho relativo da auditoria); corrigido e reexecutado com exit 0.

## CP-6 integrado ao main e auditoria de publicação (2026-11-06T02:00:22-03:00)
- Revisão pré-merge: sem dependências novas; única permissão nova `referencias.gerir` (ADMIN global); portão com 2 operações habilitadas (ENTRADA, ESTORNO); nenhum teste removido (3 mudanças só de assinatura); regressões de segurança presentes.
- Merge `--no-ff` → **main `b3c0f81`**. Sobre main: `scripts/test.sh` exit 0 (105 backend, 24 Vitest, build, 16 E2E + 3 skips, 0 vulnerabilidades); `scripts/verificar-privilegios.sh` exit 0 (10/10 negações, E2E com runtime sem DDL 14 passed).
- GitHub `prbtechal/SIGARB`: **privado**, **vazio** (sem branches/tags), sem rulesets disponíveis no plano; acesso: prbtechal (admin), joseook (write).
- Auditoria do histórico publicável (29 commits, 255 caminhos): DOCX do SRS nunca versionado; 0 ocorrências dos 6 segredos reais locais; 0 chaves privadas/APP_KEY/tokens; sem dumps, logs, builds, uploads. Aviso: IPs institucionais 192.168.200.8/.9 constam da documentação — aceitável apenas por o repositório ser privado.
- **Push NÃO executado:** bloqueado pela política de permissões da sessão do agente. Remoto não configurado localmente. Aguarda execução/autorização do usuário.

## CP-7 — Observabilidade e recuperação (2026-11-06T02:45:05-03:00, c41d091, branch feat/checkpoint-7-observabilidade-recuperacao)
Logs JSON correlacionados, liveness/readiness, backup/restauração local cifrados com 11 casos, carga local com k6 (0% erro, integridade preservada), verificações de acessibilidade sem axe nos três motores. Firefox: axe.run derruba o navegador com qualquer injeção — compensado. Primeira rodada de carga (83% de erro) era defeito do script e foi descartada. Push ao GitHub continua pendente (bloqueado pela política de permissões do agente); auditoria de publicação aprovada.

## CP-7 integrado ao main e publicado (2026-11-06)
- Merge local do CP-7 em main: `ff0eb04`. Push normal (sem force) `2254704..ff0eb04` para `origin/main` (repositório privado). Somente main publicado.

## CP-8 — Runtime production-like e capacidade (2026-11-06, branch feat/checkpoint-8-runtime-capacity-validation)
- Ambiente local Apache 2.4 event + PHP-FPM 8.5 (OPcache) + MySQL 8.4, usuários de banco separados (app/migração), dados sintéticos (`fc5dfd2`).
- **Incidente de segurança (local):** uma execução `bash -x scripts/prodlocal.sh` imprimiu na sessão do agente segredos de DEV (valores do `.env` do prodlocal e senha de demonstração). Mitigação imediata: ambiente prodlocal destruído e `.env` regenerado; `SIGARB_DEMO_PASSWORD`, `SIGARB_DB_APP_PASSWORD` e `SIGARB_DB_MIGR_PASSWORD` rotacionados sem impressão; banco de demonstração recriado. Nenhum segredo foi versionado nem publicado. Regra: **nunca** usar `bash -x` em scripts que carregam `.env`.
- Investigação de gargalo (evidência em `docs/operacao/CAPACIDADE.md`): causa raiz `enablereuse=on` (504 a 35 s; 1,21%/1,92%/5,61% de falhas a 10/25/50 VUs — relato inicial de "0%" corrigido); rajada de login sem rampa no harness; espera dupla de lock em `INSERT IGNORE` de saldo. Corrigidos. 50 VUs: 0% de erro em 2 repetições, integridade íntegra. 100 VUs não executado (host compartilhado no limite).
- Defeitos corrigidos: sondas de saúde retornavam 500 com banco fora (dependiam de sessão/cache em banco); harness de carga deixava coletor órfão e apagava evidência; auditoria de dependências relatava falha de rede como "vulnerabilidade".
- Validação final (2026-11-06): merge **NO-GO** — `composer audit` impossível (packagist.org inacessível da rede local). Testes 112/24/19/19 verdes, npm 0. Teste de concorrência em lote novo reforçado (1 linha de saldo por lote, sem atualização perdida). Pint: ~60 arquivos legados divergentes descobertos (não é gate). Documento de prontidão para homologação criado; nenhum acesso a VMs.
- Auditoria concluída (rede do host; DNS rotativo do packagist com IPs inalcançáveis): 0 advisories; pipeline exit 0 → merge GO.
- CP-8 integrado: merge `d3ea7dd`, pipeline exit 0 no main, push `ff0eb04..d3ea7dd`. Pint: branch `chore/php-pint-standardization` validado, não mesclado.
- Release/piloto (2026-11-06): pacote reprodutível + implantação versionada testada localmente 26/26; 4 defeitos de implantação corrigidos; piloto na VM NO-GO (infra). Branch feat/release-piloto não mesclado.
- CP-9 (2026-11-06): interface institucional (referência Sistema-de-escalas @849b07c) e Central de Suporte com auditoria integrada; branch feat/interface-institucional-central-suporte, não mesclado.
