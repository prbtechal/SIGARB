#!/usr/bin/env bash
# E2E em Chromium + Firefox e, em seguida, WebKit — todos no host, sem Docker (decisão do responsável, 2026-11-19).
# Um único banco E2E recriado no início; API isolada em :18482.
# WebKit exige bibliotecas de sistema: instale UMA vez com `cd frontend && sudo npx playwright install-deps webkit`
# (e `npx playwright install webkit`). Sem elas, a etapa WebKit falha com a mensagem do Playwright sobre dependências.
set -euo pipefail
RAIZ="$(cd "$(dirname "$0")/.." && pwd)"
SIGARB_E2E_FIREFOX=1 "$RAIZ/scripts/e2e.sh" "$@"
echo "== WebKit (host)"
SIGARB_E2E_SEM_MIGRAR=1 SIGARB_E2E_WEBKIT=1 "$RAIZ/scripts/e2e.sh" "$@"
