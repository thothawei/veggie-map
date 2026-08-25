<?php

namespace App\AiOffice\Http\Requests;

use App\AiOffice\Models\Project;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'repository_url' => ['sometimes', 'nullable', 'url', 'max:2048'],
            'status' => ['sometimes', 'required', Rule::in(Project::STATUSES)],
        ];
    }
}
