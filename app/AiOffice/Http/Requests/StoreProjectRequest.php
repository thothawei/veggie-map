<?php

namespace App\AiOffice\Http\Requests;

use App\AiOffice\Models\Project;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        // 授權在 Controller 用 Policy 判斷（跟 repo 既有做法一致），
        // 這裡只管欄位驗證。
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'repository_url' => ['nullable', 'url', 'max:2048'],
            'status' => ['nullable', Rule::in(Project::STATUSES)],
        ];
    }
}
