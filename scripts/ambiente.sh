# SIGARB — funções comuns dos scripts locais (sem Docker; decisão do responsável, 2026-11-19). Uso: . scripts/ambiente.sh
# - Segredos locais só em backend/.env (ignorado pelo Git): DB_PASSWORD, SIGARB_DEMO_PASSWORD, SIGARB_DB_APP_PASSWORD,
#   SIGARB_DB_MIGR_PASSWORD. Não existe mais infra/.env.
# - MYSQL_ADMIN: cliente com acesso administrativo ao MySQL LOCAL (padrão `mysql`, ex.: usuário do sistema via auth_socket;
#   ou MYSQL_ADMIN="mysql -uroot -p"). Usado só para os bancos sigarb* e os usuários sigarb*.
RAIZ="${RAIZ:-$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)}"
ENV_BACKEND="$RAIZ/backend/.env"

# env_backend NOME [padrão] — valor de NOME no backend/.env (sem aspas e sem comentário no fim), ou o padrão.
env_backend() {
  local v=""
  [ -f "$ENV_BACKEND" ] && v=$(grep -E "^$1=" "$ENV_BACKEND" | tail -n1 | cut -d= -f2- | sed -e 's/[[:space:]]\{1,\}#.*$//' -e "s/^['\"]//" -e "s/['\"]$//")
  printf '%s' "${v:-${2:-}}"
}

# Exporta o que os scripts precisam; o ambiente já definido tem precedência sobre o backend/.env.
carregar_ambiente() {
  [ -f "$ENV_BACKEND" ] || { echo "ERRO: crie backend/.env a partir de backend/.env.example e rode database/dev/preparar.sh"; exit 1; }
  export SIGARB_DEMO_PASSWORD="${SIGARB_DEMO_PASSWORD:-$(env_backend SIGARB_DEMO_PASSWORD)}"
  export SIGARB_DB_PASSWORD="${SIGARB_DB_PASSWORD:-$(env_backend DB_PASSWORD)}"
  export SIGARB_DB_APP_PASSWORD="${SIGARB_DB_APP_PASSWORD:-$(env_backend SIGARB_DB_APP_PASSWORD)}"
  export SIGARB_DB_MIGR_PASSWORD="${SIGARB_DB_MIGR_PASSWORD:-$(env_backend SIGARB_DB_MIGR_PASSWORD)}"
  MYSQL_ADMIN="${MYSQL_ADMIN:-mysql}"
}

# mysql_admin [args...] — cliente administrativo local (stderr descartado, como nos scripts anteriores).
mysql_admin() { ${MYSQL_ADMIN:-mysql} "$@" 2>/dev/null; }

# API local no servidor embutido do PHP (o mesmo do ambiente anterior; limites de upload = antigo infra/php/sigarb.ini).
# Variáveis passadas aqui (DB_DATABASE etc.) prevalecem sobre o backend/.env.
# Uso: subir_api <porta> <arquivo-pid> [VAR=valor ...]   — processo em grupo próprio (setsid), encerrado por parar_api.
subir_api() {
  local porta="$1" pid="$2"; shift 2
  mkdir -p "$RAIZ/backend/storage/logs"
  ( cd "$RAIZ/backend/public" && exec setsid env PHP_CLI_SERVER_WORKERS="${PHP_CLI_SERVER_WORKERS:-4}" "$@" \
      php -d upload_max_filesize=11M -d post_max_size=12M -d max_file_uploads=1 -d expose_php=Off \
      -S "127.0.0.1:$porta" ../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php ) \
    > "$RAIZ/backend/storage/logs/php-server-$porta.log" 2>&1 < /dev/null &
  echo $! > "$pid"
  for _ in $(seq 1 30); do curl -sf "http://127.0.0.1:$porta/up" >/dev/null && return 0; sleep 1; done
  echo "ERRO: API em :$porta não respondeu (ver backend/storage/logs/php-server-$porta.log)"; return 1
}
# parar_api <arquivo-pid> — encerra o grupo de processos (servidor + workers).
parar_api() {
  [ -s "$1" ] || return 0
  local p; p=$(cat "$1"); kill -- "-$p" 2>/dev/null || kill "$p" 2>/dev/null || true; rm -f "$1"; return 0
}
