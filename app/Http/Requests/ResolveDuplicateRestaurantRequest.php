<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ResolveDuplicateRestaurantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // keep＝這筆留著（只清掉重複標記）；deactivate＝這筆是重複的，下架它。
            // 刻意沒有 delete／merge，見 DuplicateRestaurantController 的說明。
            'action' => ['required', Rule::in(['keep', 'deactivate'])],
        ];
    }
}
