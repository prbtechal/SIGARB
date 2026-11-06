#!/usr/bin/env bash
# Restaura um backup local em AMBIENTE DESCARTÁVEL (banco novo sigarb_restore_<ts> + diretório temporário de documentos)
# e verifica: integridade do arquivo cifrado, hashes das partes, contagens, cadeia de auditoria e checksums dos documentos.
# Nunca sobrescreve bancos existentes. Uso: scripts/restaurar-local.sh <arquivo.tar.gpg> [--manter]
set -euo pipefail
RAIZ="$(cd "$(dirname "$0")/.." && pwd)"
ARQ="${1:?informe o arquivo .tar.gpg}"; MANTER="${2:-}"
CHAVE="${SIGARB_BACKUP_CHAVE:-$HOME/.config/sigarb-dev/backup.chave}"
set -a; . "$RAIZ/infra/.env"; set +a
TS=$(date +%Y%m%d%H%M%S); BANCO="sigarb_restore_$TS"
TMP=$(mktemp -d); DOCS="$RAIZ/backend/storage/framework/testing/restore-$TS"
mysql_root() { docker exec -i sigarb-dev-mysql mysql -uroot -p"$SIGARB_DB_ROOT_PASSWORD" "$@" 2>/dev/null; }
limpar() {
  rm -rf "$TMP"
  if [ "$MANTER" != "--manter" ]; then mysql_root -e "DROP DATABASE IF EXISTS \`$BANCO\`" || true; rm -rf "$DOCS"; fi
}
trap limpar EXIT
trap 'exit 130' INT
trap 'exit 143' TERM
falha() { echo "FALHA: $*"; exit 1; }

[ -f "$CHAVE" ] && [ "$(stat -c %a "$CHAVE")" = 600 ] || falha "chave ausente ou sem permissão 600"
echo "== 1. Integridade do arquivo cifrado"
if [ -f "$ARQ.sha256" ]; then [ "$(sha256sum "$ARQ" | cut -d' ' -f1)" = "$(cat "$ARQ.sha256")" ] || falha "SHA-256 do arquivo cifrado não confere"; fi
gpg --batch --quiet --decrypt --passphrase-file <(grep -v '^#' "$CHAVE") "$ARQ" 2>/dev/null > "$TMP/pacote.tar" || falha "decifragem/autenticação falhou (chave errada ou arquivo adulterado)"
[ "$(tar -tf "$TMP/pacote.tar" | sort | tr '\n' ' ')" = "banco.sql documentos.tar manifesto.json " ] || falha "pacote com conteúdo inesperado"
tar -C "$TMP" -xf "$TMP/pacote.tar" banco.sql documentos.tar manifesto.json || falha "pacote incompleto"
M="$TMP/manifesto.json"; campo() { python3 -I -c "import json,sys;print(json.load(open(sys.argv[1]))${1})" "$M"; }

echo "== 2. Hashes das partes"
[ "$(sha256sum "$TMP/banco.sql" | cut -d' ' -f1)" = "$(campo "['sha256_banco']")" ] || falha "hash do dump diverge do manifesto"
[ "$(sha256sum "$TMP/documentos.tar" | cut -d' ' -f1)" = "$(campo "['sha256_documentos']")" ] || falha "hash dos documentos diverge do manifesto"

echo "== 3. Restauração em banco descartável $BANCO"
mysql_root -e "CREATE DATABASE \`$BANCO\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; GRANT ALL ON \`$BANCO\`.* TO 'sigarb'@'%';" || falha "não foi possível criar o banco descartável"
mysql_root "$BANCO" < "$TMP/banco.sql" || falha "importação do dump falhou"
# Só caminhos documentos/xx/yy/<sha64>, arquivos regulares; nada absoluto, '..' ou links.
tar -tvf "$TMP/documentos.tar" | awk '{print substr($1,1,1)}' | grep -qv '^-' && falha "documentos.tar contém entrada que não é arquivo regular"
tar -tf "$TMP/documentos.tar" | grep -vqE '^documentos/[0-9a-f]{2}/[0-9a-f]{2}/[0-9a-f]{64}$' && falha "documentos.tar contém caminho não permitido"
mkdir -p "$DOCS"; tar -C "$DOCS" --no-same-owner --no-same-permissions -xf "$TMP/documentos.tar" || falha "extração dos documentos falhou"

echo "== 4. Verificações"
for t in $(python3 -I -c "import json,sys;print(' '.join(json.load(open(sys.argv[1]))['contagens']))" "$M"); do
  n=$(mysql_root -N -B "$BANCO" -e "SELECT COUNT(*) FROM \`$t\`"); e=$(campo "['contagens']['$t']")
  [ "$n" = "$e" ] || falha "contagem de $t: $n (esperado $e)"
done
echo "contagens: ok"
[ "$(mysql_root -N -B "$BANCO" -e 'SELECT ultimo_hash FROM auditoria_cadeia WHERE id=1')" = "$(campo "['ultimo_hash_auditoria']")" ] || falha "ponteiro da auditoria diverge"
[ "$(mysql_root -N -B "$BANCO" -e "SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA='$BANCO'")" -ge 10 ] || falha "triggers de imutabilidade não restaurados"
cd "$RAIZ/infra"
docker compose run --rm --no-deps -w /app -T -e DB_DATABASE="$BANCO" -e SIGARB_DOCUMENTOS_ROOT="/app/storage/framework/testing/restore-$TS" api \
  sh -c 'php artisan sigarb:auditoria:verificar && php artisan sigarb:documentos:verificar' 2>/dev/null | grep -v Container || falha "auditoria ou documentos inválidos após restauração"
echo "RESTAURACAO_OK banco=$BANCO documentos=$(campo "['documentos']") eventos=$(campo "['contagens']['eventos_auditoria']")"
[ "$MANTER" = "--manter" ] && echo "Ambiente mantido: banco $BANCO e documentos em $DOCS (remova manualmente)."
exit 0
