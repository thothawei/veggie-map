<?php

namespace App\AiOffice\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Message extends Model
{
    protected $table = 'ai_office_messages';

    protected $fillable = ['project_id', 'task_id', 'from_agent_id', 'to_agent_id', 'content'];

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return BelongsTo<Task, $this>
     */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    /**
     * @return BelongsTo<Agent, $this>
     */
    public function sender(): BelongsTo
    {
        return $this->belongsTo(Agent::class, 'from_agent_id');
    }

    /**
     * @return BelongsTo<Agent, $this>
     */
    public function recipient(): BelongsTo
    {
        return $this->belongsTo(Agent::class, 'to_agent_id');
    }
}
