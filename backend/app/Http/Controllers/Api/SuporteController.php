<?php

namespace App\Http\Controllers\Api;

use App\Models\Chamado;
use App\Models\ChamadoMensagem;
use App\Models\EventoAuditoria;
use App\Models\Notificacao;
use App\Models\Usuario;
use App\Services\SuporteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** Central de Suporte (abertura, conversa, atendimento) e notificações in-app. */
class SuporteController extends ApiController
{
    public function __construct(private SuporteService $suporte) {}

    /** Meus chamados (sempre só os do próprio usuário). */
    public function meus(Request $r): JsonResponse
    {
        $this->exigir('suporte.abrir');
        $q = Chamado::query()->where('solicitante_id', $r->user()->id);
        if ($s = $r->validate(['status' => ['nullable', Rule::in(Chamado::STATUS)]])['status'] ?? null) {
            $q->where('status', $s);
        }

        return $this->paginar($r, $q, fn (Chamado $c) => $this->resumo($c), ['id', 'updated_at'], '-updated_at');
    }

    /** Fila de atendimento, filtrada pelo escopo de `suporte.atender`. */
    public function fila(Request $r): JsonResponse
    {
        $this->exigir('suporte.atender');
        $f = $r->validate([
            'status' => ['nullable', Rule::in(Chamado::STATUS)], 'prioridade' => ['nullable', Rule::in(Chamado::PRIORIDADES)],
            'categoria' => ['nullable', Rule::in(Chamado::CATEGORIAS)], 'atribuido' => ['nullable', 'in:eu,ninguem'],
            'q' => ['nullable', 'string', 'max:80'],
        ]);
        $q = $this->escopoAtendimento($r)->with(['solicitante:id,nome,login', 'atribuidoA:id,nome', 'unidade:id,sigla']);
        foreach (['status', 'prioridade', 'categoria'] as $k) {
            if (isset($f[$k])) {
                $q->where($k, $f[$k]);
            }
        }
        if (($f['atribuido'] ?? null) === 'eu') {
            $q->where('atribuido_a_id', $r->user()->id);
        } elseif (($f['atribuido'] ?? null) === 'ninguem') {
            $q->whereNull('atribuido_a_id');
        }
        if (! empty($f['q'])) {
            $q->where(fn ($w) => $w->where('protocolo', 'like', $this->like($f['q']))->orWhere('assunto', 'like', $this->like($f['q'])));
        }

        return $this->paginar($r, $q, fn (Chamado $c) => $this->resumo($c, true), ['id', 'updated_at', 'prioridade'], '-updated_at');
    }

    public function resumoFila(Request $r): JsonResponse
    {
        $this->exigir('suporte.atender');
        $contagens = $this->escopoAtendimento($r)->selectRaw('status, COUNT(*) total')->groupBy('status')->pluck('total', 'status');

        return response()->json([
            'por_status' => collect(Chamado::STATUS)->mapWithKeys(fn ($s) => [$s => (int) ($contagens[$s] ?? 0)]),
            'sem_responsavel' => $this->escopoAtendimento($r)->whereNull('atribuido_a_id')->whereIn('status', ['ABERTO', 'EM_ATENDIMENTO'])->count(),
            'meus' => $this->escopoAtendimento($r)->where('atribuido_a_id', $r->user()->id)->whereNotIn('status', ['RESOLVIDO', 'CANCELADO'])->count(),
        ]);
    }

    public function store(Request $r): JsonResponse
    {
        $this->exigir('suporte.abrir');
        $d = $r->validate([
            'categoria' => ['required', Rule::in(Chamado::CATEGORIAS)], 'prioridade' => ['required', Rule::in(Chamado::PRIORIDADES)],
            'assunto' => ['required', 'string', 'min:5', 'max:150'], 'descricao' => ['required', 'string', 'min:10', 'max:5000'],
            'rota' => ['nullable', 'string', 'max:200', 'regex:#^/[A-Za-z0-9/_\-]*$#'],
        ]);
        // Diagnóstico seguro: só caminho da tela (sem query string), navegador truncado e request_id. Nunca corpo/cabeçalhos HTTP.
        $diag = array_filter([
            'rota' => $d['rota'] ?? null,
            'navegador' => Str::limit((string) $r->userAgent(), 160, ''),
            'request_id' => $r->attributes->get('request_id'),
            'versao' => config('app.versao'),
        ]);
        $c = $this->suporte->abrir($r->user(), $d, $diag);

        return response()->json($this->detalhe($r, $c->fresh()), 201);
    }

    public function show(Request $r, string $codigo): JsonResponse
    {
        $this->exigir('suporte.abrir');

        return response()->json($this->detalhe($r, $this->suporte->visivel($r->user(), $codigo)));
    }

    public function responder(Request $r, string $codigo): JsonResponse
    {
        $this->exigir('suporte.abrir');
        $c = $this->suporte->visivel($r->user(), $codigo);
        $d = $r->validate(['corpo' => ['required', 'string', 'min:2', 'max:5000'], 'tipo' => ['nullable', 'in:PUBLICA,INTERNA']]);
        $this->suporte->responder($r->user(), $c, $d['corpo'], $d['tipo'] ?? 'PUBLICA');

        return response()->json($this->detalhe($r, $c->fresh()), 201);
    }

    public function atualizar(Request $r, string $codigo): JsonResponse
    {
        $this->exigir('suporte.atender');
        $c = $this->suporte->visivel($r->user(), $codigo);
        $d = $r->validate([
            'versao' => ['required', 'integer', 'min:1'], 'status' => ['nullable', Rule::in(Chamado::STATUS)],
            'prioridade' => ['nullable', Rule::in(Chamado::PRIORIDADES)], 'atribuido_a_id' => ['nullable', 'integer'],
        ]);
        if (! $r->has('atribuido_a_id')) {
            unset($d['atribuido_a_id']);
        }
        $this->suporte->atualizar($r->user(), $c, $d);

        return response()->json($this->detalhe($r, $c->fresh()));
    }

    public function reabrir(Request $r, string $codigo): JsonResponse
    {
        $this->exigir('suporte.abrir');
        $c = $this->suporte->visivel($r->user(), $codigo);
        $d = $r->validate(['versao' => ['required', 'integer', 'min:1'], 'motivo' => ['required', 'string', 'min:5', 'max:1000']]);
        $this->suporte->reabrir($r->user(), $c, (int) $d['versao'], $d['motivo']);

        return response()->json($this->detalhe($r, $c->fresh()));
    }

    /** Atendentes elegíveis para atribuição neste chamado. */
    public function atendentes(Request $r, string $codigo): JsonResponse
    {
        $this->exigir('suporte.atender');
        $c = $this->suporte->visivel($r->user(), $codigo);
        $lista = Usuario::where('ativo', true)->orderBy('nome')->get(['id', 'nome', 'login'])
            ->filter(fn (Usuario $u) => $this->suporte->atende($u, $c))->values();

        return response()->json(['data' => $lista]);
    }

    /** Histórico de auditoria do chamado (somente atendimento; sem textos livres — a auditoria não os guarda). */
    public function historico(Request $r, string $codigo): JsonResponse
    {
        $this->exigir('suporte.atender');
        $c = $this->suporte->visivel($r->user(), $codigo);
        if (! $this->suporte->atende($r->user(), $c)) {
            $this->exigir('suporte.atender', $c->unidade_id);
        }
        $ev = EventoAuditoria::with('usuario:id,nome,login')->where('recurso_tipo', 'chamado')->where('recurso_id', $c->id)->orderBy('id')->get();

        return response()->json(['data' => $ev->map(fn (EventoAuditoria $e) => [
            'id' => $e->id, 'ocorrido_em' => $e->ocorrido_em->toIso8601String(), 'acao' => $e->acao, 'resultado' => $e->resultado,
            'usuario' => $e->usuario?->only(['id', 'nome', 'login']), 'request_id' => $e->request_id, 'dados' => $e->dados,
        ])]);
    }

    public function notificacoes(Request $r): JsonResponse
    {
        $q = Notificacao::where('usuario_id', $r->user()->id);
        $itens = (clone $q)->with('chamado:id,codigo,protocolo')->orderByDesc('id')->limit(30)->get();

        return response()->json([
            'nao_lidas' => (clone $q)->whereNull('lida_em')->count(),
            'data' => $itens->map(fn (Notificacao $n) => [
                'id' => $n->id, 'tipo' => $n->tipo, 'texto' => $n->texto, 'lida' => $n->lida_em !== null,
                'criada_em' => $n->created_at->toIso8601String(), 'chamado' => $n->chamado?->only(['codigo', 'protocolo']),
            ]),
        ]);
    }

    public function marcarLida(Request $r, int $id): JsonResponse
    {
        // Filtro pelo dono na própria consulta: id alheio = 404, sem revelar existência.
        $n = Notificacao::where('usuario_id', $r->user()->id)->findOrFail($id);
        $n->lida_em ??= now();
        $n->save();

        return response()->json(['ok' => true]);
    }

    public function marcarTodas(Request $r): JsonResponse
    {
        Notificacao::where('usuario_id', $r->user()->id)->whereNull('lida_em')->update(['lida_em' => now()]);

        return response()->json(['ok' => true]);
    }

    private function escopoAtendimento(Request $r)
    {
        $q = Chamado::query();
        $ids = $this->authz()->unidadesPermitidas($r->user(), 'suporte.atender');
        if ($ids !== null) {
            $q->whereIn('unidade_id', $ids ?: [0]); // chamados sem unidade (contas globais) só para atendimento global
        }

        return $q;
    }

    private function resumo(Chamado $c, bool $fila = false): array
    {
        return array_filter([
            'codigo' => $c->codigo, 'protocolo' => $c->protocolo, 'assunto' => $c->assunto, 'categoria' => $c->categoria,
            'prioridade' => $c->prioridade, 'status' => $c->status, 'versao' => $c->versao,
            'criado_em' => $c->created_at->toIso8601String(), 'atualizado_em' => $c->updated_at->toIso8601String(),
            'solicitante' => $fila ? $c->solicitante?->only(['id', 'nome', 'login']) : null,
            'atribuido_a' => $fila ? $c->atribuidoA?->only(['id', 'nome']) : null,
            'unidade' => $fila ? $c->unidade?->only(['id', 'sigla']) : null,
        ], fn ($v) => $v !== null);
    }

    private function detalhe(Request $r, Chamado $c): array
    {
        $atende = $this->suporte->atende($r->user(), $c);
        $c->load(['solicitante:id,nome,login', 'atribuidoA:id,nome', 'unidade:id,sigla']);
        $msgs = ChamadoMensagem::with('autor:id,nome')->where('chamado_id', $c->id)
            ->when(! $atende, fn ($q) => $q->where('tipo', '<>', 'INTERNA'))->orderBy('id')->get();

        return $this->resumo($c, true) + [
            'atendimento' => $atende,
            'resolvido_em' => $c->resolvido_em?->toIso8601String(),
            'reaberturas' => $c->reaberturas,
            'pode_reabrir' => $c->status === 'RESOLVIDO' && ($atende || ($c->resolvido_em?->gte(now()->subDays(SuporteService::DIAS_REABERTURA))
                && $c->reaberturas < SuporteService::MAX_REABERTURAS)),
            'transicoes' => $atende ? Chamado::TRANSICOES[$c->status] : [],
            'diagnostico' => $atende ? $c->diagnostico : null,
            'mensagens' => $msgs->map(fn (ChamadoMensagem $m) => [
                'id' => $m->id, 'tipo' => $m->tipo, 'corpo' => $m->corpo, 'redigido' => $m->redigido,
                'autor' => $m->autor?->only(['id', 'nome']), 'do_solicitante' => $m->autor_id === $c->solicitante_id,
                'criada_em' => $m->created_at->toIso8601String(),
            ]),
        ];
    }
}
