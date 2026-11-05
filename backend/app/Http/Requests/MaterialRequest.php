<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** RF-007/008/009, UC-03. */
class MaterialRequest extends FormRequest
{
    public function rules(): array
    {
        $id = $this->route('material')?->id;
        $req = $this->isMethod('POST') ? 'required' : 'sometimes';

        return [
            'categoria_id' => [$req, 'integer', 'exists:categorias,id'],
            'fabricante_id' => ['nullable', 'integer', 'exists:fabricantes,id'],
            'calibre_id' => ['nullable', 'integer', 'exists:calibres,id'],
            'codigo' => [$req, 'string', 'regex:/^[A-Z0-9._-]{2,40}$/', Rule::unique('materiais', 'codigo')->ignore($id)],
            'nome' => [$req, 'string', 'min:2', 'max:160'],
            'modelo' => ['nullable', 'string', 'max:120'],
            'modo_controle' => [$req, Rule::in(['INDIVIDUAL', 'LOTE'])],
            'unidade_medida' => ['sometimes', 'string', 'max:20'],
            'ativo' => ['sometimes', 'boolean'],
        ];
    }
}
