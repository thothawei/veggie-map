<?php

namespace App\AiOffice\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgentTool extends Model
{
    protected $table = 'ai_office_agent_tools';

    protected $fillable = ['agent_id', 'tool'];

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }
}
