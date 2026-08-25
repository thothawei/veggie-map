<?php

namespace App\AiOffice\Http\Resources;

use App\AiOffice\Models\Task;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Task */
class TaskResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'project_id' => $this->project_id,
            'parent_task_id' => $this->parent_task_id,
            'title' => $this->title,
            'description' => $this->description,
            'status' => $this->status,
            'priority' => $this->priority,
            'assigned_agent_id' => $this->assigned_agent_id,
            'agent' => $this->whenLoaded('agent', fn () => new AgentResource($this->agent)),
            'result' => $this->result,
            'error' => $this->error,
            'retry_count' => $this->retry_count,
            'max_retries' => $this->max_retries,
            // 相依只回 id 陣列：前端畫 DAG 只需要邊，不需要每個節點的完整內容
            // （規格第 49 節）。
            'dependencies' => $this->whenLoaded(
                'dependencies',
                fn () => $this->dependencies->pluck('id')->all(),
            ),
            'started_at' => $this->started_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
