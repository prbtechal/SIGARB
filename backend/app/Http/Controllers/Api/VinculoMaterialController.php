<?php

namespace App\Http\Controllers\Api;

use App\Support\PerfilMaterial;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * CP-12 — RF-VIN-06: material atualmente sob responsabilidade de militares, derivado das fontes de verdade existentes
 * (acautelamentos ATIVO + entregas com retorno ainda não devolvidas). Somente leitura; nenhuma tabela nova.
 * Escopo na query: unidade do acautelamento / do local de origem da entrega, com `estoque.ver`. Matrícula não é exposta.
 */
class VinculoMaterialController extends ApiController
{
    public function index(Request $r): JsonResponse
    {
        $this->exigir('estoque.ver');
        $f = $r->validate([
            'militar_id' => ['nullable', 'integer'], 'unidade_id' => ['nullable', 'integer'], 'categoria_id' => ['nullable', 'integer'],
            'tipo_id' => ['nullable', 'integer'], 'perfil' => ['nullable', Rule::in(PerfilMaterial::perfis())],
            'modo_controle' => ['nullable', 'in:INDIVIDUAL,LOTE'], 'custodia' => ['nullable', 'in:ACAUTELAMENTO,ENTREGA'],
            'situacao' => ['nullable', 'in:ACAUTELADO,ENTREGUE,MANUTENCAO,RESERVADO'],
        ]);

        $acaut = DB::table('acautelamentos as a')->join('itens_individuais as i', 'i.id', '=', 'a.item_individual_id')->where('a.estado', 'ATIVO')
            ->selectRaw("'ACAUTELAMENTO' as custodia, a.militar_id, a.unidade_id, i.material_id, i.id as item_individual_id, NULL as lote_id, 1 as quantidade,
                i.identificador as referencia, i.situacao, i.estado_conservacao, i.validade, a.updated_at as desde, a.codigo as origem");
        $entregas = DB::table('movimentacoes as mv')->join('movimentacao_itens as mi', 'mi.movimentacao_id', '=', 'mv.id')
            ->join('locais_estoque as lo', 'lo.id', '=', 'mv.local_origem_id')
            ->leftJoin('itens_individuais as i', 'i.id', '=', 'mi.item_individual_id')->leftJoin('lotes as l', 'l.id', '=', 'mi.lote_id')
            ->where('mv.tipo', 'ENTREGA')->whereNotNull('mv.militar_id')->whereNotNull('mv.previsao_retorno')
            ->whereNotExists(fn ($q) => $q->from('movimentacoes as c')->whereColumn('c.vinculo_id', 'mv.id'))
            ->whereNotExists(fn ($q) => $q->from('acautelamentos as ac')->whereColumn('ac.entrega_id', 'mv.id')) // já contado acima
            ->selectRaw("'ENTREGA' as custodia, mv.militar_id, lo.unidade_id, COALESCE(i.material_id, l.material_id) as material_id, mi.item_individual_id, mi.lote_id, mi.quantidade,
                COALESCE(i.identificador, l.codigo) as referencia, i.situacao, i.estado_conservacao, COALESCE(i.validade, l.validade) as validade, mv.ocorrido_em as desde, CAST(mv.id AS CHAR) as origem");

        $q = DB::query()->fromSub($acaut->unionAll($entregas), 'v')
            ->join('militares as mil', 'mil.id', '=', 'v.militar_id')->join('postos_graduacoes as pg', 'pg.id', '=', 'mil.posto_graduacao_id')
            ->join('materiais as m', 'm.id', '=', 'v.material_id')->join('categorias as c', 'c.id', '=', 'm.categoria_id')
            ->leftJoin('tipos_material as t', 't.id', '=', 'm.tipo_id')->join('unidades as u', 'u.id', '=', 'v.unidade_id')
            ->select(['v.*', 'mil.nome_guerra', 'pg.sigla as posto', 'm.codigo as material_codigo', 'm.nome as material_nome', 'm.modo_controle',
                'c.id as categoria_id', 'c.nome as categoria', 't.id as tipo_id', 't.nome as tipo', 't.perfil', 'u.sigla as unidade_sigla']);
        $this->authz()->escopar($q, $r->user(), 'estoque.ver', 'v.unidade_id');
        foreach (['militar_id' => 'v.militar_id', 'unidade_id' => 'v.unidade_id', 'categoria_id' => 'c.id', 'tipo_id' => 't.id', 'perfil' => 't.perfil',
            'modo_controle' => 'm.modo_controle', 'custodia' => 'v.custodia', 'situacao' => 'v.situacao'] as $k => $col) {
            if (isset($f[$k])) {
                $q->where($col, $f[$k]);
            }
        }
        $p = $q->orderByDesc('v.desde')->orderBy('v.referencia')->paginate(min(100, max(1, (int) $r->query('per_page', 20))));

        return response()->json([
            'data' => collect($p->items())->map(fn ($v) => [
                'custodia' => $v->custodia, 'origem' => $v->origem, 'desde' => $v->desde,
                'militar' => ['id' => $v->militar_id, 'posto' => $v->posto, 'nome_guerra' => $v->nome_guerra],
                'unidade' => ['id' => $v->unidade_id, 'sigla' => $v->unidade_sigla],
                'material' => ['id' => $v->material_id, 'codigo' => $v->material_codigo, 'nome' => $v->material_nome, 'modo_controle' => $v->modo_controle,
                    'categoria' => $v->categoria, 'tipo' => $v->tipo, 'perfil' => $v->perfil],
                'item_individual_id' => $v->item_individual_id, 'lote_id' => $v->lote_id, 'referencia' => $v->referencia,
                'quantidade' => (int) $v->quantidade, 'situacao' => $v->situacao, 'estado_conservacao' => $v->estado_conservacao, 'validade' => $v->validade,
            ]),
            'meta' => ['page' => $p->currentPage(), 'per_page' => $p->perPage(), 'total' => $p->total(), 'last_page' => $p->lastPage()],
        ]);
    }
}
