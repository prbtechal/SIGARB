<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class LocalEstoqueRequest extends FormRequest
{
    public function rules(): array
    {
        $post = $this->isMethod('POST');
        $id = $this->route('local')?->id;

        return [
            'unidade_id' => [$post ? 'required' : 'prohibited', 'integer', 'exists:unidades,id'],
            'codigo' => [$post ? 'required' : 'sometimes', 'string', 'regex:/^[A-Z0-9._-]{2,30}$/', Rule::unique('locais_estoque', 'codigo')->ignore($id)],
            'nome' => [$post ? 'required' : 'sometimes', 'string', 'min:3', 'max:160'],
            'tipo' => [$post ? 'required' : 'sometimes', Rule::in(['RESERVA', 'DEPOSITO'])],
            'ativo' => ['sometimes', 'boolean'],
        ];
    }
}
