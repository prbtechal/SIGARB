#!/usr/bin/env bash
# Valida o modelo de privilégio mínimo (ADR-013) no banco ISOLADO sigarb_e2e:
#  1) migra com sigarb_migr; 2) testes negativos com sigarb_app; 3) roda o E2E completo com sigarb_app.
set -euo pipefail
RAIZ="$(cd "$(dirname "$0")/.." && pwd)"
cd "$RAIZ/infra"; set -a; . ./.env; set +a
: "${SIGARB_DB_APP_PASSWORD:?defina SIGARB_DB_APP_PASSWORD em infra/.env}"
: "${SIGARB_DB_MIGR_PASSWORD:?defina SIGARB_DB_MIGR_PASSWORD em infra/.env}"
BANCO=sigarb_e2e
sql_root() { docker exec -i sigarb-dev-mysql mysql -uroot -p"$SIGARB_DB_ROOT_PASSWORD" 2>/dev/null; }
sed -e "s/@BANCO@/$BANCO/g" -e "s/@SENHA_APP@/$SIGARB_DB_APP_PASSWORD/g" -e "s/@SENHA_MIGR@/$SIGARB_DB_MIGR_PASSWORD/g" "$RAIZ/infra/mysql/privilegios.sql.tpl" | sql_root
echo "== Privilégios efetivos de sigarb_app"; echo "SHOW GRANTS FOR 'sigarb_app'@'%';" | sql_root | sed 's/IDENTIFIED.*//'

echo "== Migração com sigarb_migr"
docker compose --profile e2e run --rm --no-deps -w /app -e DB_USERNAME=sigarb_migr -e DB_PASSWORD="$SIGARB_DB_MIGR_PASSWORD" api-e2e \
  sh -c 'php artisan migrate:fresh --seed --force -q && echo migracao-ok'

echo "== Testes negativos com sigarb_app (cada um DEVE falhar)"
neg=0
for q in "CREATE TABLE x_teste (id INT)" "ALTER TABLE usuarios ADD COLUMN x INT" "DROP TRIGGER trg_auditoria_no_update" \
         "DROP TABLE eventos_auditoria" "DELETE FROM movimentacoes" "DELETE FROM documentos" "TRUNCATE TABLE eventos_auditoria" \
         "GRANT SELECT ON $BANCO.* TO 'sigarb_app'@'%'" "UPDATE eventos_auditoria SET acao='x'" "SELECT * FROM mysql.user"; do
  if docker exec sigarb-dev-mysql mysql -usigarb_app -p"$SIGARB_DB_APP_PASSWORD" "$BANCO" -e "$q" >/dev/null 2>&1; then
    echo "  PERMITIDO (falha de segurança): $q"; neg=1
  else
    echo "  negado: $q"
  fi
done
[ $neg -eq 0 ] || { echo "Privilégio excessivo detectado."; exit 1; }

echo "== E2E completo com sigarb_app (prova que o runtime funciona só com DML)"
cd "$RAIZ"; SIGARB_E2E_DB_USER=sigarb_app SIGARB_E2E_DB_PASSWORD="$SIGARB_DB_APP_PASSWORD" SIGARB_E2E_SEM_MIGRAR=1 scripts/e2e.sh --project=desktop
