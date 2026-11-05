<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class LoginRequest extends FormRequest
{
    public function rules(): array
    {
        return ['login' => ['required', 'string', 'regex:/^[A-Za-z0-9._]{1,60}$/'], 'password' => ['required', 'string', 'max:200']];
    }
}
