<?php

namespace App\Http\Requests;

use App\Support\Conservacao;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
            // Data/hora real da entrada (ISO 8601 com fuso). Ausente = agora. Limites e justificativa do retroativo: EstoqueService.
            'ocorrido_em' => ['nullable', 'date', 'after:2000-01-01'],
            'itens' => ['required', 'array', 'min:1', "max:$max"],
            'itens.*.material_id' => ['required', 'integer'],
            'itens.*.identificador' => ['nullable', 'string', 'regex:/^[A-Za-z0-9._\/-]{3,60}$/', 'distinct'],
            'itens.*.numero_serie' => ['nullable', 'string', 'max:80'],
            'itens.*.estado_conservacao' => ['nullable', Conservacao::regra()],
            // CP-12: atributos do item físico; obrigatoriedade/aplicabilidade por perfil do tipo (EstoqueService).
            'itens.*.fabricado_em' => ['nullable', 'date_format:Y-m-d'],
            'itens.*.tamanho' => ['nullable', 'string', 'max:20'],
            'itens.*.nivel_protecao' => ['nullable', Rule::exists('niveis_protecao', 'nome')->where('ativo', true)],
            'itens.*.lote_codigo' => ['nullable', 'string', 'regex:/^[A-Za-z0-9._\/-]{2,60}$/'],
            'itens.*.validade' => ['nullable', 'date_format:Y-m-d'],
            'itens.*.fabricante_id' => ['nullable', 'integer', Rule::exists('fabricantes', 'id')->where('ativo', true)],
            'itens.*.quantidade' => ['nullable', 'integer', 'min:1', 'max:10000000'],
        ];
    }
}
