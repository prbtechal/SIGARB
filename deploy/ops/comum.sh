# SIGARB — funções comuns de implantação. Carregado por preflight.sh, implantar.sh e reverter.sh.
# Variáveis (com padrões para a VM .8; sobrescreva no ambiente):
#   BASE=/var/www/app/sigarb          raiz: releases/, shared/, current
#   PHP_BIN=php8.5                    binário PHP da VM
#   USUARIO_APP=sigarb                identidade do pool FPM (nologin)
#   RECARREGAR_FPM="systemctl reload php8.5-fpm"   (reload, nunca restart: preserva os outros pools)
#   URL_SAUDE=https://sigarb.prb.intra/api/v1/health
#   DISCO_MINIMO_MB=2048
set -euo pipefail
BASE="${BASE:-/var/www/app/sigarb}"; PHP_BIN="${PHP_BIN:-php8.5}"; USUARIO_APP="${USUARIO_APP:-sigarb}"
RECARREGAR_FPM="${RECARREGAR_FPM:-systemctl reload php8.5-fpm}"; URL_SAUDE="${URL_SAUDE:-https://sigarb.prb.intra/api/v1/health}"
DISCO_MINIMO_MB="${DISCO_MINIMO_MB:-2048}"
REL="$BASE/releases"; SH="$BASE/shared"; ATUAL="$BASE/current"; HIST="$BASE/releases/HISTORICO"
log() { echo "[$(date -u +%FT%TZ)] $*" | tee -a "$BASE/implantacao.log" >&2; }
falha() { log "FALHA: $*"; exit 1; }

artisan() { # artisan da release indicada, com o .env compartilhado (runtime: sigarb_app)
  local r="$1"; shift; (cd "$r/backend" && "$PHP_BIN" artisan "$@")
}
artisan_migr() { # mesma release, credencial de MIGRAÇÃO (shared/migr.env, 600, só na janela)
  local r="$1"; shift
  [ -f "$SH/migr.env" ] || falha "shared/migr.env ausente: migração exige credencial de migração explícita"
  # Sem config em cache: senão o artisan usaria a credencial de runtime do cache em vez da de migração.
  rm -f "$r/backend/bootstrap/cache/config.php"
  ( set -a; . "$SH/migr.env"; set +a; cd "$r/backend" && "$PHP_BIN" artisan "$@" )
}
migracoes_banco() { # lista de migrações aplicadas (consulta somente leitura com a identidade de runtime)
  local s; s=$(artisan "$1" migrate:status --no-ansi 2>&1) || { echo "$s" | grep -qi "table not found" && return 0; echo "$s" >&2; return 1; }
  echo "$s" | awk '/Ran/{print $1}' | sort
}
saude() { # live 200 e ready 200, com tentativas
  local i
  for i in $(seq 1 "${TENTATIVAS_SAUDE:-10}"); do
    if [ "$(curl -s -o /dev/null -w '%{http_code}' -m 5 "$URL_SAUDE/live")" = 200 ] && \
       [ "$(curl -s -o /dev/null -w '%{http_code}' -m 5 "$URL_SAUDE/ready")" = 200 ]; then return 0; fi
    sleep 2
  done
  return 1
}
trocar_para() { # troca atômica do symlink current e registro no histórico
  local alvo="$1"
  ln -sfn "$alvo" "$BASE/current.novo" && mv -Tf "$BASE/current.novo" "$ATUAL"
  echo "$(date -u +%FT%TZ) $(basename "$alvo")" >> "$HIST"
}
