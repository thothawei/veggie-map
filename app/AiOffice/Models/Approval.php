<?php

namespace App\AiOffice\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property array<string, mixed>|null $payload
 * @property Carbon|null $approved_at
 * @property Carbon|null $rejected_at
 * @property Carbon|null $expires_at
 */
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

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<Task, $this> */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    /** @return BelongsTo<Agent, $this> */
    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    /** @return BelongsTo<ToolExecution, $this> */
    public function toolExecution(): BelongsTo
    {
        return $this->belongsTo(ToolExecution::class);
    }

    /** @return BelongsTo<User, $this> */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /** @return BelongsTo<User, $this> */
    public function rejecter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }
}
