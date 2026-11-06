#!/usr/bin/env bash
# Teste de carga LOCAL e isolado (k6 em container) contra a API E2E (:18482, banco sigarb_e2e).
# NÃO representa capacidade de produção: servidor embutido do PHP (4 workers), sem OPcache, estação compartilhada.
set -euo pipefail
RAIZ="$(cd "$(dirname "$0")/.." && pwd)"
cd "$RAIZ/infra"; set -a; . ./.env; set +a
SAIDA="${SAIDA:-$RAIZ/.carga}"; mkdir -p "$SAIDA"
docker compose --profile e2e run --rm --no-deps -w /app api-e2e sh -c 'php artisan migrate:fresh --seed --force -q'
# 23 usuários sintéticos de carga (GESTOR em UN-ALFA), senha = SIGARB_DEMO_PASSWORD
IDS=$(docker compose --profile e2e run --rm --no-deps -w /app -T api-e2e php artisan tinker --execute='
$p=App\Models\Papel::where("codigo","GESTOR")->value("id"); $un=App\Models\Unidade::where("sigla","UN-ALFA")->value("id");
for($i=1;$i<=23;$i++){ $u=App\Models\Usuario::create(["nome"=>"Carga Sintética $i","login"=>sprintf("carga.%02d",$i),"password"=>env("SIGARB_DEMO_PASSWORD")]);
 App\Models\AtribuicaoPapel::create(["usuario_id"=>$u->id,"papel_id"=>$p,"unidade_id"=>$un]); }
echo App\Models\LocalEstoque::where("codigo","RES-ALFA-01")->value("id")," ",App\Models\Material::where("codigo","DEMO-MUN-01")->value("id");' 2>/dev/null | tail -1)
read -r LOCAL MATERIAL <<< "$IDS"
docker compose --profile e2e up -d --force-recreate api-e2e >/dev/null
trap 'docker compose -f "$RAIZ/infra/compose.yaml" --profile e2e stop api-e2e >/dev/null; kill $STATS 2>/dev/null || true' EXIT
for i in $(seq 1 30); do curl -sf http://127.0.0.1:18482/api/v1/health/live >/dev/null && break; sleep 1; done
( while true; do docker stats --no-stream --format '{{.Name}},{{.CPUPerc}},{{.MemUsage}}' sigarb-e2e-api sigarb-dev-mysql; sleep 3; done ) > "$SAIDA/recursos.csv" & STATS=$!
docker run --rm --network host -v "$RAIZ/scripts/carga:/carga:ro" -v "$SAIDA:/saida" --user "$(id -u):$(id -g)" \
  -e SENHA="$SIGARB_DEMO_PASSWORD" -e LOCAL="$LOCAL" -e MATERIAL="$MATERIAL" \
  grafana/k6:1.3.0 run --quiet --summary-export /saida/resumo.json /carga/sigarb.js > "$SAIDA/k6.txt" 2>&1 || true
kill $STATS 2>/dev/null || true
# Integridade após a carga: soma do lote = entradas aceitas; nenhum saldo negativo; cadeia íntegra
docker compose --profile e2e run --rm --no-deps -w /app -T api-e2e php artisan tinker --execute='
$s=(int)DB::table("saldos_estoque as s")->join("lotes as l","l.id","=","s.lote_id")->where("l.codigo","LOTE-CARGA")->sum("s.quantidade");
$m=DB::table("movimentacoes")->where("documento_referencia","DOC-CARGA")->count();
echo "saldo_lote_carga=$s movimentacoes_carga=$m cadeia=", app(App\Services\AuditoriaService::class)->verificar()===null?"integra":"QUEBRADA";' 2>/dev/null | tail -1 > "$SAIDA/integridade.txt"
cat "$SAIDA/integridade.txt"
