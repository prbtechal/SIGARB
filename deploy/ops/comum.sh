# SIGARB — funções comuns de implantação. Carregado por preflight.sh, implantar.sh e reverter.sh.
# Variáveis (com padrões para a VM .8; sobrescreva no ambiente):
#   BASE=/var/www/app/sigarb          raiz: releases/, shared/, current
#   PHP_BIN=php8.5                    binário PHP da VM
#   USUARIO_APP=sigarb                identidade do pool FPM (nologin)
#   RECARREGAR_FPM="systemctl reload php8.5-fpm"   (reload, nunca restart: preserva os outros pools)
#   URL_SAUDE=https://sigarb.prb.intra/api/v1/health
#   DISCO_MINIMO_MB=2048
set -euo pipefail
export LC_COLLATE=C   # ordem estável para comm/sort (MIGRACOES.txt é gerado com a mesma regra)
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
migracoes_banco() { # migrações REGISTRADAS NO BANCO (tabela migrations), lidas com a identidade de runtime.
  # Não usar migrate:status: ele só lista migrações cujos arquivos existem na release, escondendo justamente
  # as que o banco tem e a release não conhece (banco à frente do código).
  (cd "$1/backend" && "$PHP_BIN" -r '
    require "vendor/autoload.php"; $a = require "bootstrap/app.php";
    $a->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    if (! Illuminate\Support\Facades\Schema::hasTable("migrations")) exit(0);
    foreach (Illuminate\Support\Facades\DB::table("migrations")->orderBy("migration")->pluck("migration") as $m) echo $m, PHP_EOL;') | LC_ALL=C sort
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
cachear() { # config/route cache com segredos (senha do banco, APP_KEY): nunca legível por outros.
  local r="$1" f
  ( umask 027; artisan "$r" config:cache --no-ansi >/dev/null && artisan "$r" route:cache --no-ansi >/dev/null )
  for f in "$r"/backend/bootstrap/cache/*.php; do
    chgrp "$USUARIO_APP" "$f" && chmod 0640 "$f" || falha "não foi possível proteger $f (grupo $USUARIO_APP existe?)"
  done
  [ -z "$(find "$r/backend/bootstrap/cache" -name '*.php' -perm /o=rwx)" ] || falha "cache de configuração legível por outros"
}
trocar_para() { # troca atômica do symlink current e registro no histórico
  local alvo="$1"
  ln -sfn "$alvo" "$BASE/current.novo" && mv -Tf "$BASE/current.novo" "$ATUAL"
  echo "$(date -u +%FT%TZ) $(basename "$alvo")" >> "$HIST"
}
