#!/usr/bin/env bash
# E2E em Chromium + Firefox (host) e WebKit (imagem oficial do Playwright, mesma versão).
# Um único banco E2E recriado no início; API isolada em :18482.
set -euo pipefail
RAIZ="$(cd "$(dirname "$0")/.." && pwd)"
VERSAO=$(node -p "require('$RAIZ/frontend/node_modules/@playwright/test/package.json').version")
SIGARB_E2E_FIREFOX=1 "$RAIZ/scripts/e2e.sh" "$@"
echo "== WebKit (container mcr.microsoft.com/playwright:v$VERSAO-noble)"
cd "$RAIZ/infra"; set -a; . ./.env; set +a
docker compose --profile e2e up -d api-e2e >/dev/null
trap 'docker compose -f "$RAIZ/infra/compose.yaml" --profile e2e stop api-e2e >/dev/null' EXIT
for i in $(seq 1 30); do curl -sf http://127.0.0.1:18482/up >/dev/null && break; sleep 1; done
docker run --rm --network host --user "$(id -u):$(id -g)" -e HOME=/tmp -e CI=1 \
  -e SIGARB_E2E_WEBKIT=1 -e SIGARB_API_URL=http://127.0.0.1:18482 -e SIGARB_DEMO_PASSWORD="$SIGARB_DEMO_PASSWORD" \
  -v "$RAIZ/frontend:/w" -w /w "mcr.microsoft.com/playwright:v$VERSAO-noble" npx playwright test "$@"
