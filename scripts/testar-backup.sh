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

echo "== Falha explícita no backup"
ID=$(docker exec sigarb-dev-mysql mysql -uroot -p"$SIGARB_DB_ROOT_PASSWORD" -N -B sigarb_e2e -e "SELECT armazenamento_id FROM documentos LIMIT 1" 2>/dev/null)
if [ -n "$ID" ]; then
  F="$RAIZ/backend/storage/app/private/documentos/${ID:0:2}/${ID:2:2}/$ID"
  mv "$F" "$F.oculto"
  caso "documento referenciado ausente aborta o backup" falha "$RAIZ/scripts/backup-local.sh" sigarb_e2e "$DEST"
  mv "$F.oculto" "$F"
fi

restos=$(docker exec sigarb-dev-mysql mysql -uroot -p"$SIGARB_DB_ROOT_PASSWORD" -N -B -e "SHOW DATABASES LIKE 'sigarb_restore_%'" 2>/dev/null | wc -l)
caso "nenhum banco de restauração deixado para trás" sucesso test "$restos" -eq 0
echo "Resultado: $ok ok, $falhou falha(s)"
[ $falhou -eq 0 ]
