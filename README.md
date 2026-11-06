# SIGARB — Sistema Integrado de Gestão de Armamentos e Reserva Bélica

MVP **local de demonstração** (dados fictícios, sem validade institucional), baseado no SRS v1.0 (rascunho de 2026-10-27).

- Back-end: Laravel 13 / PHP 8.5 / MySQL 8.4 — API `/api/v1` com sessão por cookie e CSRF.
- Front-end: React 19 + Vite + TypeScript.
- Implementado: autenticação e sessões, papéis com escopo organizacional, organização/locais, militares (separados de usuários), catálogo individual × lote, saldos, **entrada e estorno** transacionais e idempotentes, auditoria append-only com hash encadeado.
- Bloqueado até decisão institucional: saída, entrega, devolução, transferência, reserva, baixa, manutenção, inventário, acautelamento, aprovação, carteira, relatórios.

```bash
cp infra/.env.example infra/.env   # edite as senhas locais
scripts/dev.sh                      # http://127.0.0.1:18481
scripts/test.sh                     # todas as suítes
```

Comece por `AGENTS.md` e `docs/desenvolvimento/CHECKPOINT.md`. **Não está aprovado para produção** (ver `docs/operacao/IMPLANTACAO-FUTURA.md`).
