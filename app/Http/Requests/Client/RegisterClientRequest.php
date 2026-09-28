<?php

namespace App\Http\Requests\Client;

use Illuminate\Foundation\Http\FormRequest;

class RegisterClientRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'firstname' => ['required', 'string', 'max:80'],
            'lastname' => ['required', 'string', 'max:80'],
            'email' => ['required', 'email', 'max:160', 'unique:clients,email'],
            'phone' => ['nullable', 'string', 'max:40'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ];
    }
}
