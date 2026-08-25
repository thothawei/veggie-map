<?php

namespace App\AiOffice\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Project extends Model
{
    use HasFactory;

    /** 規格第 8 節。 */
    public const STATUSES = ['planning', 'active', 'paused', 'completed', 'failed', 'archived'];

    protected $table = 'ai_office_projects';

    protected $fillable = [
        'name', 'description', 'repository_url', 'workspace_path', 'status', 'created_by',
    ];

    /**
     * 顯式預設值，不依賴 migration 的 DB-side default——create() 之後的記憶體 model
     * 不會自動回填資料庫預設值，少了這行 API 回應裡的 status 會是 null。
     * （這個 repo 在 AuthController 的 role 欄位已經踩過同一個坑。）
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'planning',
    ];

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return HasMany<Task, $this> */
    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    /** @return HasMany<Activity, $this> */
    public function activities(): HasMany
    {
        return $this->hasMany(Activity::class);
    }

    /** @return HasMany<Approval, $this> */
    public function approvals(): HasMany
    {
        return $this->hasMany(Approval::class);
    }

    /** @return HasMany<ProjectFile, $this> */
    public function files(): HasMany
    {
        return $this->hasMany(ProjectFile::class);
    }
}
