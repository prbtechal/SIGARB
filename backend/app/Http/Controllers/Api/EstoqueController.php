<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\EntradaRequest;
use App\Http\Requests\EstornoRequest;
use App\Models\ItemIndividual;
use App\Models\LocalEstoque;
use App\Models\Material;
use App\Models\Militar;
use App\Models\Movimentacao;
use App\Services\EstoqueService;
use App\Services\OperacaoEstoqueService;
use App\Support\Conservacao;
use App\Support\Operacoes;
use App\Support\PerfilMaterial;
use App\Support\SigarbException;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/** RF-010, RF-011 (ENTRADA/ESTORNO), RF-013, RF-026, RF-027, UC-02, UC-04. */
class EstoqueController extends ApiController
{
    public function __construct(private EstoqueService $estoque) {}

    /** Saldos: lotes (saldos_estoque) + itens individuais agregados, em uma única listagem paginada. */
    public function saldos(Request $r): JsonResponse
    {
        $this->exigir('estoque.ver');
        $f = $r->validate([
            'local_id' => ['nullable', 'integer'], 'unidade_id' => ['nullable', 'integer'], 'material_id' => ['nullable', 'integer'],
            'natureza' => ['nullable', 'in:ARMAMENTO,MUNICAO,EQUIPAMENTO,PRODUTO,TREINAMENTO'], 'q' => ['nullable', 'string', 'max:80'],
        ]);
        $q = $this->consultaSaldos($r, $f);
        $q->orderBy('m.nome')->orderBy('le.codigo')->orderBy('s.lote_codigo');
        $p = $q->paginate(min(100, max(1, (int) $r->query('per_page', 20))));

        return response()->json([
            'data' => collect($p->items())->map(fn ($s) => self::linhaSaldo($s)),
            'meta' => ['page' => $p->currentPage(), 'per_page' => $p->perPage(), 'total' => $p->total(), 'last_page' => $p->lastPage()],
        ]);
    }

    private static function linhaSaldo(object $s): array
    {
        return [
            'local' => ['id' => $s->local_estoque_id, 'codigo' => $s->local_codigo, 'nome' => $s->local_nome],
            'unidade' => ['id' => $s->unidade_id, 'sigla' => $s->unidade_sigla],
            'material' => ['id' => $s->material_id, 'codigo' => $s->material_codigo, 'nome' => $s->material_nome, 'modo_controle' => $s->modo_controle, 'categoria' => $s->categoria, 'tipo' => $s->tipo ?? null, 'natureza' => $s->natureza, 'unidade_medida' => $s->unidade_medida],
            'lote' => $s->lote_id ? ['id' => $s->lote_id, 'codigo' => $s->lote_codigo, 'validade' => $s->validade] : null,
            'quantidade' => (int) $s->quantidade, 'reservada' => (int) $s->quantidade_reservada, 'disponivel' => (int) $s->disponivel,
        ];
    }

    /**
     * Detalhe do material no estoque: catálogo + saldo por local/lote no escopo + o que o usuário pode fazer.
     * Somente leitura; itens e movimentações vêm das listas paginadas já existentes (filtro material_id).
     */
    public function material(Request $r, Material $material): JsonResponse
    {
        $this->exigir('estoque.ver');
        $r->validate(['local_id' => ['nullable', 'integer']]);
        // ?local_id= mantém o filtro da lista de saldos (o total passa a ser do local).
        $saldos = $this->consultaSaldos($r, ['material_id' => $material->id] + ($r->filled('local_id') ? ['local_id' => (int) $r->query('local_id')] : []))->orderBy('le.codigo')->orderBy('s.lote_codigo')->limit(500)->get();
        // Sem catálogo e sem nada do material no escopo: não confirma nem a existência.
        if ($saldos->isEmpty() && ! $this->authz()->pode($r->user(), 'catalogo.ver')) {
            throw new SigarbException('NAO_ENCONTRADO', 'Material não encontrado.', 404);
        }
        $u = $r->user();
        $pode = fn (string $perm) => $this->authz()->pode($u, $perm);

        return response()->json(CatalogoController::dto($material->load(['categoria', 'fabricante', 'calibre', 'tipo'])) + [
            'saldos' => $saldos->map(fn ($s) => self::linhaSaldo($s))->values(),
            'total' => ['quantidade' => (int) $saldos->sum('quantidade'), 'reservada' => (int) $saldos->sum('quantidade_reservada'), 'disponivel' => (int) $saldos->sum('disponivel')],
            // Atalhos de tela; cada fluxo continua verificado no servidor e exige motivo.
            'pode' => ['editar_catalogo' => Gate::allows('update', $material), 'entrada' => $pode('estoque.entrada'),
                'operar' => collect(['estoque.saida', 'estoque.baixar', 'estoque.transferir', 'estoque.reservar', 'estoque.manutencao', 'estoque.entregar'])->contains($pode),
                'inventario' => $pode('inventario.contar')],
        ]);
    }

    /** Consulta de saldos já escopada e filtrada (reaproveitada pela exportação de relatórios). */
    public function consultaSaldos(Request $r, array $f): Builder
    {
        $lotes = DB::table('saldos_estoque as s')->join('lotes as l', 'l.id', '=', 's.lote_id')
            ->where('s.quantidade', '>', 0)
            ->selectRaw('s.local_estoque_id, l.material_id, l.id as lote_id, l.codigo as lote_codigo, l.validade, s.quantidade, s.quantidade_reservada, s.quantidade - s.quantidade_reservada as disponivel');
        $individuais = DB::table('itens_individuais as i')->whereNotIn('i.situacao', ['ESTORNADO', 'BAIXADO'])
            ->groupBy('i.local_estoque_id', 'i.material_id')
            ->selectRaw("i.local_estoque_id, i.material_id, NULL as lote_id, NULL as lote_codigo, NULL as validade, COUNT(*) as quantidade, SUM(i.situacao = 'RESERVADO') as quantidade_reservada, SUM(i.situacao = 'DISPONIVEL') as disponivel");

        $q = DB::query()->fromSub($lotes->unionAll($individuais), 's')
            ->join('locais_estoque as le', 'le.id', '=', 's.local_estoque_id')
            ->join('unidades as u', 'u.id', '=', 'le.unidade_id')
            ->join('materiais as m', 'm.id', '=', 's.material_id')
            ->join('categorias as c', 'c.id', '=', 'm.categoria_id')
            ->leftJoin('tipos_material as tm', 'tm.id', '=', 'm.tipo_id')
            ->select(['tm.nome as tipo', 's.*', 'le.codigo as local_codigo', 'le.nome as local_nome', 'u.id as unidade_id', 'u.sigla as unidade_sigla',
                'm.codigo as material_codigo', 'm.nome as material_nome', 'm.modo_controle', 'm.unidade_medida', 'c.nome as categoria', 'c.natureza']);
        $this->authz()->escopar($q, $r->user(), 'estoque.ver', 'le.unidade_id');
        foreach (['local_id' => 's.local_estoque_id', 'unidade_id' => 'u.id', 'material_id' => 's.material_id', 'natureza' => 'c.natureza'] as $k => $col) {
            if (isset($f[$k])) {
                $q->where($col, $f[$k]);
            }
        }
        if (! empty($f['q'])) {
            $q->where(fn ($w) => $w->where('m.nome', 'like', $this->like($f['q']))->orWhere('m.codigo', 'like', $this->like($f['q']))->orWhere('s.lote_codigo', 'like', $this->like($f['q']))
                // Número de série (ou identificador) de item individual daquele material naquele local.
                ->orWhereExists(fn ($e) => $e->from('itens_individuais as ii')->whereColumn('ii.material_id', 's.material_id')->whereColumn('ii.local_estoque_id', 's.local_estoque_id')
                    ->where(fn ($x) => $x->where('ii.numero_serie', 'like', $this->like($f['q']))->orWhere('ii.identificador', 'like', $this->like($f['q'])))));
        }

        return $q;
    }

    public function itensIndividuais(Request $r): JsonResponse
    {
        $this->exigir('estoque.ver');
        $r->validate(['q' => ['nullable', 'string', 'max:80'], 'local_id' => ['nullable', 'integer'], 'material_id' => ['nullable', 'integer'],
            'situacao' => ['nullable', 'in:DISPONIVEL,RESERVADO,ACAUTELADO,ENTREGUE,MANUTENCAO,BAIXADO,ESTORNADO,EM_TRANSITO,FORA_DO_ESTOQUE'],
            // CP-12: filtros por catálogo, unidade e validade do item (coletes).
            'unidade_id' => ['nullable', 'integer'], 'categoria_id' => ['nullable', 'integer'], 'tipo_id' => ['nullable', 'integer'],
            'perfil' => ['nullable', Rule::in(PerfilMaterial::perfis())], 'validade' => ['nullable', 'in:VENCIDO,VENCENDO,VALIDO,COM_VALIDADE']]);
        $q = ItemIndividual::query()->with(['material:id,codigo,nome,tipo_id', 'material.tipo:id,nome,perfil', 'local:id,codigo,nome,unidade_id', 'local.unidade:id,sigla'])
            ->whereIn('local_estoque_id', $this->locaisPermitidos($r, 'estoque.ver'));
        if ($t = $r->query('q')) {
            $q->where(fn ($w) => $w->where('identificador', 'like', $this->like($t))->orWhere('numero_serie', 'like', $this->like($t)));
        }
        foreach (['local_id' => 'local_estoque_id', 'material_id' => 'material_id', 'situacao' => 'situacao'] as $k => $c) {
            if ($r->filled($k)) {
                $q->where($c, $r->query($k));
            }
        }
        if ($r->filled('unidade_id')) {
            $q->whereIn('local_estoque_id', DB::table('locais_estoque')->select('id')->where('unidade_id', $r->query('unidade_id')));
        }
        foreach (['categoria_id', 'tipo_id'] as $k) {
            if ($r->filled($k)) {
                $q->whereIn('material_id', DB::table('materiais')->select('id')->where($k, $r->query($k)));
            }
        }
        if ($r->filled('perfil')) {
            $q->whereIn('material_id', DB::table('materiais as m')->join('tipos_material as t', 't.id', '=', 'm.tipo_id')->select('m.id')->where('t.perfil', $r->query('perfil')));
        }
        [$hoje, $limite] = $this->janelaValidade();
        match ($r->query('validade')) {
            'VENCIDO' => $q->where('validade', '<', $hoje),
            'VENCENDO' => $q->whereBetween('validade', [$hoje, $limite]),
            'VALIDO' => $q->where('validade', '>', $limite),
            'COM_VALIDADE' => $q->whereNotNull('validade'),
            default => null,
        };

        return $this->paginar($r, $q, fn ($i) => [
            'id' => $i->id, 'identificador' => $i->identificador, 'numero_serie' => $i->numero_serie,
            'situacao' => $i->situacao, 'estado_conservacao' => $i->estado_conservacao,
            'fabricado_em' => $i->fabricado_em?->toDateString(), 'validade' => $i->validade?->toDateString(),
            'validade_estado' => self::estadoValidade($i->validade?->toDateString(), $hoje, $limite),
            'tamanho' => $i->tamanho, 'nivel_protecao' => $i->nivel_protecao,
            'material' => $i->material->only(['id', 'codigo', 'nome']) + ['tipo' => $i->material->tipo?->only(['id', 'nome', 'perfil'])],
            'local' => $i->local->only(['id', 'codigo', 'nome']), 'unidade' => $i->local->unidade?->only(['id', 'sigla']),
            'pode_atualizar_conservacao' => $this->authz()->pode($r->user(), 'estoque.entrada', $i->local->unidade_id),
        ], ['id', 'identificador', 'validade'], 'identificador');
    }

    /** [hoje, limite do alerta] — antecedência em config sigarb.alertas.validade_item_dias. */
    public static function janelaValidade(): array
    {
        return [now()->toDateString(), now()->addDays(config('sigarb.alertas.validade_item_dias'))->toDateString()];
    }

    public static function estadoValidade(?string $v, string $hoje, string $limite): ?string
    {
        return $v === null ? null : ($v < $hoje ? 'VENCIDO' : ($v <= $limite ? 'VENCENDO' : 'VALIDO'));
    }

    /**
     * CP-12 — atualização autorizada do ESTADO DE CONSERVAÇÃO (condição física), com justificativa e auditoria.
     * Não altera a situação operacional nem o razão. Item estornado/baixado não muda.
     */
    public function atualizarConservacao(Request $r, ItemIndividual $item): JsonResponse
    {
        $unidade = (int) LocalEstoque::whereKey($item->local_estoque_id)->value('unidade_id');
        if (! $this->authz()->pode($r->user(), 'estoque.ver', $unidade)) {
            throw new SigarbException('NAO_ENCONTRADO', 'Item não encontrado.', 404); // não revela item fora do escopo
        }
        Gate::allowIf(fn ($u) => $this->authz()->pode($u, 'estoque.entrada', $unidade));
        $d = $r->validate(['estado_conservacao' => ['required', Conservacao::regra()], 'justificativa' => ['required', 'string', 'min:10', 'max:500']]);
        $antes = DB::transaction(function () use ($item, $d, $unidade) {
            $atual = ItemIndividual::whereKey($item->id)->lockForUpdate()->firstOrFail();
            if (in_array($atual->situacao, ['ESTORNADO', 'BAIXADO'], true)) {
                throw new SigarbException('ITEM_ENCERRADO', 'Item estornado ou baixado não tem a conservação alterada.', 409);
            }
            if ($atual->estado_conservacao === $d['estado_conservacao']) {
                throw new SigarbException('SITUACAO_INALTERADA', 'O item já está neste estado de conservação.', 409);
            }
            $antes = $atual->estado_conservacao;
            $atual->update(['estado_conservacao' => $d['estado_conservacao']]);
            $this->auditoria()->registrar('item.conservacao_alterada', 'SUCESSO', 'item_individual', $atual->id, $unidade,
                ['antes' => $antes, 'depois' => $d['estado_conservacao'], 'justificativa' => $d['justificativa']]);

            return $antes;
        });

        return response()->json(['id' => $item->id, 'estado_conservacao' => $d['estado_conservacao'], 'anterior' => $antes]);
    }

    public function movimentacoes(Request $r): JsonResponse
    {
        Gate::authorize('viewAny', Movimentacao::class);
        $tipos = implode(',', array_merge(['ENTRADA', 'ESTORNO'], array_keys(OperacaoEstoqueService::ABERTURAS), array_keys(OperacaoEstoqueService::CONCLUSOES)));
        $f = $r->validate(['tipo' => ['nullable', "in:$tipos"], 'pendente' => ['nullable', 'boolean'], 'local_id' => ['nullable', 'integer'], 'de' => ['nullable', 'date'], 'ate' => ['nullable', 'date'],
            'item_individual_id' => ['nullable', 'integer'], 'lote_id' => ['nullable', 'integer'], 'militar_id' => ['nullable', 'integer'], 'material_id' => ['nullable', 'integer']]);
        $locais = $this->locaisPermitidos($r, 'estoque.ver');
        $q = Movimentacao::query()->with(['responsavel:id,nome,login', 'localOrigem:id,codigo', 'localDestino:id,codigo', 'estorno:id,estorno_de_id', 'conclusao:id,vinculo_id'])
            ->withCount('itens')
            ->where(fn ($w) => $w->whereIn('local_destino_id', $locais)->orWhereIn('local_origem_id', $locais));
        if (! empty($f['tipo'])) {
            $q->where('tipo', $f['tipo']);
        }
        // Aberturas ainda sem conclusão (transferências a receber, reservas ativas, itens em manutenção).
        if (! empty($f['pendente'])) {
            $q->whereIn('tipo', OperacaoEstoqueService::PENDENTES)->whereDoesntHave('conclusao')
                ->where(fn ($w) => $w->where('tipo', '!=', 'ENTREGA')->orWhereNotNull('previsao_retorno'));
        }
        if (! empty($f['local_id'])) {
            $q->where(fn ($w) => $w->where('local_destino_id', $f['local_id'])->orWhere('local_origem_id', $f['local_id']));
        }
        if (! empty($f['de'])) {
            $q->where('ocorrido_em', '>=', $f['de']);
        }
        if (! empty($f['ate'])) {
            $q->where('ocorrido_em', '<', date('Y-m-d', strtotime($f['ate'].' +1 day')));
        }
        // RF-027: linha do tempo de um item/lote específico.
        if (! empty($f['item_individual_id'])) {
            $q->whereHas('itens', fn ($w) => $w->where('item_individual_id', $f['item_individual_id']));
        }
        if (! empty($f['militar_id'])) {
            $q->where('militar_id', $f['militar_id']);
        }
        if (! empty($f['material_id'])) {
            $q->whereHas('itens', fn ($w) => $w->where(fn ($x) => $x->whereIn('item_individual_id', DB::table('itens_individuais')->select('id')->where('material_id', $f['material_id']))
                ->orWhereIn('lote_id', DB::table('lotes')->select('id')->where('material_id', $f['material_id']))));
        }
        if (! empty($f['lote_id'])) {
            $q->whereHas('itens', fn ($w) => $w->where('lote_id', $f['lote_id']));
        }

        return $this->paginar($r, $q, fn ($m) => $this->resumo($m), ['id', 'ocorrido_em'], '-ocorrido_em');
    }

    public function movimentacao(Request $r, Movimentacao $movimentacao): JsonResponse
    {
        Gate::authorize('view', $movimentacao);
        $movimentacao->load(['responsavel:id,nome,login', 'localOrigem:id,codigo,nome', 'localDestino:id,codigo,nome', 'estorno', 'estornoDe:id,tipo',
            'itens.itemIndividual.material:id,codigo,nome', 'itens.lote.material:id,codigo,nome']);

        // CP-13 (P-05): o que ainda falta devolver de uma entrega com retorno (para a devolução parcial).
        $pendente = null;
        if ($movimentacao->tipo === 'ENTREGA' && $movimentacao->previsao_retorno && ! $movimentacao->conclusao) {
            [$ind, $lot] = app(OperacaoEstoqueService::class)->restante($movimentacao, null);
            $porItem = $movimentacao->itens->keyBy('item_individual_id');
            $porLote = $movimentacao->itens->whereNotNull('lote_id')->keyBy('lote_id');
            $pendente = [
                'itens' => collect(array_keys($ind))->map(fn ($id) => ['item_individual_id' => $id, 'identificador' => $porItem[$id]->itemIndividual->identificador, 'numero_serie' => $porItem[$id]->itemIndividual->numero_serie,
                    'estado_conservacao' => $porItem[$id]->itemIndividual->estado_conservacao, 'material' => $porItem[$id]->itemIndividual->material->only(['codigo', 'nome'])])->values(),
                'lotes' => collect($lot)->map(fn ($q, $id) => ['lote_id' => $id, 'codigo' => $porLote[$id]->lote->codigo, 'quantidade' => $q,
                    'material' => $porLote[$id]->lote->material->only(['codigo', 'nome'])])->values(),
            ];
        }

        return response()->json($this->resumo($movimentacao) + [
            'pendente_devolucao' => $pendente,
            'origem_descricao' => $movimentacao->origem_descricao,
            'justificativa' => $movimentacao->justificativa,
            'estorno_de_id' => $movimentacao->estorno_de_id,
            'pode_estornar' => $movimentacao->tipo === 'ENTRADA' && ! $movimentacao->estorno
                && Gate::allows('estornar', $movimentacao) && Operacoes::estado('ESTORNO') !== 'BLOQUEADA',
            'itens' => $movimentacao->itens->map(fn ($i) => $i->item_individual_id
                ? ['tipo' => 'INDIVIDUAL', 'quantidade' => 1, 'item' => ['id' => $i->itemIndividual->id, 'identificador' => $i->itemIndividual->identificador, 'numero_serie' => $i->itemIndividual->numero_serie, 'situacao' => $i->itemIndividual->situacao], 'material' => $i->itemIndividual->material->only(['id', 'codigo', 'nome'])]
                : ['tipo' => 'LOTE', 'quantidade' => $i->quantidade, 'lote' => ['id' => $i->lote->id, 'codigo' => $i->lote->codigo, 'validade' => $i->lote->validade?->format('Y-m-d')], 'material' => $i->lote->material->only(['id', 'codigo', 'nome'])]),
        ]);
    }

    /** POST /movements — somente tipos HABILITADOS no portão de políticas (ADR-006). */
    public function registrar(Request $r): JsonResponse
    {
        $tipo = strtoupper((string) $r->input('tipo'));
        if (isset(OperacaoEstoqueService::ABERTURAS[$tipo]) || isset(OperacaoEstoqueService::CONCLUSOES[$tipo])) {
            return $this->operacao($r, $tipo);
        }
        if ($tipo !== 'ENTRADA') {
            if (array_key_exists($tipo, config('sigarb.operacoes'))) {
                $this->auditoria()->registrar('operacao.bloqueada', 'NEGADO', dados: ['operacao' => $tipo]);
                throw SigarbException::operacaoBloqueada($tipo);
            }
            throw new SigarbException('TIPO_INVALIDO', 'Tipo de movimentação desconhecido.', 422);
        }
        $req = app(EntradaRequest::class);
        $d = $req->validated();
        $local = LocalEstoque::findOrFail($d['local_destino_id']);
        Gate::authorize('registrarEntrada', $local);
        [$mov, $replay] = $this->estoque->registrarEntrada($r->user(), $d, $this->chave($r));

        return $this->movimentacao($r, $mov)->setStatusCode($replay ? 200 : 201)->header('Idempotent-Replayed', $replay ? 'true' : 'false');
    }

    /** Operações de regra provisória (ver OperacaoEstoqueService). Portão checado antes da validação. */
    private function operacao(Request $r, string $tipo): JsonResponse
    {
        $abertura = isset(OperacaoEstoqueService::ABERTURAS[$tipo]);
        $op = $abertura ? OperacaoEstoqueService::ABERTURAS[$tipo][2] : OperacaoEstoqueService::ABERTURAS[OperacaoEstoqueService::CONCLUSOES[$tipo][0]][2];
        if (Operacoes::estado($op) === 'BLOQUEADA') {
            $this->auditoria()->registrar('operacao.bloqueada', 'NEGADO', dados: ['operacao' => $tipo]);
            throw SigarbException::operacaoBloqueada($op);
        }
        $perm = self::PERMISSAO[$op];
        $svc = app(OperacaoEstoqueService::class);
        if (! $abertura) {
            $d = $r->validate(['vinculo_id' => ['required', 'integer'], 'justificativa' => ['nullable', 'string', 'max:500'],
                // CP-13 (P-05): devolução parcial — itens/quantidades que voltam; ausente = todo o restante.
                'itens' => [$tipo === 'DEVOLUCAO' ? 'nullable' : 'prohibited', 'array', 'min:1', 'max:'.config('sigarb.movimentacao.max_itens')],
                'itens.*.item_individual_id' => ['nullable', 'integer', 'distinct', 'required_without:itens.*.lote_id', 'prohibits:itens.*.lote_id'],
                'itens.*.lote_id' => ['nullable', 'integer', 'required_without:itens.*.item_individual_id'],
                'itens.*.quantidade' => ['required_with:itens.*.lote_id', 'nullable', 'integer', 'min:1', 'max:10000000'],
                'itens.*.estado_conservacao' => ['nullable', 'prohibits:itens.*.lote_id', Conservacao::regra()]]);
            $ab = Movimentacao::find($d['vinculo_id']) ?? throw new SigarbException('VINCULO_INVALIDO', 'Movimentação vinculada inexistente.', 422);
            $local = $tipo === 'RECEBIMENTO' ? $ab->local_destino_id : $ab->local_origem_id;
            $this->exigir($perm, LocalEstoque::whereKey($local)->value('unidade_id'));
            [$mov, $replay] = $svc->concluir($r->user(), $tipo, $ab, $d['justificativa'] ?? null, $this->chave($r), $d['itens'] ?? null);
        } else {
            $max = config('sigarb.movimentacao.max_itens');
            $exigeDoc = in_array($tipo, ['SAIDA', 'BAIXA', 'TRANSFERENCIA'], true);
            $d = $r->validate([
                'local_origem_id' => ['required', 'integer', 'exists:locais_estoque,id'],
                'local_destino_id' => [$tipo === 'TRANSFERENCIA' ? 'required' : 'prohibited', 'integer', 'exists:locais_estoque,id'],
                'militar_id' => [$tipo === 'ENTREGA' ? 'required' : 'prohibited', 'integer', Rule::exists('militares', 'id')->where('situacao', 'ATIVO')],
                'previsao_retorno' => [$tipo === 'ENTREGA' ? 'nullable' : 'prohibited', 'date_format:Y-m-d', 'after_or_equal:today'],
                'destinacao' => [$tipo === 'SAIDA' ? 'required' : 'nullable', 'string', 'min:3', 'max:255'],
                'documento_referencia' => [$exigeDoc ? 'required' : 'nullable', 'string', 'min:3', 'max:120'],
                'justificativa' => ['required', 'string', 'min:5', 'max:500'],
                'itens' => ['required', 'array', 'min:1', "max:$max"],
                'itens.*.item_individual_id' => ['nullable', 'integer', 'distinct', 'required_without:itens.*.lote_id', 'prohibits:itens.*.lote_id'],
                'itens.*.lote_id' => ['nullable', 'integer', 'required_without:itens.*.item_individual_id'],
                'itens.*.quantidade' => ['required_with:itens.*.lote_id', 'nullable', 'integer', 'min:1', 'max:10000000'],
            ]);
            $this->exigir($perm, LocalEstoque::whereKey($d['local_origem_id'])->value('unidade_id'));
            if (isset($d['militar_id'])) {
                $this->exigir('militares.ver', Militar::whereKey($d['militar_id'])->value('unidade_id'));
            }
            [$mov, $replay] = $svc->abrir($r->user(), $tipo, $d, $this->chave($r));
        }

        return $this->movimentacao($r, $mov)->setStatusCode($replay ? 200 : 201)->header('Idempotent-Replayed', $replay ? 'true' : 'false');
    }

    public const PERMISSAO = ['SAIDA' => 'estoque.saida', 'BAIXA' => 'estoque.baixar', 'TRANSFERENCIA' => 'estoque.transferir',
        'RESERVA' => 'estoque.reservar', 'MANUTENCAO' => 'estoque.manutencao', 'ENTREGA' => 'estoque.entregar'];

    public function estornar(EstornoRequest $r, Movimentacao $movimentacao): JsonResponse
    {
        Gate::authorize('estornar', $movimentacao);
        [$mov, $replay] = $this->estoque->estornar($r->user(), $movimentacao, $r->validated('justificativa'), $this->chave($r));

        return $this->movimentacao($r, $mov)->setStatusCode($replay ? 200 : 201)->header('Idempotent-Replayed', $replay ? 'true' : 'false');
    }

    private function chave(Request $r): string
    {
        $c = (string) $r->header('Idempotency-Key');
        if (! preg_match('/^[A-Za-z0-9-]{16,80}$/', $c)) {
            throw new SigarbException('IDEMPOTENCY_KEY_OBRIGATORIA', 'Cabeçalho Idempotency-Key obrigatório (16–80 caracteres alfanuméricos ou hífen).', 428);
        }

        return $c;
    }

    private function locaisPermitidos(Request $r, string $perm): Builder
    {
        $q = DB::table('locais_estoque')->select('id');

        return $this->authz()->escopar($q, $r->user(), $perm);
    }

    private function resumo(Movimentacao $m): array
    {
        return [
            'id' => $m->id, 'tipo' => $m->tipo, 'ocorrido_em' => $m->ocorrido_em->toIso8601String(),
            'responsavel' => ['id' => $m->responsavel->id, 'nome' => $m->responsavel->nome],
            'local_origem' => $m->localOrigem?->only(['id', 'codigo', 'nome']),
            'local_destino' => $m->localDestino?->only(['id', 'codigo', 'nome']),
            'documento_referencia' => $m->documento_referencia,
            'itens_count' => $m->itens_count ?? $m->itens()->count(),
            'estornada' => (bool) $m->estorno,
            'estorno_id' => $m->estorno?->id,
            'provisoria' => (bool) $m->provisoria,
            'vinculo_id' => $m->vinculo_id,
            'conclusao_id' => in_array($m->tipo, OperacaoEstoqueService::PENDENTES, true) ? $m->conclusao?->id : null,
            'militar' => $m->militar_id ? $m->militar?->only(['id', 'nome_guerra']) : null,
            'previsao_retorno' => $m->previsao_retorno?->format('Y-m-d'),
        ];
    }
}
