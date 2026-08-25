<?php

namespace App\AiOffice\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TaskRun extends Model
{
    public const STATUSES = ['running', 'completed', 'failed', 'cancelled'];

    protected $table = 'ai_office_task_runs';

    protected $fillable = [
        'task_id', 'agent_id', 'run_number', 'input', 'output', 'status',
        'started_at', 'completed_at', 'duration_ms',
        'token_input', 'token_output', 'estimated_cost', 'error',
    ];

    protected function casts(): array
    {
        return [
            'input' => 'array',
            'output' => 'array',
            'run_number' => 'integer',
            'duration_ms' => 'integer',
            'token_input' => 'integer',
            'token_output' => 'integer',
            'estimated_cost' => 'decimal:6',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    public function toolExecutions(): HasMany
    {
        return $this->hasMany(ToolExecution::class);
    }
}
