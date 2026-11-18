<?php

namespace App\Http\Controllers\Api;

use App\Models\Militar;
use App\Support\Busca;
use App\Support\GrupoMaterial;
use App\Support\Natureza;
use App\Support\PerfilMaterial;
use App\Support\SituacaoNaEntrega;
use Illuminate\Database\Query\Builder;
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
    /**
     * Decisão do responsável (2026-11-18): UMA linha por entrega (movimentação ENTREGA) ou por acautelamento, com todos os materiais
     * ainda pendentes daquela entrega. Filtros escolhem as entregas (basta um material casar); a linha traz a entrega inteira.
     * Paginação por entrega. Também alimenta a tela "Devolução de material" (busca por efetivo, matrícula, CPF, entrega ou pedido).
     */
    public function index(Request $r): JsonResponse
    {
        $this->exigir('estoque.ver');
        $f = $r->validate([
            'militar_id' => ['nullable', 'integer'], 'unidade_id' => ['nullable', 'integer'], 'categoria_id' => ['nullable', 'integer'],
            'tipo_id' => ['nullable', 'integer'], 'perfil' => ['nullable', Rule::in(PerfilMaterial::perfis())],
            'modo_controle' => ['nullable', 'in:INDIVIDUAL,LOTE'], 'custodia' => ['nullable', 'in:ACAUTELAMENTO,ENTREGA'],
            'situacao' => ['nullable', 'in:ACAUTELADO,ENTREGUE,MANUTENCAO,RESERVADO'],
            'material_id' => ['nullable', 'integer'], 'q' => ['nullable', 'string', 'max:80'], 'atrasados' => ['nullable', 'boolean'],
            'entrega_id' => ['nullable', 'integer'], 'grupo' => ['nullable', Rule::in(GrupoMaterial::chaves())],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $u = $r->user();
        $hoje = now()->toDateString();

        $q = self::consulta();
        $this->authz()->escopar($q, $u, 'estoque.ver', 'v.unidade_id');
        $this->authz()->ocultarDepositos($q, $u, 'v.local_id'); // depósito só para a gestão (2026-11-18)
        foreach (['militar_id' => 'v.militar_id', 'unidade_id' => 'v.unidade_id', 'categoria_id' => 'c.id', 'tipo_id' => 't.id', 'perfil' => 't.perfil',
            'modo_controle' => 'm.modo_controle', 'custodia' => 'v.custodia', 'situacao' => 'v.situacao', 'material_id' => 'v.material_id', 'entrega_id' => 'v.entrega_id'] as $k => $col) {
            if (isset($f[$k])) {
                $q->where($col, $f[$k]);
            }
        }
        if (! empty($f['q'])) {
            $this->buscar($q, $u, trim($f['q']));
        }
        if (! empty($f['atrasados'])) {
            $q->whereNotNull('v.previsao_retorno')->where('v.previsao_retorno', '<', $hoje);
        }
        if (! empty($f['grupo'])) {
            GrupoMaterial::filtrar($q, $f['grupo']);
        }
        $grupos = DB::query()->fromSub($q, 'x')->groupBy('x.custodia', 'x.origem')
            ->selectRaw('x.custodia, x.origem, MIN(x.desde) as desde');
        $p = DB::query()->fromSub($grupos, 'g')->orderByDesc('g.desde')->orderByDesc('g.origem')
            ->paginate((int) ($f['per_page'] ?? 20));

        // Linhas completas das entregas desta página (sem os filtros de material/texto: a entrega vem inteira).
        $chaves = collect($p->items())->pluck('origem')->all();
        $linhas = $chaves === [] ? collect() : self::consulta()->whereIn('v.origem', $chaves)->orderBy('m.nome')->orderBy('v.referencia')->get()
            ->filter(fn ($v) => $this->authz()->pode($u, 'estoque.ver', (int) $v->unidade_id))->groupBy(fn ($v) => $v->custodia.'|'.$v->origem);
        $pedidos = $this->pedidos($linhas->flatten(1));
        $cache = [];
        $pode = function (string $perm, int $unidade) use ($u, &$cache): bool {
            return $cache[$perm][$unidade] ??= $this->authz()->pode($u, $perm, $unidade);
        };

        return response()->json([
            'data' => collect($p->items())->map(function ($g) use ($linhas, $pedidos, $pode, $hoje) {
                $itens = $linhas->get($g->custodia.'|'.$g->origem, collect())->values();
                $v = $itens->first();
                if (! $v) {
                    return null;
                }
                $chavePedido = $v->custodia === 'ENTREGA' ? 'e'.$v->entrega_id : 'a'.$v->origem;

                return [
                    'custodia' => $v->custodia, 'origem' => $v->origem, 'desde' => $v->desde,
                    'entrega_id' => $v->entrega_id ? (int) $v->entrega_id : null,
                    'pedido' => $pedidos[$chavePedido] ?? null,
                    'militar' => ['id' => $v->militar_id, 'posto' => Militar::siglaExibicao($v->posto, $v->categoria_efetivo), 'nome_guerra' => $v->nome_guerra, 'nome' => $v->militar_nome, 'categoria_efetivo' => $v->categoria_efetivo],
                    'unidade' => ['id' => $v->unidade_id, 'sigla' => $v->unidade_sigla],
                    'local' => $v->local_id ? ['id' => (int) $v->local_id, 'codigo' => $v->local_codigo, 'nome' => $v->local_nome] : null,
                    'previsao_retorno' => $v->previsao_retorno,
                    'atrasado' => $v->previsao_retorno !== null && $v->previsao_retorno < $hoje,
                    'total_itens' => $itens->count(),
                    'quantidade_total' => (int) $itens->sum('quantidade'),
                    'conservacao' => $itens->pluck('estado_conservacao')->filter()->countBy()->sortDesc()->all(),
                    'itens' => $itens->map(fn ($i) => [
                        'material' => ['id' => $i->material_id, 'codigo' => $i->material_codigo, 'nome' => $i->material_nome, 'modo_controle' => $i->modo_controle,
                            'categoria' => $i->categoria, 'tipo' => $i->tipo, 'perfil' => $i->perfil, 'natureza' => $i->natureza, 'natureza_rotulo' => Natureza::rotulo($i->natureza),
                            'unidade_medida' => $i->unidade_medida],
                        'item_individual_id' => $i->item_individual_id, 'lote_id' => $i->lote_id, 'referencia' => $i->referencia,
                        'numero_serie' => $i->numero_serie, 'lote_codigo' => $i->lote_codigo, 'quantidade' => (int) $i->quantidade,
                        'quantidade_entregue' => (int) $i->quantidade_entregue, 'quantidade_devolvida' => (int) $i->quantidade_devolvida,
                        'situacao' => $i->situacao, 'estado_conservacao' => $i->estado_conservacao, 'validade' => $i->validade,
                        'situacao_na_entrega' => SituacaoNaEntrega::pendente((int) $i->quantidade_entregue, (int) $i->quantidade_devolvida),
                    ])->values(),
                    'pode_receber' => $v->custodia === 'ENTREGA' && $pode('estoque.entregar', (int) $v->unidade_id),
                ];
            })->filter()->values(),
            'meta' => ['page' => $p->currentPage(), 'per_page' => $p->perPage(), 'total' => $p->total(), 'last_page' => $p->lastPage()],
        ]);
    }

    /**
     * GET /stock/linked/recipients — "Devolução de material" já aberta (decisão do responsável, 2026-11-18): uma linha por EFETIVO com
     * material pendente na base (escopo `estoque.ver`), sem precisar buscar. Uma consulta agregada (GROUP BY efetivo) sobre a mesma fonte
     * da lista agrupada; a busca do balcão (`q`) filtra a mesma lista. Ordem: atrasados primeiro, depois a previsão de retorno mais próxima
     * (acautelamento sem previsão vai ao fim), depois a entrega mais antiga. As entregas do efetivo vêm de `GET /stock/linked?militar_id=`.
     */
    public function porEfetivo(Request $r): JsonResponse
    {
        $this->exigir('estoque.ver');
        $f = $r->validate(['q' => ['nullable', 'string', 'max:80'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:50']]);
        $u = $r->user();
        $hoje = now()->toDateString(); // mesma referência do selo "Atrasado" da lista agrupada
        $q = self::consulta();
        $this->authz()->escopar($q, $u, 'estoque.ver', 'v.unidade_id');
        $this->authz()->ocultarDepositos($q, $u, 'v.local_id'); // depósito só para a gestão (2026-11-18)
        if (! empty($f['q'])) {
            $this->buscar($q, $u, trim($f['q']));
        }
        $agg = $q->reorder()->groupBy('v.militar_id')->select(DB::raw("v.militar_id,
            COUNT(DISTINCT CONCAT(v.custodia, '|', v.origem)) as entregas,
            SUM(CASE WHEN v.item_individual_id IS NOT NULL THEN 1 ELSE 0 END) as itens,
            SUM(CASE WHEN v.lote_id IS NOT NULL THEN 1 ELSE 0 END) as lotes,
            CAST(SUM(CASE WHEN v.lote_id IS NOT NULL THEN v.quantidade ELSE 0 END) AS SIGNED) as quantidade_lotes,
            MIN(v.desde) as mais_antiga, MIN(v.previsao_retorno) as proxima_previsao,
            COUNT(DISTINCT CASE WHEN v.previsao_retorno < '$hoje' THEN v.origem END) as atrasadas"));
        $p = DB::query()->fromSub($agg, 'g')->orderByRaw('g.atrasadas > 0 DESC')->orderByRaw('g.proxima_previsao IS NULL')
            ->orderBy('g.proxima_previsao')->orderBy('g.mais_antiga')->orderBy('g.militar_id')->paginate((int) ($f['per_page'] ?? 20));
        $mil = DB::table('militares as mil')->join('postos_graduacoes as pg', 'pg.id', '=', 'mil.posto_graduacao_id')
            ->whereIn('mil.id', collect($p->items())->pluck('militar_id')->all() ?: [0])
            ->get(['mil.id', 'mil.nome', 'mil.nome_guerra', 'mil.categoria_efetivo', 'pg.sigla as posto'])->keyBy('id');

        return response()->json([
            'data' => collect($p->items())->map(function ($g) use ($mil) {
                $m = $mil->get($g->militar_id);

                return [
                    'militar' => ['id' => (int) $g->militar_id, 'posto' => Militar::siglaExibicao($m?->posto, $m?->categoria_efetivo), 'nome_guerra' => $m?->nome_guerra,
                        'nome' => $m?->nome, 'categoria_efetivo' => $m?->categoria_efetivo,
                        'apto_arma_fogo' => $m?->categoria_efetivo === null ? null : in_array($m->categoria_efetivo, Militar::APTAS_LETAL, true)],
                    'entregas' => (int) $g->entregas, 'itens' => (int) $g->itens, 'lotes' => (int) $g->lotes, 'quantidade_lotes' => (int) $g->quantidade_lotes,
                    'mais_antiga' => $g->mais_antiga, 'proxima_previsao' => $g->proxima_previsao,
                    'atrasadas' => (int) $g->atrasadas, 'atrasado' => (int) $g->atrasadas > 0,
                ];
            })->values(),
            'meta' => ['page' => $p->currentPage(), 'per_page' => $p->perPage(), 'total' => $p->total(), 'last_page' => $p->lastPage()],
        ]);
    }

    /** Colunas da busca textual (palavra por palavra, parcial, sem acento/maiúsculas — App\Support\Busca). */
    public const COLUNAS_BUSCA = ['mil.nome_guerra', 'mil.nome', 'm.nome', 'm.codigo', 't.nome', 'v.referencia', 'v.numero_serie', 'v.lote_codigo'];

    /**
     * Busca do balcão (decisão 2026-11-18): material (nome, código, tipo), efetivo (nome, nome de guerra), série, identificador, lote —
     * palavra por palavra, parcial e sem acento ("PT", "colete", "munições"); nº da entrega ("1183" ou "#1183"); comprovante do pedido
     * ("PED-XXXXXX" ou o código completo); matrícula e CPF exatos só onde a matrícula completa é visível (sem oráculo da máscara).
     */
    private function buscar(Builder $q, $u, string $t): void
    {
        $detalhe = $this->authz()->unidadesPermitidas($u, 'militares.ver_detalhe');
        $digitos = preg_replace('/\D/', '', $t);
        $q->where(function ($w) use ($t, $detalhe, $digitos) {
            $w->where(fn ($x) => Busca::aplicar($x, $t, self::COLUNAS_BUSCA));
            if (preg_match('/^#?(\d{1,9})$/', $t, $n)) {
                $w->orWhere('v.entrega_id', (int) $n[1]);
            }
            if (preg_match('/^(?:PED-?([0-9A-HJKMNP-TV-Z]{6})|([0-9A-HJKMNP-TV-Z]{26}))$/i', $t, $c)) {
                $ped = DB::table('pedidos_material')->when(! empty($c[2]), fn ($x) => $x->where('codigo', strtoupper($c[2])),
                    fn ($x) => $x->where('codigo', 'like', '%'.strtoupper($c[1])));
                $w->orWhereIn('v.entrega_id', (clone $ped)->whereNotNull('entrega_movimentacao_id')->select('entrega_movimentacao_id'))
                    ->orWhereIn('v.origem', DB::table('pedido_material_acautelamentos as pa')->join('acautelamentos as a', 'a.id', '=', 'pa.acautelamento_id')
                        ->whereIn('pa.pedido_id', (clone $ped)->select('id'))->select('a.codigo'));
            }
            $chave = fn ($m) => $m->where('mil.matricula', $t)->when(strlen($digitos) === 11, fn ($x) => $x->orWhere('mil.cpf', $digitos));
            if ($detalhe === null) {
                $w->orWhere($chave);
            } elseif ($detalhe !== []) {
                $w->orWhere(fn ($m) => $m->where($chave)->whereIn('mil.unidade_id', $detalhe));
            }
        });
    }

    /** Pedido do portal ligado à entrega (entrega_movimentacao_id) ou ao acautelamento da arma do pedido. */
    private function pedidos($linhas): array
    {
        $entregas = $linhas->pluck('entrega_id')->filter()->unique()->values()->all();
        $acauts = $linhas->where('custodia', 'ACAUTELAMENTO')->pluck('origem')->unique()->values()->all();
        $out = [];
        $fmt = fn ($c) => ['codigo' => $c, 'comprovante' => 'PED-'.substr($c, -6)];
        if ($entregas) {
            foreach (DB::table('pedidos_material')->whereIn('entrega_movimentacao_id', $entregas)->get(['codigo', 'entrega_movimentacao_id']) as $p) {
                $out['e'.$p->entrega_movimentacao_id] = $fmt($p->codigo);
            }
        }
        if ($acauts) {
            foreach (DB::table('pedido_material_acautelamentos as pa')->join('acautelamentos as a', 'a.id', '=', 'pa.acautelamento_id')
                ->join('pedidos_material as p', 'p.id', '=', 'pa.pedido_id')->whereIn('a.codigo', $acauts)->get(['p.codigo', 'a.codigo as acaut']) as $p) {
                $out['a'.$p->acaut] = $fmt($p->codigo);
            }
        }

        return $out;
    }

    /** Consulta-base (sem escopo nem filtros): material com cada efetivo agora. Também usada no portal do efetivo ("Material comigo"). */
    public static function consulta(): Builder
    {
        $acaut = DB::table('acautelamentos as a')->join('itens_individuais as i', 'i.id', '=', 'a.item_individual_id')->where('a.estado', 'ATIVO')
            ->selectRaw("'ACAUTELAMENTO' as custodia, a.militar_id, a.unidade_id, i.material_id, i.id as item_individual_id, NULL as lote_id, 1 as quantidade,
                i.identificador as referencia, i.numero_serie, NULL as lote_codigo, i.situacao, i.estado_conservacao, i.validade, a.updated_at as desde, a.codigo as origem,
                NULL as entrega_id, NULL as previsao_retorno, i.local_estoque_id as local_id, 1 as quantidade_entregue, 0 as quantidade_devolvida");
        // Pendente = entregue − já devolvido nas devoluções parciais desta entrega (item individual: 1 − 1 = 0 sai da lista).
        // Lote (munição etc.) não tem situação própria: enquanto houver pendente, a linha é ENTREGUE como os itens (2026-11-18).
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
                COALESCE(i.identificador, l.codigo) as referencia, i.numero_serie, l.codigo as lote_codigo, COALESCE(i.situacao, 'ENTREGUE') as situacao, i.estado_conservacao, COALESCE(i.validade, l.validade) as validade,
                mv.ocorrido_em as desde, CAST(mv.id AS CHAR) as origem, mv.id as entrega_id, mv.previsao_retorno, mv.local_origem_id as local_id,
                mi.quantidade as quantidade_entregue, $devolvido as quantidade_devolvida");

        return DB::query()->fromSub($acaut->unionAll($entregas), 'v')
            ->join('militares as mil', 'mil.id', '=', 'v.militar_id')->join('postos_graduacoes as pg', 'pg.id', '=', 'mil.posto_graduacao_id')
            ->join('materiais as m', 'm.id', '=', 'v.material_id')->join('categorias as c', 'c.id', '=', 'm.categoria_id')
            ->leftJoin('tipos_material as t', 't.id', '=', 'm.tipo_id')->join('unidades as u', 'u.id', '=', 'v.unidade_id')
            ->leftJoin('locais_estoque as le', 'le.id', '=', 'v.local_id')
            ->select(['v.*', 'mil.nome_guerra', 'mil.nome as militar_nome', 'mil.categoria_efetivo', 'pg.sigla as posto', 'm.codigo as material_codigo', 'm.nome as material_nome', 'm.modo_controle', 'm.unidade_medida',
                'c.id as categoria_id', 'c.nome as categoria', 'c.natureza', 't.id as tipo_id', 't.nome as tipo', 't.perfil', 'u.sigla as unidade_sigla', 'le.codigo as local_codigo', 'le.nome as local_nome']);
    }
}
