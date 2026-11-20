#!/usr/bin/env bash
# APAGA e recria o banco LOCAL de demonstração (sigarb) com dados sintéticos. Nunca usar fora do ambiente local.
set -euo pipefail
RAIZ="$(cd "$(dirname "$0")/.." && pwd)"
. "$RAIZ/scripts/ambiente.sh"; carregar_ambiente
[ "$(env_backend DB_DATABASE)" = sigarb ] && [[ "$(env_backend DB_HOST)" =~ ^(127\.0\.0\.1|localhost)$ ]] \
  || { echo "ERRO: backend/.env não aponta para o banco local sigarb (127.0.0.1)"; exit 2; }
cd "$RAIZ/backend"
php artisan migrate:fresh --seed --force && php artisan sigarb:auditoria:verificar
