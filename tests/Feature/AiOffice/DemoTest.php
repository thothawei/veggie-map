<?php

namespace Tests\Feature\AiOffice;

use App\AiOffice\Demo\DemoRunner;
use App\AiOffice\Models\Activity;
use App\AiOffice\Models\Approval;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * 規格第 79 節的完整 Demo。這是整個子系統第一次「從一句需求跑到專案完成」的端到端驗證：
 * 規劃 → 派工 → 工具真的寫出檔案 → 撞到風險門檻停下來 → 人核准 → 接著跑完。
 *
 * 一個字都不會送到真的 Claude API（用 DemoScriptProvider）。
 */
class DemoTest extends TestCase
{
    use RefreshDatabase;

    private string $workspaceRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workspaceRoot = sys_get_temp_dir().'/ai-office-demo-'.uniqid('', true);
        mkdir($this->workspaceRoot, 0755, true);
        config(['ai_office.workspace_root' => $this->workspaceRoot]);

        DemoRunner::bootstrapEnvironment();
    }

    protected function tearDown(): void
    {
        if (is_dir($this->workspaceRoot)) {
            File::deleteDirectory($this->workspaceRoot);
        }

        parent::tearDown();
    }

    /**
     * @return array<string, mixed>
     */
    private function runDemo(): array
    {
        return $this->app->make(DemoRunner::class)->run(User::factory()->create(['role' => 'admin']));
    }

    public function test_the_demo_takes_one_requirement_all_the_way_to_a_completed_project(): void
    {
        $report = $this->runDemo();

        $this->assertSame('completed', $report['project']->status);
        $this->assertCount(4, $report['tasks']);

        foreach ($report['tasks'] as $task) {
            $this->assertSame('completed', $task->status, "任務「{$task->title}」沒有跑完。");
        }
    }

    public function test_tasks_run_in_dependency_order_not_all_at_once(): void
    {
        $report = $this->runDemo();

        $byTitle = $report['tasks']->keyBy('title');

        // 先確認時間戳真的有值：null >= null 也是 true，少了這幾行，
        // 就算四個任務全都沒跑，下面的斷言照樣會綠。
        foreach ($byTitle as $task) {
            $this->assertNotNull($task->started_at, "任務「{$task->title}」沒有開始時間。");
            $this->assertNotNull($task->completed_at, "任務「{$task->title}」沒有完成時間。");
        }

        // 「實作」必須在「設計」完成之後才開始，否則相依圖等於沒作用。
        $this->assertTrue(
            $byTitle['實作 Todo REST API']->started_at >= $byTitle['設計 Todo 資料表']->completed_at,
        );
        $this->assertTrue(
            $byTitle['撰寫 Todo API 測試']->started_at >= $byTitle['實作 Todo REST API']->completed_at,
        );
    }

    public function test_each_task_went_to_an_agent_of_the_planned_role(): void
    {
        $report = $this->runDemo();

        $roles = $report['tasks']->mapWithKeys(fn ($task) => [$task->title => $task->agent?->role])->all();

        $this->assertSame('backend', $roles['設計 Todo 資料表']);
        $this->assertSame('backend', $roles['實作 Todo REST API']);
        $this->assertSame('qa', $roles['撰寫 Todo API 測試']);
        $this->assertSame('devops', $roles['撰寫上線說明']);
    }

    public function test_the_agents_really_wrote_files_into_the_project_workspace(): void
    {
        $report = $this->runDemo();

        // Demo 的重點之一：Agent 不是只回了一段文字，是真的產生了東西。
        $this->assertSame(
            ['DEPLOY.md', 'docs/schema.md', 'routes/todos.php', 'tests/TodoApiTest.php'],
            $report['workspace'],
        );
    }

    public function test_a_medium_risk_action_stops_for_a_human_and_resumes_after_approval(): void
    {
        $report = $this->runDemo();

        $approval = $report['approval'];

        $this->assertNotNull($approval, 'Demo 應該要撞到一次核准門檻。');
        $this->assertSame('approved', $approval->status);
        $this->assertSame('write_file', $approval->action);

        // 核准後任務要真的接著跑完，不是停在 waiting_review。
        $this->assertSame('completed', $approval->task->fresh()->status);
        $this->assertDatabaseHas('ai_office_activities', [
            'project_id' => $report['project']->id,
            'type' => 'ApprovalGranted',
        ]);
    }

    public function test_the_whole_run_is_recorded_in_activities_runs_and_token_usage(): void
    {
        $report = $this->runDemo();

        $types = Activity::query()
            ->where('project_id', $report['project']->id)
            ->pluck('type')
            ->unique()
            ->all();

        foreach (['ProjectPlanned', 'TaskStarted', 'TaskCompleted', 'ApprovalRequested', 'ApprovalGranted'] as $type) {
            $this->assertContains($type, $types, "事件流少了 {$type}。");
        }

        // 每一次 LLM 請求都要記帳（規格第 40 節），包含規劃那一次。
        $this->assertGreaterThan(0, $report['usage']['requests']);
        $this->assertGreaterThan(0, $report['usage']['total_tokens']);

        $this->assertDatabaseHas('ai_office_task_runs', ['status' => 'completed']);
        $this->assertDatabaseHas('ai_office_tool_executions', ['action' => 'write_file', 'status' => 'succeeded']);
    }

    public function test_rejecting_the_approval_stops_the_task_instead_of_silently_continuing(): void
    {
        $user = User::factory()->create(['role' => 'admin']);

        $report = $this->app->make(DemoRunner::class)
            ->run($user, name: 'Todo API Demo（拒絕）', decision: 'reject');

        $approval = Approval::query()->where('project_id', $report['project']->id)->firstOrFail();
        $this->assertSame('rejected', $approval->status);

        // 被拒絕的那一步不能自己往下跑：任務停在 rejected，工具沒有被執行。
        $blocked = $report['project']->tasks()->where('title', '撰寫上線說明')->firstOrFail();
        $this->assertSame('rejected', $blocked->status);
        $this->assertNotContains('DEPLOY.md', $report['workspace']);

        $this->assertDatabaseHas('ai_office_tool_executions', [
            'task_id' => $blocked->id,
            'action' => 'write_file',
            'status' => 'denied',
        ]);
    }
}
