<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** RF-005 — dados mínimos (ADR-009). */
class MilitarRequest extends FormRequest
{
    public function rules(): array
    {
        $id = $this->route('militar')?->id;
        $req = $this->isMethod('POST') ? 'required' : 'sometimes';

        return [
            'unidade_id' => [$req, 'integer', 'exists:unidades,id'],
            'posto_graduacao_id' => [$req, 'integer', 'exists:postos_graduacoes,id'],
            'nome' => [$req, 'string', 'min:3', 'max:160'],
            'nome_guerra' => [$req, 'string', 'min:2', 'max:60'],
            'matricula' => [$req, 'string', 'regex:/^[A-Z0-9-]{4,30}$/', Rule::unique('militares', 'matricula')->ignore($id)],
            'situacao' => ['sometimes', Rule::in(['ATIVO', 'AFASTADO', 'INATIVO'])],
            // CP-12 — opcionais (sem regra institucional que os torne obrigatórios). CPF não é coletado (P-09).
            'quadro' => ['nullable', 'string', 'max:60'],
            'funcao' => ['nullable', 'string', 'max:80'],
            'telefone_institucional' => ['nullable', 'string', 'regex:/^[0-9 ()+-]{8,20}$/'],
            'email_institucional' => ['nullable', 'string', 'email:rfc', 'max:120'],
            'observacoes_administrativas' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return ['matricula.regex' => 'Matrícula: 4 a 30 caracteres, letras maiúsculas, dígitos ou hífen.',
            'telefone_institucional.regex' => 'Telefone: 8 a 20 caracteres (dígitos, espaço, parênteses, + ou -).'];
    }
}
