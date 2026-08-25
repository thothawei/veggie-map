<?php

namespace App\AiOffice\Http\Resources;

use App\AiOffice\Models\AgentMemory;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin AgentMemory */
class AgentMemoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'agent_id' => $this->agent_id,
            'project_id' => $this->project_id,
            'memory_type' => $this->memory_type,
            'content' => $this->content,
            'importance' => $this->importance,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
