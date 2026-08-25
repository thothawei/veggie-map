<?php

namespace App\Console\Commands;

use App\AiOffice\Demo\DemoRunner;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * 規格第 79 節的完整 Demo。一句需求跑到專案完成，全程假模型，不會打真的 Claude API。
 *
 * 這個指令**會寫資料庫**（專案、任務、執行紀錄、事件、用量）與 workspace 檔案，
 * 目的就是讓人跑完之後可以進 /ai-office 面板看見真的資料。要重跑用 --fresh。
 */
class AiOfficeDemo extends Command
{
    protected $signature = 'ai-office:demo
        {--user= : 用哪個使用者的身分核准（email，預設取第一個 admin）}
        {--fresh : 先刪掉同名的舊 Demo 專案}
        {--reject : 改成拒絕那一步核准，示範被拒絕時任務會停下來}';

    protected $description = '跑一次 AI Office 完整 Demo：CEO 拆任務 → Agent 執行 → 人工核准 → 專案完成';

    public function handle(): int
    {
        $user = $this->resolveUser();

        if ($user === null) {
            return self::FAILURE;
        }

        // 順序很重要：DemoRunner 建構時就會把 AgentOrchestrator → CeoPlanner →
        // LlmProviderInterface 一路解析掉。先注入（method injection）再切換 provider
        // 的話，Planner 手上握的仍然是預設的 MockProvider，佇列空的 → 規劃直接失敗、
        // 專案變成 failed。所以先設定環境，再解析 DemoRunner。
        DemoRunner::bootstrapEnvironment();

        $runner = app(DemoRunner::class);

        $this->components->info('使用假模型（DemoScriptProvider），不會送出任何真的 API 請求。');

        $report = $runner->run(
            $user,
            fresh: (bool) $this->option('fresh'),
            decision: $this->option('reject') ? 'reject' : 'approve',
        );

        $this->renderReport($report);

        // 被拒絕是預期中的結果，不是執行失敗——指令本身仍然成功。
        return self::SUCCESS;
    }

    private function resolveUser(): ?User
    {
        $email = $this->option('user');

        if (is_string($email) && $email !== '') {
            $user = User::where('email', $email)->first();

            if ($user === null) {
                $this->components->error("找不到 email 為 [{$email}] 的使用者。");
            }

            return $user;
        }

        $admin = User::where('role', 'admin')->orderBy('id')->first();

        if ($admin === null) {
            $this->components->error(
                '找不到任何 admin 使用者可以當核准人。'
                .'請先建立一個，或用 --user=<email> 指定。'
            );
        }

        return $admin;
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function renderReport(array $report): void
    {
        $project = $report['project'];

        $this->newLine();
        $this->components->twoColumnDetail('<fg=cyan>專案</>', "{$project->name}（#{$project->id}）");
        $this->components->twoColumnDetail('狀態', $project->status);

        $this->newLine();
        $this->components->info('流程');
        foreach ($report['steps'] as $step) {
            $this->components->twoColumnDetail($step['label'], $step['detail']);
        }

        $this->newLine();
        $this->components->info('任務');
        $this->table(
            ['#', '任務', '負責 Agent', '狀態', '重試'],
            collect($report['tasks'])->map(fn ($task) => [
                $task->id,
                $task->title,
                // 三元而不是 `?->name ?? ...`：assigned_agent_id 是 nullable，但 Larastan
                // 把關聯推成非 null，會叫你拿掉 `?.`——照做就變成對 null 取屬性的致命錯誤。
                $task->agent === null ? '未指派' : $task->agent->name,
                $task->status,
                "{$task->retry_count}/{$task->max_retries}",
            ])->all(),
        );

        $approval = $report['approval'];

        if ($approval !== null) {
            $this->components->twoColumnDetail(
                '人工核准',
                "{$approval->action}（{$approval->risk_level}）→ {$approval->status}",
            );
        }

        $this->newLine();
        $this->components->info('Agent 產出的檔案');
        if ($report['workspace'] === []) {
            $this->components->warn('workspace 裡沒有檔案。');
        }
        foreach ($report['workspace'] as $file) {
            $this->components->twoColumnDetail('', $file);
        }

        $usage = $report['usage'];
        $this->newLine();
        $this->components->twoColumnDetail('事件數', (string) $report['activities']);
        $this->components->twoColumnDetail(
            'LLM 用量',
            "{$usage['requests']} 次請求／{$usage['total_tokens']} tokens／\${$usage['estimated_cost']}",
        );

        $this->newLine();
        $this->components->info("到 /ai-office/projects/{$project->id} 可以看到同一份資料的面板版本。");
    }
}
