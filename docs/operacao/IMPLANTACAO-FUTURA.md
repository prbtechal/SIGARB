# Implantação futura — NÃO AUTORIZADA

**Status: o MVP não foi implantado e não pode ser implantado sob a diretiva atual.** Nenhum acesso às VMs 192.168.200.8 (aplicação) e 192.168.200.9 (banco) foi feito pelo projeto SIGARB.

## Variância (ADR-008)
O SRS prevê nuvem com HTTPS (§1.5). Há proposta de usar as VMs de intranet que já hospedam outros sistemas em produção. A aprovação existente para esses sistemas **não cobre o SIGARB**. Requer decisão explícita (P-01): isolamento, capacidade, HTTPS, responsáveis.

## Checklist mínimo antes de qualquer implantação
- Aprovação institucional formal + revisão de segurança + regras operacionais aprovadas.
- Servidor web real (Apache/Nginx + PHP-FPM com OPcache), **não** `php -S`.
- HTTPS + HSTS; `SESSION_SECURE_COOKIE=true`; `APP_DEBUG=false`; `APP_ENV=production` (bloqueia o seeder de demonstração).
- `SIGARB_TRUSTED_PROXIES` com o IP exato do proxy reverso (nunca `*`) — ADR-014.
- Cabeçalhos de segurança da SPA iguais a `frontend/csp.ts` + HSTS e `upgrade-insecure-requests` — ADR-012.
- Usuários MySQL conforme `infra/mysql/privilegios.sql.tpl`; runtime com `sigarb_app`, migração com `sigarb_migr` em janela autorizada — ADR-013.
- `scripts/auditar-dependencias.sh` sem vulnerabilidades de produção antes de cada liberação.
- Banco e documentos com backup criptografado e restauração testada; RPO/RTO aprovados (P-10, RNF-15).
- Migrações revisadas; plano de rollback; teste de carga representativo em ambiente isolado.
- Criação de contas reais apenas após política de senha/MFA (P-11).

## Lições do ambiente production-like local (CP-8) — obrigatórias para homologação
Evidência completa: `docs/operacao/CAPACIDADE.md`.
- **Não usar `enablereuse=on`** no `ProxyPass`/`ProxyPassMatch` para o PHP-FPM sem dimensionar o pool: com reuso, threads do Apache prendem filhos do FPM ociosos e conexões novas recebem 504 após o `ProxyTimeout` (medido: 1,2–5,6% de falhas).
- **Sondas de saúde não podem depender do banco** fora da própria verificação: `routes/saude.php` fica fora do grupo `web`; limite de taxa e cache das sondas usam store local (`SIGARB_SAUDE_CACHE`, padrão `file`). Orquestrador: liveness → reinício; readiness → retirar do balanceamento.
- **Binlog ligado exige `log_bin_trust_function_creators=1`** (ou criação dos triggers por conta com SUPER) para as migrações com triggers de imutabilidade.
- **Durabilidade**: manter `innodb_flush_log_at_trx_commit=1` e `sync_binlog=1`; não reduzir para ganhar desempenho.
- **Dimensionamento de PHP-FPM**: o gargalo medido é CPU (bcrypt no login e renderização JSON). `pm.max_children` deve seguir CPU e memória disponíveis — mais filhos sem CPU só aumenta a fila.
- **Escritas de estoque**: o teto é a serialização global da cadeia de auditoria (ADR-007); qualquer mudança (ex.: cadeias por unidade) é decisão institucional.
- **Logins em massa** (início de expediente) concentram CPU: considerar no dimensionamento; não reduzir o custo do bcrypt.
