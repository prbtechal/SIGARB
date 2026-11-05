<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/** RF-004, UC-15. Política de senha provisória (P-11). */
class UsuarioRequest extends FormRequest
{
    public function rules(): array
    {
        $id = $this->route('usuario')?->id;
        $post = $this->isMethod('POST');

        return [
            'nome' => [$post ? 'required' : 'sometimes', 'string', 'min:3', 'max:120'],
            'login' => [$post ? 'required' : 'prohibited', 'string', 'regex:/^[a-z0-9._]{3,60}$/', Rule::unique('usuarios', 'login')],
            'email' => ['nullable', 'email', 'max:160', Rule::unique('usuarios', 'email')->ignore($id)],
            'password' => [$post ? 'required' : 'sometimes', 'string', 'max:200', Password::min(config('sigarb.senha.min'))->letters()->numbers()],
            'militar_id' => ['nullable', 'integer', 'exists:militares,id', Rule::unique('usuarios', 'militar_id')->ignore($id)],
            'ativo' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return ['militar_id.unique' => 'Este militar já está vinculado a outra conta.'];
    }
}
