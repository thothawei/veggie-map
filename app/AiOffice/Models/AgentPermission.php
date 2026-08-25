<?php

namespace App\AiOffice\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgentPermission extends Model
{
    /** 規格第 21 節的 YES／NO／APPROVAL。 */
    public const EFFECTS = ['allow', 'deny', 'approval'];

    protected $table = 'ai_office_agent_permissions';

    protected $fillable = ['agent_id', 'ability', 'effect'];

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }
}
