<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\UsuarioRequest;
use App\Models\AtribuicaoPapel;
use App\Models\Papel;
use App\Models\Usuario;
use App\Services\DispositivoService;
use App\Support\SigarbException;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/** RF-004, RF-002 (revogação administrativa), UC-15. Histórico preservado: contas são desativadas, nunca apagadas. */
class UsuarioController extends ApiController
{
    public function index(Request $r): JsonResponse
    {
        Gate::authorize('viewAny', Usuario::class);
        $r->validate(['q' => ['nullable', 'string', 'max:80'], 'ativo' => ['nullable', 'boolean']]);
        $q = Usuario::query()->with(['atribuicoes.papel', 'atribuicoes.unidade', 'militar:id,nome_guerra']);
        if ($t = $r->query('q')) {
            $q->where(fn ($w) => $w->where('nome', 'like', $this->like($t))->orWhere('login', 'like', $this->like($t)));
        }
        if ($r->has('ativo')) {
            $q->where('ativo', $r->boolean('ativo'));
        }

        return $this->paginar($r, $q, fn ($u) => $this->dto($u), ['id', 'nome', 'login'], 'nome');
    }

    public function show(Usuario $usuario): JsonResponse
    {
        Gate::authorize('view', $usuario);

        return response()->json($this->dto($usuario->load(['atribuicoes.papel', 'atribuicoes.unidade', 'militar:id,nome_guerra'])));
    }

    public function store(UsuarioRequest $r): JsonResponse
    {
        Gate::authorize('create', Usuario::class);
        $u = Usuario::create($r->validated());
        $this->auditoria()->registrar('usuario.criado', 'SUCESSO', 'usuario', $u->id, dados: Arr::except($r->validated(), ['password']));

        return response()->json($this->dto($u->load(['atribuicoes', 'militar'])), 201);
    }

    public function update(UsuarioRequest $r, Usuario $usuario): JsonResponse
    {
        Gate::authorize('update', $usuario);
        $d = $r->validated();
        if (array_key_exists('ativo', $d) && ! $d['ativo'] && $usuario->id === $r->user()->id) {
            throw new SigarbException('AUTO_DESATIVACAO', 'Não é permitido desativar a própria conta.', 422);
        }
        DB::transaction(function () use ($usuario, $d) {
            $antes = Arr::except($usuario->only(array_keys($d)), ['password']);
            if (array_key_exists('ativo', $d) && $d['ativo'] !== $usuario->ativo) {
                $usuario->desativado_em = $d['ativo'] ? null : now();
                if (! $d['ativo']) {
                    DB::table('sessions')->where('user_id', $usuario->id)->delete(); // revogação imediata
                    app(DispositivoService::class)->revogarTodos($usuario->id);
                }
            }
            $usuario->fill($d)->save();
            $this->auditoria()->registrar('usuario.atualizado', 'SUCESSO', 'usuario', $usuario->id, dados: [
                'antes' => $antes, 'depois' => Arr::except($d, ['password']), 'senha_redefinida' => isset($d['password']),
            ]);
            if (isset($d['password'])) {
                DB::table('sessions')->where('user_id', $usuario->id)->delete();
                app(DispositivoService::class)->revogarTodos($usuario->id);
            }
        });

        return response()->json($this->dto($usuario->fresh(['atribuicoes.papel', 'atribuicoes.unidade', 'militar'])));
    }

    public function atribuirPapel(Request $r, Usuario $usuario): JsonResponse
    {
        Gate::authorize('alterarAcesso', $usuario);
        $d = $r->validate(['papel' => ['required', 'exists:papeis,codigo'], 'unidade_id' => ['nullable', 'integer', 'exists:unidades,id'],
            // P-06 (CP-14): delegação/substituição temporária — vale até o fim do dia informado.
            'expira_em' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:today']]);
        $expira = isset($d['expira_em']) ? Carbon::parse($d['expira_em'])->endOfDay() : null;
        $papel = Papel::where('codigo', $d['papel'])->firstOrFail();
        $existe = AtribuicaoPapel::where('usuario_id', $usuario->id)->where('papel_id', $papel->id)
            ->where('unidade_id', $d['unidade_id'] ?? null)->first();
        if ($existe && (! $existe->expira_em || $existe->expira_em->isFuture())) {
            throw new SigarbException('ATRIBUICAO_DUPLICADA', 'O usuário já possui este papel neste escopo.', 422);
        }
        // Delegação vencida no mesmo escopo é renovada (UNIQUE usuario+papel+unidade).
        $a = $existe ? tap($existe)->update(['expira_em' => $expira, 'concedido_por_id' => $r->user()->id])
            : AtribuicaoPapel::create(['usuario_id' => $usuario->id, 'papel_id' => $papel->id, 'unidade_id' => $d['unidade_id'] ?? null, 'concedido_por_id' => $r->user()->id, 'expira_em' => $expira]);
        $this->auditoria()->registrar('acesso.papel.atribuido', 'SUCESSO', 'usuario', $usuario->id, $a->unidade_id, ['papel' => $papel->codigo, 'unidade_id' => $a->unidade_id, 'expira_em' => $expira?->toIso8601String()]);

        return response()->json($this->dto($usuario->fresh(['atribuicoes.papel', 'atribuicoes.unidade', 'militar'])), 201);
    }

    public function removerPapel(Request $r, Usuario $usuario, AtribuicaoPapel $atribuicao): JsonResponse
    {
        Gate::authorize('alterarAcesso', $usuario);
        abort_unless($atribuicao->usuario_id === $usuario->id, 404);
        $info = ['papel' => $atribuicao->papel->codigo, 'unidade_id' => $atribuicao->unidade_id];
        $atribuicao->delete();
        $this->auditoria()->registrar('acesso.papel.removido', 'SUCESSO', 'usuario', $usuario->id, $info['unidade_id'], $info);

        return response()->json($this->dto($usuario->fresh(['atribuicoes.papel', 'atribuicoes.unidade', 'militar'])));
    }

    public function revogarSessoes(Request $r, Usuario $usuario): JsonResponse
    {
        Gate::allowIf(fn ($u) => $this->authz()->global($u, 'sessoes.revogar') && $u->id !== $usuario->id);
        $n = DB::table('sessions')->where('user_id', $usuario->id)->delete();
        app(DispositivoService::class)->revogarTodos($usuario->id);
        $this->auditoria()->registrar('auth.sessao.revogada_admin', 'SUCESSO', 'usuario', $usuario->id, dados: ['quantidade' => $n]);

        return response()->json(['revogadas' => $n]);
    }

    public function papeis(): JsonResponse
    {
        Gate::authorize('viewAny', Usuario::class);

        return response()->json(['data' => Papel::with('permissoes:id,codigo')->orderBy('id')->get()->map(fn ($p) => [
            'codigo' => $p->codigo, 'nome' => $p->nome, 'descricao' => $p->descricao, 'permissoes' => $p->permissoes->pluck('codigo'),
        ])]);
    }

    private function dto(Usuario $u): array
    {
        return [
            'id' => $u->id, 'nome' => $u->nome, 'login' => $u->login, 'email' => $u->email, 'ativo' => $u->ativo,
            'desativado_em' => $u->desativado_em?->toIso8601String(), 'ultimo_login_em' => $u->ultimo_login_em?->toIso8601String(),
            'militar' => $u->militar ? ['id' => $u->militar->id, 'nome_guerra' => $u->militar->nome_guerra] : null,
            'atribuicoes' => $u->atribuicoes->map(fn ($a) => ['id' => $a->id, 'papel' => $a->papel?->codigo, 'unidade' => $a->unidade ? ['id' => $a->unidade->id, 'sigla' => $a->unidade->sigla] : null,
                'expira_em' => $a->expira_em?->toIso8601String(), 'vencida' => (bool) $a->expira_em?->isPast()])->values(),
            'sessoes_ativas' => DB::table('sessions')->where('user_id', $u->id)->count(),
        ];
    }
}
