<?php

namespace App\AiOffice\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgentError extends Model
{
    protected $table = 'ai_office_agent_errors';

    protected $fillable = ['agent_id', 'project_id', 'task_id', 'type', 'message', 'context'];

    protected function casts(): array
    {
        return ['context' => 'array'];
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
}
