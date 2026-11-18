<?php

namespace App\Http\Controllers\Api;

use App\Models\Material;
use App\Models\Militar;
use App\Models\PedidoMaterial;
use App\Services\PedidoMaterialService;
use App\Support\Natureza;
use App\Support\SigarbException;
use App\Support\SituacaoNaEntrega;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Portal do efetivo (decisão do responsável, 2026-11-18) — lado do próprio efetivo (permissão `portal.efetivo`).
 * Tudo é filtrado pelo efetivo ligado à conta (usuarios.militar_id) na própria consulta: ninguém vê pedido ou material de outro.
 */
class PortalEfetivoController extends ApiController
{
    private const ULID = '/^[0-9A-HJKMNP-TV-Z]{26}$/';

    public function __construct(private PedidoMaterialService $svc) {}

    public function resumo(Request $r): JsonResponse
    {
        $m = $this->efetivo($r, exigirVinculo: false);
        if (! $m) {
            return response()->json(['sem_vinculo' => true, 'efetivo' => null, 'pedidos_abertos' => [], 'limite_abertos' => PedidoMaterialService::limiteAbertos(),
                'material' => ['total' => 0, 'atrasados' => 0]]);
        }
        $abertos = PedidoMaterial::where('militar_id', $m->id)->whereIn('status', PedidoMaterial::ABERTOS)->orderByDesc('id')->get();
        $hoje = now()->toDateString();
        $mat = VinculoMaterialController::consulta()->where('v.militar_id', $m->id);

        return response()->json([
            'sem_vinculo' => false, 'efetivo' => $this->dadosEfetivo($m),
            'pedidos_abertos' => $abertos->map(fn ($p) => PedidoMaterialController::resumo($p))->values(),
            'limite_abertos' => PedidoMaterialService::limiteAbertos(),
            'material' => ['total' => (clone $mat)->count(), 'atrasados' => (clone $mat)->whereNotNull('v.previsao_retorno')->where('v.previsao_retorno', '<', $hoje)->count()],
        ]);
    }

    /** Catálogo para o pedido: materiais ativos por categoria, com o que está bloqueado e por quê; locais de retirada da unidade. */
    public function catalogo(Request $r): JsonResponse
    {
        $m = $this->efetivo($r);
        $mats = Material::with(['categoria:id,nome,natureza', 'tipo:id,nome,perfil'])->where('ativo', true)->orderBy('nome')->get();

        return response()->json([
            'efetivo' => $this->dadosEfetivo($m),
            'limite_abertos' => PedidoMaterialService::limiteAbertos(),
            'abertos' => PedidoMaterial::where('militar_id', $m->id)->whereIn('status', PedidoMaterial::ABERTOS)->count(),
            'locais' => PedidoMaterialService::locaisRetirada($m)->map(fn ($l) => ['id' => $l->id, 'codigo' => $l->codigo, 'nome' => $l->nome, 'unidade' => $l->unidade?->sigla])->values(),
            'data' => $mats->map(fn (Material $x) => [
                'id' => $x->id, 'codigo' => $x->codigo, 'nome' => $x->nome, 'modo_controle' => $x->modo_controle, 'unidade_medida' => $x->unidade_medida,
                'categoria' => $x->categoria?->nome, 'natureza' => $x->categoria?->natureza, 'natureza_rotulo' => Natureza::rotulo($x->categoria?->natureza),
                'tipo' => $x->tipo?->nome, 'perfil' => $x->tipo?->perfil, 'bloqueio' => PedidoMaterialService::bloqueio($m, $x->categoria?->natureza),
            ])->values(),
        ]);
    }

    public function pedidos(Request $r): JsonResponse
    {
        $m = $this->efetivo($r);
        $f = $r->validate(['status' => ['nullable', 'in:'.implode(',', PedidoMaterial::STATUS)]]);
        $q = PedidoMaterial::query()->where('militar_id', $m->id)->with(PedidoMaterialController::RELACOES)
            ->when(! empty($f['status']), fn ($q) => $q->where('status', $f['status']));

        return $this->paginar($r, $q, fn (PedidoMaterial $p) => PedidoMaterialController::resumo($p) + ['pode_cancelar' => in_array($p->status, PedidoMaterial::ABERTOS, true)], ['id'], '-id');
    }

    public function criar(Request $r): JsonResponse
    {
        $m = $this->efetivo($r);
        $d = $r->validate([
            'itens' => ['required', 'array', 'min:1', 'max:20'], 'itens.*.material_id' => ['required', 'integer', 'distinct'],
            'itens.*.quantidade' => ['required', 'integer', 'min:1', 'max:1000'],
            'retirada_prevista' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:today', 'before_or_equal:'.now()->addDays(60)->toDateString()],
            'local_retirada_id' => ['nullable', 'integer'], 'observacao' => ['nullable', 'string', 'max:500'],
        ], ['itens.required' => 'Escolha ao menos um material.', 'retirada_prevista.after_or_equal' => 'A retirada não pode ser no passado.',
            'retirada_prevista.before_or_equal' => 'A retirada prevista pode ser marcada para até 60 dias.']);
        $p = $this->svc->criar($r->user(), $m, $d);

        return $this->mostrar($r, $p)->setStatusCode(201);
    }

    public function show(Request $r, string $codigo): JsonResponse
    {
        return $this->mostrar($r, $this->obter($r, $codigo));
    }

    public function cancelar(Request $r, string $codigo): JsonResponse
    {
        $p = $this->obter($r, $codigo);
        $d = $r->validate(['versao' => ['required', 'integer'], 'motivo' => ['nullable', 'string', 'max:500']]);

        return $this->mostrar($r, $this->svc->cancelar($r->user(), $p, $d['motivo'] ?? null, $d['versao']));
    }

    /** Material comigo: itens e lotes entregues ao efetivo pendentes de devolução e acautelamentos ativos. */
    public function material(Request $r): JsonResponse
    {
        $m = $this->efetivo($r);
        $hoje = now()->toDateString();
        $linhas = VinculoMaterialController::consulta()->where('v.militar_id', $m->id)->orderByDesc('v.desde')->limit(200)->get();

        return response()->json(['data' => $linhas->map(fn ($v) => [
            'custodia' => $v->custodia, 'origem' => $v->custodia === 'ACAUTELAMENTO' ? $v->origem : null, 'desde' => $v->desde,
            'material' => ['nome' => $v->material_nome, 'categoria' => $v->categoria, 'natureza' => $v->natureza, 'natureza_rotulo' => Natureza::rotulo($v->natureza), 'unidade_medida' => $v->unidade_medida],
            'referencia' => $v->referencia, 'numero_serie' => $v->numero_serie, 'lote_codigo' => $v->lote_codigo, 'quantidade' => (int) $v->quantidade,
            // Lote: entregue/devolvido para "Entregue · 10 pendentes" ou "Parcial: 4 de 10 devolvidos" (2026-11-18).
            'situacao' => $v->situacao, 'quantidade_entregue' => (int) $v->quantidade_entregue, 'quantidade_devolvida' => (int) $v->quantidade_devolvida,
            'situacao_na_entrega' => SituacaoNaEntrega::pendente((int) $v->quantidade_entregue, (int) $v->quantidade_devolvida),
            'unidade' => $v->unidade_sigla, 'local' => $v->local_nome, 'previsao_retorno' => $v->previsao_retorno,
            'atrasado' => $v->previsao_retorno !== null && $v->previsao_retorno < $hoje,
        ])->values()]);
    }

    private function mostrar(Request $r, PedidoMaterial $p): JsonResponse
    {
        return response()->json(PedidoMaterialController::detalhe($p, $r->user()) + ['pode_cancelar' => in_array($p->status, PedidoMaterial::ABERTOS, true)]);
    }

    private function dadosEfetivo(Militar $m): array
    {
        $m->loadMissing('postoGraduacao', 'unidade');

        return ['nome' => $m->nome, 'nome_guerra' => $m->nome_guerra, 'posto' => $m->sigla_exibicao, 'unidade' => $m->unidade?->sigla,
            'categoria_efetivo' => $m->categoria_efetivo, 'apto_arma_fogo' => in_array($m->categoria_efetivo, Militar::APTAS_LETAL, true), 'situacao' => $m->situacao];
    }

    /** Efetivo da própria conta. Sem `portal.efetivo` = 403; conta sem efetivo ligado = 422 (procure a T.I.). */
    private function efetivo(Request $r, bool $exigirVinculo = true): ?Militar
    {
        $this->exigir('portal.efetivo');
        $m = $r->user()->militar_id ? Militar::find($r->user()->militar_id) : null;
        if (! $m && $exigirVinculo) {
            throw new SigarbException('SEM_EFETIVO', 'Sua conta não está ligada a um cadastro de efetivo. Procure a T.I.', 422);
        }

        return $m;
    }

    private function obter(Request $r, string $codigo): PedidoMaterial
    {
        $m = $this->efetivo($r);
        $p = preg_match(self::ULID, $codigo) ? PedidoMaterial::where('codigo', $codigo)->where('militar_id', $m->id)->first() : null;

        return $p ?? throw new SigarbException('NAO_ENCONTRADO', 'Pedido não encontrado.', 404);
    }
}
