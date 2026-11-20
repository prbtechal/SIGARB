#!/usr/bin/env bash
# Teste da implantação versionada (deploy/ops), SEM Docker (decisão do responsável, 2026-11-19). Nada toca VM institucional.
# "VM .8": a própria máquina onde o script roda — precisa ser DESCARTÁVEL (VM de teste/homologação local), porque o teste
#          cria o usuário de sistema `sigarb`, /srv/sigarb e /usr/local/bin/recarregar-teste, como o RUNBOOK faz na .8.
# "VM .9": schema isolado sigarb_rel + usuários rel_app/rel_migr no MySQL local (DB_HOST_TESTE, padrão 127.0.0.1).
# Requisitos: root (sudo), PHP 8.5 com pdo_mysql, curl, flock, openssl e o cliente mysql com acesso administrativo.
# Uso: sudo scripts/testar-implantacao.sh <R1.tar.gz> <R2.tar.gz>   (duas releases de SHAs diferentes)
set -euo pipefail
RAIZ="$(cd "$(dirname "$0")/.." && pwd)"; R1="$(realpath "$1")"; R2="$(realpath "$2")"
[ "$(id -u)" = 0 ] || { echo "ERRO: rode como root numa máquina DESCARTÁVEL (o teste cria o usuário sigarb e /srv/sigarb)."; exit 2; }
if id sigarb >/dev/null 2>&1 || [ -e /srv/sigarb ]; then
  echo "ERRO: usuário sigarb ou /srv/sigarb já existem — esta não é uma máquina descartável; nada foi alterado."; exit 2; fi
export DB_HOST_TESTE="${DB_HOST_TESTE:-127.0.0.1}"
TMP=$(mktemp -d)
root_sql() { ${MYSQL_ADMIN:-mysql} "$@" 2>/dev/null; }
limpar() {
  [ "${MANTER:-0}" = 1 ] && { echo "MANTIDO: /srv/sigarb, usuário sigarb e schema sigarb_rel"; return; }
  [ -s /run/sigarb-teste.pid ] && kill "$(cat /run/sigarb-teste.pid)" 2>/dev/null || true
  rm -f /run/sigarb-teste.pid /usr/local/bin/recarregar-teste; rm -rf /srv/sigarb /tmp/ops /tmp/sigarb-*/
  id sigarb >/dev/null 2>&1 && userdel sigarb 2>/dev/null || true; getent group sigarb >/dev/null && groupdel sigarb 2>/dev/null || true
  root_sql -e "DROP DATABASE IF EXISTS sigarb_rel; DROP USER IF EXISTS 'rel_app'@'localhost', 'rel_migr'@'localhost';" || true
  rm -rf "$TMP"
}
trap limpar EXIT; trap "exit 143" TERM INT
umask 077; SA=$(openssl rand -hex 18); SM=$(openssl rand -hex 18)   # senhas descartáveis, nunca impressas

# Pacotes de falha: R2 adulterado (checksum externo) e R2 "quebrado" (saúde 500, checksums internos refeitos).
cp "$R1" "$TMP/"; cp "$R1.sha256" "$TMP/"; cp "$R2" "$TMP/"; cp "$R2.sha256" "$TMP/"
N2=$(basename "$R2" .tar.gz); mkdir "$TMP/q"; tar -xzf "$R2" -C "$TMP/q"
cp "$TMP/q/$N2/backend/public/index.php" "$TMP/index.orig"
printf '<?php http_response_code(500); echo "falha injetada";\n' > "$TMP/q/$N2/backend/public/index.php"
(cd "$TMP/q/$N2" && find . -type f ! -name SHA256SUMS -print0 | LC_ALL=C sort -z | xargs -0 sha256sum > SHA256SUMS)
mv "$TMP/q/$N2" "$TMP/q/sigarb-quebrada"; tar --mode="u+rwX,go+rX,go-w" -czf "$TMP/sigarb-quebrada.tar.gz" -C "$TMP/q" sigarb-quebrada
(cd "$TMP" && sha256sum sigarb-quebrada.tar.gz > sigarb-quebrada.tar.gz.sha256)
cp "$R1" "$TMP/sigarb-adulterada.tar.gz"; cp "$R1.sha256" "$TMP/sigarb-adulterada.tar.gz.sha256"
sed -i 's/ .*/  sigarb-adulterada.tar.gz/' "$TMP/sigarb-adulterada.tar.gz.sha256"
printf '\x00' | dd of="$TMP/sigarb-adulterada.tar.gz" bs=1 seek=4000 conv=notrunc 2>/dev/null
chmod 755 "$TMP"; chmod 644 "$TMP"/*.tar.gz "$TMP"/*.sha256   # o usuário sigarb lê os pacotes (antes: volume :ro)

root_sql <<SQL
DROP USER IF EXISTS 'rel_app'@'localhost', 'rel_migr'@'localhost'; DROP DATABASE IF EXISTS sigarb_rel; CREATE DATABASE sigarb_rel CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'rel_app'@'localhost' IDENTIFIED BY '$SA' WITH MAX_USER_CONNECTIONS 15; CREATE USER 'rel_migr'@'localhost' IDENTIFIED BY '$SM';
GRANT ALL PRIVILEGES ON sigarb_rel.* TO 'rel_migr'@'localhost'; GRANT SELECT, INSERT, UPDATE ON sigarb_rel.* TO 'rel_app'@'localhost';
SQL

# Os nomes /pacotes/... do roteiro viram o diretório temporário.
sed "s#/pacotes/#$TMP/#g" "$RAIZ/scripts/testar-implantacao-vm.sh" > "$TMP/vm.sh"
SA="$SA" SM="$SM" R1="$TMP/$(basename "$R1")" R2="$TMP/$(basename "$R2")" bash "$TMP/vm.sh" || { echo "FALHOU"; exit 1; }
echo "== Privilégios da identidade de runtime no banco de teste"
root_sql -N -e "SHOW GRANTS FOR 'rel_app'@'localhost'" | sed 's/^/  /'
