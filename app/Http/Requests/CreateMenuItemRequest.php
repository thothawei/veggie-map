<?php

namespace App\Http\Requests;

use App\Support\DietCatalog;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateMenuItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'price' => ['nullable', 'numeric', 'min:0', 'max:999999.99'],
            'diet_type' => ['required', 'string', Rule::in(DietCatalog::menuItemDietCodes())],
            'is_available' => ['nullable', 'boolean'],
        ];
    }
}
