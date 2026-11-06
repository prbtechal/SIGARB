#!/usr/bin/env bash
# APAGA e recria o banco LOCAL de demonstração (sigarb) com dados sintéticos. Nunca usar fora do ambiente local.
set -euo pipefail
RAIZ="$(cd "$(dirname "$0")/.." && pwd)"
cd "$RAIZ/infra"
docker compose run --rm --no-deps -w /app api sh -c 'php artisan migrate:fresh --seed --force && php artisan sigarb:auditoria:verificar'
