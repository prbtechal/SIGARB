#!/usr/bin/env bash
# Suíte completa: backend (PHPUnit, banco sigarb_test), front-end (typecheck, Vitest, build) e E2E (banco sigarb_e2e).
set -euo pipefail
RAIZ="$(cd "$(dirname "$0")/.." && pwd)"
cd "$RAIZ/infra"; docker compose up -d mysql >/dev/null
docker compose run --rm --no-deps -w /app api php artisan test
cd "$RAIZ/frontend"; npx tsc -b; npx vitest run; npm run build
"$RAIZ/scripts/e2e.sh"
