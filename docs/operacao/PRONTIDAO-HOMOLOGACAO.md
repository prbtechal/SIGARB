# Prontidão para homologação — requisitos de runtime (CP-8)

> **Situação: NÃO PRONTO para implantar.** A instituição autorizou hospedar o SIGARB nas VMs .8 (aplicação) e .9 (banco),
> mas a implantação exige **aprovação de execução separada** e infraestrutura verificada. Este documento foi escrito **sem
> acessar** as VMs; nada aqui descreve o estado real delas. Nenhuma migração de dados reais foi feita.
> Evidência de desempenho: `docs/operacao/CAPACIDADE.md` (local, sintética). **Sem afirmação de 1.000 ou 5.000 usuários.**

## 1. Runtime da aplicação (VM .8)
- PHP **8.5** com `pdo_mysql`, `pcntl`, OPcache ligado (`validate_timestamps=0` em produção; recarregar o FPM a cada deploy), `memory_limit` 192M. Referência: `infra/prodlocal/php/`.
- Laravel 13; `composer install --no-dev --optimize-autoloader`; `php artisan config:cache route:cache`.
- PHP-FPM `pm=static`; **`pm.max_children` dimensionado pela CPU**, não pela concorrência desejada. Medido: 12 filhos em 2 CPU saturam a CPU (~47 req/s mistos). Ponto de partida: 4–6 filhos por núcleo, ajustado por medição na própria VM. `request_terminate_timeout` ≥ `ProxyTimeout`.
- Usuário de sistema dedicado; código somente leitura para o FPM; gravação apenas em `storage/` e no diretório de documentos.

## 2. Apache (VM .8)
- MPM event, `mod_proxy_fcgi`, **sem `enablereuse=on`** (causa raiz dos 504; ver CAPACIDADE.md). Só o front controller é executável: `ProxyPassMatch "^/api/" "fcgi://…/public/index.php"`.
- SPA estática com fallback, CSP idêntica a `frontend/csp.ts`, `X-Content-Type-Options`, `Referrer-Policy`, `Permissions-Policy`, COOP.
- Status do FPM apenas em interface interna. Referência: `infra/prodlocal/apache/sigarb.conf`.
- `TrustedProxies` com lista explícita dos proxies institucionais (`config/trustedproxy.php`) — nunca `*`.

## 3. TLS e DNS (pendente — institucional)
- Nome DNS institucional, certificado de AC confiável, TLS 1.2+ (preferir 1.3), **HSTS**, redirecionamento 80→443.
- Cookies `Secure`, `HttpOnly`, `SameSite=Lax`; `SESSION_SECURE_COOKIE=true`; `APP_URL` em https.

## 4. MySQL (VM .9)
- MySQL **8.4**, `innodb_flush_log_at_trx_commit=1`, `sync_binlog=1` (**não reduzir**), binlog ligado → `log_bin_trust_function_creators=1` (ou triggers criados por conta privilegiada), slow log ligado.
- Banco **exclusivo** do SIGARB (sem compartilhar schema/usuários com outros sistemas da VM).
- Contas separadas: **runtime** (`sigarb_app`: DML; DELETE só nas 7 tabelas permitidas; sem DDL) e **migração** (`sigarb_migr`, usada só no deploy). Script de referência: `scripts/verificar-privilegios.sh`.
- Acesso de rede só a partir da VM .8 (firewall), TLS entre aplicação e banco se a rede não for isolada.
- Medido: ≤ 15 conexões a 50 VUs com 12 filhos FPM → `max_connections` com folga (≥ 2× filhos FPM + administração).

## 5. Capacidade (o que a evidência permite dizer)
| Medido (local, 2 CPU app, 1 CPU banco) | Valor |
|---|---|
| 50 VUs mistos, 0% erro, 2 repetições | ~47 req/s, p95 ≈ 1,6 s |
| Escrita, mesmo lote / lotes independentes (20 VUs) | 27 / 24 req/s (teto: cadeia de auditoria global) |
| Rajada de 20 logins simultâneos | p50 2,6 s (bcrypt, CPU) |
Não é extrapolável. Metas (P-10) indefinidas. Requisito: teste de carga **na VM de homologação**, com dados sintéticos e janela aprovada.

## 6. Documentos privados
- Diretório fora do docroot, dono do usuário do FPM, permissão 0700/0600, em volume com backup. `SIGARB_DOCUMENTOS_ROOT`.
- Readiness exige espaço livre ≥ `SIGARB_DISCO_MINIMO_BYTES` (provisório 512 MB, P-10).

## 7. Backup e recuperação
- `scripts/backup-local.sh` / `restaurar-local.sh` são **referência local**; para homologação: banco + documentos no mesmo ponto de consistência, GnuPG AES-256, chave fora das VMs e fora do Git, cópia fora do site.
- Restauração sempre em banco descartável com verificação (contagens, triggers ativos, cadeia de auditoria, checksums de documentos) — 19 casos automatizados locais.
- RPO/RTO: indefinidos (P-10).

## 8. Saúde e logs
- `/api/v1/health/live` (processo) e `/api/v1/health/ready` (banco, armazenamento, espaço) — independentes de sessão; banco fora → 200/503.
- Logs JSON (`sigarb_json`) com `request_id`; rotação diária; envio ao coletor institucional (indefinido). Sem dados pessoais/segredos nos logs.

## 9. Rollback
- Deploy por release versionada (diretório por SHA + symlink). Rollback = voltar o symlink + recarregar FPM.
- Migrações: backup cifrado **verificado** imediatamente antes; migrações destrutivas proibidas sem plano de reversão aprovado. Banco de auditoria é append-only: rollback de dados = restauração, nunca DELETE.

## 10. Bloqueios para homologação (todos abertos)
- **Gate do merge do CP-8:** `composer audit` não executável desta estação (`packagist.org` inacessível).
- Aprovação de execução da implantação; inspeção das VMs .8/.9 (feita pelo agente de infraestrutura, não por este projeto).
- TLS/DNS, firewall, contas de serviço, coletor de logs, destino de backup.
- P-01, P-02 (hierarquia real), P-03/P-05/P-06 (fluxos de negócio **bloqueados**: entrega, devolução, transferência, baixa, aprovação — desabilitados em `config/sigarb.php`), P-08, P-09, P-10, P-11.
- Aceite institucional: nenhum requisito ACCEPTED. Leitor de tela pendente.
