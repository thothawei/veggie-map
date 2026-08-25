<?php

namespace App\AiOffice\Providers;

use App\AiOffice\Models\Agent;
use App\AiOffice\Models\Project;
use App\AiOffice\Models\Task;
use App\AiOffice\Policies\AgentPolicy;
use App\AiOffice\Policies\ProjectPolicy;
use App\AiOffice\Policies\TaskPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * AI Office 子系統的註冊點。
 *
 * Policy 必須手動綁定：Laravel 11 的自動探索只認 App\Models\X → App\Policies\XPolicy
 * 這條慣例，我們的 Model 在 App\AiOffice\Models\ 底下，探索器找不到對應的 Policy，
 * 沒綁的話 authorize() 會直接丟「找不到 policy」而不是正確的 403。
 */
class AiOfficeServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Gate::policy(Project::class, ProjectPolicy::class);
        Gate::policy(Task::class, TaskPolicy::class);
        Gate::policy(Agent::class, AgentPolicy::class);
    }
}
