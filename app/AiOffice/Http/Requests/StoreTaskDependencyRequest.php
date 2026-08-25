<?php

namespace App\AiOffice\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTaskDependencyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        // 相依只能連到同一個專案裡的任務。
        $projectId = $this->route('task')->project_id;

        return [
            'depends_on_task_ids' => ['required', 'array', 'min:1'],
            'depends_on_task_ids.*' => [
                'integer',
                Rule::exists('ai_office_tasks', 'id')->where('project_id', $projectId),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'depends_on_task_ids.*.exists' => '相依任務必須是同一個專案裡的任務。',
        ];
    }
}
