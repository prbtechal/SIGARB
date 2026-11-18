<?php

namespace App\Http\Controllers\Api;

use App\Support\GrupoMaterial;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Painel "Início" por tipo de material (decisão do responsável, 2026-11-18): números objetivos no escopo do usuário (armeiro = sua base).
 * Uma consulta agregada por bloco — disponível/validade, com efetivos, entradas/saídas, pedidos, entregas atrasadas —, cada uma
 * agrupada pelo grupo do catálogo oficial (App\Support\GrupoMaterial). Sem N+1 e sem dado pessoal: só contagens.
 *
 * Definições:
 *  - disponível: item individual DISPONIVEL + saldo de lote (quantidade − reservada) nos locais do escopo (`estoque.ver`);
 *  - com efetivos: o que está entregue/acautelado e ainda não voltou (mesma fonte da tela "Entrega de material");
 *  - entradas: ENTRADA e DEVOLUCAO com destino no escopo; saídas: ENTREGA, SAIDA e BAIXA com origem no escopo (hoje e 7 dias, horário de Maceió);
 *  - vencidos / vencendo: material disponível com validade passada / até `sigarb.alertas.validade_item_dias` dias.
 */
class PainelMaterialController extends ApiController
{
    private const ENTRADAS = ['ENTRADA', 'DEVOLUCAO'];

    private const SAIDAS = ['ENTREGA', 'SAIDA', 'BAIXA'];

    public function __invoke(Request $r): JsonResponse
    {
        $this->exigir('estoque.ver');
        $u = $r->user();
        $a = $this->authz();
        $agora = CarbonImmutable::now(RelatorioEntregasController::FUSO);
        $hoje = $agora->toDateString();
        $limite = $agora->addDays((int) config('sigarb.alertas.validade_item_dias', 30))->toDateString();
        $inicioHoje = $agora->startOfDay()->utc()->toDateTimeString();
        $inicio7 = $agora->startOfDay()->subDays(6)->utc()->toDateTimeString();
        $grupo = GrupoMaterial::sql('c', 't');
        $locais = $a->escopar(DB::table('locais_estoque')->select('id'), $u, 'estoque.ver');

        // 1. Disponível e validade (itens individuais + lotes) — uma consulta.
        $itens = DB::table('itens_individuais as i')->where('i.situacao', 'DISPONIVEL')->whereIn('i.local_estoque_id', $locais)
            ->selectRaw('i.material_id, 1 as q, i.validade');
        $lotes = DB::table('saldos_estoque as s')->join('lotes as l', 'l.id', '=', 's.lote_id')->whereIn('s.local_estoque_id', $locais)
            ->whereRaw('s.quantidade - s.quantidade_reservada > 0')->selectRaw('l.material_id, s.quantidade - s.quantidade_reservada as q, l.validade');
        $disp = DB::query()->fromSub($itens->unionAll($lotes), 'x')->join('materiais as m', 'm.id', '=', 'x.material_id')
            ->join('categorias as c', 'c.id', '=', 'm.categoria_id')->leftJoin('tipos_material as t', 't.id', '=', 'm.tipo_id')
            ->groupByRaw($grupo)
            ->selectRaw("$grupo as grupo, CAST(SUM(x.q) AS SIGNED) as disponiveis,
                CAST(SUM(CASE WHEN x.validade IS NOT NULL AND x.validade < ? THEN x.q ELSE 0 END) AS SIGNED) as vencidos,
                CAST(SUM(CASE WHEN x.validade IS NOT NULL AND x.validade BETWEEN ? AND ? THEN x.q ELSE 0 END) AS SIGNED) as vencendo,
                CAST(SUM(CASE WHEN x.validade IS NOT NULL THEN 1 ELSE 0 END) AS SIGNED) as com_validade", [$hoje, $hoje, $limite])
            ->get()->keyBy('grupo');

        // 2. Com efetivos (entregas pendentes + acautelamentos ativos) — uma consulta sobre a fonte da tela "Entrega de material".
        $vinc = VinculoMaterialController::consulta();
        $a->escopar($vinc, $u, 'estoque.ver', 'v.unidade_id');
        $comEfetivos = $vinc->reorder()->groupByRaw($grupo)
            ->select(DB::raw("$grupo as grupo, CAST(SUM(v.quantidade) AS SIGNED) as quantidade, COUNT(DISTINCT v.militar_id) as efetivos"))
            ->get()->keyBy('grupo');

        // 3. Entradas e saídas (hoje e 7 dias) — uma consulta.
        $ids = (clone $locais)->pluck('id')->map(fn ($i) => (int) $i)->all() ?: [0];
        $in = implode(',', $ids);
        $tiposE = "'".implode("','", self::ENTRADAS)."'";
        $tiposS = "'".implode("','", self::SAIDAS)."'";
        $entrada = "(mv.tipo IN ($tiposE) AND mv.local_destino_id IN ($in))";
        $saida = "(mv.tipo IN ($tiposS) AND mv.local_origem_id IN ($in))";
        $fluxo = DB::table('movimentacoes as mv')->join('movimentacao_itens as mi', 'mi.movimentacao_id', '=', 'mv.id')
            ->leftJoin('itens_individuais as i', 'i.id', '=', 'mi.item_individual_id')->leftJoin('lotes as l', 'l.id', '=', 'mi.lote_id')
            ->join('materiais as m', 'm.id', '=', DB::raw('COALESCE(i.material_id, l.material_id)'))
            ->join('categorias as c', 'c.id', '=', 'm.categoria_id')->leftJoin('tipos_material as t', 't.id', '=', 'm.tipo_id')
            ->where('mv.ocorrido_em', '>=', $inicio7)->whereRaw("($entrada OR $saida)")
            ->groupByRaw($grupo)
            ->selectRaw("$grupo as grupo,
                CAST(SUM(CASE WHEN $entrada AND mv.ocorrido_em >= ? THEN mi.quantidade ELSE 0 END) AS SIGNED) as entradas_hoje,
                CAST(SUM(CASE WHEN $saida AND mv.ocorrido_em >= ? THEN mi.quantidade ELSE 0 END) AS SIGNED) as saidas_hoje,
                CAST(SUM(CASE WHEN $entrada THEN mi.quantidade ELSE 0 END) AS SIGNED) as entradas_7_dias,
                CAST(SUM(CASE WHEN $saida THEN mi.quantidade ELSE 0 END) AS SIGNED) as saidas_7_dias", [$inicioHoje, $inicioHoje])
            ->get()->keyBy('grupo');

        $grupos = collect(GrupoMaterial::ROTULOS)->map(function ($rotulo, $chave) use ($disp, $comEfetivos, $fluxo) {
            $d = $disp->get($chave);
            $e = $comEfetivos->get($chave);
            $f = $fluxo->get($chave);

            return [
                'chave' => $chave, 'rotulo' => $rotulo,
                'disponiveis' => (int) ($d->disponiveis ?? 0), 'vencidos' => (int) ($d->vencidos ?? 0), 'vencendo' => (int) ($d->vencendo ?? 0),
                'tem_validade' => (int) ($d->com_validade ?? 0) > 0,
                'com_efetivos' => (int) ($e->quantidade ?? 0), 'efetivos' => (int) ($e->efetivos ?? 0),
                'entradas_hoje' => (int) ($f->entradas_hoje ?? 0), 'saidas_hoje' => (int) ($f->saidas_hoje ?? 0),
                'entradas_7_dias' => (int) ($f->entradas_7_dias ?? 0), 'saidas_7_dias' => (int) ($f->saidas_7_dias ?? 0),
            ];
        })->values();

        // 4. Operação do dia — pedidos (pedidos.gerir) e entregas com devolução atrasada (estoque.ver).
        $atrasadas = VinculoMaterialController::consulta();
        $a->escopar($atrasadas, $u, 'estoque.ver', 'v.unidade_id');
        $operacao = ['entregas_atrasadas' => (int) $atrasadas->reorder()->where('v.custodia', 'ENTREGA')->whereNotNull('v.previsao_retorno')
            ->where('v.previsao_retorno', '<', now()->toDateString())->select(DB::raw('COUNT(DISTINCT v.origem) as n'))->value('n')];
        if ($a->pode($u, 'pedidos.gerir')) {
            $ped = $a->escopar(DB::table('pedidos_material'), $u, 'pedidos.gerir')->whereIn('status', ['PENDENTE', 'APROVADO'])
                ->selectRaw("SUM(status = 'PENDENTE') as pendentes, SUM(status = 'APROVADO') as aprovados, MIN(CASE WHEN status = 'PENDENTE' THEN created_at END) as pendente_mais_antigo")
                ->first();
            $operacao += [
                'pedidos_pendentes' => (int) ($ped->pendentes ?? 0), 'pedidos_aprovados' => (int) ($ped->aprovados ?? 0),
                'pendente_mais_antigo' => $ped?->pendente_mais_antigo ? CarbonImmutable::parse($ped->pendente_mais_antigo, 'UTC')->toIso8601String() : null,
            ];
        }

        return response()->json(['gerado_em' => now()->toIso8601String(), 'validade_dias' => (int) config('sigarb.alertas.validade_item_dias', 30),
            'grupos' => $grupos, 'operacao' => $operacao]);
    }
}
