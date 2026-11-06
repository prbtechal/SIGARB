#!/usr/bin/env bash
# SIGARB — reverte o CÓDIGO para uma release anterior já extraída. Uso: ops/reverter.sh [nome-da-release]
# Sem argumento: a release ativa imediatamente antes da atual (HISTORICO). O banco NUNCA é revertido aqui:
# se o banco tiver migrações que a release alvo não conhece, a reversão é BLOQUEADA (exige restauração de backup).
. "$(dirname "$0")/comum.sh"
exec 9>"$BASE/.implantacao.lock"; flock -n 9 || falha "implantação em andamento"
ATUAL_NOME=$(basename "$(readlink -f "$ATUAL")")
ALVO="${1:-$(awk '{print $2}' "$HIST" | grep -vx "$ATUAL_NOME" | tail -1)}"
[ -n "$ALVO" ] && [ -d "$REL/$ALVO" ] || falha "release alvo inexistente: '${ALVO}'"
APLICADAS=$(migracoes_banco "$REL/$ALVO" | grep -v '^$' || true)
desconhecidas=$(comm -13 "$REL/$ALVO/MIGRACOES.txt" <(echo "$APLICADAS") || true)
[ -z "$desconhecidas" ] || falha "BLOQUEADO: o banco tem migrações que $ALVO não conhece ($(echo $desconhecidas)). Reverter exige restaurar backup anterior à migração."
artisan "$REL/$ALVO" config:cache --no-ansi >/dev/null; artisan "$REL/$ALVO" route:cache --no-ansi >/dev/null
trocar_para "$REL/$ALVO"; eval "$RECARREGAR_FPM"
saude && { log "REVERTIDO para $ALVO (de $ATUAL_NOME)"; echo "REVERSAO_OK $ALVO"; } || falha "revertido para $ALVO, mas a saúde falhou"
