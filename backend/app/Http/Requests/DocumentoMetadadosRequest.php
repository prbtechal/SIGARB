<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Somente metadados descritivos; arquivo, checksum, unidade e autor são imutáveis (trigger). */
class DocumentoMetadadosRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'titulo' => ['sometimes', 'string', 'min:3', 'max:160'],
            'descricao' => ['sometimes', 'nullable', 'string', 'max:500'],
            'numero_referencia' => ['sometimes', 'nullable', 'string', 'max:120'],
            'tipo_documento_id' => ['sometimes', 'integer', 'exists:tipos_documento,id'],
            'unidade_id' => ['prohibited'], 'sha256' => ['prohibited'], 'nome_original' => ['prohibited'], 'situacao' => ['prohibited'],
        ];
    }
}
