#!/usr/bin/env bash
# Teste LOCAL da implantação versionada (deploy/ops). Nada toca VM institucional.
# "VM .8": contêiner descartável da imagem de runtime (PHP 8.5 + pdo_mysql). "VM .9": schema isolado sigarb_rel no MySQL de dev.
# Uso: scripts/testar-implantacao.sh <R1.tar.gz> <R2.tar.gz>   (duas releases de SHAs diferentes)
set -euo pipefail
RAIZ="$(cd "$(dirname "$0")/.." && pwd)"; R1="$(realpath "$1")"; R2="$(realpath "$2")"
set -a; . "$RAIZ/infra/.env"; set +a
TMP=$(mktemp -d); C="sigarb-teste-implantacao-$$"
root_sql() { docker exec -i sigarb-dev-mysql mysql -uroot -p"$SIGARB_DB_ROOT_PASSWORD" "$@" 2>/dev/null; }
limpar() { [ "${MANTER:-0}" = 1 ] && { echo "MANTIDO: $C"; return; }; docker rm -f "$C" >/dev/null 2>&1 || true; root_sql -e "DROP DATABASE IF EXISTS sigarb_rel; DROP USER IF EXISTS 'rel_app'@'%', 'rel_migr'@'%';" || true; rm -rf "$TMP"; }
trap limpar EXIT; trap "exit 143" TERM INT
umask 077; SA=$(openssl rand -hex 18); SM=$(openssl rand -hex 18)   # senhas descartáveis, nunca impressas

# Pacotes de falha: R2 adulterado (checksum externo) e R2 "quebrado" (saúde 500, checksums internos refeitos).
cp "$R1" "$TMP/"; cp "$R1.sha256" "$TMP/"; cp "$R2" "$TMP/"; cp "$R2.sha256" "$TMP/"
N2=$(basename "$R2" .tar.gz); mkdir "$TMP/q"; tar -xzf "$R2" -C "$TMP/q"
cp "$TMP/q/$N2/backend/public/index.php" "$TMP/index.orig"
printf '<?php http_response_code(500); echo "falha injetada";\n' > "$TMP/q/$N2/backend/public/index.php"
(cd "$TMP/q/$N2" && find . -type f ! -name SHA256SUMS -print0 | LC_ALL=C sort -z | xargs -0 sha256sum > SHA256SUMS)
mv "$TMP/q/$N2" "$TMP/q/sigarb-quebrada"; tar -czf "$TMP/sigarb-quebrada.tar.gz" -C "$TMP/q" sigarb-quebrada
(cd "$TMP" && sha256sum sigarb-quebrada.tar.gz > sigarb-quebrada.tar.gz.sha256)
cp "$R1" "$TMP/sigarb-adulterada.tar.gz"; cp "$R1.sha256" "$TMP/sigarb-adulterada.tar.gz.sha256"
sed -i 's/ .*/  sigarb-adulterada.tar.gz/' "$TMP/sigarb-adulterada.tar.gz.sha256"
printf '\x00' | dd of="$TMP/sigarb-adulterada.tar.gz" bs=1 seek=4000 conv=notrunc 2>/dev/null

root_sql <<SQL
DROP USER IF EXISTS 'rel_app'@'%', 'rel_migr'@'%'; DROP DATABASE IF EXISTS sigarb_rel; CREATE DATABASE sigarb_rel CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'rel_app'@'%' IDENTIFIED BY '$SA' WITH MAX_USER_CONNECTIONS 15; CREATE USER 'rel_migr'@'%' IDENTIFIED BY '$SM';
GRANT ALL PRIVILEGES ON sigarb_rel.* TO 'rel_migr'@'%'; GRANT SELECT, INSERT, UPDATE ON sigarb_rel.* TO 'rel_app'@'%';
SQL

docker run -d --name "$C" --network sigarb-dev_default --entrypoint sleep -v "$TMP:/pacotes:ro" sigarb-pl-app:local infinity >/dev/null
docker cp "$RAIZ/scripts/testar-implantacao-vm.sh" "$C:/tmp/vm.sh"
docker exec -u 0 -e SA="$SA" -e SM="$SM" -e R1="/pacotes/$(basename "$R1")" -e R2="/pacotes/$(basename "$R2")" "$C" bash /tmp/vm.sh \
  || { echo "FALHOU"; exit 1; }
echo "== Privilégios da identidade de runtime no banco de teste"
root_sql -N -e "SHOW GRANTS FOR 'rel_app'@'%'" | sed 's/^/  /'
