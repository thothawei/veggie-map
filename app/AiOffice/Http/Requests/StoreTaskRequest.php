<?php

namespace App\AiOffice\Http\Requests;

use App\AiOffice\Models\Task;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTaskRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $projectId = $this->route('project')->id;

        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:10000'],
            'status' => ['nullable', Rule::in(Task::STATUSES)],
            // 0–100，越大越優先（見 migration 註解）。
            'priority' => ['nullable', 'integer', 'between:0,100'],
            'max_retries' => ['nullable', 'integer', 'between:0,10'],
            // 父任務與相依都必須是同一個專案裡的任務。少了這個條件，任務會跨專案
            // 牽連，等於打破規格第 42 節的專案隔離。
            'parent_task_id' => [
                'nullable', 'integer',
                Rule::exists('ai_office_tasks', 'id')->where('project_id', $projectId),
            ],
            'assigned_agent_id' => ['nullable', 'integer', Rule::exists('ai_office_agents', 'id')],
            'dependencies' => ['nullable', 'array'],
            'dependencies.*' => [
                'integer',
                Rule::exists('ai_office_tasks', 'id')->where('project_id', $projectId),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'parent_task_id.exists' => '父任務必須是同一個專案裡的任務。',
            'dependencies.*.exists' => '相依任務必須是同一個專案裡的任務。',
        ];
    }
}
