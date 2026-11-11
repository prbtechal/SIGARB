<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\LoginRequest;
use App\Models\Usuario;
use App\Http\Requests\UsuarioRequest;
use App\Models\PostoGraduacao;
use App\Rules\SenhaPsi;
use Illuminate\Support\Arr;
use App\Services\DispositivoService;
use App\Services\MfaService;
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
            // Contra bloqueio em massa (PSI-PRB 10.3 é permanente): um IP bloqueia no máximo ~falhas/10 contas por hora.
            'ip_hora' => ['login-ip-h:'.$r->ip(), config('sigarb.login.falhas_por_ip_hora'), 3600],
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
            $this->auditoria()->registrar('auth.login', 'NEGADO', 'usuario', $u?->id, dados: ['login' => self::mascarar($login), 'motivo' => 'limite_tentativas']);
            throw new SigarbException('MUITAS_TENTATIVAS', 'Muitas tentativas. Aguarde '.RateLimiter::availableIn($bloqueio).' segundos.', 429);
        }

        $senhaOk = Hash::check((string) $r->input('password'), $u?->password ?? '$2y$12$'.str_repeat('a', 53));
        // Conta bloqueada (PSI-PRB 10.3) recebe a MESMA resposta genérica, com ou sem senha certa: não revela existência
        // da conta nem confirma a senha a quem a está adivinhando.
        if (! $u || ! $senhaOk || ! $u->ativo || $u->bloqueado_em) {
            foreach ($limites as [$k, , $decay]) {
                RateLimiter::hit($k, $decay);
            }
            $dispositivos->registrarFalha($disp);
            $motivo = ! $u ? 'identificador_inexistente' : ($u->bloqueado_em ? 'conta_bloqueada' : (! $senhaOk ? 'senha_incorreta' : 'conta_inativa'));
            $this->auditoria()->registrar('auth.login', 'FALHA', 'usuario', $u?->id, dados: ['login' => self::mascarar($login), 'motivo' => $motivo]);
            if ($motivo === 'senha_incorreta') {
                $this->registrarFalhaConta($u);
            }
            throw new SigarbException('CREDENCIAIS_INVALIDAS', self::MSG_CREDENCIAIS, 401);
        }

        if ($u->mfaAtivo()) {
            // Segunda etapa (PSI-PRB cap. 11): sem sessão autenticada até o código ser validado.
            $r->session()->put('mfa_pendente', ['id' => $u->id, 'em' => time()]);
            $this->auditoria()->registrar('auth.mfa.solicitado', 'SUCESSO', 'usuario', $u->id, usuarioId: $u->id);

            return response()->json(['mfa_requerido' => true]);
        }

        return $this->concluirLogin($r, $u, [$chave, $limites['conta'][0]], $dispositivos);
    }

    public const MSG_CREDENCIAIS = 'CPF ou senha inválidos. Após 10 tentativas erradas seguidas a conta é bloqueada; nesse caso, procure o Gestor de TI.';

    /** Segunda etapa do login: código do aplicativo autenticador ou código de recuperação. */
    public function confirmarMfa(Request $r)
    {
        $d = $r->validate(['codigo' => ['required', 'string', 'max:20']]);
        $p = $r->session()->get('mfa_pendente');
        if (! $p || time() - $p['em'] > 300) {
            $r->session()->forget('mfa_pendente');
            throw new SigarbException('MFA_EXPIRADO', 'O tempo para informar o código acabou. Entre novamente com CPF e senha.', 401);
        }
        $u = Usuario::find($p['id']);
        if (! $u || ! $u->ativo || $u->bloqueado_em || ! $u->mfaAtivo()) {
            $r->session()->forget('mfa_pendente');
            throw new SigarbException('CREDENCIAIS_INVALIDAS', self::MSG_CREDENCIAIS, 401);
        }
        if (! app(MfaService::class)->validar($u, $d['codigo'])) {
            $this->auditoria()->registrar('auth.mfa', 'FALHA', 'usuario', $u->id, usuarioId: $u->id);
            if ($this->registrarFalhaConta($u)) {
                $r->session()->forget('mfa_pendente');
            }
            throw new SigarbException('CODIGO_INVALIDO', 'Código inválido ou já usado. Confira o aplicativo autenticador e tente de novo.', 422);
        }
        $r->session()->forget('mfa_pendente');
        $this->auditoria()->registrar('auth.mfa', 'SUCESSO', 'usuario', $u->id, usuarioId: $u->id);
        $login = $u->login;

        return $this->concluirLogin($r, $u, ['login:'.$login.'|'.$r->ip(), 'login-conta:id'.$u->id], app(DispositivoService::class));
    }

    private function concluirLogin(Request $r, Usuario $u, array $chaves, DispositivoService $dispositivos)
    {
        foreach ($chaves as $k) {
            RateLimiter::clear($k);
        }
        Auth::guard('web')->login($u);
        $r->session()->regenerate(true);
        $u->forceFill(['ultimo_login_em' => now(), 'falhas_login' => 0])->saveQuietly();
        $this->aplicarLimiteSessoes($u, $r->session()->getId());
        $this->auditoria()->registrar('auth.login', 'SUCESSO', 'usuario', $u->id, usuarioId: $u->id);

        return response()->json($this->perfil($u))->withCookie($dispositivos->emitir($r, $u));
    }

    /** Conta falhas consecutivas; ao atingir o limite, bloqueia a conta no banco. Devolve true se bloqueou agora. */
    private function registrarFalhaConta(Usuario $u): bool
    {
        $n = DB::transaction(function () use ($u) {
            $n = (int) Usuario::whereKey($u->id)->lockForUpdate()->value('falhas_login') + 1;
            Usuario::whereKey($u->id)->update(['falhas_login' => $n] + ($n >= config('sigarb.login.bloqueio_falhas') ? ['bloqueado_em' => now()] : []));

            return $n;
        });
        if ($n !== (int) config('sigarb.login.bloqueio_falhas')) {
            return false;
        }
        // Sem derrubar sessões já abertas: quem bloqueia pode ser um atacante sem login (D-06). O IP fica no evento.
        $this->auditoria()->registrar('auth.conta_bloqueada', 'SUCESSO', 'usuario', $u->id, dados: ['falhas_consecutivas' => $n, 'ip_ultima_falha' => request()->ip()], usuarioId: $u->id);

        return true;
    }

    /** Login informado em falhas: só começo e fim (CPF não fica legível na trilha). */
    public static function mascarar(string $login): string
    {
        $n = strlen($login);

        return $n <= 5 ? str_repeat('*', $n) : substr($login, 0, 3).str_repeat('*', $n - 5).substr($login, -2);
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

    /** Troca da própria senha: obrigatória com senha padrão/redefinida ou expirada (PSI-PRB cap. 10). */
    public function trocarSenha(Request $r): JsonResponse
    {
        $u = $r->user();
        $d = $r->validate([
            'senha_atual' => ['required', 'string', 'max:200'],
            'nova_senha' => ['required', 'string', 'max:200', 'confirmed', 'different:senha_atual', new SenhaPsi($u)],
        ], ['nova_senha.confirmed' => 'As senhas não conferem.', 'nova_senha.different' => 'A nova senha deve ser diferente da atual.']);
        if (! Hash::check($d['senha_atual'], $u->password)) {
            $this->auditoria()->registrar('auth.senha_trocada', 'FALHA', 'usuario', $u->id);
            throw new SigarbException('SENHA_ATUAL_INCORRETA', 'Senha atual incorreta.', 422);
        }
        $motivo = $u->senha_provisoria ? 'provisoria' : ($u->senhaExpirada() ? 'expirada' : 'voluntaria');
        $u->forceFill(['password' => $d['nova_senha'], 'senha_provisoria' => false])->save();
        // Encerra as outras sessões; a atual continua.
        DB::table('sessions')->where('user_id', $u->id)->where('id', '!=', $r->session()->getId())->delete();
        $this->auditoria()->registrar('auth.senha_trocada', 'SUCESSO', 'usuario', $u->id, dados: ['motivo' => $motivo]);

        return response()->json($this->perfil($u));
    }

    /** Dados funcionais da própria conta (+ lista de postos para o formulário). */
    public function meuPerfil(Request $r): JsonResponse
    {
        $u = $r->user();

        return response()->json(['nome' => $u->nome, 'login' => $u->login, ...UsuarioController::funcionais($u),
            'postos' => PostoGraduacao::orderBy('ordem')->get(['id', 'sigla', 'nome'])]);
    }

    /** A própria pessoa altera só posto, cargo, matrícula e telefone; login, papéis e situação ficam com o administrador. */
    public function atualizarMeuPerfil(Request $r): JsonResponse
    {
        $u = $r->user();
        $d = $r->validate(UsuarioRequest::funcionais($u->id), UsuarioRequest::mensagensFuncionais());
        $antes = $u->only(array_keys($d));
        $u->forceFill($d)->save();
        $mudou = array_keys(array_diff_assoc(array_map('strval', $u->only(array_keys($d))), array_map('strval', $antes)));
        if ($mudou) {
            $this->auditoria()->registrar('usuario.perfil_atualizado', 'SUCESSO', 'usuario', $u->id,
                dados: ['antes' => Arr::only($antes, $mudou), 'depois' => Arr::only($u->only($mudou), $mudou), 'por' => 'proprio']);
        }

        return $this->meuPerfil($r);
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

    public function perfil(Usuario $u): array
    {
        $u->load(['atribuicoes.papel', 'atribuicoes.unidade', 'militar.postoGraduacao']);

        return [
            'usuario' => [
                'id' => $u->id, 'nome' => $u->nome, 'login' => $u->login, 'senha_provisoria' => (bool) $u->senha_provisoria,
                'senha_expirada' => $u->senhaExpirada(), 'senha_alterada_em' => $u->senha_alterada_em?->toIso8601String(),
                'mfa_ativo' => $u->mfaAtivo(), 'mfa_obrigatorio' => $exigeMfa = $u->exigeMfa(), 'mfa_cadastro_pendente' => $exigeMfa && ! $u->mfaAtivo(),
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
