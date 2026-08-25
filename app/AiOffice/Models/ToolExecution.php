<?php

namespace App\AiOffice\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ToolExecution extends Model
{
    public const STATUSES = ['pending_approval', 'running', 'succeeded', 'failed', 'denied'];

    protected $table = 'ai_office_tool_executions';

    protected $fillable = [
        'task_run_id', 'task_id', 'agent_id', 'tool', 'action',
        'risk_level', 'input', 'output', 'status', 'error', 'duration_ms',
    ];

    protected function casts(): array
    {
        return [
            'input' => 'array',
            'output' => 'array',
            'duration_ms' => 'integer',
        ];
    }

    public function taskRun(): BelongsTo
    {
        return $this->belongsTo(TaskRun::class);
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }
}
