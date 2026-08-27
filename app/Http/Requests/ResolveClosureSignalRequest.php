<?php

namespace App\Http\Requests;

use App\Models\RestaurantClosureSignal;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ResolveClosureSignalRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            // confirmed＝確認歇業並下架；dismissed＝誤報，店還在。
            // 沒有第三種：訊號要嘛成立要嘛不成立，「再看看」等於沒審。
            'resolution' => ['required', Rule::in(RestaurantClosureSignal::RESOLUTIONS)],
        ];
    }
}
