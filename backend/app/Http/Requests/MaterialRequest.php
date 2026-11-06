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
            // Valores desativados (RF-029) não são aceitos em cadastros/alterações novos.
            'categoria_id' => [$req, 'integer', Rule::exists('categorias', 'id')->where('ativo', true)],
            'fabricante_id' => ['nullable', 'integer', Rule::exists('fabricantes', 'id')->where('ativo', true)],
            'calibre_id' => ['nullable', 'integer', Rule::exists('calibres', 'id')->where('ativo', true)],
            'codigo' => [$req, 'string', 'regex:/^[A-Z0-9._-]{2,40}$/', Rule::unique('materiais', 'codigo')->ignore($id)],
            'nome' => [$req, 'string', 'min:2', 'max:160'],
            'modelo' => ['nullable', 'string', 'max:120'],
            'modo_controle' => [$req, Rule::in(['INDIVIDUAL', 'LOTE'])],
            'unidade_medida' => ['sometimes', 'string', 'max:20'],
            'ativo' => ['sometimes', 'boolean'],
        ];
    }
}
