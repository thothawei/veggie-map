<?php

namespace App\AiOffice\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Approval extends Model
{
    /** 規格第 23 節。 */
    public const STATUSES = ['pending', 'approved', 'rejected', 'expired'];

    public const RISK_LEVELS = ['low', 'medium', 'high', 'critical'];

    protected $table = 'ai_office_approvals';

    protected $fillable = [
        'project_id', 'task_id', 'agent_id', 'tool_execution_id', 'action', 'risk_level',
        'reason', 'payload', 'status', 'approved_by', 'approved_at',
        'rejected_by', 'rejected_at', 'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    public function toolExecution(): BelongsTo
    {
        return $this->belongsTo(ToolExecution::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function rejecter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }
}
