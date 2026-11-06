<?php

namespace App\Http\Controllers\Api;

use App\Services\AuditoriaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * RF-024 — indicadores calculados no servidor, cada bloco condicionado à permissão e filtrado por escopo.
 * Não há indicadores de operações bloqueadas (acautelamento, entregas etc.): apenas a contagem de bloqueios.
 * Agregados nunca expõem identificação pessoal (matrícula, nomes de militares) nem conteúdo de documentos.
 */
class DashboardController extends ApiController
{
    public function __invoke(Request $r, AuditoriaService $auditoria): JsonResponse
    {
        $u = $r->user();
        $a = $this->authz();
        $out = [
            'gerado_em' => now()->toIso8601String(),
            'pendencias_institucionais' => 12,
            'operacoes_bloqueadas' => collect(config('sigarb.operacoes'))->where('estado', 'BLOQUEADA')->count(),
        ];

        if ($a->pode($u, 'organizacao.ver')) {
            $un = $a->escopar(DB::table('unidades')->where('ativo', true), $u, 'organizacao.ver', 'id');
            $out['unidades'] = ['ativas' => $un->count()];
        }

        if ($a->pode($u, 'catalogo.ver')) {
            $out['catalogo'] = [
                'materiais_ativos' => DB::table('materiais')->where('ativo', true)->count(),
                'por_modo' => DB::table('materiais')->where('ativo', true)->groupBy('modo_controle')->selectRaw('modo_controle as rotulo, COUNT(*) as valor')->orderBy('rotulo')->get(),
            ];
        }

        if ($a->pode($u, 'estoque.ver')) {
            $locais = $a->escopar(DB::table('locais_estoque')->select('id'), $u, 'estoque.ver');
            $out['estoque'] = [
                'locais' => DB::table('locais_estoque')->whereIn('id', $locais)->count(),
                'itens_individuais_disponiveis' => DB::table('itens_individuais')->whereIn('local_estoque_id', $locais)->where('situacao', 'DISPONIVEL')->count(),
                'lotes_com_saldo' => DB::table('saldos_estoque')->whereIn('local_estoque_id', $locais)->where('quantidade', '>', 0)->count(),
                'quantidade_em_lotes' => (int) DB::table('saldos_estoque')->whereIn('local_estoque_id', $locais)->sum('quantidade'),
                'lotes_validade_90_dias' => DB::table('saldos_estoque as s')->join('lotes as l', 'l.id', '=', 's.lote_id')
                    ->whereIn('s.local_estoque_id', $locais)->where('s.quantidade', '>', 0)
                    ->whereNotNull('l.validade')->where('l.validade', '<=', now()->addDays(90)->toDateString())->count(),
                'movimentacoes_7_dias' => DB::table('movimentacoes')->where('ocorrido_em', '>=', now()->subDays(7))
                    ->where(fn ($w) => $w->whereIn('local_destino_id', $locais)->orWhereIn('local_origem_id', $locais))->count(),
                // Quantidade física por natureza: lotes (soma) + itens individuais não estornados/baixados (contagem).
                'por_natureza' => DB::query()->fromSub(
                    DB::table('saldos_estoque as s')->join('lotes as l', 'l.id', '=', 's.lote_id')->join('materiais as m', 'm.id', '=', 'l.material_id')
                        ->join('categorias as c', 'c.id', '=', 'm.categoria_id')->whereIn('s.local_estoque_id', $locais)
                        ->selectRaw('c.natureza, SUM(s.quantidade) as q')->groupBy('c.natureza')
                        ->unionAll(DB::table('itens_individuais as i')->join('materiais as m', 'm.id', '=', 'i.material_id')->join('categorias as c', 'c.id', '=', 'm.categoria_id')
                            ->whereIn('i.local_estoque_id', $locais)->whereNotIn('i.situacao', ['ESTORNADO', 'BAIXADO'])
                            ->selectRaw('c.natureza, COUNT(*) as q')->groupBy('c.natureza')), 't')
                    ->groupBy('natureza')->selectRaw('natureza as rotulo, CAST(SUM(q) AS UNSIGNED) as valor')->orderBy('rotulo')->get(),
            ];
            $out['ultimas_movimentacoes'] = DB::table('movimentacoes as m')->join('usuarios as us', 'us.id', '=', 'm.responsavel_id')
                ->leftJoin('locais_estoque as l', 'l.id', '=', DB::raw('COALESCE(m.local_destino_id, m.local_origem_id)'))
                ->where(fn ($w) => $w->whereIn('m.local_destino_id', $locais)->orWhereIn('m.local_origem_id', $locais))
                ->orderByDesc('m.ocorrido_em')->limit(5)
                ->get(['m.id', 'm.tipo', 'm.ocorrido_em', 'us.nome as responsavel', 'l.codigo as local']);
        }

        if ($a->pode($u, 'militares.ver')) {
            $out['militares_ativos'] = $a->escopar(DB::table('militares'), $u, 'militares.ver')->where('situacao', 'ATIVO')->count();
        }

        if ($a->pode($u, 'documentos.ver')) {
            $docs = $a->escopar(DB::table('documentos'), $u, 'documentos.ver');
            $out['documentos'] = [
                'por_situacao' => (clone $docs)->groupBy('situacao')->selectRaw('situacao as rotulo, COUNT(*) as valor')->orderBy('rotulo')->get(),
                'enviados_30_dias' => (clone $docs)->where('created_at', '>=', now()->subDays(30))->count(),
            ];
        }

        if ($a->pode($u, 'auditoria.ver')) {
            $ev = $a->escopar(DB::table('eventos_auditoria'), $u, 'auditoria.ver');
            $out['auditoria'] = [
                'negados_24h' => (clone $ev)->where('resultado', 'NEGADO')->where('ocorrido_em', '>=', now()->subDay())->count(),
                'falhas_integridade_documento_30_dias' => (clone $ev)->where('acao', 'documento.integridade')->where('ocorrido_em', '>=', now()->subDays(30))->count(),
                'atividade_recente' => (clone $ev)->leftJoin('usuarios as us', 'us.id', '=', 'eventos_auditoria.usuario_id')
                    ->whereNotIn('acao', ['auditoria.consulta', 'documento.consulta'])
                    ->orderByDesc('eventos_auditoria.id')->limit(8)
                    ->get(['eventos_auditoria.id', 'eventos_auditoria.acao', 'eventos_auditoria.resultado', 'eventos_auditoria.ocorrido_em', 'us.login as ator']),
            ];
            // Verificação completa da cadeia é cara: só escopo global e com cache de 5 min.
            if ($a->global($u, 'auditoria.ver')) {
                $out['auditoria']['cadeia_integra'] = Cache::remember('sigarb:dashboard:cadeia', 300, fn () => $auditoria->verificar() === null);
            }
        }

        return response()->json($out);
    }
}
