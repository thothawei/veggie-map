<?php

namespace App\AiOffice\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgentMemory extends Model
{
    /** 規格第 41 節。 */
    public const TYPES = [
        'project_context', 'technical_decision', 'user_preference', 'task_result', 'error_pattern',
    ];

    protected $table = 'ai_office_agent_memories';

    protected $fillable = ['agent_id', 'project_id', 'memory_type', 'content', 'importance'];

    protected function casts(): array
    {
        return ['importance' => 'integer'];
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
