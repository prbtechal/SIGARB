#!/usr/bin/env bash
# SIGARB — implanta uma release versionada com troca atômica e rollback AUTOMÁTICO DE CÓDIGO se a saúde falhar.
# Uso: ops/implantar.sh <sigarb-SHA.tar.gz> [--simular] [--migrar]
#   --simular  preflight + extração verificada + relatório de compatibilidade de schema; NÃO troca nada.
#   --migrar   autoriza aplicar migrações pendentes com shared/migr.env (exige BACKUP_VERIFICADO=<id do backup restaurado>).
# Garantias: lock exclusivo; extração em diretório temporário + verificação SHA256SUMS + rename atômico;
# shared/.env e shared/storage (uploads) nunca são tocados; o banco NUNCA é revertido automaticamente.
. "$(dirname "$0")/comum.sh"
PKG="${1:?uso: implantar.sh <pacote.tar.gz> [--simular] [--migrar]}"; shift; SIMULAR=0; MIGRAR=0
for a in "$@"; do case $a in --simular) SIMULAR=1;; --migrar) MIGRAR=1;; *) falha "opção desconhecida: $a";; esac; done
exec 9>"$BASE/.implantacao.lock"; flock -n 9 || falha "outra implantação em andamento"

"$(dirname "$0")/preflight.sh" "$PKG" || { [ $SIMULAR = 1 ] && log "SIMULAÇÃO: preflight bloqueado (seguindo apenas para relatório)" || falha "preflight bloqueado"; }
NOME=$(basename "$PKG" .tar.gz); DEST="$REL/$NOME"
rm -rf "$REL"/.extraindo-* 2>/dev/null || true   # sobras de implantação interrompida
if [ -d "$DEST" ]; then log "release $NOME já extraída (reuso)"; else
  T="$REL/.extraindo-$NOME"; mkdir -p "$T"; tar -xzf "$PKG" -C "$T"
  (cd "$T/$NOME" && sha256sum -c --quiet SHA256SUMS) || { rm -rf "$T"; falha "SHA256SUMS interno divergente"; }
  # Release é imutável: dono do operador, sem escrita para o pool; storage/.env vêm de shared/.
  rm -rf "$T/$NOME/backend/storage"; ln -s "$SH/storage" "$T/$NOME/backend/storage"; ln -s "$SH/.env" "$T/$NOME/backend/.env"
  chmod -R go-w "$T/$NOME"
  [ $SIMULAR = 1 ] && { DEST="$T/$NOME"; } || { mv "$T/$NOME" "$DEST"; rmdir "$T"; }
fi
log "release $NOME: $(grep -E '^(git_sha|schema_ultima_migracao)=' "$DEST/MANIFESTO.txt" | tr '\n' ' ')"

# Compatibilidade de schema: banco à frente do código = BLOQUEIO; pendências = só com --migrar.
APLICADAS=$(migracoes_banco "$DEST") || falha "não foi possível ler o estado de migrações com a identidade de runtime"
desconhecidas=$(comm -13 "$DEST/MIGRACOES.txt" <(echo "$APLICADAS" | grep -v '^$') || true)
pendentes=$(comm -23 "$DEST/MIGRACOES.txt" <(echo "$APLICADAS" | grep -v '^$') || true)
[ -z "$desconhecidas" ] || falha "banco contém migrações que esta release não conhece (banco à frente do código): $(echo $desconhecidas)"
log "migrações pendentes: $(echo "$pendentes" | grep -c . || true)"
if [ $SIMULAR = 1 ]; then
  [ -z "$pendentes" ] || artisan_migr "$DEST" migrate --pretend --force --no-ansi 2>&1 | tail -20 || true
  rm -rf "$REL/.extraindo-$NOME"; log "SIMULAÇÃO concluída: nada foi trocado"; echo "SIMULACAO_OK"; exit 0
fi
if [ -n "$pendentes" ]; then
  [ $MIGRAR = 1 ] || falha "há migrações pendentes; repita com --migrar após backup verificado"
  [ -n "${BACKUP_VERIFICADO:-}" ] || falha "--migrar exige BACKUP_VERIFICADO=<identificador do backup restaurado com sucesso>"
  log "aplicando migrações (backup verificado: $BACKUP_VERIFICADO) — IRREVERSÍVEL por rollback de código"
  artisan_migr "$DEST" migrate --force --no-ansi
fi

cachear "$DEST"
ANTERIOR=$(readlink -f "$ATUAL" 2>/dev/null || true)
trocar_para "$DEST"; eval "$RECARREGAR_FPM" 9>&-
if saude; then log "IMPLANTADO $NOME (anterior: ${ANTERIOR:+$(basename "$ANTERIOR")})"; echo "IMPLANTACAO_OK $NOME"; exit 0; fi
log "saúde falhou após a troca"
if [ -n "$ANTERIOR" ] && [ -d "$ANTERIOR" ]; then
  trocar_para "$ANTERIOR"; eval "$RECARREGAR_FPM" 9>&-
  saude && log "ROLLBACK AUTOMÁTICO DE CÓDIGO para $(basename "$ANTERIOR") — saúde OK (banco NÃO revertido)" || log "ROLLBACK de código feito, mas a saúde continua falhando: intervenção manual"
fi
falha "implantação de $NOME falhou"
