<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\LoginRequest;
use App\Models\Usuario;
use App\Services\DispositivoService;
use App\Support\Operacoes;
use App\Support\SigarbException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/** RF-001, RF-002, UC-01 (ADR-003). */
class AuthController extends ApiController
{
    public function csrf(): JsonResponse
    {
        return response()->json(null, 204);
    }

    public function login(LoginRequest $r)
    {
        $login = Str::lower(trim($r->string('login')));
        $chave = 'login:'.$login.'|'.$r->ip();
        // Login restrito a ASCII (LoginRequest) e chave por ID do usuário: sem divergência de collation.
        $u = Usuario::where('login', $login)->first();
        $limites = [
            'par' => [$chave, config('sigarb.login.tentativas_por_minuto'), 60],
            'conta' => ['login-conta:'.($u ? 'id'.$u->id : 'nome:'.$login), config('sigarb.login.falhas_por_conta'), 900],
            'ip' => ['login-ip:'.$r->ip(), config('sigarb.login.falhas_por_ip'), 60],
        ];
        $dispositivos = app(DispositivoService::class);
        $disp = $u ? $dispositivos->confiavel($r, $u) : null;
        $bloqueio = null;
        foreach ($limites as $tipo => [$k, $max]) {
            if (! RateLimiter::tooManyAttempts($k, $max)) {
                continue;
            }
            // Anti-DoS: o limite por conta só é dispensado para dispositivo confiável registrado no servidor
            // (login anterior bem-sucedido, não revogado e sem falhas recentes próprias).
            if ($tipo === 'conta' && $disp) {
                continue;
            }
            $bloqueio = $k;
            break;
        }
        if ($bloqueio) {
            $this->auditoria()->registrar('auth.login', 'NEGADO', 'usuario', $u?->id, dados: ['login' => $login, 'motivo' => 'limite_tentativas']);
            throw new SigarbException('MUITAS_TENTATIVAS', 'Muitas tentativas. Aguarde '.RateLimiter::availableIn($bloqueio).' segundos.', 429);
        }

        $senhaOk = Hash::check((string) $r->input('password'), $u?->password ?? '$2y$12$'.str_repeat('a', 53));
        if (! $u || ! $senhaOk || ! $u->ativo) {
            foreach ($limites as [$k, , $decay]) {
                RateLimiter::hit($k, $decay);
            }
            $dispositivos->registrarFalha($disp);
            $this->auditoria()->registrar('auth.login', 'FALHA', 'usuario', $u?->id, dados: [
                'login' => $login, 'motivo' => ! $u ? 'identificador_inexistente' : (! $senhaOk ? 'senha_incorreta' : 'conta_inativa'),
            ]);
            throw new SigarbException('CREDENCIAIS_INVALIDAS', 'Identificador ou senha inválidos.', 401);
        }

        RateLimiter::clear($chave);
        RateLimiter::clear($limites['conta'][0]);
        Auth::guard('web')->login($u);
        $r->session()->regenerate(true);
        $u->forceFill(['ultimo_login_em' => now()])->saveQuietly();
        $this->aplicarLimiteSessoes($u, $r->session()->getId());
        $this->auditoria()->registrar('auth.login', 'SUCESSO', 'usuario', $u->id, usuarioId: $u->id);

        return response()->json($this->perfil($u))->withCookie($dispositivos->emitir($r, $u));
    }

    public function logout(Request $r): JsonResponse
    {
        $this->auditoria()->registrar('auth.logout', 'SUCESSO', 'usuario', $r->user()->id);
        Auth::guard('web')->logout();
        $r->session()->invalidate();
        $r->session()->regenerateToken();

        return response()->json(null, 204);
    }

    public function me(Request $r): JsonResponse
    {
        return response()->json($this->perfil($r->user()));
    }

    public function sessoes(Request $r): JsonResponse
    {
        $atual = $r->session()->getId();
        $lista = DB::table('sessions')->where('user_id', $r->user()->id)->orderByDesc('last_activity')->get()
            ->map(fn ($s) => [
                'ref' => substr(hash('sha256', $s->id), 0, 16), // nunca expor o ID real da sessão
                'ip' => $s->ip_address,
                'user_agent' => Str::limit((string) $s->user_agent, 120),
                'ultima_atividade' => date(DATE_ATOM, $s->last_activity),
                'atual' => $s->id === $atual,
            ]);

        return response()->json(['data' => $lista]);
    }

    public function revogarSessao(Request $r, string $ref): JsonResponse
    {
        $alvo = DB::table('sessions')->where('user_id', $r->user()->id)->pluck('id')
            ->first(fn ($id) => hash_equals(substr(hash('sha256', $id), 0, 16), $ref));
        if (! $alvo) {
            throw new SigarbException('NAO_ENCONTRADO', 'Sessão não encontrada.', 404);
        }
        if ($alvo === $r->session()->getId()) {
            throw new SigarbException('SESSAO_ATUAL', 'Use "Sair" para encerrar a sessão atual.', 422);
        }
        DB::table('sessions')->where('id', $alvo)->delete();
        $this->auditoria()->registrar('auth.sessao.revogada', 'SUCESSO', 'usuario', $r->user()->id, dados: ['ref' => $ref]);

        return response()->json(null, 204);
    }

    private function aplicarLimiteSessoes(Usuario $u, string $atual): void
    {
        $manter = max(0, config('sigarb.sessao.max_simultaneas') - 1);
        $excedentes = DB::table('sessions')->where('user_id', $u->id)->where('id', '!=', $atual)
            ->orderByDesc('last_activity')->pluck('id')->slice($manter);
        if ($excedentes->isNotEmpty()) {
            DB::table('sessions')->whereIn('id', $excedentes->all())->delete();
            $this->auditoria()->registrar('auth.sessao.revogada_limite', 'SUCESSO', 'usuario', $u->id,
                dados: ['quantidade' => $excedentes->count()], usuarioId: $u->id);
        }
    }

    private function perfil(Usuario $u): array
    {
        $u->load(['atribuicoes.papel', 'atribuicoes.unidade', 'militar.postoGraduacao']);

        return [
            'usuario' => [
                'id' => $u->id, 'nome' => $u->nome, 'login' => $u->login,
                'militar' => $u->militar ? ['id' => $u->militar->id, 'nome_guerra' => $u->militar->nome_guerra, 'posto' => $u->militar->postoGraduacao?->sigla] : null,
            ],
            'papeis' => $u->atribuicoes->map(fn ($a) => [
                'papel' => $a->papel->codigo, 'papel_nome' => $a->papel->nome,
                'unidade' => $a->unidade ? ['id' => $a->unidade->id, 'sigla' => $a->unidade->sigla] : null,
            ])->values(),
            'permissoes' => $this->authz()->permissoes($u),
            'operacoes' => collect(config('sigarb.operacoes'))->map(fn ($o, $k) => ['codigo' => $k, 'estado' => Operacoes::estado($k)] + $o)->values(),
            'ambiente' => config('sigarb.ambiente_demonstracao') ? 'DEMONSTRACAO' : 'NAO_PRODUCAO',
        ];
    }
}
