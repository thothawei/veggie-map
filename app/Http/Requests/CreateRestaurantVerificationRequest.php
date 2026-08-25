<?php

namespace App\Http\Requests;

use App\Support\VerificationCatalog;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateRestaurantVerificationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'verification_type' => [
                'required',
                'string',
                Rule::in(VerificationCatalog::adminTypeCodes()),
            ],
            'note' => ['nullable', 'string', 'max:2000'],
            'expires_at' => ['nullable', 'date', 'after:now'],
        ];
    }
}
