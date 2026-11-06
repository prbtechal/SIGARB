#!/usr/bin/env bash
# Ambiente production-like LOCAL. Uso: scripts/prodlocal.sh subir|recriar|parar|destruir
set -euo pipefail
RAIZ="$(cd "$(dirname "$0")/.." && pwd)"; D="$RAIZ/infra/prodlocal"; cd "$D"
ENV="$D/.env"
if [ ! -f "$ENV" ]; then
  umask 077; g() { head -c 24 /dev/urandom | base64 | tr -dc A-Za-z0-9 | head -c 28; }
  { echo "SIGARB_PL_APP_KEY=base64:$(head -c 32 /dev/urandom | base64)"; echo "SIGARB_PL_DB_ROOT=$(g)"; echo "SIGARB_PL_DB_APP=App$(g)"; echo "SIGARB_PL_DB_MIGR=Mig$(g)"
    grep '^SIGARB_DEMO_PASSWORD=' "$RAIZ/infra/.env"; } > "$ENV"
fi
set -a; . "$ENV"; set +a
dc() { docker compose --env-file "$ENV" "$@"; }
case "${1:-subir}" in
  subir|recriar)
    [ "${1:-}" = recriar ] && dc down -v
    (cd "$RAIZ/frontend" && npm run build >/dev/null)
    dc build -q && dc up -d mysql
    for i in $(seq 1 60); do docker inspect -f '{{.State.Health.Status}}' sigarb-pl-mysql 2>/dev/null | grep -q healthy && break; sleep 2; done
    privilegios() { sed -e "s/@BANCO@/sigarb/g" -e "s/@SENHA_APP@/$SIGARB_PL_DB_APP/g" -e "s/@SENHA_MIGR@/$SIGARB_PL_DB_MIGR/g" "$RAIZ/infra/mysql/privilegios.sql.tpl" \
      | docker exec -i sigarb-pl-mysql mysql --force -uroot -p"$SIGARB_PL_DB_ROOT" >/dev/null 2>&1 || true; }
    privilegios   # usuários + privilégios de banco (GRANTs por tabela falham enquanto as tabelas não existem)
    dc --profile tarefas run --rm -T migrar sh -c 'php artisan migrate --force --seed -q' || { echo "ERRO: migração"; exit 1; }
    privilegios   # agora as tabelas existem: aplica os DELETE por tabela
    echo migrado
    dc up -d app apache
    for i in $(seq 1 30); do curl -sf http://127.0.0.1:18490/api/v1/health/ready >/dev/null && break; sleep 1; done
    curl -s http://127.0.0.1:18490/api/v1/health/ready; echo ;;
  parar) dc stop ;;
  destruir) dc down -v ;;
  *) echo "uso: $0 subir|recriar|parar|destruir"; exit 2 ;;
esac
