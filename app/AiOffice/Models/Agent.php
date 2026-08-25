<?php

namespace App\AiOffice\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Agent extends Model
{
    use HasFactory;

    /** 規格第 6 節的六個角色，加上第 67 節 seeder 才出現的 design。 */
    public const ROLES = ['ceo', 'frontend', 'backend', 'automation', 'qa', 'design', 'devops'];

    /** 規格第 7 節。 */
    public const STATUSES = ['idle', 'working', 'waiting_review', 'error', 'offline'];

    protected $table = 'ai_office_agents';

    protected $fillable = [
        'name', 'role', 'avatar', 'description', 'system_prompt',
        'model_provider', 'model_name', 'status', 'max_concurrency',
    ];

    protected function casts(): array
    {
        return ['max_concurrency' => 'integer'];
    }

    /** @return HasMany<AgentTool, $this> */
    public function tools(): HasMany
    {
        return $this->hasMany(AgentTool::class);
    }

    /** @return HasMany<AgentPermission, $this> */
    public function permissions(): HasMany
    {
        return $this->hasMany(AgentPermission::class);
    }

    public function memories(): HasMany
    {
        return $this->hasMany(AgentMemory::class);
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class, 'assigned_agent_id');
    }

    public function errors(): HasMany
    {
        return $this->hasMany(AgentError::class);
    }

    /**
     * 目前手上占著的任務數（已指派＋執行中），AgentSelector 用來挑最不忙的人。
     * 能不能再 dispatch 一筆 ExecuteTaskJob 看的是 running 數對 max_concurrency，
     * 兩者不要混用——否則滿載時連「之後輪到誰」都會丟。
     */
    public function activeTaskCount(): int
    {
        return $this->tasks()->whereIn('status', ['assigned', 'running'])->count();
    }
}
