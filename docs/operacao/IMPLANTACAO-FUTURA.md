# Implantação futura — NÃO AUTORIZADA

**Status: o MVP não foi implantado e não pode ser implantado sob a diretiva atual.** Nenhum acesso às VMs 192.168.200.8 (aplicação) e 192.168.200.9 (banco) foi feito pelo projeto SIGARB.

## Variância (ADR-008)
O SRS prevê nuvem com HTTPS (§1.5). Há proposta de usar as VMs de intranet que já hospedam outros sistemas em produção. A aprovação existente para esses sistemas **não cobre o SIGARB**. Requer decisão explícita (P-01): isolamento, capacidade, HTTPS, responsáveis.

## Checklist mínimo antes de qualquer implantação
- Aprovação institucional formal + revisão de segurança + regras operacionais aprovadas.
- Servidor web real (Apache/Nginx + PHP-FPM com OPcache), **não** `php -S`.
- HTTPS + HSTS; `SESSION_SECURE_COOKIE=true`; `APP_DEBUG=false`; `APP_ENV=production` (bloqueia o seeder de demonstração).
- `TrustProxies` configurado com o proxy real (os limitadores de login dependem do IP correto).
- Usuário MySQL de aplicação com privilégios mínimos; usuário separado para migrações (triggers).
- Banco e documentos com backup criptografado e restauração testada; RPO/RTO aprovados (P-10, RNF-15).
- Migrações revisadas; plano de rollback; teste de carga representativo em ambiente isolado.
- Criação de contas reais apenas após política de senha/MFA (P-11).
