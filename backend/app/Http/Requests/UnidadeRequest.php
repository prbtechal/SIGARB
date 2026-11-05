<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UnidadeRequest extends FormRequest
{
    public function rules(): array
    {
        $post = $this->isMethod('POST');

        return [
            'organizacao_id' => [$post ? 'required' : 'prohibited', 'integer', 'exists:organizacoes,id'],
            'unidade_superior_id' => [$post ? 'nullable' : 'prohibited', 'integer', 'exists:unidades,id'],
            'sigla' => [$post ? 'required' : 'sometimes', 'string', 'regex:/^[A-Z0-9 .\/-]{2,30}$/'],
            'nome' => [$post ? 'required' : 'sometimes', 'string', 'min:3', 'max:160'],
            'tipo' => [$post ? 'required' : 'sometimes', Rule::in(['UNIDADE', 'SUBUNIDADE'])],
            'ativo' => ['sometimes', 'boolean'],
        ];
    }
}
