#!/usr/bin/env bash
# Sobe o ambiente LOCAL de demonstração: MySQL + API (:18480) e o front-end Vite (:18481).
set -euo pipefail
RAIZ="$(cd "$(dirname "$0")/.." && pwd)"
cd "$RAIZ/infra"
[ -f .env ] || { echo "Crie infra/.env a partir de infra/.env.example (defina senhas e SIGARB_DEMO_PASSWORD)."; exit 1; }
docker compose up -d --build mysql api
[ -f "$RAIZ/backend/.env" ] || cp "$RAIZ/backend/.env.example" "$RAIZ/backend/.env"
[ -d "$RAIZ/backend/vendor" ] || docker compose run --rm --no-deps -w /app api composer install --no-interaction
grep -q '^APP_KEY=base64' "$RAIZ/backend/.env" || docker compose run --rm --no-deps -w /app api php artisan key:generate --force
docker compose run --rm --no-deps -w /app api php artisan migrate --force
cd "$RAIZ/frontend"; [ -d node_modules ] || npm ci
echo "API: http://127.0.0.1:18480/up   Front-end: http://127.0.0.1:18481"
exec npx vite
