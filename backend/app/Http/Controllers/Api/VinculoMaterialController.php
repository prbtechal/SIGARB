<?php

namespace App\Http\Controllers\Api;

use App\Support\Natureza;
use App\Support\PerfilMaterial;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * CP-12 — RF-VIN-06: material atualmente sob responsabilidade de militares, derivado das fontes de verdade existentes
 * (acautelamentos ATIVO + entregas com retorno ainda não devolvidas, descontadas as devoluções parciais). Somente leitura.
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
            // Decisão 2026-11-16: filtros do armeiro — material, texto (efetivo, material, série/código, lote) e só atrasados.
            'material_id' => ['nullable', 'integer'], 'q' => ['nullable', 'string', 'max:80'], 'atrasados' => ['nullable', 'boolean'],
        ]);
        $hoje = now()->toDateString();

        $acaut = DB::table('acautelamentos as a')->join('itens_individuais as i', 'i.id', '=', 'a.item_individual_id')->where('a.estado', 'ATIVO')
            ->selectRaw("'ACAUTELAMENTO' as custodia, a.militar_id, a.unidade_id, i.material_id, i.id as item_individual_id, NULL as lote_id, 1 as quantidade,
                i.identificador as referencia, i.numero_serie, NULL as lote_codigo, i.situacao, i.estado_conservacao, i.validade, a.updated_at as desde, a.codigo as origem,
                NULL as entrega_id, NULL as previsao_retorno, i.local_estoque_id as local_id");
        // Pendente = entregue − já devolvido nas devoluções parciais desta entrega (item individual: 1 − 1 = 0 sai da lista).
        $devolvido = 'COALESCE((SELECT SUM(pi.quantidade) FROM movimentacao_itens pi JOIN movimentacoes pd ON pd.id = pi.movimentacao_id
            WHERE pd.vinculo_parcial_id = mv.id AND (pi.lote_id = mi.lote_id OR pi.item_individual_id = mi.item_individual_id)), 0)';
        $entregas = DB::table('movimentacoes as mv')->join('movimentacao_itens as mi', 'mi.movimentacao_id', '=', 'mv.id')
            ->join('locais_estoque as lo', 'lo.id', '=', 'mv.local_origem_id')
            ->leftJoin('itens_individuais as i', 'i.id', '=', 'mi.item_individual_id')->leftJoin('lotes as l', 'l.id', '=', 'mi.lote_id')
            ->where('mv.tipo', 'ENTREGA')->whereNotNull('mv.militar_id')->whereNotNull('mv.previsao_retorno')
            ->whereNotExists(fn ($q) => $q->from('movimentacoes as c')->whereColumn('c.vinculo_id', 'mv.id'))
            ->whereNotExists(fn ($q) => $q->from('acautelamentos as ac')->whereColumn('ac.entrega_id', 'mv.id')) // já contado acima
            ->whereRaw("mi.quantidade - $devolvido > 0")
            ->selectRaw("'ENTREGA' as custodia, mv.militar_id, lo.unidade_id, COALESCE(i.material_id, l.material_id) as material_id, mi.item_individual_id, mi.lote_id,
                mi.quantidade - $devolvido as quantidade,
                COALESCE(i.identificador, l.codigo) as referencia, i.numero_serie, l.codigo as lote_codigo, i.situacao, i.estado_conservacao, COALESCE(i.validade, l.validade) as validade,
                mv.ocorrido_em as desde, CAST(mv.id AS CHAR) as origem, mv.id as entrega_id, mv.previsao_retorno, mv.local_origem_id as local_id");

        $q = DB::query()->fromSub($acaut->unionAll($entregas), 'v')
            ->join('militares as mil', 'mil.id', '=', 'v.militar_id')->join('postos_graduacoes as pg', 'pg.id', '=', 'mil.posto_graduacao_id')
            ->join('materiais as m', 'm.id', '=', 'v.material_id')->join('categorias as c', 'c.id', '=', 'm.categoria_id')
            ->leftJoin('tipos_material as t', 't.id', '=', 'm.tipo_id')->join('unidades as u', 'u.id', '=', 'v.unidade_id')
            ->leftJoin('locais_estoque as le', 'le.id', '=', 'v.local_id')
            ->select(['v.*', 'mil.nome_guerra', 'mil.categoria_efetivo', 'pg.sigla as posto', 'm.codigo as material_codigo', 'm.nome as material_nome', 'm.modo_controle', 'm.unidade_medida',
                'c.id as categoria_id', 'c.nome as categoria', 'c.natureza', 't.id as tipo_id', 't.nome as tipo', 't.perfil', 'u.sigla as unidade_sigla', 'le.codigo as local_codigo', 'le.nome as local_nome']);
        $this->authz()->escopar($q, $r->user(), 'estoque.ver', 'v.unidade_id');
        foreach (['militar_id' => 'v.militar_id', 'unidade_id' => 'v.unidade_id', 'categoria_id' => 'c.id', 'tipo_id' => 't.id', 'perfil' => 't.perfil',
            'modo_controle' => 'm.modo_controle', 'custodia' => 'v.custodia', 'situacao' => 'v.situacao', 'material_id' => 'v.material_id'] as $k => $col) {
            if (isset($f[$k])) {
                $q->where($col, $f[$k]);
            }
        }
        if (! empty($f['q'])) {
            $t = $this->like($f['q']);
            $q->where(fn ($w) => $w->where('mil.nome_guerra', 'like', $t)->orWhere('mil.nome', 'like', $t)->orWhere('m.nome', 'like', $t)
                ->orWhere('v.referencia', 'like', $t)->orWhere('v.numero_serie', 'like', $t));
        }
        if (! empty($f['atrasados'])) {
            $q->whereNotNull('v.previsao_retorno')->where('v.previsao_retorno', '<', $hoje);
        }
        $p = $q->orderByDesc('v.desde')->orderBy('v.referencia')->paginate(min(100, max(1, (int) $r->query('per_page', 20))));
        $u = $r->user();
        $cache = [];
        $pode = function (string $perm, int $unidade) use ($u, &$cache): bool {
            return $cache[$perm][$unidade] ??= $this->authz()->pode($u, $perm, $unidade);
        };

        return response()->json([
            'data' => collect($p->items())->map(fn ($v) => [
                'custodia' => $v->custodia, 'origem' => $v->origem, 'desde' => $v->desde,
                'militar' => ['id' => $v->militar_id, 'posto' => $v->posto, 'nome_guerra' => $v->nome_guerra, 'categoria_efetivo' => $v->categoria_efetivo],
                'unidade' => ['id' => $v->unidade_id, 'sigla' => $v->unidade_sigla],
                'local' => $v->local_id ? ['id' => (int) $v->local_id, 'codigo' => $v->local_codigo, 'nome' => $v->local_nome] : null,
                'material' => ['id' => $v->material_id, 'codigo' => $v->material_codigo, 'nome' => $v->material_nome, 'modo_controle' => $v->modo_controle,
                    'categoria' => $v->categoria, 'tipo' => $v->tipo, 'perfil' => $v->perfil, 'natureza' => $v->natureza, 'natureza_rotulo' => Natureza::rotulo($v->natureza),
                    'unidade_medida' => $v->unidade_medida],
                'item_individual_id' => $v->item_individual_id, 'lote_id' => $v->lote_id, 'referencia' => $v->referencia,
                'numero_serie' => $v->numero_serie, 'lote_codigo' => $v->lote_codigo,
                'quantidade' => (int) $v->quantidade, 'situacao' => $v->situacao, 'estado_conservacao' => $v->estado_conservacao, 'validade' => $v->validade,
                'entrega_id' => $v->entrega_id ? (int) $v->entrega_id : null, 'previsao_retorno' => $v->previsao_retorno,
                'atrasado' => $v->previsao_retorno !== null && $v->previsao_retorno < $hoje,
                'pode_receber' => $v->custodia === 'ENTREGA' && $pode('estoque.entregar', (int) $v->unidade_id),
            ]),
            'meta' => ['page' => $p->currentPage(), 'per_page' => $p->perPage(), 'total' => $p->total(), 'last_page' => $p->lastPage()],
        ]);
    }
}
