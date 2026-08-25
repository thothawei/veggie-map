<?php

namespace App\AiOffice\Http\Requests;

use App\AiOffice\Models\Task;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTaskRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'status' => ['sometimes', 'required', Rule::in(Task::STATUSES)],
            'priority' => ['sometimes', 'required', 'integer', 'between:0,100'],
            'max_retries' => ['sometimes', 'required', 'integer', 'between:0,10'],
            'assigned_agent_id' => [
                'sometimes', 'nullable', 'integer', Rule::exists('ai_office_agents', 'id'),
            ],
        ];
    }
}
