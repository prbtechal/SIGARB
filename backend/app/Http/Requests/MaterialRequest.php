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
            // RF-007 — ficha técnica de armamento: campos PROVISÓRIOS (lista oficial depende de P-04).
            'ficha_tecnica' => ['nullable', 'array:especie,sistema_funcionamento,capacidade_carregador,comprimento_cano_mm,numero_raias,acabamento'],
            'ficha_tecnica.especie' => ['nullable', 'in:PISTOLA,REVOLVER,ESPINGARDA,CARABINA,FUZIL,SUBMETRALHADORA,OUTRA'],
            'ficha_tecnica.sistema_funcionamento' => ['nullable', 'in:REPETICAO,SEMIAUTOMATICO,AUTOMATICO'],
            'ficha_tecnica.capacidade_carregador' => ['nullable', 'integer', 'min:1', 'max:200'],
            'ficha_tecnica.comprimento_cano_mm' => ['nullable', 'integer', 'min:10', 'max:2000'],
            'ficha_tecnica.numero_raias' => ['nullable', 'integer', 'min:0', 'max:20'],
            'ficha_tecnica.acabamento' => ['nullable', 'string', 'max:60'],
        ];
    }
}
