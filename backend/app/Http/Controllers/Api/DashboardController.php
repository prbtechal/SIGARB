<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** RF-024 (parcial): indicadores no escopo autorizado. Sem gráficos/alertas configuráveis ainda (RF-022, RN-023). */
class DashboardController extends ApiController
{
    public function __invoke(Request $r): JsonResponse
    {
        $u = $r->user();
        $out = ['pendencias_institucionais' => 12, 'operacoes_bloqueadas' => collect(config('sigarb.operacoes'))->where('estado', 'BLOQUEADA')->count()];

        if ($this->authz()->pode($u, 'estoque.ver')) {
            $locais = $this->authz()->escopar(DB::table('locais_estoque')->select('id'), $u, 'estoque.ver');
            $out['estoque'] = [
                'locais' => DB::table('locais_estoque')->whereIn('id', $locais)->count(),
                'itens_individuais_disponiveis' => DB::table('itens_individuais')->whereIn('local_estoque_id', $locais)->where('situacao', 'DISPONIVEL')->count(),
                'lotes_com_saldo' => DB::table('saldos_estoque')->whereIn('local_estoque_id', $locais)->where('quantidade', '>', 0)->count(),
                'quantidade_em_lotes' => (int) DB::table('saldos_estoque')->whereIn('local_estoque_id', $locais)->sum('quantidade'),
                // Informativo; não é o mecanismo de alertas do RF-022 (regra configurada pendente).
                'lotes_validade_90_dias' => DB::table('saldos_estoque as s')->join('lotes as l', 'l.id', '=', 's.lote_id')
                    ->whereIn('s.local_estoque_id', $locais)->where('s.quantidade', '>', 0)
                    ->whereNotNull('l.validade')->where('l.validade', '<=', now()->addDays(90)->toDateString())->count(),
                'movimentacoes_7_dias' => DB::table('movimentacoes')->where('ocorrido_em', '>=', now()->subDays(7))
                    ->where(fn ($w) => $w->whereIn('local_destino_id', $locais)->orWhereIn('local_origem_id', $locais))->count(),
            ];
            $out['ultimas_movimentacoes'] = DB::table('movimentacoes as m')->join('usuarios as us', 'us.id', '=', 'm.responsavel_id')
                ->leftJoin('locais_estoque as l', 'l.id', '=', DB::raw('COALESCE(m.local_destino_id, m.local_origem_id)'))
                ->where(fn ($w) => $w->whereIn('m.local_destino_id', $locais)->orWhereIn('m.local_origem_id', $locais))
                ->orderByDesc('m.ocorrido_em')->limit(5)
                ->get(['m.id', 'm.tipo', 'm.ocorrido_em', 'us.nome as responsavel', 'l.codigo as local']);
        }
        if ($this->authz()->pode($u, 'militares.ver')) {
            $out['militares_ativos'] = $this->authz()->escopar(DB::table('militares'), $u, 'militares.ver')->where('situacao', 'ATIVO')->count();
        }

        return response()->json($out);
    }
}
