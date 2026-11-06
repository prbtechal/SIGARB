<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** RF-014 — o conteúdo é validado de novo no DocumentoService (assinatura + finfo + PDF ativo). */
class DocumentoUploadRequest extends FormRequest
{
    public function rules(): array
    {
        $kb = intdiv(config('sigarb.documentos.max_bytes'), 1024);

        return [
            'arquivo' => ['required', 'file', "max:$kb"],
            'tipo_documento_id' => ['required', 'integer', Rule::exists('tipos_documento', 'id')->where('ativo', true)],
            'unidade_id' => ['required', 'integer', 'exists:unidades,id'],
            'titulo' => ['required', 'string', 'min:3', 'max:160'],
            'descricao' => ['nullable', 'string', 'max:500'],
            'numero_referencia' => ['nullable', 'string', 'max:120'],
            'movimentacao_id' => ['nullable', 'integer', 'exists:movimentacoes,id'],
        ];
    }

    public function messages(): array
    {
        return ['arquivo.max' => 'O arquivo excede o limite permitido.', 'arquivo.required' => 'Selecione um arquivo.'];
    }
}
