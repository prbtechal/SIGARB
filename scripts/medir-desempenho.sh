#!/usr/bin/env bash
# Medição BÁSICA e local (não é teste de carga). Requer a API de E2E ou dev rodando. Nunca apontar para infraestrutura institucional.
set -euo pipefail
BASE="${1:-http://127.0.0.1:18482}"; N="${N:-200}"; C="${C:-10}"
RAIZ="$(cd "$(dirname "$0")/.." && pwd)"; set -a; . "$RAIZ/infra/.env"; set +a
case "$BASE" in http://127.0.0.1:*|http://localhost:*) ;; *) echo "Somente localhost."; exit 1;; esac
J=$(mktemp); trap 'rm -f $J' EXIT
curl -s -c $J -b $J "$BASE/api/v1/csrf" >/dev/null
T=$(awk '/XSRF-TOKEN/{print $7}' $J | python3 -c 'import sys,urllib.parse;print(urllib.parse.unquote(sys.stdin.read().strip()))')
curl -s -c $J -b $J -H "X-XSRF-TOKEN: $T" -H 'Accept: application/json' -H 'Content-Type: application/json' \
  -d "{\"login\":\"auditor.demo\",\"password\":\"$SIGARB_DEMO_PASSWORD\"}" "$BASE/api/v1/auth/login" -o /dev/null -w "login %{http_code}\n"
for rota in /api/v1/me /api/v1/stock/balances /api/v1/movements /api/v1/militares; do
  seq "$N" | xargs -P "$C" -I{} curl -s -o /dev/null -b $J -H 'Accept: application/json' -w "%{http_code} %{time_total}\n" "$BASE$rota" \
  | python3 -c "
import sys
r=[l.split() for l in sys.stdin]; t=sorted(float(x[1])*1000 for x in r); ok=sum(1 for x in r if x[0]=='200')
p=lambda q:t[min(len(t)-1,int(q*len(t)))]
print(f'$rota  n={len(t)} conc=$C ok={ok}  p50={p(.5):.0f}ms p95={p(.95):.0f}ms max={t[-1]:.0f}ms')"
done
