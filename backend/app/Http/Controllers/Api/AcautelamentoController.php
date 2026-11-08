<?php

namespace App\Http\Controllers\Api;

use App\Models\Acautelamento;
use App\Models\ItemIndividual;
use App\Models\Militar;
use App\Services\AcautelamentoService;
use App\Support\Conservacao;
use App\Support\Operacoes;
use App\Support\SigarbException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** RF-015..018 — acautelamento (regra provisória). Rotas por código ULID; 404 uniforme fora do escopo. */
class AcautelamentoController extends ApiController
{
    public function __construct(private AcautelamentoService $svc) {}

    public function index(Request $r): JsonResponse
    {
        $this->exigir('estoque.ver');
        $f = $r->validate(['estado' => ['nullable', 'in:SOLICITADO,APROVADO,REJEITADO,CANCELADO,ATIVO,ENCERRADO'], 'inspecao_vencendo' => ['nullable', 'boolean'], 'militar_id' => ['nullable', 'integer']]);
        $q = Acautelamento::query()->with(['militar:id,nome_guerra,posto_graduacao_id', 'militar.postoGraduacao:id,sigla', 'item:id,identificador,material_id', 'item.material:id,nome', 'unidade:id,sigla']);
        $this->authz()->escopar($q, $r->user(), 'estoque.ver');
        if (! empty($f['estado'])) {
            $q->where('estado', $f['estado']);
        }
        if (! empty($f['militar_id'])) {
            $q->where('militar_id', $f['militar_id']);
        }
        if (! empty($f['inspecao_vencendo'])) {
            $q->where('estado', 'ATIVO')->where('proxima_inspecao', '<=', now()->addDays(config('sigarb.acautelamento.alerta_inspecao_dias')));
        }

        return $this->paginar($r, $q, fn ($a) => $this->resumo($a), ['id', 'proxima_inspecao'], '-id');
    }

    public function show(Request $r, string $codigo): JsonResponse
    {
        $a = $this->obter($r, $codigo);
        $a->load(['solicitante:id,nome', 'aprovador:id,nome', 'inspecoes.inspetor:id,nome']);
        $u = $r->user();
        $ops = fn (string $op) => Operacoes::estado($op) !== 'BLOQUEADA';

        return response()->json($this->resumo($a) + [
            'justificativa' => $a->justificativa, 'solicitante' => $a->solicitante->only(['id', 'nome']),
            'aprovador' => $a->aprovador?->only(['id', 'nome']), 'decidido_em' => $a->decidido_em?->toIso8601String(),
            'decisao_justificativa' => $a->decisao_justificativa, 'entrega_id' => $a->entrega_id, 'devolucao_id' => $a->devolucao_id,
            'inspecoes' => $a->inspecoes->map(fn ($i) => ['id' => $i->id, 'realizada_em' => $i->realizada_em->toIso8601String(), 'resultado' => $i->resultado, 'observacao' => $i->observacao, 'inspetor' => $i->inspetor->nome]),
            'acoes' => [
                'decidir' => $a->estado === 'SOLICITADO' && $ops('ACAUTELAMENTO') && $this->authz()->pode($u, 'acautelamento.aprovar', $a->unidade_id)
                    && ($a->solicitante_id !== $u->id || $this->authz()->temPapel($u, config('sigarb.politicas.autoaprovacao_papeis'), $a->unidade_id)),
                'cancelar' => in_array($a->estado, ['SOLICITADO', 'APROVADO'], true) && ($a->solicitante_id === $u->id || $this->authz()->pode($u, 'acautelamento.operar', $a->unidade_id)),
                'entregar' => $a->estado === 'APROVADO' && $ops('ACAUTELAMENTO') && $this->authz()->pode($u, 'acautelamento.operar', $a->unidade_id),
                'inspecionar' => $a->estado === 'ATIVO' && $ops('INSPECAO') && $this->authz()->pode($u, 'acautelamento.operar', $a->unidade_id),
                'desacautelar' => $a->estado === 'ATIVO' && $ops('DESACAUTELAMENTO') && $this->authz()->pode($u, 'acautelamento.operar', $a->unidade_id),
                'carteira' => $a->estado === 'ATIVO' && $this->authz()->pode($u, 'acautelamento.operar', $a->unidade_id),
            ],
        ]);
    }

    public function solicitar(Request $r): JsonResponse
    {
        Operacoes::exigir('ACAUTELAMENTO');
        $d = $r->validate([
            'militar_id' => ['required', 'integer', 'exists:militares,id'],
            'item_individual_id' => ['required', 'integer', 'exists:itens_individuais,id'],
            'justificativa' => ['required', 'string', 'min:5', 'max:500'],
        ]);
        $unidade = ItemIndividual::with('local')->find($d['item_individual_id'])->local->unidade_id;
        $this->exigir('acautelamento.solicitar', $unidade);
        $this->exigir('militares.ver', Militar::whereKey($d['militar_id'])->value('unidade_id'));

        return $this->detalhe($r, $this->svc->solicitar($r->user(), $d), 201);
    }

    public function decidir(Request $r, string $codigo): JsonResponse
    {
        $a = $this->obter($r, $codigo);
        $d = $r->validate(['decisao' => ['required', 'in:APROVAR,REJEITAR'], 'justificativa' => ['nullable', 'string', 'max:500'], 'versao' => ['required', 'integer']]);
        $this->exigir('acautelamento.aprovar', $a->unidade_id);

        return $this->detalhe($r, $this->svc->decidir($r->user(), $a, $d['decisao'] === 'APROVAR', $d['justificativa'] ?? null, $d['versao']));
    }

    public function cancelar(Request $r, string $codigo): JsonResponse
    {
        $a = $this->obter($r, $codigo);
        $d = $r->validate(['justificativa' => ['required', 'string', 'min:5', 'max:500'], 'versao' => ['required', 'integer']]);
        if ($a->solicitante_id !== $r->user()->id) {
            $this->exigir('acautelamento.operar', $a->unidade_id);
        }

        return $this->detalhe($r, $this->svc->cancelar($r->user(), $a, $d['justificativa'], $d['versao']));
    }

    public function entregar(Request $r, string $codigo): JsonResponse
    {
        $a = $this->obter($r, $codigo);
        $d = $r->validate(['versao' => ['required', 'integer']]);
        $this->exigir('acautelamento.operar', $a->unidade_id);

        return $this->detalhe($r, $this->svc->entregar($r->user(), $a, $d['versao']));
    }

    public function inspecionar(Request $r, string $codigo): JsonResponse
    {
        $a = $this->obter($r, $codigo);
        $d = $r->validate(['resultado' => ['required', 'in:CONFORME,NAO_CONFORME'], 'observacao' => ['nullable', 'string', 'max:500', Rule::requiredIf($r->input('resultado') === 'NAO_CONFORME')], 'versao' => ['required', 'integer']]);
        $this->exigir('acautelamento.operar', $a->unidade_id);

        return $this->detalhe($r, $this->svc->inspecionar($r->user(), $a, $d['resultado'], $d['observacao'] ?? null, $d['versao']));
    }

    public function desacautelar(Request $r, string $codigo): JsonResponse
    {
        $a = $this->obter($r, $codigo);
        $d = $r->validate(['estado_conservacao' => ['required', Conservacao::regra()], 'divergencias' => ['nullable', 'string', 'max:400'], 'versao' => ['required', 'integer']]);
        $this->exigir('acautelamento.operar', $a->unidade_id);

        return $this->detalhe($r, $this->svc->desacautelar($r->user(), $a, $d['estado_conservacao'], $d['divergencias'] ?? null, $d['versao']));
    }

    /** RF-017 provisório: dados da carteira para impressão. O QR leva só o token opaco (P-07). */
    public function carteira(Request $r, string $codigo): JsonResponse
    {
        $a = $this->obter($r, $codigo);
        $this->exigir('acautelamento.operar', $a->unidade_id);
        if ($a->estado !== 'ATIVO' || ! $a->carteira_token) {
            throw new SigarbException('CARTEIRA_INDISPONIVEL', 'Só há carteira para acautelamento ativo.', 409);
        }
        $a->load(['militar.postoGraduacao', 'item.material.calibre', 'unidade']);
        $this->auditoria()->registrar('acautelamento.carteira_emitida', 'SUCESSO', 'acautelamento', $a->id, $a->unidade_id, ['codigo' => $a->codigo]);

        return response()->json([
            'codigo' => $a->codigo, 'token' => $a->carteira_token, 'emitida_em' => now()->toIso8601String(),
            'efetivo' => ['nome' => $a->militar->nome, 'nome_guerra' => $a->militar->nome_guerra, 'posto' => $a->militar->postoGraduacao->sigla, 'matricula' => $this->authz()->pode($r->user(), 'militares.ver_detalhe', $a->militar->unidade_id) ? $a->militar->matricula : '•••'.substr($a->militar->matricula, -3)],
            'item' => ['identificador' => $a->item->identificador, 'numero_serie' => $a->item->numero_serie, 'material' => $a->item->material->nome, 'calibre' => $a->item->material->calibre?->nome],
            'unidade' => $a->unidade->sigla, 'proxima_inspecao' => $a->proxima_inspecao?->format('Y-m-d'),
            'valida_ate' => $a->proxima_inspecao?->format('Y-m-d'), // P-07: validade = próxima inspeção
        ]);
    }

    /** Verificação do QR: exige sessão (P-07 não autoriza consulta pública) e devolve o mínimo. */
    public function verificar(Request $r, string $token): JsonResponse
    {
        $this->exigir('estoque.ver');
        $a = Acautelamento::with(['militar:id,nome_guerra,posto_graduacao_id', 'militar.postoGraduacao:id,sigla', 'item:id,identificador'])
            ->where('carteira_token', $token)->where('estado', 'ATIVO')->first();
        $this->auditoria()->registrar('acautelamento.carteira_verificada', $a ? 'SUCESSO' : 'FALHA', 'acautelamento', $a?->id, $a?->unidade_id);

        // P-07 (CP-14): vale enquanto o acautelamento estiver ativo e a inspeção em dia.
        $vencida = $a && $a->proxima_inspecao && $a->proxima_inspecao->lt(today());

        return response()->json($a ? ['valida' => ! $vencida, 'motivo' => $vencida ? 'INSPECAO_VENCIDA' : null, 'efetivo' => $a->militar->postoGraduacao->sigla.' '.$a->militar->nome_guerra,
            'item' => $a->item->identificador, 'proxima_inspecao' => $a->proxima_inspecao?->format('Y-m-d')] : ['valida' => false, 'motivo' => 'INEXISTENTE_OU_ENCERRADA']);
    }

    private function obter(Request $r, string $codigo): Acautelamento
    {
        $a = Acautelamento::with(['militar:id,nome_guerra,posto_graduacao_id,unidade_id', 'militar.postoGraduacao:id,sigla', 'item:id,identificador,numero_serie,material_id', 'item.material:id,nome', 'unidade:id,sigla'])
            ->where('codigo', $codigo)->first();
        if (! $a || ! $this->authz()->pode($r->user(), 'estoque.ver', $a->unidade_id)) {
            throw new SigarbException('NAO_ENCONTRADO', 'Acautelamento não encontrado.', 404);
        }

        return $a;
    }

    private function detalhe(Request $r, Acautelamento $a, int $status = 200): JsonResponse
    {
        return $this->show($r, $a->codigo)->setStatusCode($status);
    }

    private function resumo(Acautelamento $a): array
    {
        return [
            'codigo' => $a->codigo, 'estado' => $a->estado, 'versao' => $a->versao,
            'efetivo' => ['id' => $a->militar->id, 'nome_guerra' => $a->militar->nome_guerra, 'posto' => $a->militar->postoGraduacao?->sigla],
            'item' => ['id' => $a->item->id, 'identificador' => $a->item->identificador, 'material' => $a->item->material?->nome],
            'unidade' => $a->unidade->only(['id', 'sigla']),
            'proxima_inspecao' => $a->proxima_inspecao?->format('Y-m-d'),
            'inspecao_atrasada' => $a->estado === 'ATIVO' && $a->proxima_inspecao?->isPast(),
            'criado_em' => $a->created_at->toIso8601String(),
        ];
    }
}
