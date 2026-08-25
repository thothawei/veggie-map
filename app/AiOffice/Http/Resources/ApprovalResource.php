<?php

namespace App\AiOffice\Http\Resources;

use App\AiOffice\Models\Approval;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Approval */
class ApprovalResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'project_id' => $this->project_id,
            'task_id' => $this->task_id,
            'agent_id' => $this->agent_id,
            'tool_execution_id' => $this->tool_execution_id,
            'action' => $this->action,
            'risk_level' => $this->risk_level,
            'reason' => $this->reason,
            'payload' => $this->payload,
            'status' => $this->status,
            'approved_by' => $this->approved_by,
            'approved_at' => $this->approved_at?->toIso8601String(),
            'rejected_by' => $this->rejected_by,
            'rejected_at' => $this->rejected_at?->toIso8601String(),
            'expires_at' => $this->expires_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
