#!/usr/bin/env bash
# Executa a suíte E2E contra o Laravel real, em banco isolado (sigarb_e2e) com dados sintéticos. Sem Docker:
# API isolada no servidor embutido do PHP em :18482 (MySQL local), navegadores do Playwright no host.
set -euo pipefail
RAIZ="$(cd "$(dirname "$0")/.." && pwd)"
. "$RAIZ/scripts/ambiente.sh"
carregar_ambiente
[ -n "$SIGARB_DEMO_PASSWORD" ] || { echo "ERRO: defina SIGARB_DEMO_PASSWORD em backend/.env"; exit 1; }
# Mesmo ambiente do antigo serviço api-e2e: banco próprio, cache em banco, sem debug e as regras provisórias ligadas
# só no E2E (desligadas por padrão no dev e na release).
E2E_ENV=(DB_HOST=127.0.0.1 DB_DATABASE=sigarb_e2e CACHE_STORE=database APP_DEBUG=false
  "SIGARB_OPERACOES_PROVISORIAS=${SIGARB_E2E_OPERACOES_PROVISORIAS-SAIDA,BAIXA,TRANSFERENCIA,RESERVA,MANUTENCAO,ENTREGA,DEVOLUCAO,ACAUTELAMENTO,INSPECAO,DESACAUTELAMENTO,INVENTARIO}")
if [ -z "${SIGARB_E2E_SEM_MIGRAR:-}" ]; then
  (cd "$RAIZ/backend" && env "${E2E_ENV[@]}" php artisan migrate:fresh --seed --force -q)
fi
# Identidade de runtime opcional (ADR-013): por padrão usa o usuário de desenvolvimento.
USUARIO="${SIGARB_E2E_DB_USER:-sigarb}"; SENHA="${SIGARB_E2E_DB_PASSWORD:-$SIGARB_DB_PASSWORD}"
PID="$RAIZ/backend/storage/logs/.api-18482.pid"; parar_api "$PID"
trap 'parar_api "$PID"' EXIT
subir_api 18482 "$PID" "${E2E_ENV[@]}" DB_USERNAME="$USUARIO" DB_PASSWORD="$SENHA"
cd "$RAIZ/frontend"
SIGARB_API_URL=http://127.0.0.1:18482 npx playwright test "$@"
