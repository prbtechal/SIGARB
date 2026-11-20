#!/usr/bin/env bash
# Teste de carga LOCAL e isolado contra a API E2E (:18482, banco sigarb_e2e), sem Docker (decisão do responsável, 2026-11-19).
# Requer o k6 instalado no host (binário oficial, ex.: ~/.local/bin/k6; a série anterior usou o k6 1.3.0).
# NÃO representa capacidade de produção: servidor embutido do PHP (4 workers), sem OPcache, estação compartilhada.
set -euo pipefail
RAIZ="$(cd "$(dirname "$0")/.." && pwd)"
. "$RAIZ/scripts/ambiente.sh"; carregar_ambiente
command -v k6 >/dev/null || { echo "ERRO: k6 não encontrado no PATH (instale o binário oficial do k6; sem Docker)."; exit 2; }
SAIDA="${SAIDA:-$RAIZ/.carga}"; mkdir -p "$SAIDA"
E2E_ENV=(DB_HOST=127.0.0.1 DB_DATABASE=sigarb_e2e CACHE_STORE=database APP_DEBUG=false)
art() { (cd "$RAIZ/backend" && env "${E2E_ENV[@]}" php artisan "$@"); }
art migrate:fresh --seed --force -q
# 23 usuários sintéticos de carga (GESTOR em UN-ALFA), senha = SIGARB_DEMO_PASSWORD
IDS=$(art tinker --execute='
$p=App\Models\Papel::where("codigo","GESTOR")->value("id"); $un=App\Models\Unidade::where("sigla","UN-ALFA")->value("id");
for($i=1;$i<=23;$i++){ $u=App\Models\Usuario::create(["nome"=>"Carga Sintética $i","login"=>sprintf("carga.%02d",$i),"password"=>env("SIGARB_DEMO_PASSWORD")]);
 App\Models\AtribuicaoPapel::create(["usuario_id"=>$u->id,"papel_id"=>$p,"unidade_id"=>$un]); }
echo App\Models\LocalEstoque::where("codigo","RES-ALFA-01")->value("id")," ",App\Models\Material::where("codigo","DEMO-MUN-01")->value("id");' 2>/dev/null | tail -1)
read -r LOCAL MATERIAL <<< "$IDS"
PID="$RAIZ/backend/storage/logs/.api-18482.pid"; parar_api "$PID"
subir_api 18482 "$PID" "${E2E_ENV[@]}"
# Recursos do processo da API (grupo do servidor embutido) e do mysqld, a cada 3 s.
( while true; do ps -o pid=,pcpu=,rss=,comm= -g "$(cat "$PID")" 2>/dev/null | awk -v t="$(date +%T)" '{print t",api,"$1","$2"%,"$3"KiB"}'
    ps -o pid=,pcpu=,rss=,comm= -C mysqld 2>/dev/null | awk -v t="$(date +%T)" '{print t",mysqld,"$1","$2"%,"$3"KiB"}'; sleep 3; done ) > "$SAIDA/recursos.csv" & STATS=$!
trap 'parar_api "$PID"; kill $STATS 2>/dev/null || true' EXIT
SENHA="$SIGARB_DEMO_PASSWORD" LOCAL="$LOCAL" MATERIAL="$MATERIAL" \
  k6 run --quiet --summary-export "$SAIDA/resumo.json" "$RAIZ/scripts/carga/sigarb.js" > "$SAIDA/k6.txt" 2>&1 || true
kill $STATS 2>/dev/null || true
# Integridade após a carga: soma do lote = entradas aceitas; nenhum saldo negativo; cadeia íntegra
art tinker --execute='
$s=(int)DB::table("saldos_estoque as s")->join("lotes as l","l.id","=","s.lote_id")->where("l.codigo","LOTE-CARGA")->sum("s.quantidade");
$m=DB::table("movimentacoes")->where("documento_referencia","DOC-CARGA")->count();
echo "saldo_lote_carga=$s movimentacoes_carga=$m cadeia=", app(App\Services\AuditoriaService::class)->verificar()===null?"integra":"QUEBRADA";' 2>/dev/null | tail -1 > "$SAIDA/integridade.txt"
cat "$SAIDA/integridade.txt"
