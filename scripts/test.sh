#!/usr/bin/env bash
# Suíte completa, sem Docker: backend (Pint + PHPUnit, banco sigarb_test), front-end (typecheck, Vitest, build),
# E2E (banco sigarb_e2e), backup/restauração e auditoria de dependências. Pré-requisito: database/dev/preparar.sh.
set -euo pipefail
RAIZ="$(cd "$(dirname "$0")/.." && pwd)"
cd "$RAIZ/backend"
echo "== Formatação PHP (Pint)"; vendor/bin/pint --test
php artisan test
cd "$RAIZ/frontend"; npx tsc -b; npx vitest run; npm run build
"$RAIZ/scripts/e2e.sh"
"$RAIZ/scripts/testar-backup.sh"
"$RAIZ/scripts/auditar-dependencias.sh"
