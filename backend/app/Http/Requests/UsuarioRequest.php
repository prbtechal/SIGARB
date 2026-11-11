<?php

namespace App\Http\Requests;

use App\Support\Cpf;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
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
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('login')) {
            $this->merge(['login' => Cpf::digitos($this->input('login'))]);
        }
    }

    public function messages(): array
    {
        return ['militar_id.unique' => 'Este militar já está vinculado a outra conta.'];
    }
}
