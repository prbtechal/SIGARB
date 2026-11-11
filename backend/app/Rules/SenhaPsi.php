<?php

namespace App\Rules;

use App\Models\Usuario;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * PSI-PRB 10.1 — senha pessoal: mínimo de caracteres (config), maiúscula, minúscula, número e especial; sem nome, CPF/login ou data de
 * nascimento; diferente das últimas 5. Vale para troca, criação e redefinição (a senha PADRÃO provisória não passa por aqui).
 */
class SenhaPsi implements ValidationRule
{
    /** Partículas comuns de nomes que não identificam ninguém. */
    private const PARTICULAS = ['das', 'dos', 'des', 'del'];

    public function __construct(private ?Usuario $usuario = null, private ?string $nome = null, private ?string $login = null) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $v = (string) $value;
        $min = (int) config('sigarb.senha.min');
        if (mb_strlen($v) < $min) {
            $fail("A senha deve ter pelo menos $min caracteres.");
        }
        foreach (['/\p{Lu}/u' => 'uma letra maiúscula', '/\p{Ll}/u' => 'uma letra minúscula', '/\d/' => 'um número', '/[^\p{L}\p{N}]/u' => 'um caractere especial (ex.: ! @ # -)'] as $re => $falta) {
            if (! preg_match($re, $v)) {
                $fail("A senha deve ter pelo menos $falta.");
            }
        }
        $simples = Str::lower(Str::ascii($v));
        $nome = $this->nome ?? $this->usuario?->nome;
        foreach (preg_split('/[^a-z]+/', Str::lower(Str::ascii((string) $nome)), -1, PREG_SPLIT_NO_EMPTY) as $parte) {
            if (strlen($parte) >= 3 && ! in_array($parte, self::PARTICULAS, true) && str_contains($simples, $parte)) {
                $fail('A senha não pode conter o seu nome.');
                break;
            }
        }
        $login = Str::lower((string) ($this->login ?? $this->usuario?->login));
        if (strlen($login) >= 4 && str_contains(preg_replace('/[^a-z0-9]/', '', $simples), preg_replace('/[^a-z0-9]/', '', $login))) {
            $fail('A senha não pode conter o seu CPF (login).');
        }
        // Data de nascimento do efetivo vinculado, se o cadastro tiver esse dado (hoje o cadastro de efetivo não tem).
        $nasc = $this->usuario?->militar?->getAttribute('data_nascimento');
        if ($nasc) {
            $d = Carbon::parse($nasc);
            $digitos = preg_replace('/\D/', '', $v);
            foreach (['dmY', 'Ymd', 'dmy'] as $f) {
                if (str_contains($digitos, $d->format($f))) {
                    $fail('A senha não pode conter a sua data de nascimento.');
                    break;
                }
            }
        }
        if ($v === config('sigarb.senha.padrao')) {
            $fail('Escolha uma senha diferente da senha padrão.');
        }
        if ($this->usuario?->id) {
            $n = (int) config('sigarb.senha.historico');
            $anteriores = DB::table('historico_senhas')->where('usuario_id', $this->usuario->id)->orderByDesc('id')->limit($n)->pluck('password_hash');
            if ($anteriores->contains(fn ($h) => Hash::check($v, $h))) {
                $fail("A senha não pode ser igual a nenhuma das suas últimas $n senhas.");
            }
        }
    }
}
