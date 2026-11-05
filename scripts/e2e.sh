#!/usr/bin/env bash
# Executa a suíte E2E contra o Laravel real, em banco isolado (sigarb_e2e) com dados sintéticos.
set -euo pipefail
RAIZ="$(cd "$(dirname "$0")/.." && pwd)"
cd "$RAIZ/infra"
[ -f .env ] || { echo "Crie infra/.env a partir de infra/.env.example"; exit 1; }
set -a; . ./.env; set +a
docker compose up -d mysql >/dev/null
docker compose --profile e2e run --rm --no-deps -w /app api-e2e sh -c 'php artisan migrate:fresh --seed --force -q'
docker compose --profile e2e up -d --force-recreate api-e2e >/dev/null
trap 'docker compose -f "$RAIZ/infra/compose.yaml" --profile e2e stop api-e2e >/dev/null' EXIT
for i in $(seq 1 30); do curl -sf http://127.0.0.1:18482/up >/dev/null && break; sleep 1; done
cd "$RAIZ/frontend"
SIGARB_API_URL=http://127.0.0.1:18482 SIGARB_DEMO_PASSWORD="$SIGARB_DEMO_PASSWORD" npx playwright test "$@"
