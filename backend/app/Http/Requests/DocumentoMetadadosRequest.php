<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Somente metadados descritivos; arquivo, checksum, unidade e autor são imutáveis (trigger). */
class DocumentoMetadadosRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'titulo' => ['sometimes', 'string', 'min:3', 'max:160'],
            'descricao' => ['sometimes', 'nullable', 'string', 'max:500'],
            'numero_referencia' => ['sometimes', 'nullable', 'string', 'max:120'],
            'tipo_documento_id' => ['sometimes', 'integer', Rule::exists('tipos_documento', 'id')->where('ativo', true)],
            'unidade_id' => ['prohibited'], 'sha256' => ['prohibited'], 'nome_original' => ['prohibited'], 'situacao' => ['prohibited'],
        ];
    }
}
