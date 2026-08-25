<?php

namespace App\AiOffice\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * casts() 是方法不是 $casts 屬性，PHPStan 推不出這兩個欄位是 Carbon。
 *
 * @property Carbon|null $started_at
 * @property Carbon|null $completed_at
 */
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

    /** @return HasMany<ToolExecution, $this> */
    public function toolExecutions(): HasMany
    {
        return $this->hasMany(ToolExecution::class);
    }

    /** @return HasMany<TokenUsage, $this> */
    public function tokenUsages(): HasMany
    {
        return $this->hasMany(TokenUsage::class);
    }

    /**
     * 這次執行累計的用量。刻意從 token_usages 加總而不是在迴圈裡自己累加：
     * 每次 LLM 請求都會寫一筆用量，加總才是真的花了多少，中途丟例外也不會少算。
     */
    public function tokenUsageSum(string $column): string
    {
        return (string) ($this->tokenUsages()->sum($column) ?: 0);
    }
}
