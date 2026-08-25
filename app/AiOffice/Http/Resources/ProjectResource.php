<?php

namespace App\AiOffice\Http\Resources;

use App\AiOffice\Models\Project;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Project */
class ProjectResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'repository_url' => $this->repository_url,
            'workspace_path' => $this->workspace_path,
            'status' => $this->status,
            'created_by' => $this->created_by,
            // withCount('tasks') 有載入時才出現，避免每次列表都多打一次 count。
            'task_count' => $this->whenCounted('tasks'),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
