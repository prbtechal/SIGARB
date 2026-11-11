<?php

namespace App\Http\Requests;

use App\Support\Cpf;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use App\Models\Usuario;
use App\Rules\SenhaPsi;

/** RF-004, UC-15. Política de senha provisória (P-11). */
class UsuarioRequest extends FormRequest
{
    public function rules(): array
    {
        $id = $this->route('usuario')?->id;
        $post = $this->isMethod('POST');

        return [
            'nome' => [$post ? 'required' : 'sometimes', 'string', 'min:3', 'max:120'],
            // Acesso pelo CPF (só dígitos). Contas antigas com outro identificador continuam entrando até serem editadas.
            'login' => [$post ? 'required' : 'sometimes', 'string', function ($a, $v, $fail) { if (! Cpf::valido($v)) { $fail('CPF inválido.'); } }, Rule::unique('usuarios', 'login')->ignore($id)],
            'email' => ['nullable', 'email', 'max:160', Rule::unique('usuarios', 'email')->ignore($id)],
            // Sem senha: a conta recebe o CPF como senha provisória (ADMIN: senha padrão) e troca no primeiro acesso.
            // PSI-PRB 10.1 também para a senha inicial/redefinida digitada pelo administrador.
            'password' => ['sometimes', 'nullable', 'string', 'max:200', new SenhaPsi($this->route('usuario'), $this->input('nome'), $this->input('login'))],
            'militar_id' => ['nullable', 'integer', 'exists:militares,id', Rule::unique('usuarios', 'militar_id')->ignore($id)],
            'ativo' => ['sometimes', 'boolean'],
            'senha_padrao' => ['sometimes', 'boolean'],
            // Dados funcionais de OUTRA pessoa: motivo obrigatório (vai para a auditoria). Na criação não se exige.
            'motivo' => [Rule::requiredIf(fn () => ! $post && $this->hasAny(Usuario::CAMPOS_FUNCIONAIS) && $this->route('usuario')?->id !== $this->user()?->id),
                'nullable', 'string', 'min:5', 'max:300'],
        ] + self::funcionais($id);
    }

    /** Também usadas em PUT /me/profile. */
    public static function funcionais(?int $ignorar): array
    {
        return [
            'posto_graduacao_id' => ['sometimes', 'nullable', 'integer', 'exists:postos_graduacoes,id'],
            'cargo' => ['sometimes', 'nullable', 'string', 'max:80'],
            'matricula' => ['sometimes', 'nullable', 'string', 'max:30', Rule::unique('usuarios', 'matricula')->ignore($ignorar)],
            'telefone' => ['sometimes', 'nullable', 'string', 'regex:/^[0-9()+\-\s]{8,20}$/'],
        ];
    }

    public static function mensagensFuncionais(): array
    {
        return ['matricula.unique' => 'Esta matrícula já está em outra conta.', 'telefone.regex' => 'Telefone inválido: use só números, espaços, parênteses, + e -.',
            'motivo.required' => 'Informe o motivo da alteração dos dados funcionais.'];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('login')) {
            $this->merge(['login' => Cpf::digitos($this->input('login'))]);
        }
    }

    public function messages(): array
    {
        return ['militar_id.unique' => 'Este militar já está vinculado a outra conta.'] + self::mensagensFuncionais();
    }
}
