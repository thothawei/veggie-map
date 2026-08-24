<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateRestaurantReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'type' => ['required', Rule::in([
                'closed', 'not_vegetarian', 'wrong_info',
                'menu_changed', 'wrong_address', 'wrong_hours', 'other',
            ])],
            'description' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
