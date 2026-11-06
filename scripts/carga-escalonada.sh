#!/usr/bin/env bash
# Carga escalonada no ambiente production-like LOCAL. Dados sintéticos.
# Uso: scripts/carga-escalonada.sh "10 25 50" [rotulo]
#   CENARIO=completo|leitura|login|escrita_mesmo_lote|escrita_lotes (padrão completo)
#   DURACAO=60s  LIMITE_LOAD=9  BASE=http://127.0.0.1:18490
# Garantias do harness:
#   - nunca apaga evidência anterior (diretório novo com carimbo de tempo);
#   - coletor de recursos e contêiner k6 são encerrados em término normal, falha do k6, SIGINT/SIGTERM;
#   - cada nível grava STATUS (COMPLETO | FALHOU_LIMIAR | FALHOU_K6 rc=N | INTERROMPIDO); métricas de nível
#     não COMPLETO são parciais e não podem ser apresentadas como resultado aprovado;
#   - para sozinho se o k6 abortar ou o load average do host passar de LIMITE_LOAD.
set -euo pipefail
RAIZ="$(cd "$(dirname "$0")/.." && pwd)"; D="$RAIZ/infra/prodlocal"
NIVEIS="${1:-10 25 50}"; ROTULO="${2:-base}"; LIMITE_LOAD="${LIMITE_LOAD:-9}"; CENARIO="${CENARIO:-completo}"
SAIDA="$RAIZ/.carga/prodlocal-$ROTULO-$(date -u +%Y%m%dT%H%M%SZ)"; mkdir -p "$SAIDA"
set -a; . "$D/.env"; set +a
dc() { docker compose -f "$D/compose.yaml" --env-file "$D/.env" "$@"; }
sql() { docker exec sigarb-pl-mysql mysql -uroot -p"$SIGARB_PL_DB_ROOT" -N -B sigarb -e "$1" 2>/dev/null; }

COL=""; K6="sigarb-k6-$$"; NIVEL_ATUAL=""
parar_coletor() {
  if [ -n "$COL" ]; then pkill -P "$COL" 2>/dev/null || true; kill "$COL" 2>/dev/null || true; wait "$COL" 2>/dev/null || true; COL=""; fi
}
limpar() {
  local rc=$?
  parar_coletor
  docker rm -f "$K6" >/dev/null 2>&1 || true
  if [ -n "$NIVEL_ATUAL" ] && [ ! -s "$SAIDA/STATUS-$NIVEL_ATUAL" ]; then echo "INTERROMPIDO rc=$rc (resultado parcial, inválido)" > "$SAIDA/STATUS-$NIVEL_ATUAL"; fi
  exit "$rc"
}
trap limpar EXIT
trap 'exit 130' INT
trap 'exit 143' TERM

# Registro reprodutível da execução
{ echo "git_sha=$(git -C "$RAIZ" rev-parse HEAD)"; echo "git_sujo=$(git -C "$RAIZ" status --porcelain | wc -l)"
  echo "rampa=${RAMPA:-30s} cenario=$CENARIO niveis=$NIVEIS duracao=${DURACAO:-60s} inicio_utc=$(date -u +%FT%TZ)"
  echo "fpm_filhos=${SIGARB_FPM_FILHOS:-12 (padrão do compose)}"; grep -E "enablereuse|ProxyTimeout|^Timeout" "$D/apache/sigarb.conf" || true
  grep -E "^(pm|request_terminate)" "$D/php/www.conf" || true
  sql "SELECT CONCAT(@@version,' flush=',@@innodb_flush_log_at_trx_commit,' sync_binlog=',@@sync_binlog,' lock_wait_timeout=',@@innodb_lock_wait_timeout,' max_conn=',@@max_connections)"
} > "$SAIDA/execucao.txt"

PREP=$(dc --profile tarefas run --rm -T migrar php artisan sigarb:carga:preparar 2>/dev/null | tail -1)
[ -n "$PREP" ] || { echo "ERRO: preparação dos dados de carga falhou"; exit 1; }
read -r LOCAL MATERIAL DOCS <<< "$PREP"

integridade() { # saldo do lote de carga, entradas registradas, duplicidade de chave, negativos e cadeia de auditoria
  echo "saldo_lote_carga=$(sql "SELECT COALESCE(SUM(s.quantidade),0) FROM saldos_estoque s JOIN lotes l ON l.id=s.lote_id WHERE l.codigo LIKE 'LOTE-CARGA-PL%'")" \
       "entradas_carga=$(sql "SELECT COUNT(*) FROM movimentacoes WHERE documento_referencia='DOC-CARGA-PL' AND tipo='ENTRADA'")" \
       "itens_carga=$(sql "SELECT COALESCE(SUM(i.quantidade),0) FROM movimentacao_itens i JOIN movimentacoes m ON m.id=i.movimentacao_id WHERE m.documento_referencia='DOC-CARGA-PL' AND m.tipo='ENTRADA'")" \
       "saldos_negativos=$(sql "SELECT COUNT(*) FROM saldos_estoque WHERE quantidade<0")" \
       "chaves_duplicadas=$(sql "SELECT COUNT(*) FROM (SELECT chave_idempotencia FROM movimentacoes WHERE chave_idempotencia IS NOT NULL GROUP BY chave_idempotencia HAVING COUNT(*)>1) x")" \
       "eventos_auditoria=$(sql "SELECT COUNT(*) FROM eventos_auditoria")" \
       "cadeia=[$(dc run --rm --no-deps -T app php artisan sigarb:auditoria:verificar 2>/dev/null | tail -1)]"
}
echo "antes: $(integridade)" | tee "$SAIDA/integridade.txt"

for N in $NIVEIS; do
  carga=$(cut -d' ' -f1 /proc/loadavg)
  if awk "BEGIN{exit !($carga > $LIMITE_LOAD)}"; then echo "PARADA: load average do host $carga > $LIMITE_LOAD antes do nível $N" | tee -a "$SAIDA/integridade.txt"; break; fi
  NIVEL_ATUAL=$N; echo "== nível $N VUs, cenário $CENARIO (load host $carga)"
  sql "FLUSH STATUS" >/dev/null || true
  slow0=$(docker exec sigarb-pl-mysql sh -c 'wc -l < /var/lib/mysql/lento.log 2>/dev/null || echo 0')
  dl0=$(sql "SELECT COUNT FROM information_schema.INNODB_METRICS WHERE NAME='lock_deadlocks'")
  lw0=$(sql "SELECT VARIABLE_VALUE FROM performance_schema.global_status WHERE VARIABLE_NAME='Innodb_row_lock_waits'")
  lt0=$(sql "SELECT VARIABLE_VALUE FROM performance_schema.global_status WHERE VARIABLE_NAME='Innodb_row_lock_time'")
  t0=$(date -u +%FT%TZ)
  ( while true; do
      ts=$(date -u +%T)
      docker stats --no-stream --format "$ts,{{.Name}},{{.CPUPerc}},{{.MemUsage}}" sigarb-pl-app sigarb-pl-mysql sigarb-pl-apache 2>/dev/null || true
      curl -s -m 2 http://127.0.0.1:18491/fpm-status | awk -F': *' -v ts="$ts" '/^(active processes|idle processes|max children reached|listen queue|max listen queue|slow requests):/{printf "%s,fpm,%s,%s\n",ts,$1,$2}' || true
      echo "$ts,db,threads_running,$(sql "SHOW GLOBAL STATUS LIKE 'Threads_running'" | cut -f2)"
      echo "$ts,db,threads_connected,$(sql "SHOW GLOBAL STATUS LIKE 'Threads_connected'" | cut -f2)"
      echo "$ts,host,load,$(cut -d' ' -f1 /proc/loadavg)"
      sleep 3 & wait $!
    done ) > "$SAIDA/recursos-$N.csv" 2>/dev/null & COL=$!
  rc=0; docker run --rm --name "$K6" --network host --user "$(id -u):$(id -g)" -v "$RAIZ/scripts/carga:/carga:ro" -v "$SAIDA:/saida" \
    -e VUS="$N" -e SENHA="$SIGARB_DEMO_PASSWORD" -e LOCAL="$LOCAL" -e MATERIAL="$MATERIAL" -e DOCS="$DOCS" -e DURACAO="${DURACAO:-60s}" \
    -e CENARIO="$CENARIO" -e RAMPA="${RAMPA:-30s}" -e BASE="${BASE:-http://127.0.0.1:18490}" \
    grafana/k6:1.3.0 run --quiet --summary-export "/saida/resumo-$N.json" --out "csv=/saida/requisicoes-$N.csv.gz" \
    /carga/escalonado.js > "$SAIDA/k6-$N.txt" 2>&1 || rc=$?
  parar_coletor
  slow1=$(docker exec sigarb-pl-mysql sh -c 'wc -l < /var/lib/mysql/lento.log 2>/dev/null || echo 0')
  dl1=$(sql "SELECT COUNT FROM information_schema.INNODB_METRICS WHERE NAME='lock_deadlocks'")
  lw1=$(sql "SELECT VARIABLE_VALUE FROM performance_schema.global_status WHERE VARIABLE_NAME='Innodb_row_lock_waits'")
  lt1=$(sql "SELECT VARIABLE_VALUE FROM performance_schema.global_status WHERE VARIABLE_NAME='Innodb_row_lock_time'")
  fpm_mortos=$(docker logs --since "$t0" sigarb-pl-app 2>&1 | grep -c "execution timed out" || true)
  echo "max_used_connections=$(sql "SHOW GLOBAL STATUS LIKE 'Max_used_connections'" | cut -f2) linhas_slowlog=$((slow1-slow0)) deadlocks=$((dl1-dl0)) row_lock_waits=$((lw1-lw0)) row_lock_time_ms=$((lt1-lt0)) fpm_workers_terminados_timeout=$fpm_mortos k6_rc=$rc" > "$SAIDA/banco-$N.txt"
  cat "$SAIDA/banco-$N.txt"
  echo "nível $N: $(integridade)" | tee -a "$SAIDA/integridade.txt"
  case $rc in
    0)  echo "COMPLETO" > "$SAIDA/STATUS-$N" ;;
    99) echo "FALHOU_LIMIAR rc=99 (k6 abortou: erro > 5% ou p95 > 5 s; resultado parcial)" > "$SAIDA/STATUS-$N" ;;
    *)  echo "FALHOU_K6 rc=$rc (resultado inválido)" > "$SAIDA/STATUS-$N" ;;
  esac
  NIVEL_ATUAL=""; cat "$SAIDA/STATUS-$N"
  [ $rc -eq 0 ] || { echo "PARADA: nível $N não completou (rc=$rc)"; FALHA=1; break; }
  sleep 10
done
echo "saida: $SAIDA"; [ "${FALHA:-0}" -eq 0 ] || exit 1
