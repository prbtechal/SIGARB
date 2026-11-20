#!/usr/bin/env bash
# Sobe o ambiente LOCAL de demonstração, sem Docker: API no servidor embutido do PHP (:18480, MySQL local, banco sigarb)
# e o front-end Vite (:18481). Pré-requisito único: database/dev/preparar.sh (bancos e usuário sigarb@localhost).
set -euo pipefail
RAIZ="$(cd "$(dirname "$0")/.." && pwd)"
. "$RAIZ/scripts/ambiente.sh"
[ -f "$RAIZ/backend/.env" ] || { cp "$RAIZ/backend/.env.example" "$RAIZ/backend/.env"; chmod 600 "$RAIZ/backend/.env"
  echo "backend/.env criado: defina DB_PASSWORD e SIGARB_DEMO_PASSWORD e rode database/dev/preparar.sh"; exit 1; }
carregar_ambiente
cd "$RAIZ/backend"
[ -d vendor ] || composer install --no-interaction
grep -q '^APP_KEY=base64' .env || php artisan key:generate --force
php artisan migrate --force
PID="$RAIZ/backend/storage/logs/.api-18480.pid"; parar_api "$PID"
subir_api "${SIGARB_API_PORT:-18480}" "$PID"
trap 'parar_api "$PID"' EXIT
cd "$RAIZ/frontend"; [ -d node_modules ] || npm ci
echo "API: http://127.0.0.1:${SIGARB_API_PORT:-18480}/up   Front-end: http://127.0.0.1:18481"
npx vite
