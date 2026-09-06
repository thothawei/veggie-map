<?php

namespace App\AiOffice\Http\Resources;

use App\AiOffice\Models\Activity;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Activity */
class ActivityResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'project_id' => $this->project_id,
            // 只有跨專案的 LogsView（規格 §44）才會 eager-load project——單一
            // 專案內的事件流已經在那個專案的頁面脈絡裡，名字是多餘的欄位。
            'project_name' => $this->whenLoaded('project', fn () => $this->project->name),
            'task_id' => $this->task_id,
            'agent_id' => $this->agent_id,
            'type' => $this->type,
            'description' => $this->description,
            'payload' => $this->payload,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
