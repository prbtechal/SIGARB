<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class LoginRequest extends FormRequest
{
    /** CPF digitado com pontos e traço vira só dígitos. */
    protected function prepareForValidation(): void
    {
        if (preg_match('/^[\d.\-\s]+$/', (string) $this->input('login'))) {
            $this->merge(['login' => preg_replace('/\D/', '', $this->input('login'))]);
        }
    }

    public function rules(): array
    {
        return ['login' => ['required', 'string', 'regex:/^[A-Za-z0-9._]{1,60}$/'], 'password' => ['required', 'string', 'max:200']];
    }
}
