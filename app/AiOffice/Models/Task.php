<?php

namespace App\AiOffice\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * casts() 是方法而不是 $casts 屬性，PHPStan 推不出這兩個欄位是 Carbon，
 * 會以為是資料庫欄位型別對應的 string。補上 @property 讓它知道。
 *
 * @property Carbon|null $started_at
 * @property Carbon|null $completed_at
 */
class Task extends Model
{
    use HasFactory;

    /** 規格第 9 節。 */
    public const STATUSES = [
        'pending', 'planning', 'assigned', 'running', 'waiting_review',
        'approved', 'rejected', 'completed', 'failed', 'cancelled',
    ];

    /** 相依已滿足、可以真的開跑的終點狀態。 */
    public const TERMINAL_SUCCESS_STATUSES = ['completed', 'approved'];

    protected $table = 'ai_office_tasks';

    protected $fillable = [
        'project_id', 'parent_task_id', 'title', 'description', 'status', 'priority',
        'assigned_agent_id', 'created_by', 'result', 'error', 'retry_count', 'max_retries',
        'started_at', 'completed_at',
    ];

    /**
     * 同 Project：不依賴 DB-side default，否則剛建立的任務在 API 回應裡
     * status／priority 全是 null，前端會以為欄位壞掉。
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'pending',
        'priority' => 50,
        'retry_count' => 0,
        'max_retries' => 3,
    ];

    protected function casts(): array
    {
        return [
            'result' => 'array',
            'priority' => 'integer',
            'retry_count' => 'integer',
            'max_retries' => 'integer',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_task_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_task_id');
    }

    /** @return BelongsTo<Agent, $this> */
    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class, 'assigned_agent_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** 這個任務要等哪些任務先完成。 */
    public function dependencies(): BelongsToMany
    {
        return $this->belongsToMany(
            self::class,
            'ai_office_task_dependencies',
            'task_id',
            'depends_on_task_id',
        )->withTimestamps();
    }

    /** 反向：這個任務完成後可以解鎖哪些任務。 */
    public function dependents(): BelongsToMany
    {
        return $this->belongsToMany(
            self::class,
            'ai_office_task_dependencies',
            'depends_on_task_id',
            'task_id',
        )->withTimestamps();
    }

    /** @return HasMany<TaskRun, $this> */
    public function runs(): HasMany
    {
        return $this->hasMany(TaskRun::class);
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(TaskAssignment::class);
    }

    public function toolExecutions(): HasMany
    {
        return $this->hasMany(ToolExecution::class);
    }

    public function approvals(): HasMany
    {
        return $this->hasMany(Approval::class);
    }

    /**
     * 規格第 10 節：所有相依都完成後才可以執行。
     *
     * 只有 completed／approved 算數——failed 或 cancelled 的前置任務不該讓後續任務
     * 「因為不是 pending 就放行」，那會讓半條鏈在前面壞掉的情況下繼續往下跑。
     */
    public function dependenciesSatisfied(): bool
    {
        return ! $this->dependencies()
            ->whereNotIn('ai_office_tasks.status', self::TERMINAL_SUCCESS_STATUSES)
            ->exists();
    }
}
