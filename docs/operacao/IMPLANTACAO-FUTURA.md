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
