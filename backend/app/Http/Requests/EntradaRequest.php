<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** UC-04 — entrada administrativa. RN-001, RN-003, RN-004, RN-005. */
class EntradaRequest extends FormRequest
{
    public function rules(): array
    {
        $max = config('sigarb.movimentacao.max_itens');

        return [
            'tipo' => ['required', 'string'],
            'local_destino_id' => ['required', 'integer', 'exists:locais_estoque,id'],
            'origem_descricao' => ['required', 'string', 'min:3', 'max:255'],
            'documento_referencia' => ['required', 'string', 'min:3', 'max:120'],
            'justificativa' => ['nullable', 'string', 'max:500'],
            'itens' => ['required', 'array', 'min:1', "max:$max"],
            'itens.*.material_id' => ['required', 'integer'],
            'itens.*.identificador' => ['nullable', 'string', 'regex:/^[A-Za-z0-9._\/-]{3,60}$/', 'distinct'],
            'itens.*.numero_serie' => ['nullable', 'string', 'max:80'],
            'itens.*.estado_conservacao' => ['nullable', 'in:NOVO,BOM,REGULAR,RUIM'],
            'itens.*.lote_codigo' => ['nullable', 'string', 'regex:/^[A-Za-z0-9._\/-]{2,60}$/'],
            'itens.*.validade' => ['nullable', 'date_format:Y-m-d'],
            'itens.*.fabricante_id' => ['nullable', 'integer', 'exists:fabricantes,id'],
            'itens.*.quantidade' => ['nullable', 'integer', 'min:1', 'max:10000000'],
        ];
    }
}
