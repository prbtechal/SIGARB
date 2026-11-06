# Desenvolvimento local

Requisitos: Docker + Compose, Node 24+. PHP no host **não** é necessário.

```bash
cp infra/.env.example infra/.env      # defina senhas locais e SIGARB_DEMO_PASSWORD (>=12 caracteres)
scripts/dev.sh                         # MySQL :18406, API :18480, front-end :18481
scripts/reset-demo.sh                  # APAGA e recria o banco local com dados fictícios
scripts/test.sh                        # backend + front-end + E2E
```

Isolamento: projeto Compose `sigarb-dev`, containers `sigarb-dev-*`/`sigarb-e2e-api`, volume `sigarb-dev-mysql-data`, portas 184xx somente em 127.0.0.1. Bancos: `sigarb` (demo), `sigarb_test` (PHPUnit), `sigarb_e2e` (Playwright).

Usuários de demonstração (senha = `SIGARB_DEMO_PASSWORD` do seu `infra/.env`; identidades fictícias):
`admin.demo` (ADMIN global) · `gestor.alfa` (GESTOR UN-ALFA) · `operador.alfa1` (OPERADOR SU-ALFA-1) · `gestor.bravo` (GESTOR UN-BRAVO) · `auditor.demo` (AUDITOR global) · `aprovador.demo` (APROVADOR, sem ações).

Observação: a API usa `php -S` com o `server.php` do Laravel, porque o `php artisan serve` não repassa as variáveis de ambiente do container.
