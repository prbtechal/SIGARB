<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class EstornoRequest extends FormRequest
{
    public function rules(): array
    {
        return ['justificativa' => ['required', 'string', 'min:10', 'max:500']];
    }
}
