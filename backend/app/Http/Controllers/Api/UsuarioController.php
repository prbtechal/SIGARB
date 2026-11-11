<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\UsuarioRequest;
use App\Models\AtribuicaoPapel;
use App\Models\Papel;
use App\Models\Usuario;
use App\Services\AuditoriaService;
use App\Services\AutorizacaoService;
use App\Services\DispositivoService;
use App\Support\SigarbException;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;

/** RF-004, RF-002 (revogação administrativa), UC-15. Histórico preservado: contas são desativadas, nunca apagadas. */
class UsuarioController extends ApiController
{
    public function index(Request $r): JsonResponse
    {
        Gate::authorize('viewAny', Usuario::class);
        $r->validate(['q' => ['nullable', 'string', 'max:80'], 'ativo' => ['nullable', 'boolean']]);
        $q = Usuario::query()->with(['atribuicoes.papel', 'atribuicoes.unidade', 'militar']);
        if ($t = $r->query('q')) {
            $q->where(fn ($w) => $w->where('nome', 'like', $this->like($t))->orWhere('login', 'like', $this->like($t)));
        }
        if ($r->has('ativo')) {
            $q->where('ativo', $r->boolean('ativo'));
        }

        return $this->paginar($r, $q, fn ($u) => $this->dto($u, false), ['id', 'nome', 'login'], 'nome');
    }

    public function show(Usuario $usuario): JsonResponse
    {
        Gate::authorize('view', $usuario);

        return response()->json($this->dto($usuario->load(['atribuicoes.papel', 'atribuicoes.unidade', 'militar'])));
    }

    public function store(UsuarioRequest $r): JsonResponse
    {
        Gate::authorize('create', Usuario::class);
        $d = Arr::except($r->validated(), ['senha_padrao', 'motivo']);
        $d['password'] ??= $d['login']; // conta nova ainda não tem papel: senha provisória = CPF (exceção E-03)
        $u = new Usuario($d);
        $u->senha_provisoria = true; // senha definida pelo administrador: a pessoa troca no primeiro acesso
        $u->save();
        $this->auditoria()->registrar('usuario.criado', 'SUCESSO', 'usuario', $u->id, dados: Arr::except($d, ['password']));

        return response()->json($this->dto($u->load(['atribuicoes', 'militar'])), 201);
    }

    public function update(UsuarioRequest $r, Usuario $usuario): JsonResponse
    {
        Gate::authorize('update', $usuario);
        $d = Arr::except($r->validated(), ['senha_padrao', 'motivo']);
        if ($r->boolean('senha_padrao')) {
            $d['password'] = $this->senhaProvisoria($usuario, $d['login'] ?? $usuario->login);
        }
        if (empty($d['password'])) {
            unset($d['password']);
        }
        if (array_key_exists('ativo', $d) && ! $d['ativo'] && $usuario->id === $r->user()->id) {
            throw new SigarbException('AUTO_DESATIVACAO', 'Não é permitido desativar a própria conta.', 422);
        }
        $motivo = $r->input('motivo');
        DB::transaction(function () use ($usuario, $d, $motivo) {
            $antes = Arr::except($usuario->only(array_keys($d)), ['password']);
            if (array_key_exists('ativo', $d) && $d['ativo'] !== $usuario->ativo) {
                $usuario->desativado_em = $d['ativo'] ? null : now();
                if (! $d['ativo']) {
                    DB::table('sessions')->where('user_id', $usuario->id)->delete(); // revogação imediata
                    app(DispositivoService::class)->revogarTodos($usuario->id);
                }
            }
            $usuario->fill($d);
            if (isset($d['password'])) {
                $usuario->senha_provisoria = true;
            }
            $usuario->save();
            $this->auditoria()->registrar('usuario.atualizado', 'SUCESSO', 'usuario', $usuario->id, dados: [
                'antes' => self::mascararTelefone($antes), 'depois' => self::mascararTelefone(Arr::except($d, ['password'])), 'senha_redefinida' => isset($d['password']),
            ] + ($motivo ? ['motivo' => $motivo] : []));
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
        // Conta que ainda está com a senha provisória = CPF e vira privilegiada não pode continuar com senha previsível.
        if ($usuario->senha_provisoria && $usuario->privilegiada() && Hash::check($usuario->login, $usuario->password)) {
            $usuario->forceFill(['password' => config('sigarb.senha.padrao')])->save();
            DB::table('sessions')->where('user_id', $usuario->id)->delete();
            app(DispositivoService::class)->revogarTodos($usuario->id);
            $this->auditoria()->registrar('usuario.senha_provisoria_trocada', 'SUCESSO', 'usuario', $usuario->id, dados: ['motivo' => 'papel_privilegiado', 'papel' => $papel->codigo]);
        }

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

    /**
     * Senha provisória de conta nova/redefinida (decisão do responsável, 2026-11-11, exceção E-03 da PSI-PRB):
     * o próprio CPF (login), com troca obrigatória no primeiro acesso. Conta privilegiada (pelas permissões efetivas,
     * em qualquer escopo) recebe a senha padrão de config (SIGARB_SENHA_PADRAO), para não ter senha previsível pelo CPF.
     */
    public function senhaProvisoria(Usuario $u, string $login): string
    {
        return $u->exists && $u->privilegiada() ? config('sigarb.senha.padrao') : $login;
    }

    /** PSI-PRB 10.3: só quem gere contas desbloqueia, com motivo registrado na auditoria. */
    public function desbloquear(Request $r, Usuario $usuario): JsonResponse
    {
        Gate::authorize('alterarAcesso', $usuario);
        $d = $r->validate(['motivo' => ['required', 'string', 'min:5', 'max:300']]);
        if (! $usuario->bloqueado_em) {
            throw new SigarbException('CONTA_NAO_BLOQUEADA', 'Esta conta não está bloqueada.', 422);
        }
        $usuario->forceFill(['bloqueado_em' => null, 'falhas_login' => 0])->saveQuietly();
        $this->auditoria()->registrar('usuario.desbloqueado', 'SUCESSO', 'usuario', $usuario->id, dados: ['motivo' => $d['motivo']]);

        return response()->json($this->dto($usuario->fresh(['atribuicoes.papel', 'atribuicoes.unidade', 'militar'])));
    }

    public function papeis(): JsonResponse
    {
        Gate::authorize('viewAny', Usuario::class);

        return response()->json(['data' => Papel::with('permissoes:id,codigo')->orderBy('id')->get()->map(fn ($p) => [
            'codigo' => $p->codigo, 'nome' => $p->nome, 'descricao' => $p->descricao, 'permissoes' => $p->permissoes->pluck('codigo'),
        ])]);
    }

    /**
     * Dados funcionais da conta + os do efetivo vinculado, só como referência. Matrícula e telefone do efetivo só aparecem
     * para quem tem militares.ver_detalhe na unidade dele (como em MilitarController), e a revelação é auditada.
     * Sem $quem (listas), sempre mascarados.
     */
    public static function funcionais(Usuario $u, ?Usuario $quem = null): array
    {
        $u->loadMissing(['postoGraduacao', 'militar.postoGraduacao']);
        $m = $u->militar;
        $ve = $m && $quem && app(AutorizacaoService::class)->pode($quem, 'militares.ver_detalhe', $m->unidade_id);
        if ($ve && ($m->matricula || $m->telefone_institucional)) {
            app(AuditoriaService::class)->registrar('militar.consulta_detalhe', 'SUCESSO', 'militar', $m->id, $m->unidade_id, ['via' => 'conta', 'usuario_id' => $u->id]);
        }

        return [
            'posto_graduacao' => $u->postoGraduacao ? $u->postoGraduacao->only(['id', 'sigla', 'nome']) : null,
            'cargo' => $u->cargo, 'matricula' => $u->matricula, 'telefone' => $u->telefone,
            'militar' => $m ? ['id' => $m->id, 'nome_guerra' => $m->nome_guerra, 'posto' => $m->postoGraduacao?->sigla,
                'matricula' => $ve || ! $m->matricula ? $m->matricula : '•••'.substr($m->matricula, -3),
                'telefone' => $ve ? $m->telefone_institucional : ($m->telefone_institucional ? '[informado]' : null)] : null,
        ];
    }

    /** Telefone na trilha de auditoria: só os 4 últimos dígitos. */
    public static function mascararTelefone(array $d): array
    {
        if (! empty($d['telefone'])) {
            $d['telefone'] = '•••'.substr(preg_replace('/\D/', '', (string) $d['telefone']), -4);
        }

        return $d;
    }

    private function dto(Usuario $u, bool $revelar = true): array
    {
        return [
            'id' => $u->id, 'nome' => $u->nome, 'login' => $u->login, 'email' => $u->email, 'ativo' => $u->ativo,
            'desativado_em' => $u->desativado_em?->toIso8601String(), 'ultimo_login_em' => $u->ultimo_login_em?->toIso8601String(),
            'bloqueado_em' => $u->bloqueado_em?->toIso8601String(), 'mfa_ativo' => $u->mfaAtivo(),
            ...self::funcionais($u, $revelar ? auth()->user() : null),
            'atribuicoes' => $u->atribuicoes->map(fn ($a) => ['id' => $a->id, 'papel' => $a->papel?->codigo, 'unidade' => $a->unidade ? ['id' => $a->unidade->id, 'sigla' => $a->unidade->sigla] : null,
                'expira_em' => $a->expira_em?->toIso8601String(), 'vencida' => (bool) $a->expira_em?->isPast()])->values(),
            'sessoes_ativas' => DB::table('sessions')->where('user_id', $u->id)->count(),
        ];
    }
}
