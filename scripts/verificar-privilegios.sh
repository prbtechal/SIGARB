#!/usr/bin/env bash
# Valida o modelo de privilégio mínimo (ADR-013) no banco ISOLADO sigarb_e2e do MySQL LOCAL (sem Docker):
#  1) migra com sigarb_migr; 2) testes negativos com sigarb_app; 3) roda o E2E completo com sigarb_app.
set -euo pipefail
RAIZ="$(cd "$(dirname "$0")/.." && pwd)"
. "$RAIZ/scripts/ambiente.sh"; carregar_ambiente
: "${SIGARB_DB_APP_PASSWORD:?defina SIGARB_DB_APP_PASSWORD em backend/.env}"
: "${SIGARB_DB_MIGR_PASSWORD:?defina SIGARB_DB_MIGR_PASSWORD em backend/.env}"
BANCO=sigarb_e2e
sql_root() { ${MYSQL_ADMIN:-mysql} 2>/dev/null; }
esc() { printf '%s' "${1//\'/\'\'}" | sed 's/[&/\]/\\&/g'; }
aplicar() { sed -e "s/@BANCO@/$BANCO/g" -e "s/@SENHA_APP@/$(esc "$SIGARB_DB_APP_PASSWORD")/g" -e "s/@SENHA_MIGR@/$(esc "$SIGARB_DB_MIGR_PASSWORD")/g" \
  "$RAIZ/database/dev/privilegios.sql.tpl" | ${MYSQL_ADMIN:-mysql} --force >/dev/null 2>&1 || true; }
aplicar   # usuários + privilégios de banco (os GRANT por tabela só valem quando as tabelas existem)

echo "== Migração com sigarb_migr"
(cd "$RAIZ/backend" && DB_DATABASE=$BANCO DB_USERNAME=sigarb_migr DB_PASSWORD="$SIGARB_DB_MIGR_PASSWORD" \
  php artisan migrate:fresh --seed --force -q && echo migracao-ok)
aplicar   # tabelas criadas: aplica os DELETE por tabela
echo "== Privilégios efetivos de sigarb_app"; echo "SHOW GRANTS FOR 'sigarb_app'@'localhost';" | sql_root | sed 's/IDENTIFIED.*//'

echo "== Testes negativos com sigarb_app (cada um DEVE falhar)"
neg=0
for q in "CREATE TABLE x_teste (id INT)" "ALTER TABLE usuarios ADD COLUMN x INT" "DROP TRIGGER trg_auditoria_no_update" \
         "DROP TABLE eventos_auditoria" "DELETE FROM movimentacoes" "DELETE FROM documentos" "TRUNCATE TABLE eventos_auditoria" \
         "GRANT SELECT ON $BANCO.* TO 'sigarb_app'@'localhost'" "UPDATE eventos_auditoria SET acao='x'" "SELECT * FROM mysql.user"; do
  if MYSQL_PWD="$SIGARB_DB_APP_PASSWORD" mysql -h 127.0.0.1 -usigarb_app "$BANCO" -e "$q" >/dev/null 2>&1; then
    echo "  PERMITIDO (falha de segurança): $q"; neg=1
  else
    echo "  negado: $q"
  fi
done
# Controle: a mesma conexão consegue o que o runtime precisa (senão os "negados" acima poderiam ser só falha de login).
MYSQL_PWD="$SIGARB_DB_APP_PASSWORD" mysql -h 127.0.0.1 -usigarb_app "$BANCO" -N -e "SELECT COUNT(*) FROM usuarios" >/dev/null \
  || { echo "ERRO: sigarb_app não conecta/lê — os testes negativos acima não provam nada"; exit 1; }
[ $neg -eq 0 ] || { echo "Privilégio excessivo detectado."; exit 1; }

echo "== E2E completo com sigarb_app (prova que o runtime funciona só com DML)"
cd "$RAIZ"; SIGARB_E2E_DB_USER=sigarb_app SIGARB_E2E_DB_PASSWORD="$SIGARB_DB_APP_PASSWORD" SIGARB_E2E_SEM_MIGRAR=1 scripts/e2e.sh --project=desktop
