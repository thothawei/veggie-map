<?php

namespace App\AiOffice\Demo;

use App\AiOffice\Llm\LlmProviderInterface;
use App\AiOffice\Models\Activity;
use App\AiOffice\Models\Agent;
use App\AiOffice\Models\Approval;
use App\AiOffice\Models\Project;
use App\AiOffice\Models\Task;
use App\AiOffice\Models\TokenUsage;
use App\AiOffice\Orchestration\AgentOrchestrator;
use App\AiOffice\Security\WorkspaceGuard;
use App\AiOffice\Services\ApprovalService;
use App\Models\User;
use Database\Seeders\AiOfficeAgentSeeder;
use Illuminate\Support\Facades\File;

/**
 * 規格第 79 節的完整 Demo：一句需求 → CEO 拆任務 → 四個 Agent 依相依順序執行 →
 * 撞到風險門檻停下來等人核准 → 核准後接著跑完 → 專案 completed。
 *
 * 兩件事情刻意寫死在這裡，因為它們是「示範」而不是「產品行為」：
 *
 * 1. **佇列切成 sync**。正式路徑是 Horizon 背景跑（規格第 30 節），但 Demo 要在一個
 *    指令裡看到完整結果，所以同步跑。這不改變任何領域邏輯，只改工作被誰執行。
 * 2. **Demo 的維運 Agent 被改成 `write_file => approval`**。風險門檻維持預設 high，
 *    所以其他任務照常跑；只有最後一個任務會停下來等人。
 *
 *    為什麼不是降低全域門檻：門檻降到 medium 的話，每一個寫檔的任務都要人按四次，
 *    示範會變成點四次「核准」。也不是用天生就要核准的 `deploy_*`：那個能力到現在
 *    仍然沒有對應的工具，核准之後只會得到「工具尚未實作」、任務直接失敗——
 *    那示範的是缺口，不是流程。用權限層級的 approval（規格第 22 節的另一條路徑）
 *    才能真的走完「停下來 → 人按 → 工具執行 → 任務接著跑完」。
 */
class DemoRunner
{
    public function __construct(
        private readonly AgentOrchestrator $orchestrator,
        private readonly ApprovalService $approvals,
        private readonly WorkspaceGuard $workspace,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function run(
        User $approver,
        bool $fresh = false,
        string $name = 'Todo API Demo',
        string $decision = 'approve',
    ): array {
        if ($fresh) {
            Project::query()->where('name', $name)->get()->each->delete();
        }

        (new AiOfficeAgentSeeder)->run();
        $this->makeDeployStepNeedApproval();

        $project = Project::create([
            'name' => $name,
            'description' => '做一個最小可用的 Todo REST API：建立、列出、標記完成、刪除，附測試與上線說明。',
            'status' => 'planning',
            'created_by' => $approver->id,
            'workspace_path' => 'demo-'.now()->format('YmdHis'),
        ]);

        $steps = [];

        $this->orchestrator->planProject($project);
        $steps[] = $this->step('CEO 規劃', $project, "拆出 {$project->tasks()->count()} 個任務");

        // 規劃完成時 sync 佇列已經把能跑的任務一路跑到撞上核准為止。
        $approval = Approval::query()
            ->where('project_id', $project->id)
            ->where('status', 'pending')
            ->first();

        if ($approval !== null) {
            $steps[] = $this->step(
                '停下來等人',
                $project,
                "「{$approval->action}」風險 {$approval->risk_level}，任務暫停等待核准",
            );

            if ($decision === 'approve') {
                $this->approvals->approve($approval, $approver, 'Demo：確認過內容，放行。');
                $steps[] = $this->step('人工核准', $project, "{$approver->name} 核准後任務自動接著跑");
            } else {
                $this->approvals->reject($approval, $approver, 'Demo：這一步先不要做。');
                $steps[] = $this->step('人工拒絕', $project, "{$approver->name} 拒絕，任務停在 rejected");
            }
        }

        $project->refresh();

        return [
            'project' => $project,
            'steps' => $steps,
            'approval' => $approval?->fresh(),
            'tasks' => $project->tasks()->with('agent')->orderBy('id')->get(),
            'activities' => Activity::query()->where('project_id', $project->id)->count(),
            'usage' => $this->usage($project),
            'workspace' => $this->workspaceFiles($project),
        ];
    }

    /**
     * @return array{requests: int, total_tokens: int, estimated_cost: string}
     */
    private function usage(Project $project): array
    {
        $rows = TokenUsage::query()->where('project_id', $project->id);

        return [
            'requests' => (clone $rows)->count(),
            'total_tokens' => (int) (clone $rows)->sum('total_tokens'),
            'estimated_cost' => number_format((float) (clone $rows)->sum('estimated_cost'), 6, '.', ''),
        ];
    }

    /**
     * Demo 的重點之一是「Agent 真的產生了東西」，所以把 workspace 裡的檔案列出來。
     *
     * @return list<string>
     */
    private function workspaceFiles(Project $project): array
    {
        $root = $this->workspace->rootFor($project);

        if (! is_dir($root)) {
            return [];
        }

        return collect(File::allFiles($root))
            ->map(fn ($file) => ltrim(str_replace($root, '', $file->getPathname()), '/'))
            ->sort()
            ->values()
            ->all();
    }

    /**
     * @return array{label: string, status: string, detail: string}
     */
    private function step(string $label, Project $project, string $detail): array
    {
        return [
            'label' => $label,
            'status' => ($project->fresh() ?? $project)->status,
            'detail' => $detail,
        ];
    }

    /**
     * 讓 Demo 的維運 Agent 在寫檔時需要人核准（規格第 22 節的權限層級 approval）。
     *
     * 只動 Demo 會用到的那一個 Agent 的那一個能力，不動全域門檻——理由見類別說明。
     */
    private function makeDeployStepNeedApproval(): void
    {
        $devops = Agent::query()->where('role', 'devops')->orderBy('id')->first();

        $devops?->permissions()->updateOrCreate(
            ['ability' => 'write_file'],
            ['effect' => 'approval'],
        );
    }

    /** Demo 一定要跑在假 provider 上，這個方法讓呼叫端一次把環境設定好。 */
    public static function bootstrapEnvironment(): DemoScriptProvider
    {
        $provider = new DemoScriptProvider;

        app()->instance(LlmProviderInterface::class, $provider);

        // 正式路徑是 Horizon 背景跑（規格第 30 節）；Demo 要在一個指令裡看到完整結果，
        // 所以改成 sync。這不改變任何領域邏輯，只改工作被誰執行。
        config(['queue.default' => 'sync']);

        return $provider;
    }

    /** @return list<Task> */
    public static function tasksOf(Project $project): array
    {
        return $project->tasks()->orderBy('id')->get()->all();
    }
}
