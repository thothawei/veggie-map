<?php

namespace App\Http\Requests;

use App\Rules\SafeEmail;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            // SafeEmail：控制字元的第二道防線（Laravel 12.60+ 的預設 email 規則已補掉
            // CVE-2026-48019 本身），見該類別註解。
            'email' => ['required', 'string', 'email', new SafeEmail, 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', Password::min(8), 'confirmed'],
        ];
    }
}
