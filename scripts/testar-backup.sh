#!/usr/bin/env bash
# Teste automatizado de backup/restauração sobre o banco ISOLADO sigarb_e2e (dados sintéticos).
set -uo pipefail
RAIZ="$(cd "$(dirname "$0")/.." && pwd)"
set -a; . "$RAIZ/infra/.env"; set +a
DEST=$(mktemp -d); CHAVE_ERRADA=$(mktemp); trap 'rm -rf "$DEST" "$CHAVE_ERRADA"' EXIT
ok=0; falhou=0
caso() { local nome="$1" esperado="$2"; shift 2; local saida; saida=$("$@" 2>&1); local rc=$?
  if { [ "$esperado" = sucesso ] && [ $rc -eq 0 ]; } || { [ "$esperado" = falha ] && [ $rc -ne 0 ]; }; then echo "  ✓ $nome"; ok=$((ok+1)); else echo "  ✘ $nome (rc=$rc)"; echo "$saida" | tail -5; falhou=$((falhou+1)); fi
  ULTIMA_SAIDA="$saida"; }

docs=$(docker exec sigarb-dev-mysql mysql -uroot -p"$SIGARB_DB_ROOT_PASSWORD" -N -B sigarb_e2e -e "SELECT COUNT(*) FROM documentos" 2>/dev/null)
echo "sigarb_e2e: $docs documento(s) (execute scripts/e2e.sh antes para ter documentos reais)"

echo "== Backup"
caso "backup cifrado gerado" sucesso "$RAIZ/scripts/backup-local.sh" sigarb_e2e "$DEST"
ARQ=$(ls "$DEST"/*.tar.gpg | head -1)
caso "conteúdo não é legível sem decifrar" sucesso bash -c "! grep -aq 'CREATE TABLE' '$ARQ' && ! grep -aq '%PDF' '$ARQ'"
caso "permissão do arquivo é 600" sucesso bash -c "[ \$(stat -c %a '$ARQ') = 600 ]"
caso "banco fora da lista permitida é recusado" falha "$RAIZ/scripts/backup-local.sh" mysql "$DEST"

echo "== Restauração"
caso "restauração completa e verificada" sucesso "$RAIZ/scripts/restaurar-local.sh" "$ARQ"
echo "$ULTIMA_SAIDA" | grep -E "RESTAURACAO_OK|contagens|íntegra|documentos=" | sed 's/^/    /'

# Controle: a verificação respeita o diretório informado (se olhasse o original, este caso passaria por engano).
caso "verificação de documentos aponta para o diretório indicado (controle com diretório vazio)" falha \
  bash -c "cd '$RAIZ/infra' && docker compose run --rm --no-deps -w /app -T -e DB_DATABASE=sigarb_e2e -e SIGARB_DOCUMENTOS_ROOT=/tmp/vazio-controle api php artisan sigarb:documentos:verificar"

ADULT="$DEST/adulterado.tar.gpg"; cp "$ARQ" "$ADULT"
python3 -I -c "import sys;p=sys.argv[1];b=bytearray(open(p,'rb').read());b[len(b)//2]^=1;open(p,'wb').write(b)" "$ADULT"
caso "arquivo adulterado (1 bit) é rejeitado pela autenticação da cifra" falha "$RAIZ/scripts/restaurar-local.sh" "$ADULT"
echo "$ULTIMA_SAIDA" | grep FALHA | sed 's/^/    /'
cp "$ARQ.sha256" "$ADULT.sha256"
caso "arquivo adulterado é rejeitado também pelo SHA-256 externo" falha "$RAIZ/scripts/restaurar-local.sh" "$ADULT"

{ echo "# chave errada de teste"; head -c 48 /dev/urandom | base64 -w0; echo; } > "$CHAVE_ERRADA"
caso "chave errada é rejeitada" falha env SIGARB_BACKUP_CHAVE="$CHAVE_ERRADA" "$RAIZ/scripts/restaurar-local.sh" "$ARQ"

# Pacote autenticado (cifrado com a chave correta) mas contendo path traversal: deve ser recusado.
MAL="$DEST/malicioso"; mkdir -p "$MAL/x" && echo 'x' > "$MAL/x/evil"
( cd "$MAL" && tar -cf documentos.tar --transform 's|^x/evil|../../escapou|' x/evil && echo '-- vazio' > banco.sql \
  && python3 -I -c "import hashlib,json;h=lambda p:hashlib.sha256(open(p,'rb').read()).hexdigest();json.dump({'sha256_banco':h('banco.sql'),'sha256_documentos':h('documentos.tar'),'documentos':1,'ultimo_hash_auditoria':'','contagens':{}},open('manifesto.json','w'))" \
  && tar -cf - banco.sql documentos.tar manifesto.json | gpg --batch --yes --quiet --symmetric --cipher-algo AES256 --passphrase-file <(grep -v '^#' "${SIGARB_BACKUP_CHAVE:-$HOME/.config/sigarb-dev/backup.chave}") -o "$DEST/malicioso.tar.gpg" )
caso "pacote autenticado com path traversal é recusado antes de extrair" falha "$RAIZ/scripts/restaurar-local.sh" "$DEST/malicioso.tar.gpg"
echo "$ULTIMA_SAIDA" | grep FALHA | sed 's/^/    /'
caso "nada escapou para fora do diretório descartável" sucesso bash -c "! ls '$RAIZ/backend/storage/framework/escapou' '$RAIZ/backend/storage/escapou' 2>/dev/null"

echo "== Falha explícita no backup"
ID=$(docker exec sigarb-dev-mysql mysql -uroot -p"$SIGARB_DB_ROOT_PASSWORD" -N -B sigarb_e2e -e "SELECT armazenamento_id FROM documentos LIMIT 1" 2>/dev/null)
if [ -n "$ID" ]; then
  F="$RAIZ/backend/storage/app/private/documentos/${ID:0:2}/${ID:2:2}/$ID"
  mv "$F" "$F.oculto"
  caso "documento referenciado ausente aborta o backup" falha "$RAIZ/scripts/backup-local.sh" sigarb_e2e "$DEST"
  mv "$F.oculto" "$F"
fi

echo "== Triggers após restauração (comportamento, não só contagem)"
caso "restauração mantida para inspeção" sucesso "$RAIZ/scripts/restaurar-local.sh" "$ARQ" --manter
BR=$(echo "$ULTIMA_SAIDA" | sed -n 's/.*RESTAURACAO_OK banco=\([a-z0-9_]*\).*/\1/p')
sqlr() { docker exec sigarb-dev-mysql mysql -uroot -p"$SIGARB_DB_ROOT_PASSWORD" -N -B "$BR" -e "$1" 2>&1; }
caso "UPDATE em evento de auditoria restaurado é bloqueado pelo trigger" sucesso bash -c "[ -n '$BR' ] && docker exec sigarb-dev-mysql mysql -uroot -p'$SIGARB_DB_ROOT_PASSWORD' '$BR' -e 'UPDATE eventos_auditoria SET resultado=resultado LIMIT 1' 2>&1 | grep -q 45000"
caso "DELETE em evento de auditoria restaurado é bloqueado pelo trigger" sucesso bash -c "[ -n '$BR' ] && docker exec sigarb-dev-mysql mysql -uroot -p'$SIGARB_DB_ROOT_PASSWORD' '$BR' -e 'DELETE FROM eventos_auditoria LIMIT 1' 2>&1 | grep -q 45000"
caso "UPDATE em movimentação restaurada é bloqueado pelo trigger" sucesso bash -c "[ -n '$BR' ] && docker exec sigarb-dev-mysql mysql -uroot -p'$SIGARB_DB_ROOT_PASSWORD' '$BR' -e 'UPDATE movimentacoes SET justificativa=justificativa LIMIT 1' 2>&1 | grep -q 45000"
[ -n "$BR" ] && { sqlr "DROP DATABASE \`$BR\`" >/dev/null; rm -rf "$RAIZ/backend/storage/framework/testing/restore-${BR#sigarb_restore_}"; }

echo "== Restauração interrompida (SIGTERM durante a importação)"
"$RAIZ/scripts/restaurar-local.sh" "$ARQ" > /dev/null 2>&1 & PR=$!
for _ in $(seq 1 100); do docker exec sigarb-dev-mysql mysql -uroot -p"$SIGARB_DB_ROOT_PASSWORD" -N -B -e "SHOW DATABASES LIKE 'sigarb_restore_%'" 2>/dev/null | grep -q . && break; sleep 0.1; done
kill -TERM $PR 2>/dev/null; rcr=0; wait $PR || rcr=$?
caso "restauração interrompida termina com código de falha" sucesso test "$rcr" -ne 0
caso "restauração interrompida não deixa diretório temporário de documentos" sucesso bash -c "! ls -d '$RAIZ'/backend/storage/framework/testing/restore-* 2>/dev/null | grep -q ."

restos=$(docker exec sigarb-dev-mysql mysql -uroot -p"$SIGARB_DB_ROOT_PASSWORD" -N -B -e "SHOW DATABASES LIKE 'sigarb_restore_%'" 2>/dev/null | wc -l)
caso "nenhum banco de restauração deixado para trás" sucesso test "$restos" -eq 0
echo "Resultado: $ok ok, $falhou falha(s)"
[ $falhou -eq 0 ]
