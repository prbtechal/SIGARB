#!/usr/bin/env bash
# Backup LOCAL cifrado (desenvolvimento/demonstração, dados sintéticos). NÃO define política institucional (RPO/RTO/retenção: P-10/P-08).
# Uso: scripts/backup-local.sh [banco=sigarb] [destino=~/.local/share/sigarb-dev/backups]
set -euo pipefail
RAIZ="$(cd "$(dirname "$0")/.." && pwd)"
BANCO="${1:-sigarb}"; DESTINO="${2:-$HOME/.local/share/sigarb-dev/backups}"
CHAVE="${SIGARB_BACKUP_CHAVE:-$HOME/.config/sigarb-dev/backup.chave}"
[[ "$BANCO" =~ ^sigarb(_e2e|_test)?$ ]] || { echo "ERRO: banco não permitido para backup local: $BANCO"; exit 2; }
set -a; . "$RAIZ/infra/.env"; set +a
if [ ! -f "$CHAVE" ]; then
  umask 077; mkdir -p "$(dirname "$CHAVE")"
  { echo "# SIGARB — CHAVE SOMENTE DESENVOLVIMENTO. Nunca usar em produção."; head -c 48 /dev/urandom | base64 -w0; echo; } > "$CHAVE"
  echo "Chave de desenvolvimento criada fora do repositório (permissão 600)."
fi
[ "$(stat -c %a "$CHAVE")" = 600 ] || { echo "ERRO: a chave deve ter permissão 600."; exit 2; }
TMP=$(mktemp -d); trap 'rm -rf "$TMP"' EXIT
mysql_root() { docker exec -i sigarb-dev-mysql "$@" -uroot -p"$SIGARB_DB_ROOT_PASSWORD" 2>/dev/null; }

echo "== Dump de $BANCO"
mysql_root mysqldump --single-transaction --routines --triggers --hex-blob --no-tablespaces --set-gtid-purged=OFF "$BANCO" > "$TMP/banco.sql" \
  || { echo "ERRO: falha no mysqldump"; exit 1; }
grep -q "CREATE TABLE \`eventos_auditoria\`" "$TMP/banco.sql" || { echo "ERRO: dump incompleto"; exit 1; }

echo "== Documentos referenciados"
mysql_root mysql -N -B "$BANCO" -e "SELECT armazenamento_id FROM documentos" > "$TMP/ids.txt"
DOCS="$RAIZ/backend/storage/app/private"; : > "$TMP/lista.txt"
while read -r id; do
  [[ "$id" =~ ^[0-9a-f]{64}$ ]] || { echo "ERRO: identificador inválido"; exit 1; }
  rel="documentos/${id:0:2}/${id:2:2}/$id"
  [ -f "$DOCS/$rel" ] || { echo "ERRO: arquivo de documento referenciado ausente ($id). Backup abortado."; exit 1; }
  echo "$rel" >> "$TMP/lista.txt"
done < "$TMP/ids.txt"
tar -C "$DOCS" -cf "$TMP/documentos.tar" --files-from "$TMP/lista.txt"

conta() { mysql_root mysql -N -B "$BANCO" -e "SELECT COUNT(*) FROM \`$1\`"; }
TABELAS="usuarios unidades locais_estoque militares materiais lotes itens_individuais saldos_estoque movimentacoes movimentacao_itens documentos documento_vinculos eventos_auditoria"
{
  echo "{"
  echo "  \"formato\": 1, \"banco_origem\": \"$BANCO\", \"criado_em\": \"$(date -Iseconds)\","
  echo "  \"sha256_banco\": \"$(sha256sum "$TMP/banco.sql" | cut -d' ' -f1)\","
  echo "  \"sha256_documentos\": \"$(sha256sum "$TMP/documentos.tar" | cut -d' ' -f1)\","
  echo "  \"documentos\": $(wc -l < "$TMP/lista.txt"),"
  echo "  \"ultimo_hash_auditoria\": \"$(mysql_root mysql -N -B "$BANCO" -e 'SELECT ultimo_hash FROM auditoria_cadeia WHERE id=1')\","
  echo "  \"contagens\": {"; sep=""; for t in $TABELAS; do printf '%s    "%s": %s' "$sep" "$t" "$(conta "$t")"; sep=$',\n'; done; echo; echo "  }"
  echo "}"
} > "$TMP/manifesto.json"

mkdir -p "$DESTINO"; chmod 700 "$DESTINO"
SAIDA="$DESTINO/sigarb-$BANCO-$(date +%Y%m%dT%H%M%S).tar.gpg"
tar -C "$TMP" -cf - banco.sql documentos.tar manifesto.json \
  | gpg --batch --yes --quiet --symmetric --cipher-algo AES256 --s2k-digest-algo SHA512 --s2k-count 65011712 \
        --passphrase-file <(grep -v '^#' "$CHAVE") -o "$SAIDA"
chmod 600 "$SAIDA"; sha256sum "$SAIDA" | cut -d' ' -f1 > "$SAIDA.sha256"
echo "OK backup: $SAIDA ($(du -h "$SAIDA" | cut -f1), $(wc -l < "$TMP/lista.txt") documento(s))"
