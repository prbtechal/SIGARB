<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** RF-005 — dados mínimos (ADR-009). */
class MilitarRequest extends FormRequest
{
    public function rules(): array
    {
        $id = $this->route('militar')?->id;
        $req = $this->isMethod('POST') ? 'required' : 'sometimes';

        return [
            'unidade_id' => [$req, 'integer', 'exists:unidades,id'],
            'posto_graduacao_id' => [$req, 'integer', 'exists:postos_graduacoes,id'],
            'nome' => [$req, 'string', 'min:3', 'max:160'],
            'nome_guerra' => [$req, 'string', 'min:2', 'max:60'],
            'matricula' => [$req, 'string', 'regex:/^[A-Z0-9-]{4,30}$/', Rule::unique('militares', 'matricula')->ignore($id)],
            'situacao' => ['sometimes', Rule::in(['ATIVO', 'AFASTADO', 'INATIVO'])],
        ];
    }

    public function messages(): array
    {
        return ['matricula.regex' => 'Matrícula: 4 a 30 caracteres, letras maiúsculas, dígitos ou hífen.'];
    }
}
