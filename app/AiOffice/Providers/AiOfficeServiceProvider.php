<?php

namespace App\AiOffice\Providers;

use Anthropic\Client;
use App\AiOffice\Llm\ClaudeProvider;
use App\AiOffice\Llm\LlmProviderInterface;
use App\AiOffice\Llm\MockProvider;
use App\AiOffice\Models\Agent;
use App\AiOffice\Models\Approval;
use App\AiOffice\Models\Project;
use App\AiOffice\Models\Task;
use App\AiOffice\Observers\AgentStatusObserver;
use App\AiOffice\Observers\TaskStatusObserver;
use App\AiOffice\Policies\AgentPolicy;
use App\AiOffice\Policies\ApprovalPolicy;
use App\AiOffice\Policies\ProjectPolicy;
use App\AiOffice\Policies\TaskPolicy;
use App\AiOffice\Process\ProcessRunner;
use App\AiOffice\Process\SymfonyProcessRunner;
use App\AiOffice\Tools\DockerEngine;
use App\AiOffice\Tools\DockerSandboxEngine;
use App\AiOffice\Tools\ToolRegistrar;
use App\AiOffice\Tools\ToolRegistry;
use App\AiOffice\Tools\UnavailableDockerEngine;
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
    public function register(): void
    {
        // AI_OFFICE_LLM_PROVIDER=mock｜claude。未知值必須 throw，不能靜默退回 mock
        // ——設定打錯字時看起來一切正常，實際上一個字都沒送到 Claude。
        // （跟本 repo 既有的 RestaurantProviderInterface 綁定同一套做法。）
        $this->app->singleton(LlmProviderInterface::class, function () {
            $name = config('ai_office.llm.default_provider');

            return match ($name) {
                'claude' => new ClaudeProvider($this->app->make(Client::class)),
                'mock' => new MockProvider,
                default => throw new \InvalidArgumentException(
                    "Unknown AI Office LLM provider [{$name}], expected mock or claude."
                ),
            };
        });

        $this->app->singleton(Client::class, function () {
            $apiKey = config('ai_office.llm.providers.claude.api_key');

            if (blank($apiKey)) {
                // 早點炸、訊息講清楚。否則會變成 SDK 深處丟出來的 401，
                // 讓人以為是金鑰無效而不是根本沒設。
                throw new \RuntimeException(
                    'ANTHROPIC_API_KEY 未設定，無法使用 claude provider。'
                    .'開發環境請把 AI_OFFICE_LLM_PROVIDER 設成 mock。'
                );
            }

            return new Client(apiKey: $apiKey);
        });

        $this->app->singleton(ProcessRunner::class, SymfonyProcessRunner::class);

        // Docker 工具預設仍然是「不可用」。打開開關才換成真引擎——讓 Agent 能建立與
        // 啟動容器，是比執行一條白名單指令高一級的權限，不該因為升級到 Phase 11
        // 就自動生效。（Terminal 的沙箱執行是另一條路，走 SandboxManager。）
        $this->app->singleton(DockerEngine::class, fn ($app) => config('ai_office.sandbox.docker_tool_enabled', false)
            ? $app->make(DockerSandboxEngine::class)
            : $app->make(UnavailableDockerEngine::class));

        $this->app->singleton(ToolRegistry::class, function ($app) {
            $registry = new ToolRegistry;
            $app->make(ToolRegistrar::class)->register($registry);

            return $registry;
        });
    }

    public function boot(): void
    {
        Gate::policy(Project::class, ProjectPolicy::class);
        Gate::policy(Task::class, TaskPolicy::class);
        Gate::policy(Agent::class, AgentPolicy::class);
        Gate::policy(Approval::class, ApprovalPolicy::class);

        // 狀態變動寫事件流（規格第 35 節）。掛 observer 而不是在每個改狀態的地方
        // 補一行，理由見 TaskStatusObserver 的說明。
        Task::observe(TaskStatusObserver::class);
        Agent::observe(AgentStatusObserver::class);
    }
}
