# Desenvolvimento local (sem Docker)

Decisão do responsável (2026-11-19, ADR-016): os sistemas PRB **não usam Docker** — nem em desenvolvimento, testes
ou empacotamento. O isolamento em produção é das VMs (usuário de sistema, pool PHP-FPM e usuário MySQL próprios);
na estação, é por nome de banco/usuário e porta. **Não crie Dockerfile, Compose ou contêiner para o SIGARB.**

Requisitos no host: PHP **8.5** (cli; `pdo_mysql`, `mbstring`, `xml`, `curl`, `zip`, `bcmath`, `intl`, `gd`, `pcntl`),
Composer 2, Node 22+ (24 recomendado), MySQL 8 local (127.0.0.1:3306). Com binlog ligado, o MySQL precisa de
`log_bin_trust_function_creators=ON` (triggers de imutabilidade — mesmo requisito da produção).

```bash
cp backend/.env.example backend/.env && chmod 600 backend/.env
#   defina DB_PASSWORD e SIGARB_DEMO_PASSWORD (>=8 caracteres); opcional: SIGARB_DB_APP_PASSWORD e SIGARB_DB_MIGR_PASSWORD
database/dev/preparar.sh               # bancos sigarb/sigarb_test/sigarb_e2e + usuário sigarb@localhost (idempotente)
(cd backend && composer install && php artisan key:generate)
scripts/dev.sh                         # API :18480 (php -S) + front-end :18481 (Vite)
scripts/reset-demo.sh                  # APAGA e recria o banco local com dados fictícios
scripts/test.sh                        # Pint + PHPUnit + front-end + E2E + backup + auditoria de dependências
```

`database/dev/preparar.sh` usa o cliente `mysql` com acesso administrativo local (padrão: o usuário do sistema via
`auth_socket`; ou `MYSQL_ADMIN="mysql -uroot -p"`). Ele só cria/atualiza recursos do SIGARB:

| Recurso | Uso |
|---|---|
| `sigarb` | banco de demonstração (`scripts/dev.sh`) |
| `sigarb_test` | PHPUnit (`phpunit.xml`) |
| `sigarb_e2e` | Playwright, backup/restauração, privilégios (recriado a cada `scripts/e2e.sh`) |
| `sigarb`@`localhost` | usuário de desenvolvimento, ALL nos 3 bancos acima (senha = `DB_PASSWORD`) |
| `sigarb_app`/`sigarb_migr`@`localhost` | identidades do ADR-013 no `sigarb_e2e` (`database/dev/privilegios.sql.tpl`, mesmos grants da produção) |
| `ecossistema` | banco compartilhado do PRB: **só é criado se não existir** (`deploy/modelos/ecossistema.sql`); `sigarb` recebe SELECT + INSERT em `versoes` |

Nenhum outro banco da instância (`suporte*`, `rs_*`, `vs_*`…) é tocado.

Segredos locais só em `backend/.env` (ignorado pelo Git): `DB_PASSWORD`, `SIGARB_DEMO_PASSWORD`,
`SIGARB_DB_APP_PASSWORD`, `SIGARB_DB_MIGR_PASSWORD`, `SIGARB_TRUSTED_PROXIES` (opcional). Chave de backup local:
`~/.config/sigarb-dev/backup.chave` (fora do repositório).

Portas (só 127.0.0.1): API dev :18480, Vite :18481, API E2E :18482, `vite preview` do E2E :18483.
A API usa `php -S` com o `server.php` do Laravel (variáveis passadas pelo script, ex. `DB_DATABASE=sigarb_e2e`,
prevalecem sobre o `backend/.env`); limites de upload iguais aos da produção (11M/12M, 1 arquivo).

Usuários de demonstração (senha = `SIGARB_DEMO_PASSWORD` do seu `backend/.env`; identidades fictícias):
`admin.demo` (ADMIN global) · `gestor.alfa` (GESTOR UN-ALFA) · `operador.alfa1` (OPERADOR SU-ALFA-1) · `gestor.bravo` (GESTOR UN-BRAVO) · `auditor.demo` (AUDITOR global) · `aprovador.demo` (APROVADOR, sem ações).

HD externo/FUSE: `composer install` e o Vitest podem estourar tempo ou não subir workers. Rode numa cópia em disco local
(com `vendor/` e `node_modules/` próprios) quando isso acontecer.

## Scripts adicionais
| Script | Uso |
|---|---|
| `scripts/e2e-navegadores.sh` | E2E em Chromium + Firefox e depois WebKit, todos no host. WebKit exige as bibliotecas de sistema: `cd frontend && sudo npx playwright install-deps webkit` (uma vez) |
| `scripts/verificar-privilegios.sh` | Migra `sigarb_e2e` com `sigarb_migr`, testa negações de DDL/DELETE com `sigarb_app` e roda o E2E com o runtime sem DDL |
| `scripts/auditar-dependencias.sh` | `composer audit` e `npm audit` (produção × dev), somente leitura; sem rede → "AUDITORIA INCOMPLETA" (exit 2) |
| `scripts/testar-backup.sh` | Backup cifrado + restauração verificada em banco descartável `sigarb_restore_*` |
| `scripts/teste-carga.sh` | k6 **nativo** (binário no PATH) contra a API E2E; pesado, não rodar junto com outras suítes |
| `scripts/testar-implantacao.sh` | Teste do `deploy/ops` com duas releases. Exige **root numa VM/máquina descartável** (cria o usuário `sigarb` e `/srv/sigarb`, como o RUNBOOK); recusa rodar se eles já existirem |
| `prototipos/pe06/executar.sh` | Protótipo de inspeção de PDF (qpdf + ghostscript do host; sem rede via `unshare` quando disponível) |

Removidos com o Docker (2026-11-19): `infra/` (Compose dev, imagens, `prodlocal` Apache+FPM), `.dockerignore`,
`scripts/prodlocal.sh` e `scripts/carga-escalonada.sh`. As medições de capacidade do CP-8 (`docs/operacao/CAPACIDADE.md`)
continuam como evidência histórica; uma nova rodada production-like deve ser feita na VM de homologação (Apache + FPM reais).
