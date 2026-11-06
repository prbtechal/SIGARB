#!/usr/bin/env bash
# Auditoria de dependências (somente leitura: não instala nem atualiza nada).
# Separa produção de desenvolvimento. Saída resumida em stdout; JSON completo em $SAIDA.
set -uo pipefail
RAIZ="$(cd "$(dirname "$0")/.." && pwd)"
SAIDA="${SAIDA:-$RAIZ/.auditoria-dependencias}"; mkdir -p "$SAIDA"
falha=0
cd "$RAIZ/infra"
# Rede do HOST: a rede bridge do Docker desta estação não resolve DNS de forma confiável (CP-8). Mesma imagem,
# mesmo composer, mesma fonte (packagist.org) e TLS verificado; lockfile montado somente leitura.
cmp() { docker run --rm --network host -v "$RAIZ/backend:/app:ro" -w /app -e COMPOSER_HOME=/tmp/c sigarb-dev-php:local composer audit "$@"; }
echo "== Composer (produção: --no-dev)"
cmp --no-dev --locked --format=json > "$SAIDA/composer-prod.json" 2>/dev/null
c1=$?; cmp --no-dev --locked 2>&1 | tail -3
echo "== Composer (todas, inclui dev)"
cmp --locked --format=json > "$SAIDA/composer-todas.json" 2>/dev/null
cmp --locked 2>&1 | tail -3
cd "$RAIZ/frontend"
echo "== npm (produção: --omit=dev)"
npm audit --omit=dev --json > "$SAIDA/npm-prod.json" 2>/dev/null; n1=$?
npm audit --omit=dev 2>&1 | tail -3
echo "== npm (todas, inclui dev)"
npm audit --json > "$SAIDA/npm-todas.json" 2>/dev/null
npm audit 2>&1 | tail -3
# exit 1: vulnerabilidade em dependência de PRODUÇÃO. exit 2: auditoria INCOMPLETA (ex.: sem rede para o
# packagist/npm — composer retorna 100). Auditoria incompleta nunca é relatada como aprovada nem como vulnerável.
incompleta=0
[ $c1 -ge 100 ] && incompleta=1
[ $c1 -ne 0 ] && [ $c1 -lt 100 ] && falha=1
grep -q '"error"' "$SAIDA/npm-prod.json" 2>/dev/null && incompleta=1
[ $n1 -ne 0 ] && [ $incompleta -eq 0 ] && falha=1
if [ $falha -ne 0 ]; then echo "Resultado: VULNERABILIDADES EM PRODUÇÃO — ver JSON"; exit 1; fi
if [ $incompleta -ne 0 ]; then echo "Resultado: AUDITORIA INCOMPLETA (falha de rede/registro; c1=$c1) — repetir"; exit 2; fi
echo "Resultado: sem vulnerabilidades em produção"
exit 0
