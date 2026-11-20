#!/usr/bin/env bash
# SIGARB — prepara o MySQL LOCAL de desenvolvimento (sem Docker; decisão do responsável, 2026-11-19).
# Uso (na raiz do repo, com backend/.env já criado):  database/dev/preparar.sh
# Idempotente. Cria/atualiza SOMENTE recursos do SIGARB:
#  - bancos `sigarb` (demonstração), `sigarb_test` (PHPUnit) e `sigarb_e2e` (E2E, backup, privilégios);
#  - usuário de desenvolvimento `sigarb`@`localhost` (senha = DB_PASSWORD do backend/.env) com ALL nesses 3 bancos
#    (migrations criam triggers de imutabilidade: exige TRIGGER e, com binlog ligado, log_bin_trust_function_creators=ON);
#  - identidades separadas do ADR-013, `sigarb_app` (só DML) e `sigarb_migr` (DDL), no banco sigarb_e2e, com
#    SIGARB_DB_APP_PASSWORD e SIGARB_DB_MIGR_PASSWORD do backend/.env (opcional: usado por scripts/verificar-privilegios.sh);
#  - banco compartilhado `ecossistema`: só é criado se NÃO existir (deploy/modelos/ecossistema.sql, idempotente; o GRANT
#    final daquele arquivo é da produção e é ignorado aqui). O `sigarb` local recebe o mesmo acesso do runtime de produção
#    (SELECT em tudo, INSERT em versoes).
# Nenhum outro banco/usuário da instância é tocado.
# Requer: cliente mysql com acesso administrativo local (ex.: usuário criado com auth_socket) ou MYSQL_ADMIN="mysql -uroot -p".
set -euo pipefail
RAIZ="$(cd "$(dirname "$0")/../.." && pwd)"
. "$RAIZ/scripts/ambiente.sh"
carregar_ambiente
MYSQL=${MYSQL_ADMIN:-mysql}
q() { printf '%s' "${1//\'/\'\'}"; }   # escapa aspas simples para literal SQL
SENHA="$SIGARB_DB_PASSWORD"
[ -n "$SENHA" ] || { echo "ERRO: defina DB_PASSWORD em backend/.env"; exit 1; }

$MYSQL -N -e "SELECT IF(@@log_bin = 0 OR @@log_bin_trust_function_creators = 1, 'ok', 'falta')" | grep -qx ok \
  || echo "AVISO: binlog ligado sem log_bin_trust_function_creators=ON — as migrations com triggers vão falhar (mesmo requisito da produção)."

$MYSQL <<SQL
CREATE DATABASE IF NOT EXISTS sigarb CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE IF NOT EXISTS sigarb_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE IF NOT EXISTS sigarb_e2e CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'sigarb'@'localhost' IDENTIFIED BY '$(q "$SENHA")';
ALTER USER 'sigarb'@'localhost' IDENTIFIED BY '$(q "$SENHA")';
GRANT ALL PRIVILEGES ON sigarb.* TO 'sigarb'@'localhost';
GRANT ALL PRIVILEGES ON sigarb_test.* TO 'sigarb'@'localhost';
GRANT ALL PRIVILEGES ON sigarb_e2e.* TO 'sigarb'@'localhost';
FLUSH PRIVILEGES;
SQL

if [ -n "$SIGARB_DB_APP_PASSWORD" ] && [ -n "$SIGARB_DB_MIGR_PASSWORD" ]; then
  # --force: os GRANT DELETE por tabela falham enquanto sigarb_e2e não tem tabelas; verificar-privilegios.sh reaplica.
  sed -e "s/@BANCO@/sigarb_e2e/g" -e "s/@SENHA_APP@/$(q "$SIGARB_DB_APP_PASSWORD" | sed 's/[&/\]/\\&/g')/g" \
      -e "s/@SENHA_MIGR@/$(q "$SIGARB_DB_MIGR_PASSWORD" | sed 's/[&/\]/\\&/g')/g" "$RAIZ/database/dev/privilegios.sql.tpl" \
    | $MYSQL --force >/dev/null 2>&1 || true
  echo "identidades sigarb_app/sigarb_migr (ADR-013) prontas em sigarb_e2e"
else
  echo "(sem SIGARB_DB_APP_PASSWORD/SIGARB_DB_MIGR_PASSWORD no backend/.env: sigarb_app/sigarb_migr não criados)"
fi

if [ -z "$($MYSQL -N -e "SHOW DATABASES LIKE 'ecossistema'")" ]; then
  # Só a estrutura e a carga idempotente; o GRANT final do modelo é para os hosts da produção.
  sed '/^GRANT /d' "$RAIZ/deploy/modelos/ecossistema.sql" | $MYSQL
  echo "banco ecossistema criado (não existia)"
fi
$MYSQL <<SQL
GRANT SELECT ON ecossistema.* TO 'sigarb'@'localhost';
GRANT INSERT ON ecossistema.versoes TO 'sigarb'@'localhost';
FLUSH PRIVILEGES;
SQL

echo "PRONTO — agora: cd backend && composer install && php artisan key:generate && php artisan migrate --seed"
