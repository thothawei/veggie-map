<?php

namespace App\AiOffice\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TokenUsage extends Model
{
    protected $table = 'ai_office_token_usages';

    protected $fillable = [
        'provider', 'model', 'agent_id', 'project_id', 'task_id', 'task_run_id',
        'input_tokens', 'output_tokens', 'total_tokens', 'estimated_cost',
    ];

    protected function casts(): array
    {
        return [
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'total_tokens' => 'integer',
            'estimated_cost' => 'decimal:6',
        ];
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function taskRun(): BelongsTo
    {
        return $this->belongsTo(TaskRun::class);
    }
}
